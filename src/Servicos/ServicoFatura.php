<?php

namespace Onsoft\Agt\Servicos;

use App\Models\Agt\AgtInvoiceSubmission;
use App\Models\Agt\OrganizationAgtConfig;
use App\Models\Invoice\Invoice;
use App\Models\Invoice\InvoiceItem;
use App\Models\Invoice\InvoiceItemTax;
use App\Models\Invoice\InvoicePayment;
use App\Models\Invoice\InvoicePaymentAllocation;
use App\Models\Invoice\InvoicePaymentMethod;
use App\Models\Wallet\GuardianWallet;
use App\Models\Wallet\GuardianWalletMovement;
// App\Jobs\SubmitInvoiceToAgtJob removido — o pacote usa o seu próprio
// Onsoft\Agt\Jobs\SubmeterFaturaAgtJob (ver chamadas de dispatch abaixo),
// que tem protecção explícita contra submissão de faturas SAF-T(AO).
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Onsoft\Agt\Excecoes\ExcecaoFaturaAgt;
use Onsoft\Agt\Enums\TipoDocumento;
use Onsoft\Agt\Enums\EstadoPagamento;

/**
 * ServicoFatura
 *
 * Motor central de criação de faturas AGT.
 *
 * RECONSTRUÍDO a partir da documentação OFICIAL da AGT
 * (https://quiosqueagt.minfin.gov.ao/doc-agt/faturacao-electronica/1/).
 *
 * Funcionalidades:
 * ─────────────────────────────────────────────────────────────────
 * ✅ Múltiplos pagamentos (Numerário + Carteira + Transferência + Multicaixa)
 * ✅ Múltiplos estudantes por fatura (cada linha com alunoId distinto)
 * ✅ Consumidor Final lido do array do pedido (nunca assumido)
 * ✅ Carteira do encarregado como meio de pagamento
 * ✅ Excesso creditado automaticamente na carteira do encarregado
 * ✅ FR (Fatura-Recibo) obrigatoriamente pago na totalidade
 * ✅ NC gerada automaticamente ao cancelar FR submetido à AGT
 * ✅ jwsDocumentSignature (RS256) assinado com CHAVE DO CONTRIBUINTE
 *    (emitida pela AGT, disponível no portal do contribuinte)
 * ✅ jwsSoftwareSignature (RS256) assinado com CHAVE DO SOFTWARE
 *    (.env — gerada localmente pelo fabricante Onsoft)
 * ✅ Auto-submissão via queue quando auto_submit_invoices = true
 * ✅ 18 tipos de documento reais (ver Onsoft\Agt\Enums\TipoDocumento)
 *    com regras específicas por tipo
 */
class ServicoFatura
{
    private ServicoValidacaoPropina $validacaoPropina;

    public function __construct(
        private ServicoAssinatura $assinatura,
        private ServicoSeries     $series,
        private ServicoApiAgt     $apiAgt
    ) {
        $this->validacaoPropina = new ServicoValidacaoPropina();
    }

    // ══════════════════════════════════════════════════════════════════
    // CRIAR FATURA
    // ══════════════════════════════════════════════════════════════════

    public function criar(array $dados, int $organizacaoId, bool $verificarAutoSubmit = true): Invoice
    {
        // ── -2. Restrição de âmbito de tipos de documento ──────────────────
        // Este sistema só emite os tipos configurados em
        // onsoft-agt.tipos_activos (por defeito: FT, FR, NC, ND).
        // "FP" (Pró-forma) é tratado por ServicoFaturaProforma — nunca
        // chega aqui, porque nunca é persistido como Invoice.
        $tipoDocumentoPreliminar = strtoupper($dados['document_type'] ?? 'FR');
        $this->validarAmbitoTipoDocumento($tipoDocumentoPreliminar);

        // ── -1. Licença activa (appCode) + limite diário ──────────────────
        // CRÍTICO: deve correr ANTES de qualquer escrita. Verifica
        // Organization.appCode = true e o limite diário configurado em
        // organization_invoice_daily_limits. Lança ExcecaoFaturaAgt com
        // mensagem clara se a organização não puder emitir agora.
        (new ServicoLimiteDiario())->verificar($organizacaoId, $tipoDocumentoPreliminar);

        // ── 0. Idempotency check ─────────────────────────────────────────
        // Se o mesmo pedido for enviado duas vezes (timeout, retry do frontend),
        // devolve a fatura já criada em vez de criar uma duplicada.
        // O frontend deve enviar um UUID único em cada pedido de criação.
        if (!empty($dados['idempotency_key'])) {
            $existente = Invoice::withoutGlobalScopes()
                ->where('organizationId', $organizacaoId)
                ->where('idempotency_key', $dados['idempotency_key'])
                ->first();

            if ($existente) {
                return $existente->load([
                    'items.taxes',
                    'payments.methods',
                    'payments.allocations',
                    'agtSeries',
                ]);
            }
        }

        // Retry automático em caso de deadlock (3 tentativas).
        // O lock de propinas é estreito (por aluno+mensalidade), por isso
        // deadlocks só ocorrem em colisões muito raras no mesmo aluno.
        return DB::transaction(function () use ($dados, $organizacaoId, $verificarAutoSubmit) {

            // ── 1. Tipo de documento ─────────────────────────────────────
            $tipoDocumento = strtoupper($dados['document_type'] ?? 'FR');
            $this->validarTipoDocumento($tipoDocumento);

            // ── 2. Série fiscal e número ─────────────────────────────────
            $serie     = $this->series->garantirSerieFiscal($organizacaoId, $tipoDocumento);
            $numeroDoc = $this->series->proximoNumeroDocumento($serie);
            $sequencia = $this->series->extrairNumeroSequencial($numeroDoc);

            // ── 3. Validar itens ─────────────────────────────────────────
            $itens = collect($dados['items'] ?? []);
            if ($itens->isEmpty()) {
                throw new ExcecaoFaturaAgt('A fatura deve ter pelo menos um item.');
            }

            // ── 3.1 Validar ORDEM de pagamento de propinas (mesId) ────────
            // Itens com item_category = 'propina' devem trazer mesId e
            // respeitar a ordem sequencial (orderNumber) — não pode pagar
            // mês 7 sem ter pago 1-6 antes. Validação dentro da mesma
            // transação para garantir atomicidade total.
            $this->validarOrdemPropinasNosItens($itens, $organizacaoId);

            // ── 3.2 AGT Anexo I, ponto 33.c: a data/hora do sistema não pode
            // ser anterior à do último documento emitido na mesma série.
            // Previne retrocesso de relógio do servidor a corromper a
            // sequência cronológica exigida pela AGT.
            $this->validarCronologiaSerie($organizacaoId, $tipoDocumento);

            // ── 4. Calcular totais ───────────────────────────────────────
            $totais    = $this->calcularTotais($itens->all());

            // ── 5. Consumidor final (sempre do array do pedido) ──────────
            $consumidor = $this->resolverConsumidor($dados);

            // ── 6. Pagamentos múltiplos ──────────────────────────────────
            $pagamentos = collect($dados['payments'] ?? []);

            // ── 6.1 Validar exclusividade — métodos como Multicaixa
            // Express e Referência Multicaixa não podem ser misturados
            // com outros métodos na mesma fatura. Aplica-se SEMPRE,
            // mesmo quando a fatura não passa pelo fluxo de staging
            // (ex: criação directa pelo backoffice).
            $this->validarExclusividadeMetodos($pagamentos);

            $totalPago  = $this->money($pagamentos->sum(fn($p) => (float) $p['amount']));
            $troco      = max(0, $this->money($totalPago - $totais['gross_total']));
            $emFalta    = max(0, $this->money($totais['gross_total'] - $totalPago));

            // ── 7. FR obriga pagamento total ─────────────────────────────
            if ($tipoDocumento === 'FR' && $emFalta > 0) {
                throw new ExcecaoFaturaAgt(
                    "Fatura-Recibo (FR) só pode ser emitida quando totalmente paga. " .
                    "Total: {$totais['gross_total']} AOA | Pago: {$totalPago} AOA | Em falta: {$emFalta} AOA."
                );
            }

            // ── 8. Estado de pagamento ───────────────────────────────────
            $estadoPagamento = match (true) {
                $emFalta > 0 => EstadoPagamento::PARCIAL->value,
                $troco > 0   => EstadoPagamento::EXCEDIDO->value,
                default      => EstadoPagamento::PAGO->value,
            };

            // ── 9. Snapshots ─────────────────────────────────────────────
            // ── 9. Snapshot da organização — SEMPRE da BD, nunca do request ──
            $snapshotOrg = $this->construirSnapshotOrganizacao($organizacaoId);

            // ── 9.1 Resolver o modo de faturação ANTES de criar a fatura ──
            // Gravado directamente na fatura (campo imutável) — separa
            // claramente faturas Eletrónicas de faturas SAF-T desde a
            // origem, independentemente de a organização trocar de modo
            // depois. O frontend usa este campo para mostrar/esconder
            // botões (ex: nunca mostrar "Submeter à AGT" numa fatura
            // criada com invoicing_mode = 'saft_ao').
            $modoFaturacao    = new \Onsoft\Agt\Servicos\ServicoModoFaturacao();
            $emModoEletronico = $modoFaturacao->estaEmModoEletronico($organizacaoId);
            $modoDaFatura     = $emModoEletronico
                ? \Onsoft\Agt\Servicos\ServicoModoFaturacao::ELECTRONIC
                : \Onsoft\Agt\Servicos\ServicoModoFaturacao::SAFT_AO;

            // ── 10. Criar registo da fatura ──────────────────────────────
            $fatura = Invoice::withoutGlobalScopes()->create([
                'organizationId'          => $organizacaoId,
                'customerId'              => $dados['customerId'] ?? ($dados['encarregadoId'] ?? null),
                'encarregadoId'           => $dados['encarregadoId'] ?? null,
                'studentId'               => $dados['studentId'] ?? $dados['alunoId'] ?? null,
                'agtSeriesId'             => $serie->id,
                'document_type'           => $tipoDocumento,
                'document_no'             => $numeroDoc,
                'document_number'         => $numeroDoc,
                'sequence_number'         => $sequencia,
                'series_code'             => $serie->series_code,
                'fiscal_year'             => $serie->fiscal_year,
                'currency'                => $dados['currency'] ?? config('onsoft-agt.moeda_padrao', 'AOA'),
                'subtotal'                => $totais['subtotal'],
                'discount_total'          => $totais['desconto'],
                'tax_total'               => $totais['iva'],
                'gross_total'             => $totais['gross_total'],
                'total'                   => $totais['gross_total'],
                'paid_total'              => $totalPago,
                'remaining_balance'       => $emFalta,
                'balance_due'             => $emFalta,
                'change_amount'           => $troco,
                'wallet_generated_amount' => $troco,
                'payment_status'          => $estadoPagamento,
                'agt_status'              => 'draft',
                'invoicing_mode'          => $modoDaFatura,
                'submission_uuid'         => (string) Str::uuid(),
                'organization_snapshot'   => $snapshotOrg,
                'customer_snapshot'       => $consumidor,
                'encarregado_snapshot'    => $consumidor,
                'issued_at'               => now(),
                'appCode'                 => $dados['appCode'] ?? 1,
                'sourceInvoiceId'         => $dados['sourceInvoiceId'] ?? null,
                // Preenchido apenas pelo fluxo de correcção de fatura
                // rejeitada (ver corrigirFaturaRejeitada()) — exigência
                // da documentação AGT (erro E46): correcções de
                // documentos rejeitados exigem um NOVO documentNo,
                // referenciando o ORIGINAL rejeitado neste campo.
                'rejected_document_no'    => $dados['rejected_document_no'] ?? null,
                'idempotency_key'         => $dados['idempotency_key'] ?? null,
            ]);

            // ── 11. Criar linhas da fatura ───────────────────────────────
            foreach ($itens->values() as $indice => $item) {
                $this->criarLinhaFatura($fatura, $item, $indice, $organizacaoId);
            }

            // ── 12. Processar pagamentos e carteira ──────────────────────
            if ($pagamentos->isNotEmpty()) {
                $this->processarPagamentosECarteira(
                    $fatura, $dados, $organizacaoId,
                    $pagamentos, $troco, $totais['gross_total'], $totalPago
                );
            }

            // ── 13. Hash chain + assinaturas JWS ────────────────────────
            // SÓ aplicável em modo 'electronic'. Em modo 'saft_ao' a
            // fatura não é assinada nem submetida em tempo real — fica
            // disponível para ser incluída no próximo ficheiro SAF-T(AO).
            if ($emModoEletronico) {
                $this->gerarEGuardarHashChain($fatura, $organizacaoId);
            } else {
                $fatura->saveQuietly(); // garante state persistido sem hash
            }

            // ── 13.1 Criar o snapshot imutável — SÓ AGORA, depois de
            // itens, pagamentos e hash (se aplicável) estarem todos
            // persistidos. Criar mais cedo (ex: no evento `created` do
            // Eloquent) produziria sempre um snapshot vazio, porque os
            // itens e pagamentos ainda não existiam nesse momento.
            $fatura->refresh();
            \Onsoft\Agt\Observers\InvoiceObserver::criarSnapshotAgora($fatura);

            // ── 14. Auto-submeter à AGT ──────────────────────────────────
            $fatura->load(['items.taxes', 'payments.methods', 'payments.allocations', 'agtSeries']);

            if ($verificarAutoSubmit && $emModoEletronico) {
                $config = $this->obterConfigAgt($organizacaoId);

                if ($config?->agt_enabled && $config->auto_submit_invoices) {
                    // Apenas campos mutáveis — guard permite estas alterações
                    \Onsoft\Agt\Servicos\InvoiceSnapshotGuard::permitirMutacao($fatura, ['agt_status']);
                    $fatura->agt_status = 'pending';
                    $fatura->saveQuietly();
                    \Onsoft\Agt\Jobs\SubmeterFaturaAgtJob::dispatch($fatura->id);
                }
            } elseif (!$emModoEletronico) {
                // Em modo SAF-T(AO) o estado reflecte que aguarda inclusão
                // no próximo ficheiro de exportação, não submissão AGT.
                \Onsoft\Agt\Servicos\InvoiceSnapshotGuard::permitirMutacao($fatura, ['agt_status']);
                $fatura->agt_status = 'saft_pending_export';
                $fatura->saveQuietly();
            }

            // Incrementar contador diário thread-safe — só depois de
            // a fatura estar realmente persistida com sucesso.
            (new ServicoLimiteDiario())->incrementar($organizacaoId);

            return $fatura->fresh([
                'items.taxes',
                'payments.methods',
                'payments.allocations',
                'agtSeries',
            ]);
        }, 3); // 3 tentativas automáticas em caso de deadlock
    }

    // ══════════════════════════════════════════════════════════════════
    // CANCELAR FATURA
    // ══════════════════════════════════════════════════════════════════

    public function cancelar(int $faturaId, int $organizacaoId, string $motivo): Invoice
    {
        return DB::transaction(function () use ($faturaId, $organizacaoId, $motivo) {
            $fatura = Invoice::withoutGlobalScopes()
                ->where('organizationId', $organizacaoId)
                ->where('id', $faturaId)
                ->with(['items.taxes', 'payments'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($fatura->payment_status === 'cancelled') {
                throw new ExcecaoFaturaAgt('Esta fatura já está cancelada.');
            }

            if ($fatura->document_type === 'NC') {
                throw new ExcecaoFaturaAgt('Uma Nota de Crédito não pode ser cancelada.');
            }

            // Determinar se é necessária NC consoante o REGIME da fatura:
            // - electronic: precisa de NC se já foi submetida/aceite pela AGT
            // - saft_ao: precisa de NC se já foi incluída num ficheiro
            //   SAF-T exportado (saft_exported) — nesse momento já foi
            //   reportada à AGT, exactamente como uma fatura electronic
            //   aceite. Faturas saft_pending_export (nunca exportadas)
            //   podem ser canceladas sem NC, tal como um draft electronic.
            $modoFatura = $fatura->invoicing_mode ?? \Onsoft\Agt\Servicos\ServicoModoFaturacao::ELECTRONIC;

            $precisaNC = $modoFatura === \Onsoft\Agt\Servicos\ServicoModoFaturacao::SAFT_AO
                ? $fatura->agt_status === 'saft_exported'
                : in_array($fatura->agt_status, ['enviado', 'aceite', 'submitted', 'accepted'], true);

            if ($precisaNC) {
                $nc = $this->gerarNotaCredito($fatura, $motivo, $organizacaoId);
                $fatura->update([
                    'payment_status' => 'cancelled',
                    'agt_status'     => 'pending_nc',
                    'cancel_reason'  => $motivo,
                    'cancelled_at'   => now(),
                ]);
                return $nc->load(['items.taxes', 'payments']);
            }

            $fatura->update([
                'payment_status' => 'cancelled',
                'agt_status'     => 'cancelled',
                'cancel_reason'  => $motivo,
                'cancelled_at'   => now(),
            ]);

            $this->reverterCarteiraNoCancelamento($fatura, $organizacaoId);

            return $fatura->load(['items', 'payments']);
        });
    }

    // ══════════════════════════════════════════════════════════════════
    // NOTA DE CRÉDITO
    // ══════════════════════════════════════════════════════════════════

    public function gerarNotaCredito(Invoice $original, string $motivo, int $organizacaoId): Invoice
    {
        // Verificação de coerência de âmbito: NC é gerada internamente
        // pelo sistema (nunca por pedido directo do utilizador com
        // document_type arbitrário), por isso não passa por
        // validarAmbitoTipoDocumento(). Mas se 'NC' tiver sido removida
        // de onsoft-agt.tipos_activos por configuração, isso deixaria
        // faturas FR/FT existentes sem forma de serem corrigidas/
        // canceladas após submissão — um estado inconsistente que deve
        // ser sinalizado explicitamente, não falhar silenciosamente.
        $tiposActivos = array_map('strtoupper', config('onsoft-agt.tipos_activos', ['FT', 'FR', 'NC', 'ND']));
        if (!in_array('NC', $tiposActivos, true)) {
            throw new ExcecaoFaturaAgt(
                "Configuração inconsistente: 'NC' foi removida de onsoft-agt.tipos_activos, " .
                "mas o sistema precisa de gerar Notas de Crédito para cancelar faturas já " .
                "submetidas à AGT. Adicione 'NC' a AGT_TIPOS_ACTIVOS no .env."
            );
        }

        // AGT Anexo I, ponto 30: não permitir NC para um documento já
        // anulado ou já totalmente rectificado por outra NC anterior.
        $jaTemNC = Invoice::withoutGlobalScopes()
            ->where('organizationId', $organizacaoId)
            ->where('document_type', 'NC')
            ->where('sourceInvoiceId', $original->id)
            ->whereNotIn('payment_status', ['cancelled'])
            ->exists();

        if ($jaTemNC) {
            throw new ExcecaoFaturaAgt(
                "A fatura {$original->document_no} já tem uma Nota de Crédito activa associada. " .
                "Não é possível emitir uma segunda NC para o mesmo documento. " .
                "Conforme AGT Anexo I, ponto 30."
            );
        }

        $serieNC  = $this->series->garantirSerieFiscal($organizacaoId, 'NC');
        $numeroNC = $this->series->proximoNumeroDocumento($serieNC);
        $sequencia = $this->series->extrairNumeroSequencial($numeroNC);

        $nc = Invoice::withoutGlobalScopes()->create([
            'organizationId'          => $organizacaoId,
            'customerId'              => $original->customerId,
            'encarregadoId'           => $original->encarregadoId,
            'agtSeriesId'             => $serieNC->id,
            'document_type'           => 'NC',
            'document_no'             => $numeroNC,
            'document_number'         => $numeroNC,
            'sequence_number'         => $sequencia,
            'series_code'             => $serieNC->series_code,
            'fiscal_year'             => $serieNC->fiscal_year,
            'currency'                => $original->currency,
            'subtotal'                => $original->subtotal,
            'discount_total'          => $original->discount_total,
            'tax_total'               => $original->tax_total,
            'gross_total'             => $original->gross_total,
            'total'                   => $original->gross_total,
            'paid_total'              => $original->paid_total,
            'remaining_balance'       => 0,
            'balance_due'             => 0,
            'change_amount'           => 0,
            'wallet_generated_amount' => 0,
            'payment_status'          => 'paid',
            'agt_status'              => 'draft',
            'invoicing_mode'          => $original->invoicing_mode ?? \Onsoft\Agt\Servicos\ServicoModoFaturacao::ELECTRONIC,
            'submission_uuid'         => (string) Str::uuid(),
            'sourceInvoiceId'         => $original->id,
            'cancel_reason'           => $motivo,
            'organization_snapshot'   => $original->organization_snapshot,
            'customer_snapshot'       => $original->customer_snapshot,
            'encarregado_snapshot'    => $original->encarregado_snapshot,
            'issued_at'               => now(),
            'appCode'                 => $original->appCode ?? 1,
        ]);

        // Espelhar linhas com debitAmount (NC usa DebitAmount per AGT spec)
        foreach ($original->items as $itemOriginal) {
            $linhaAgt = $itemOriginal->agt_line_snapshot;
            if (is_array($linhaAgt)) {
                $linhaAgt['debitAmount']  = $linhaAgt['creditAmount'] ?? 0;
                $linhaAgt['creditAmount'] = 0;
            }

            $novoItem = InvoiceItem::withoutGlobalScopes()->create([
                'organizationId'    => $organizacaoId,
                'invoiceId'         => $nc->id,
                'invoiceable_type'  => $itemOriginal->invoiceable_type,
                'invoiceable_id'    => $itemOriginal->invoiceable_id,
                'alunoId'           => $itemOriginal->alunoId,
                'item_category'     => $itemOriginal->item_category,
                'product_code'      => $itemOriginal->product_code,
                'description'       => '[NC] ' . $itemOriginal->description,
                'quantity'          => $itemOriginal->quantity,
                'unit_of_measure'   => $itemOriginal->unit_of_measure ?? 'UN',
                'unit_price'        => $itemOriginal->unit_price,
                'unit_price_base'   => $itemOriginal->unit_price_base,
                'discount_amount'   => $itemOriginal->discount_amount,
                'tax_percentage'    => $itemOriginal->tax_percentage,
                'tax_amount'        => $itemOriginal->tax_amount,
                'tax_type'          => $itemOriginal->tax_type,
                'tax_code'          => $itemOriginal->tax_code,
                'tax_reason'        => $itemOriginal->tax_reason,
                'subtotal'          => $itemOriginal->subtotal,
                'line_total'        => $itemOriginal->line_total ?? $itemOriginal->total,
                'total'             => $itemOriginal->total,
                'line_number'       => $itemOriginal->line_number,
                'item_snapshot'     => $itemOriginal->item_snapshot,
                'aluno_snapshot'    => $itemOriginal->aluno_snapshot,
                'agt_line_snapshot' => $linhaAgt,
                'appCode'           => 1,
            ]);

            foreach ($itemOriginal->taxes as $imposto) {
                InvoiceItemTax::withoutGlobalScopes()->create([
                    'organizationId'     => $organizacaoId,
                    'invoiceItemId'      => $novoItem->id,
                    'tax_type'           => $imposto->tax_type,
                    'tax_country_region' => $imposto->tax_country_region ?? 'AO',
                    'tax_code'           => $imposto->tax_code,
                    'tax_percentage'     => $imposto->tax_percentage,
                    'tax_contribution'   => $imposto->tax_contribution,
                    'tax_reason'         => $imposto->tax_reason,
                ]);
            }
        }

        // Hash chain SÓ se a NC for em regime electronic — espelha
        // exactamente a mesma regra usada na criação de faturas normais
        // em ServicoFatura::criar(). Uma NC de uma fatura SAF-T exportada
        // também fica sem hash, e marcada para o próximo ficheiro SAF-T.
        $ncEmModoEletronico = $nc->invoicing_mode === \Onsoft\Agt\Servicos\ServicoModoFaturacao::ELECTRONIC;

        if ($ncEmModoEletronico) {
            $this->gerarEGuardarHashChain($nc, $organizacaoId);
        } else {
            \Onsoft\Agt\Servicos\InvoiceSnapshotGuard::permitirMutacao($nc, ['agt_status']);
            $nc->agt_status = 'saft_pending_export';
            $nc->saveQuietly();
        }

        // Criar o snapshot imutável da NC — agora que todas as linhas
        // (espelhadas da fatura original) e o hash (se aplicável) já
        // estão persistidos. Mesma regra de ordem usada em criar().
        $nc->refresh();
        \Onsoft\Agt\Observers\InvoiceObserver::criarSnapshotAgora($nc);

        $this->reverterCarteiraNoCancelamento($original, $organizacaoId, $nc->id);

        $config = $this->obterConfigAgt($organizacaoId);

        if ($ncEmModoEletronico && $config?->agt_enabled && $config->auto_submit_invoices) {
            \Onsoft\Agt\Servicos\InvoiceSnapshotGuard::permitirMutacao($nc, ['agt_status']);
            $nc->agt_status = 'pending';
            $nc->saveQuietly();
            \Onsoft\Agt\Jobs\SubmeterFaturaAgtJob::dispatch($nc->id);
        }

        return $nc->load(['items.taxes', 'payments']);
    }

    /**
     * Corrigir uma fatura REJEITADA pela AGT, criando uma NOVA fatura
     * com um NOVO número de documento — exigência explícita da
     * documentação oficial.
     *
     * ══════════════════════════════════════════════════════════════════════
     * LACUNA ENCONTRADA NESTA AUDITORIA — porque este método existe
     * ══════════════════════════════════════════════════════════════════════
     * A documentação AGT (Registar Factura, regra FE-RNG-073, erro E46)
     * confirma: "A emissão de documentos com o mesmo número de
     * identificação no campo documentNo de outro documento previamente
     * enviado e rejeitado pela AGT não é aceite. As correcções de
     * documentos rejeitados deverão ser efectuados com a utilização de
     * um novo número de documento."
     *
     * Antes desta correcção, ServicoFlagsUiFatura mostrava o botão
     * "Submeter à AGT" também para faturas com agt_status='rejected',
     * levando o utilizador a tentar resubmeter a MESMA fatura com o
     * MESMO documentNo — que a AGT rejeitaria outra vez, indefinidamente,
     * sem nenhum caminho de saída.
     *
     * Este método: cria uma NOVA fatura, com NOVO documentNo (nova
     * série/sequência), copiando os itens da fatura rejeitada,
     * preenchendo rejected_document_no com o documentNo original — que
     * ServicoConstrutorPayloadAgt já sabia usar para marcar
     * documentStatus='C' (Correcção) no payload enviado à AGT.
     *
     * A fatura rejeitada original NUNCA é apagada ou alterada — fica
     * como registo histórico, com agt_status='rejected' permanente.
     */
    public function corrigirFaturaRejeitada(Invoice $rejeitada, int $organizacaoId, array $alteracoes = []): Invoice
    {
        if ($rejeitada->agt_status !== 'rejected') {
            throw new ExcecaoFaturaAgt(
                "A fatura {$rejeitada->document_no} não está em estado 'rejected' " .
                "(estado actual: {$rejeitada->agt_status}). Só é possível corrigir faturas rejeitadas pela AGT."
            );
        }

        if (($rejeitada->invoicing_mode ?? ServicoModoFaturacao::ELECTRONIC) === ServicoModoFaturacao::SAFT_AO) {
            throw new ExcecaoFaturaAgt(
                "A fatura {$rejeitada->document_no} foi criada em modo SAF-T(AO) — o estado " .
                "'rejected' não se aplica a esse regime (não há submissão em tempo real a rejeitar)."
            );
        }

        $rejeitada->loadMissing(['items.taxes', 'payments.methods', 'payments.allocations']);

        // Construir os dados da nova fatura a partir da rejeitada,
        // permitindo que o chamador sobreponha campos específicos
        // (ex: corrigir o customerTaxID que causou a rejeição).
        $dadosNovaFatura = array_merge([
            'document_type'   => $rejeitada->document_type,
            'customer_nif'    => data_get($rejeitada->customer_snapshot, 'nif'),
            'customer_name'   => data_get($rejeitada->customer_snapshot, 'name'),
            'customer_country' => data_get($rejeitada->customer_snapshot, 'country_code', 'AO'),
            'encarregadoId'   => $rejeitada->encarregadoId,
            'studentId'       => $rejeitada->studentId,
            'items'           => $rejeitada->items->map(fn($item) => [
                'description'      => $item->description,
                'quantity'         => $item->quantity,
                'unit_price'       => $item->unit_price,
                'unit_price_base'  => $item->unit_price_base,
                'discount_amount'  => $item->discount_amount,
                'tax_percentage'   => $item->tax_percentage,
                'tax_code'         => $item->tax_code,
                'tax_type'         => $item->tax_type,
                'tax_reason'       => $item->tax_reason,
                'item_category'    => $item->item_category,
                'product_code'     => $item->product_code,
                'unit_of_measure'  => $item->unit_of_measure,
            ])->values()->all(),
            'payments' => $rejeitada->payments->map(fn($p) => [
                'amount'             => $p->amount,
                'tipodepagamentoId'  => $p->methods->first()?->tipodepagamentoId,
                'method_code'        => $p->methods->first()?->method_code,
                'reference'          => $p->reference,
            ])->values()->all(),
        ], $alteracoes);

        // sourceInvoiceId aponta para a fatura rejeitada (rasto de
        // proveniência interna); rejected_document_no é o campo que
        // ServicoConstrutorPayloadAgt usa para o payload real da AGT.
        $dadosNovaFatura['sourceInvoiceId']       = $rejeitada->id;
        $dadosNovaFatura['rejected_document_no']  = $rejeitada->document_no;

        return $this->criar($dadosNovaFatura, $organizacaoId);
    }

    // ══════════════════════════════════════════════════════════════════
    // HASH CHAIN + ASSINATURAS JWS
    // ══════════════════════════════════════════════════════════════════

    /**
     * Gerar e guardar as assinaturas digitais reais da AGT.
     *
     * RECONSTRUIDO a partir da documentacao OFICIAL da AGT
     * (https://quiosqueagt.minfin.gov.ao/doc-agt/faturacao-electronica/1/).
     *
     * A versao anterior gerava um "hash chain" RSA-SHA1 sobre uma string
     * concatenada por ";" (InvoiceDate;SystemEntryDate;...) - esse
     * mecanismo NAO EXISTE na API REST real da AGT. A AGT nao usa hash
     * chain entre documentos; usa duas assinaturas JWS RS256
     * independentes por documento:
     *
     *   jwsSoftwareSignature - assina {productId, productVersion,
     *     softwareValidationNumber} com a chave do SOFTWARE
     *
     *   jwsDocumentSignature - assina {documentNo, taxRegistrationNumber,
     *     documentType, documentDate, customerTaxID, customerCountry,
     *     companyName, documentTotals} com a chave do CONTRIBUINTE
     *
     * NAO ha "hashAnterior" nem "HashControl" de 4 caracteres no esquema
     * real - esses conceitos vinham do Decreto Executivo / Anexo II e
     * nao tem equivalente na API REST. Os campos invoice_hash,
     * hash_control e previous_invoice_hash sao mantidos na BD por
     * compatibilidade com migracoes/relatorios ja existentes, mas
     * deixam de ser CALCULADOS - passam a reflectir directamente o
     * jws_document_signature.
     */
    public function gerarEGuardarHashChain(Invoice $fatura, int $organizacaoId): void
    {
        $dataEmissao     = $fatura->issued_at?->format('Y-m-d') ?? now()->format('Y-m-d');
        $numeroDocumento = $fatura->document_no ?? $fatura->document_number;
        $config          = $this->obterConfigAgt($organizacaoId);

        $jwsDocSig  = null;
        $jwsSoftSig = null;

        // -- jwsDocumentSignature - CHAVE DO CONTRIBUINTE ----------------
        // IMPORTANTE: a chave do contribuinte e EMITIDA PELA AGT e
        // disponibilizada no portal do contribuinte - nunca gerada
        // localmente (ver documentacao "Gestao de Certificados e Chaves").
        // O pacote apenas a guarda (encriptada) depois de o utilizador a
        // copiar do portal para a configuracao da organizacao.
        if ($config?->taxpayer_private_key) {
            try {
                $documentTotals = [
                    'taxPayable' => $this->money((float) $fatura->tax_total),
                    'netTotal'   => $this->money((float) $fatura->subtotal),
                    'grossTotal' => $this->money((float) ($fatura->gross_total ?? $fatura->total)),
                ];

                $jwsDocSig = $this->assinatura->assinarDocumento(
                    documentNo:             $numeroDocumento,
                    taxRegistrationNumber:  $config->tax_registration_number,
                    documentType:           $fatura->document_type,
                    documentDate:           $dataEmissao,
                    customerTaxID:          data_get($fatura->customer_snapshot, 'nif', '999999999'),
                    customerCountry:        data_get($fatura->customer_snapshot, 'country_code', 'AO'),
                    companyName:            data_get($fatura->customer_snapshot, 'name', 'Consumidor Final'),
                    documentTotals:         $documentTotals,
                    chavePrivadaContribuintePem: $config->taxpayer_private_key,
                );

            } catch (\Throwable $e) {
                Log::warning('OnsoftAgt: Falha ao gerar jwsDocumentSignature (chave do contribuinte)', [
                    'fatura_id' => $fatura->id,
                    'erro'      => $e->getMessage(),
                ]);
            }
        } else {
            Log::warning('OnsoftAgt: Chave privada do contribuinte nao configurada - jwsDocumentSignature nao gerada. Esta chave e emitida pela AGT e deve ser copiada do portal do contribuinte.', [
                'fatura_id'      => $fatura->id,
                'organizationId' => $organizacaoId,
            ]);
        }

        // -- jwsSoftwareSignature - CHAVE DO SOFTWARE (.env, fabricante) --
        // Esta chave E gerada localmente pelo produtor (Onsoft) e a
        // respectiva chave PUBLICA e submetida no Portal do Parceiro:
        //   Testes:   https://portaldoparceiro.hml.minfin.gov.ao/
        //   Producao: https://portaldoparceiro.minfin.gov.ao/
        $chavePrivadaSoftware = config('onsoft-agt.software.chave_privada', '');

        if (!empty($chavePrivadaSoftware)) {
            try {
                $chavePem = str_replace('\n', "\n", $chavePrivadaSoftware);

                $jwsSoftSig = $this->assinatura->assinarSoftwareInfo(
                    productId:                config('onsoft-agt.software.nome', 'Onsoft AGT'),
                    productVersion:           config('onsoft-agt.software.versao', '1.0.0'),
                    softwareValidationNumber: config('onsoft-agt.software.numero_certificacao', ''),
                    chavePrivadaSoftwarePem:  $chavePem,
                );

            } catch (\Throwable $e) {
                Log::warning('OnsoftAgt: Falha ao gerar jwsSoftwareSignature', [
                    'fatura_id' => $fatura->id,
                    'erro'      => $e->getMessage(),
                ]);
            }
        } else {
            Log::warning('OnsoftAgt: AGT_SOFTWARE_CHAVE_PRIVADA nao definida no .env - jwsSoftwareSignature nao gerada.', [
                'fatura_id' => $fatura->id,
            ]);
        }

        // -- Guardar - invoice_hash/hash_control mantidos por
        // compatibilidade de schema, reflectindo agora jws_document_signature
        // em vez de um hash RSA-SHA1 calculado (que nao existe no esquema real).
        $fatura->invoice_hash           = $jwsDocSig;
        $fatura->previous_invoice_hash  = null; // conceito nao existe na API REST real
        $fatura->hash_control           = $jwsDocSig ? substr(hash('sha256', $jwsDocSig), 0, 8) : null;
        $fatura->jws_document_signature = $jwsDocSig;
        $fatura->jws_software_signature = $jwsSoftSig;
        $fatura->saveQuietly();
    }

    // ══════════════════════════════════════════════════════════════════
    // PAGAMENTOS E CARTEIRA
    // ══════════════════════════════════════════════════════════════════

    private function processarPagamentosECarteira(
        Invoice $fatura,
        array   $dados,
        int     $organizacaoId,
        $pagamentos,
        float   $troco,
        float   $grossTotal,
        float   $totalPago
    ): void {
        $encarregadoId = $dados['encarregadoId'] ?? null;

        $carteira = $encarregadoId
            ? GuardianWallet::withoutGlobalScopes()->firstOrCreate(
                ['organizationId' => $organizacaoId, 'encarregadoId' => $encarregadoId],
                ['balance' => 0, 'currency' => 'AOA', 'active' => true]
            )
            : null;

        $totalAlocado = 0.0;

        foreach ($pagamentos as $pagamento) {
            $metodoCodigo  = strtolower((string) ($pagamento['payment_method'] ?? $pagamento['method_code'] ?? 'cash'));
            $usaCarteira   = in_array($metodoCodigo, ['wallet', 'carteira', 'saldo'], true);
            $valorCarteira = $usaCarteira ? (float) $pagamento['amount'] : 0;
            $antesCarteira = $carteira ? (float) $carteira->balance : 0;

            if ($usaCarteira && $carteira) {
                if ($antesCarteira < $valorCarteira) {
                    throw new ExcecaoFaturaAgt(
                        "Saldo insuficiente na carteira. " .
                        "Saldo: {$antesCarteira} AOA | Solicitado: {$valorCarteira} AOA."
                    );
                }

                $carteira->balance = $this->money($antesCarteira - $valorCarteira);
                $carteira->save();

                GuardianWalletMovement::withoutGlobalScopes()->create([
                    'organizationId'   => $organizacaoId,
                    'guardianWalletId' => $carteira->id,
                    'encarregadoId'    => $encarregadoId,
                    'invoiceId'        => $fatura->id,
                    'movement_type'    => 'debit',
                    'amount'           => $valorCarteira,
                    'balance_before'   => $antesCarteira,
                    'balance_after'    => $carteira->balance,
                    'reference'        => $pagamento['reference'] ?? null,
                    'description'      => 'Pagamento com saldo — ' . $fatura->document_no,
                    'snapshot'         => $pagamento,
                ]);
            }

            $regPagamento = InvoicePayment::withoutGlobalScopes()->create([
                'organizationId'  => $organizacaoId,
                'invoiceId'       => $fatura->id,
                'encarregadoId'   => $encarregadoId,
                'amount'          => (float) $pagamento['amount'],
                'currency'        => $fatura->currency ?? 'AOA',
                'status'          => 'confirmed',
                'source'          => $pagamento['source'] ?? 'escola',
                'reference'       => $pagamento['reference'] ?? null,
                'idempotency_key' => $pagamento['idempotency_key'] ?? null,
                'createdBy'       => auth()->id(),
                'snapshot'        => array_merge($pagamento, [
                    'usa_carteira'   => $usaCarteira,
                    'saldo_antes'    => $antesCarteira,
                    'valor_carteira' => $valorCarteira,
                    'saldo_depois'   => $usaCarteira && $carteira ? $carteira->balance : $antesCarteira,
                ]),
            ]);

            InvoicePaymentMethod::withoutGlobalScopes()->create([
                'organizationId'    => $organizacaoId,
                'invoicePaymentId'  => $regPagamento->id,
                'tipodepagamentoId' => $pagamento['tipodepagamentoId'] ?? null,
                'bancoId'           => $pagamento['bancoId'] ?? null,
                'method_code'       => strtoupper($pagamento['method_code'] ?? $pagamento['payment_method'] ?? 'CASH'),
                'amount'            => (float) $pagamento['amount'],
                'reference'         => $pagamento['reference'] ?? null,
                'transaction_id'    => $pagamento['transaction_id'] ?? null,
                'metadata'          => $pagamento,
            ]);

            $valorAlocacao = min(
                (float) $pagamento['amount'],
                max(0, $grossTotal - $totalAlocado)
            );

            InvoicePaymentAllocation::withoutGlobalScopes()->create([
                'organizationId'   => $organizacaoId,
                'invoicePaymentId' => $regPagamento->id,
                'invoiceId'        => $fatura->id,
                'amount'           => $valorAlocacao,
                'allocation_type'  => 'invoice_payment',
            ]);

            $totalAlocado = $this->money($totalAlocado + $valorAlocacao);
        }

        // Troco → crédito na carteira do encarregado
        if ($troco > 0 && $carteira && $encarregadoId) {
            $antesCarteira     = (float) $carteira->balance;
            $carteira->balance = $this->money($antesCarteira + $troco);
            $carteira->save();

            GuardianWalletMovement::withoutGlobalScopes()->create([
                'organizationId'   => $organizacaoId,
                'guardianWalletId' => $carteira->id,
                'encarregadoId'    => $encarregadoId,
                'invoiceId'        => $fatura->id,
                'movement_type'    => 'overpayment_credit',
                'amount'           => $troco,
                'balance_before'   => $antesCarteira,
                'balance_after'    => $carteira->balance,
                'reference'        => 'TROCO-' . $fatura->id,
                'description'      => 'Excesso creditado na carteira — ' . $fatura->document_no,
                'snapshot'         => [
                    'gross_total' => $grossTotal,
                    'total_pago'  => $totalPago,
                    'troco'       => $troco,
                ],
            ]);
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // LINHA DE FATURA
    // ══════════════════════════════════════════════════════════════════

    private function criarLinhaFatura(Invoice $fatura, array $item, int $indice, int $organizacaoId): void
    {
        // ── Propina: criar BillingPropina de forma atómica (com lock) ────
        // Se o item já vier com invoiceable_type/invoiceable_id definidos
        // (BillingPropina já criado fora do pacote), respeita-se isso.
        // Caso contrário, o pacote cria o registo agora, dentro do lock
        // de ordem sequencial, garantindo atomicidade total.
        if (
            strtolower($item['item_category'] ?? '') === 'propina'
            && empty($item['invoiceable_id'])
        ) {
            $registo = $this->validacaoPropina->validarECriarPropinas(
                $organizacaoId,
                (int) $item['mensalidadeId'],
                (int) $item['alunoId'],
                (int) ($item['encarregadoId'] ?? 0),
                (int) $item['anolectivoId'],
                [[
                    'mesId'    => (int) ($item['mesId'] ?? $item['mesid'] ?? 0),
                    'valor'    => (float) $item['unit_price'],
                    'desconto' => (float) ($item['discount_amount'] ?? 0),
                    'multa'    => (float) ($item['multa'] ?? 0),
                ]],
                (bool) ($item['classComExam'] ?? false)
            )->first();

            $item['invoiceable_type'] = \App\Models\Invoice\Billing\BillingPropina::class;
            $item['invoiceable_id']   = $registo->id;
        }

        // ── AGT Anexo I, ponto 21: desconto deve estar entre 0% e 100% ──
        $valorBruto    = (float) $item['quantity'] * (float) $item['unit_price'];
        $descontoLinha = (float) ($item['discount_amount'] ?? 0);

        if ($descontoLinha < 0) {
            throw new ExcecaoFaturaAgt(
                "Desconto inválido na linha '{$item['description']}': não pode ser negativo."
            );
        }
        if ($valorBruto > 0 && $descontoLinha > $valorBruto) {
            throw new ExcecaoFaturaAgt(
                "Desconto inválido na linha '{$item['description']}': o desconto ({$descontoLinha}) " .
                "não pode exceder 100% do valor da linha ({$valorBruto}). Conforme AGT Anexo I, ponto 21."
            );
        }

        // ── AGT Anexo I, ponto 6.f: documentos normais (qualquer tipo
        // excepto NC) não podem ter valores negativos — apenas NC pode.
        if ($fatura->document_type !== 'NC' && $valorBruto < 0) {
            throw new ExcecaoFaturaAgt(
                "Valor negativo não permitido na linha '{$item['description']}' para documentos do tipo " .
                "{$fatura->document_type}. Valores negativos só são permitidos em Notas de Crédito (NC). " .
                "Conforme AGT Anexo I, ponto 6.f."
            );
        }

        $baseLinha   = $this->money($valorBruto - $descontoLinha);
        $taxaPerc    = (float) ($item['tax_percentage'] ?? $item['tax_rate'] ?? 0);
        $tipoImposto = $this->resolverTipoImposto($item);
        $isento      = in_array($tipoImposto, ['ISENTO', 'ISE'], true);
        $ivaLinha    = $isento ? 0 : $this->money($baseLinha * ($taxaPerc / 100));
        $totalLinha  = $this->money($baseLinha + $ivaLinha);
        $alunoId     = $item['alunoId'] ?? null;
        $codigoImposto = $item['tax_code'] ?? ($isento ? 'ISE' : 'IVA');

        $linhaAgt = $this->construirLinhaAgt($item, $indice + 1, $totalLinha, $ivaLinha, $tipoImposto, $fatura->document_type);

        $itemFatura = InvoiceItem::withoutGlobalScopes()->create([
            'organizationId'    => $organizacaoId,
            'invoiceId'         => $fatura->id,
            'invoiceable_type'  => $item['invoiceable_type'] ?? null,
            'invoiceable_id'    => $item['invoiceable_id'] ?? null,
            'itemable_type'     => $item['itemable_type'] ?? null,
            'itemable_id'       => $item['itemable_id'] ?? null,
            'alunoId'           => $alunoId,
            'item_category'     => $item['item_category'] ?? 'outro',
            'item_code'         => $item['item_code'] ?? null,
            'product_code'      => $item['product_code'] ?? $item['item_code'] ?? 'ITEM',
            'description'       => $item['description'] ?? 'Item',
            'quantity'          => (float) $item['quantity'],
            'unit_of_measure'   => $item['unit_of_measure'] ?? 'UN',
            'unit_price'        => (float) $item['unit_price'],
            'unit_price_base'   => (float) ($item['unit_price_base'] ?? $item['unit_price']),
            'discount'          => (float) ($item['discount_amount'] ?? 0),
            'discount_amount'   => (float) ($item['discount_amount'] ?? 0),
            'tax_rate'          => $taxaPerc,
            'tax_percentage'    => $taxaPerc,
            'tax_amount'        => $ivaLinha,
            'tax_type'          => $tipoImposto,
            'tax_code'          => $codigoImposto,
            'tax_reason'        => $item['tax_reason'] ?? ($isento ? 'M00' : null),
            'subtotal'          => $baseLinha,
            'line_total'        => $totalLinha,
            'total'             => $totalLinha,
            'line_number'       => $indice + 1,
            'snapshot'          => $item,
            'item_snapshot'     => $item['snapshot'] ?? $item,
            'aluno_snapshot'    => $alunoId ? ($item['aluno_snapshot'] ?? null) : null,
            'agt_line_snapshot' => $linhaAgt,
            'appCode'           => 1,
        ]);

        InvoiceItemTax::withoutGlobalScopes()->create([
            'organizationId'     => $organizacaoId,
            'invoiceItemId'      => $itemFatura->id,
            'tax_type'           => $tipoImposto,
            'tax_country_region' => 'AO',
            'tax_code'           => $codigoImposto,
            'tax_rate'           => $taxaPerc,
            'tax_percentage'     => $taxaPerc,
            'tax_amount'         => $ivaLinha,
            'tax_contribution'   => $ivaLinha,
            'exemption_code'     => $isento ? $codigoImposto : null,
            'exemption_reason'   => $isento ? ($item['tax_reason'] ?? 'M00') : null,
            'tax_reason'         => $item['tax_reason'] ?? ($isento ? 'M00' : null),
            'snapshot'           => $item,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    // AUXILIARES
    // ══════════════════════════════════════════════════════════════════

    private function calcularTotais(array $itens): array
    {
        $subtotal = 0;
        $desconto = 0;
        $iva      = 0;

        foreach ($itens as $item) {
            $base     = $this->money(((float) $item['quantity'] * (float) $item['unit_price']) - (float) ($item['discount_amount'] ?? 0));
            $taxaPerc = (float) ($item['tax_percentage'] ?? $item['tax_rate'] ?? 0);
            $isento   = in_array(strtoupper($item['tax_code'] ?? $item['tax_type'] ?? ''), ['ISE', 'ISENTO', 'M00'], true);
            $ivaItem  = $isento ? 0 : $this->money($base * ($taxaPerc / 100));

            $subtotal += $base;
            $desconto += (float) ($item['discount_amount'] ?? 0);
            $iva      += $ivaItem;
        }

        return [
            'subtotal'    => $this->money($subtotal),
            'desconto'    => $this->money($desconto),
            'iva'         => $this->money($iva),
            'gross_total' => $this->money($subtotal + $iva),
        ];
    }

    private function resolverConsumidor(array $dados): array
    {
        $nif  = $dados['customer_nif'] ?? $dados['nif'] ?? null;
        $nome = $dados['customer_name'] ?? $dados['nome'] ?? 'Consumidor Final';

        if (empty($nif) || $nif === '999999999' || $nif === '999999990') {
            $nif  = config('onsoft-agt.nif_consumidor_final', '999999999');
            $nome = 'Consumidor Final';
        }

        return [
            'nif'          => $nif,
            'name'         => $nome,
            'email'        => $dados['customer_email'] ?? null,
            'telefone'     => $dados['customer_phone'] ?? null,
            'address'      => $dados['customer_address'] ?? null,
            'country_code' => $dados['customer_country'] ?? 'AO', // ISO 3166-1-alpha-2, "AO" para domésticos (documentação Registar Factura)
        ];
    }

    private function resolverTipoImposto(array $item): string
    {
        if (in_array(strtoupper($item['tax_code'] ?? ''), ['ISE', 'ISENTO', 'M00'], true)) {
            return 'ISENTO';
        }
        if (in_array(strtoupper($item['tax_type'] ?? ''), ['ISENTO', 'ISE'], true)) {
            return 'ISENTO';
        }
        return 'IVA';
    }

    private function construirLinhaAgt(
        array  $item,
        int    $numeroLinha,
        float  $totalLinha,
        float  $ivaLinha,
        string $tipoImposto,
        string $tipoDocumento
    ): array {
        $isNC = ($tipoDocumento === 'NC');

        return [
            'lineNumber'         => $numeroLinha,
            'productCode'        => $item['product_code'] ?? $item['item_code'] ?? 'ITEM',
            'productDescription' => $item['description'] ?? 'Item',
            'quantity'           => (float) $item['quantity'],
            'unitOfMeasure'      => $item['unit_of_measure'] ?? 'UN',
            'unitPrice'          => (float) $item['unit_price'],
            'unitPriceBase'      => (float) ($item['unit_price_base'] ?? $item['unit_price']),
            'debitAmount'        => $isNC ? $totalLinha : 0,   // NC usa DebitAmount
            'creditAmount'       => $isNC ? 0 : $totalLinha,   // Os restantes 17 tipos de documento usam CreditAmount
            'settlementAmount'   => (float) ($item['discount_amount'] ?? 0),
            'taxes'              => [[
                'taxType'          => $tipoImposto,
                'taxCountryRegion' => 'AO',
                'taxCode'          => $item['tax_code'] ?? ($tipoImposto === 'ISENTO' ? 'ISE' : 'IVA'),
                'taxPercentage'    => (float) ($item['tax_percentage'] ?? $item['tax_rate'] ?? 0),
                'taxContribution'  => $ivaLinha,
                // Campo correcto conforme documentação (Registar Factura,
                // "Composição properties do array taxes") é
                // taxExemptionCode — "taxReason" nunca existiu na
                // especificação real e foi substituído nesta correcção.
                ...($tipoImposto === 'ISENTO' ? ['taxExemptionCode' => $item['tax_reason'] ?? 'M00'] : []),
            ]],
        ];
    }

    private function reverterCarteiraNoCancelamento(Invoice $fatura, int $organizacaoId, ?int $ncId = null): void
    {
        if (!$fatura->encarregadoId) return;

        $carteira = GuardianWallet::withoutGlobalScopes()->firstOrCreate(
            ['organizationId' => $organizacaoId, 'encarregadoId' => $fatura->encarregadoId],
            ['balance' => 0, 'currency' => 'AOA', 'active' => true]
        );

        $totalCarteira = $this->money(
            InvoicePayment::withoutGlobalScopes()
                ->where('invoiceId', $fatura->id)
                ->get()
                ->sum(fn($p) => (float) data_get($p->snapshot, 'valor_carteira', 0))
        );

        if ($totalCarteira > 0) {
            $antes = (float) $carteira->balance;
            $carteira->balance = $this->money($antes + $totalCarteira);
            $carteira->save();

            GuardianWalletMovement::withoutGlobalScopes()->create([
                'organizationId'   => $organizacaoId,
                'guardianWalletId' => $carteira->id,
                'encarregadoId'    => $fatura->encarregadoId,
                'invoiceId'        => $ncId ?? $fatura->id,
                'movement_type'    => 'nc_refund',
                'amount'           => $totalCarteira,
                'balance_before'   => $antes,
                'balance_after'    => $carteira->balance,
                'description'      => 'Reembolso de saldo — ' . $fatura->document_no,
            ]);
        }

        $trocoGerado = (float) $fatura->wallet_generated_amount;
        if ($trocoGerado > 0) {
            $antes     = (float) $carteira->balance;
            $aReverter = min($trocoGerado, $antes);
            if ($aReverter > 0) {
                $carteira->balance = $this->money($antes - $aReverter);
                $carteira->save();

                GuardianWalletMovement::withoutGlobalScopes()->create([
                    'organizationId'   => $organizacaoId,
                    'guardianWalletId' => $carteira->id,
                    'encarregadoId'    => $fatura->encarregadoId,
                    'invoiceId'        => $ncId ?? $fatura->id,
                    'movement_type'    => 'reverse_overpayment',
                    'amount'           => $aReverter,
                    'balance_before'   => $antes,
                    'balance_after'    => $carteira->balance,
                    'description'      => 'Reversão de excesso — ' . $fatura->document_no,
                ]);
            }
        }
    }

    private function validarTipoDocumento(string $tipo): void
    {
        $suportados = array_keys(config('onsoft-agt.tipos_documento', []));
        if (!in_array($tipo, $suportados, true)) {
            throw new ExcecaoFaturaAgt(
                "Tipo de documento inválido: {$tipo}. Suportados: " . implode(', ', $suportados)
            );
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // AUXILIAR — CONFIG COM CACHE (evita 3 queries por fatura)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Carregar OrganizationAgtConfig com cache Redis (5 minutos).
     * Elimina 3 queries separadas por fatura — carregada uma vez e reutilizada.
     */
    private function obterConfigAgt(int $organizacaoId): ?object
    {
        $chaveCache = "onsoft_agt_config_{$organizacaoId}";

        return cache()->remember($chaveCache, 300, function () use ($organizacaoId) {
            return OrganizationAgtConfig::withoutGlobalScopes()
                ->where('organizationId', $organizacaoId)
                ->first();
        });
    }

    /**
     * Invalidar cache da config (chamar quando a config é alterada).
     */
    public static function invalidarCacheConfig(int $organizacaoId): void
    {
        cache()->forget("onsoft_agt_config_{$organizacaoId}");
    }

    // ══════════════════════════════════════════════════════════════════
    // SNAPSHOT DA ORGANIZAÇÃO — SEMPRE da BD, nunca do request
    // ══════════════════════════════════════════════════════════════════

    /**
     * Construir o snapshot da organização SEMPRE a partir da tabela
     * `organizations` — nunca confiar em dados vindos do request.
     *
     * O logótipo é resolvido a partir de Organization.logo_path
     * usando Storage::disk('public')->url(), exactamente como o
     * projecto já faz em OrganizationController.
     *
     * Campos mapeados (nomes reais da tabela organizations):
     *   nif, nome_fiscal, nome_comercial, endereco, bairro,
     *   municipio, provincia, pais, telefone, telefone_alt,
     *   email, website, logo_path
     */
    private function construirSnapshotOrganizacao(int $organizacaoId): array
    {
        $org = \App\Models\Organization::find($organizacaoId);

        if (!$org) {
            throw new ExcecaoFaturaAgt("Organização [{$organizacaoId}] não encontrada.");
        }

        $logoUrl = null;
        if (!empty($org->logo_path)) {
            try {
                $logoUrl = \Illuminate\Support\Facades\Storage::disk('public')->url($org->logo_path);
            } catch (\Throwable $e) {
                Log::warning('OnsoftAgt: Falha ao resolver URL do logo', [
                    'organizationId' => $organizacaoId,
                    'logo_path'      => $org->logo_path,
                    'erro'           => $e->getMessage(),
                ]);
            }
        }

        return [
            'nif'             => $org->nif,
            'name'            => $org->nome_fiscal,
            'commercial_name' => $org->nome_comercial,
            'address'         => $org->endereco,
            'bairro'          => $org->bairro,
            'city'            => $org->municipio,
            'province'        => $org->provincia,
            'country'         => $org->pais ?? 'Angola',
            'country_code'    => 'AO',
            'telefone'        => $org->telefone,
            'telefone_alt'    => $org->telefone_alt,
            'email'           => $org->email,
            'website'         => $org->website,
            // Logo SEMPRE resolvido da BD — nunca aceite do request
            'logo_path'       => $org->logo_path,
            'logo_url'        => $logoUrl,
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    // VALIDAÇÃO DE ORDEM DE PROPINAS
    // ══════════════════════════════════════════════════════════════════

    /**
     * Validar a ordem sequencial de pagamento de propinas para TODOS os
     * itens da fatura que sejam categoria 'propina'.
     *
     * Agrupa por (mensalidadeId, alunoId, anolectivoId) porque uma fatura
     * pode ter propinas de vários alunos/mensalidades em simultâneo
     * (ex: 2 filhos do mesmo encarregado).
     *
     * Cada item de propina DEVE conter no payload:
     *   - item_category    = 'propina'
     *   - mensalidadeId    (int)
     *   - alunoId          (int)
     *   - anolectivoId     (int)
     *   - mesId            (int — meses.mesId, NÃO meses.id)
     *
     * Se a ordem for violada para qualquer grupo, lança ExcecaoFaturaAgt
     * e a transação inteira é revertida (nenhuma fatura é criada).
     */
    private function validarOrdemPropinasNosItens(\Illuminate\Support\Collection $itens, int $organizacaoId): void
    {
        $itensPropina = $itens->filter(
            fn($item) => strtolower($item['item_category'] ?? '') === 'propina'
        );

        if ($itensPropina->isEmpty()) {
            return;
        }

        // Agrupar por aluno+mensalidade+anolectivo — cada grupo valida-se
        // de forma independente (não interfere com outros alunos).
        $grupos = $itensPropina->groupBy(
            fn($item) => ($item['mensalidadeId'] ?? 0) . '|' . ($item['alunoId'] ?? 0) . '|' . ($item['anolectivoId'] ?? 0)
        );

        foreach ($grupos as $chave, $itensDoGrupo) {
            [$mensalidadeId, $alunoId, $anolectivoId] = array_map('intval', explode('|', $chave));

            if ($mensalidadeId <= 0 || $alunoId <= 0 || $anolectivoId <= 0) {
                throw new ExcecaoFaturaAgt(
                    'Item de propina inválido: mensalidadeId, alunoId e anolectivoId são obrigatórios.'
                );
            }

            $mesIds = $itensDoGrupo
                ->map(fn($item) => (int) ($item['mesId'] ?? $item['mesid'] ?? 0))
                ->filter(fn($id) => $id > 0)
                ->values()
                ->all();

            if (empty($mesIds)) {
                throw new ExcecaoFaturaAgt(
                    'Item de propina sem mesId — indique o mês (meses.mesId) a pagar.'
                );
            }

            $classComExam = (bool) ($itensDoGrupo->first()['classComExam'] ?? false);

            // Validação SEM lock aqui — o lock real acontece se/quando
            // os registos BillingPropina forem criados via
            // validarECriarPropinas(). Esta chamada serve para abortar
            // rapidamente faturas claramente fora de ordem antes de
            // gastar tempo a criar linhas de fatura.
            $this->validacaoPropina->validarOrdem(
                $mensalidadeId, $alunoId, $anolectivoId, $mesIds, $classComExam
            );
        }
    }

    /**
     * Validar que métodos de pagamento "exclusivos" (Multicaixa Express,
     * Referência Multicaixa, POS Online, ou qualquer método futuro
     * marcado como exclusivo=true na tabela `tipodepagamento`) não são
     * misturados com outros métodos na mesma fatura.
     *
     * Lê DIRECTAMENTE de `tipodepagamento.exclusivo` — a mesma tabela
     * que o projecto já usa (App\Models\Config\Financeiro\TipoDePagamento).
     * Não existe nenhuma tabela paralela.
     *
     * Para tornar um método exclusivo no futuro, basta:
     *   UPDATE tipodepagamento SET exclusivo = 1 WHERE appCode = 1011;
     * Nada no pacote precisa de ser alterado.
     */
    /**
     * Validar exclusividade de métodos de pagamento.
     *
     * CORRIGIDO nesta auditoria: esta função reimplementava, linha a
     * linha, exactamente a mesma lógica de
     * ServicoExclusividadePagamento::validar() — incluindo a mesma
     * query a `tipodepagamento` — mas com uma CHAVE DE CACHE DIFERENTE
     * ('onsoft_agt_tipodepagamento_todos' aqui vs
     * 'onsoft_agt_tipodepagamento_lista' em ServicoExclusividadePagamento).
     * Isto significava que invalidar a cache num dos serviços nunca
     * invalidava a do outro — risco real de validação com dados
     * desactualizados depois de alterar tipodepagamento.exclusivo.
     *
     * Agora delega para a fonte única de verdade.
     */
    private function validarExclusividadeMetodos(\Illuminate\Support\Collection $pagamentos): void
    {
        $resultado = (new ServicoExclusividadePagamento())->validar($pagamentos->all());

        if (!$resultado['valido']) {
            throw new ExcecaoFaturaAgt($resultado['erro']);
        }
    }

    /**
     * AGT Anexo I, ponto 33.c: validar que a data/hora de sistema não
     * é inferior à do último documento emitido na mesma série. Se for,
     * o relógio do servidor pode ter recuado (erro de NTP, restauro de
     * snapshot, etc.) — bloqueia para não corromper SystemEntryDate.
     */
    /**
     * Validar que o tipo de documento pedido está dentro do âmbito
     * configurado para este sistema (onsoft-agt.tipos_activos).
     *
     * O enum TipoDocumento suporta os 18 tipos reais da AGT, mas este
     * projecto concreto só precisa de um subconjunto. Esta validação
     * impede a criação de qualquer tipo fora desse subconjunto antes
     * de qualquer escrita à BD ou chamada à AGT.
     */
    private function validarAmbitoTipoDocumento(string $tipoDocumento): void
    {
        $tiposActivos = array_map('trim', array_map('strtoupper', config('onsoft-agt.tipos_activos', ['FT', 'FR', 'NC', 'ND'])));

        if (!in_array($tipoDocumento, $tiposActivos, true)) {
            throw new ExcecaoFaturaAgt(
                "Tipo de documento '{$tipoDocumento}' não está activo neste sistema. " .
                "Tipos permitidos: " . implode(', ', $tiposActivos) . ". " .
                "Para Factura Pró-forma, use Onsoft\\Agt\\Servicos\\ServicoFaturaProforma " .
                "em vez de ServicoFatura::criar() — pró-formas nunca são persistidas " .
                "nem enviadas à AGT."
            );
        }
    }

    private function validarCronologiaSerie(int $organizacaoId, string $tipoDocumento): void
    {
        $ultimaFatura = Invoice::withoutGlobalScopes()
            ->where('organizationId', $organizacaoId)
            ->where('document_type', $tipoDocumento)
            ->whereNotNull('issued_at')
            ->orderByDesc('id')
            ->first();

        if ($ultimaFatura && $ultimaFatura->issued_at && now()->lessThan($ultimaFatura->issued_at)) {
            throw new ExcecaoFaturaAgt(
                "A data/hora do sistema (" . now()->toDateTimeString() . ") é anterior à do último " .
                "documento emitido nesta série (" . $ultimaFatura->issued_at->toDateTimeString() . "). " .
                "Verifique o relógio do servidor antes de continuar. Conforme AGT Anexo I, ponto 33.c."
            );
        }
    }

    private function money(float $valor): float
    {
        return round($valor, 2);
    }
}
