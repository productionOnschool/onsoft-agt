<?php

namespace Onsoft\Agt\Observers;

use App\Models\Invoice\Invoice;
use App\Models\Invoice\InvoiceSnapshot;
use Onsoft\Agt\Servicos\InvoiceSnapshotGuard;
use Illuminate\Support\Facades\Log;

/**
 * InvoiceObserver
 *
 * Registado automaticamente pelo OnsoftAgtServiceProvider.
 *
 * Intercepta TODOS os eventos do modelo Invoice e:
 *
 * - created  → cria o snapshot imutável imediatamente após a criação
 *              (apenas se a fatura já tem hash — ou seja, após gerarEGuardarHashChain)
 *
 * - updating → bloqueia qualquer tentativa de alterar campos fiscais
 *              imutáveis depois de a fatura ter sido emitida (tem hash)
 *
 * - deleting → impede a eliminação de qualquer fatura com hash fiscal
 *              (per AGT spec: registos fiscais não podem ser apagados)
 *
 * AGT Decreto Executivo — Anexo I:
 * - Ponto 3:   Não alterar informação fiscal sem evidência
 * - Ponto 12l: Documento assinado não pode ter campos fiscais alterados
 * - Ponto 10:  Documentos não podem ser apagados (série só inactivada)
 */
class InvoiceObserver
{
    /**
     * Após criação — criar snapshot imutável se a fatura já tem hash.
     *
     * Nota: o hash é gerado DENTRO da transação de criação pelo ServicoFatura.
     * O observer é chamado APÓS o commit da transação, por isso o hash já existe.
     */
    /**
     * NOTA IMPORTANTE — ORDEM DE EXECUÇÃO:
     * ─────────────────────────────────────────────────────────────────
     * O evento `created` do Eloquent dispara IMEDIATAMENTE após o
     * INSERT da fatura, DENTRO da mesma transacção — não após o commit.
     * Nesse momento, os itens (InvoiceItem), pagamentos (InvoicePayment)
     * e o hash AGT ainda NÃO existem, porque ServicoFatura::criar() só
     * os persiste em passos posteriores da mesma transacção.
     *
     * Por esta razão, o snapshot NÃO é criado automaticamente aqui.
     * Criá-lo neste momento produziria sempre um snapshot vazio (sem
     * itens, sem pagamentos, sem hash) — uma falha silenciosa que
     * comprometeria toda a garantia de imutabilidade.
     *
     * O snapshot é criado explicitamente por
     * ServicoFatura::finalizarCriacaoFatura() no FIM do processo de
     * criação, depois de itens, pagamentos e hash (se aplicável)
     * estarem todos persistidos.
     */
    public function created(Invoice $invoice): void
    {
        // Intencionalmente vazio — ver nota acima.
        // Mantido como método do observer (em vez de remover a classe)
        // para preservar a estrutura de eventos e permitir reactivação
        // futura caso a ordem de criação em ServicoFatura mude.
    }

    /**
     * Criar o snapshot imutável de uma fatura. Chamado explicitamente
     * por ServicoFatura depois de itens, pagamentos e hash existirem.
     *
     * Público e estático para poder ser invocado fora do ciclo de
     * eventos do Eloquent, no momento correcto da transacção.
     */
    public static function criarSnapshotAgora(Invoice $invoice): void
    {
        try {
            (new self())->criarSnapshotImutavel($invoice);
        } catch (\Throwable $e) {
            Log::error('OnsoftAgt: Falha ao criar snapshot da fatura', [
                'invoice_id' => $invoice->id,
                'erro'       => $e->getMessage(),
            ]);
        }
    }

    /**
     * Antes de actualizar — verificar imutabilidade dos campos fiscais.
     *
     * Se a fatura já tem hash (foi emitida) e alguém tenta mudar um campo
     * fiscal, lança ExcecaoFaturaAgt e a actualização é bloqueada.
     *
     * Campos mutáveis (agt_status, cancel_reason, etc.) passam sem problemas.
     */
    public function updating(Invoice $invoice): void
    {
        try {
            InvoiceSnapshotGuard::verificarAntesDeAtualizar($invoice);
        } catch (\Onsoft\Agt\Excecoes\ExcecaoFaturaAgt $e) {
            // Re-lançar para abortar a actualização
            throw $e;
        }
    }

    /**
     * Antes de eliminar — bloquear eliminação de faturas com hash fiscal.
     *
     * Per AGT spec ponto 10:
     * "As séries não podem ser apagadas — apenas inactivadas."
     * Por extensão, os documentos emitidos também não podem ser apagados.
     */
    public function deleting(Invoice $invoice): bool
    {
        if (!empty($invoice->invoice_hash)) {
            Log::warning('OnsoftAgt: Tentativa de eliminar fatura com hash fiscal', [
                'invoice_id'  => $invoice->id,
                'document_no' => $invoice->document_no,
                'hash'        => substr($invoice->invoice_hash, 0, 20) . '...',
            ]);

            throw new \Onsoft\Agt\Excecoes\ExcecaoFaturaAgt(
                "PROIBIDO — A fatura {$invoice->document_no} não pode ser eliminada. " .
                "Documentos fiscais emitidos são imutáveis e não podem ser apagados. " .
                "Para anular, emita uma Nota de Crédito (NC). " .
                "Conforme AGT Decreto Executivo, Anexo I, ponto 10."
            );
        }

        return true;
    }

    // ──────────────────────────────────────────────────────────────────
    // Privado
    // ──────────────────────────────────────────────────────────────────

    /**
     * Criar o snapshot imutável da fatura.
     *
     * O snapshot contém TODOS os dados no momento da emissão:
     * - Dados da fatura (valores, totais, hash, assinaturas)
     * - Snapshot da organização (nome, NIF, morada)
     * - Snapshot do cliente/consumidor final (nome, NIF)
     * - Todas as linhas com taxes e snapshots de alunos
     * - Todos os pagamentos
     * - Configuração de impressão
     *
     * Este snapshot é imutável — nunca é actualizado após criação.
     * É usado pelo ServicoPdf para re-impressões fiéis ao original.
     */
    private function criarSnapshotImutavel(Invoice $invoice): void
    {
        // Não recriar se já existe
        $jaExiste = InvoiceSnapshot::withoutGlobalScopes()
            ->where('organizationId', $invoice->organizationId)
            ->where('invoiceId', $invoice->id)
            ->exists();

        if ($jaExiste) {
            return;
        }

        $invoice->loadMissing([
            'items.taxes',
            'payments.methods',
            'payments.allocations',
            'agtSeries',
        ]);

        // Obter configuração de impressão
        $configImpressao = \App\Models\Invoice\InvoicePrintConfig::withoutGlobalScopes()
            ->where('organizationId', $invoice->organizationId)
            ->first();

        // Construir payload completo e imutável
        $payload = [
            'invoice' => [
                'id'                      => $invoice->id,
                'organizationId'          => $invoice->organizationId,
                'document_type'           => $invoice->document_type,
                'document_no'             => $invoice->document_no,
                'document_number'         => $invoice->document_number,
                'series_code'             => $invoice->series_code,
                'fiscal_year'             => $invoice->fiscal_year,
                'sequence_number'         => $invoice->sequence_number,
                'currency'                => $invoice->currency,
                'subtotal'                => (float) $invoice->subtotal,
                'tax_total'               => (float) $invoice->tax_total,
                'discount_total'          => (float) $invoice->discount_total,
                'gross_total'             => (float) ($invoice->gross_total ?? $invoice->total),
                'total'                   => (float) $invoice->total,
                'paid_total'              => (float) $invoice->paid_total,
                'remaining_balance'       => (float) ($invoice->remaining_balance ?? $invoice->balance_due),
                'balance_due'             => (float) $invoice->balance_due,
                'change_amount'           => (float) $invoice->change_amount,
                'wallet_generated_amount' => (float) $invoice->wallet_generated_amount,
                'payment_status'          => $invoice->payment_status,
                'agt_status'              => $invoice->agt_status,
                'invoicing_mode'          => $invoice->invoicing_mode,
                'cancel_reason'           => $invoice->cancel_reason,
                'sourceInvoiceId'         => $invoice->sourceInvoiceId,
                'encarregadoId'           => $invoice->encarregadoId,
                'customerId'              => $invoice->customerId,
                'studentId'               => $invoice->studentId,
                'issued_at'               => optional($invoice->issued_at)->toISOString(),
            ],

            // Snapshots imutáveis — dados do momento da emissão
            'organization' => $invoice->organization_snapshot ?? [],
            'customer'     => $invoice->customer_snapshot ?? $invoice->encarregado_snapshot ?? [],
            'encarregado'  => $invoice->encarregado_snapshot ?? [],

            // Linhas com todos os detalhes
            'items' => $invoice->items->map(fn($item) => [
                'id'                => $item->id,
                'line_number'       => $item->line_number,
                'description'       => $item->description,
                'quantity'          => (float) $item->quantity,
                'unit_price'        => (float) $item->unit_price,
                'unit_price_base'   => (float) ($item->unit_price_base ?? $item->unit_price),
                'unit_of_measure'   => $item->unit_of_measure ?? 'UN',
                'discount_amount'   => (float) ($item->discount_amount ?? 0),
                'tax_type'          => $item->tax_type,
                'tax_code'          => $item->tax_code,
                'tax_percentage'    => (float) $item->tax_percentage,
                'tax_amount'        => (float) $item->tax_amount,
                'tax_reason'        => $item->tax_reason,
                'subtotal'          => (float) $item->subtotal,
                'line_total'        => (float) ($item->line_total ?? $item->total),
                'total'             => (float) $item->total,
                'product_code'      => $item->product_code,
                'item_category'     => $item->item_category,
                'alunoId'           => $item->alunoId,
                'aluno_snapshot'    => $item->aluno_snapshot,  // dados do aluno no momento
                'agt_line_snapshot' => $item->agt_line_snapshot,
                'taxes'             => $item->taxes->map(fn($t) => [
                    'tax_type'           => $t->tax_type,
                    'tax_code'           => $t->tax_code,
                    'tax_percentage'     => (float) $t->tax_percentage,
                    'tax_contribution'   => (float) $t->tax_contribution,
                    'tax_country_region' => $t->tax_country_region ?? 'AO',
                    'exemption_code'     => $t->exemption_code,
                    'exemption_reason'   => $t->exemption_reason,
                    'tax_reason'         => $t->tax_reason,
                ])->values()->all(),
            ])->values()->all(),

            // Pagamentos
            'payments' => $invoice->payments->map(fn($p) => [
                'id'       => $p->id,
                'amount'   => (float) $p->amount,
                'currency' => $p->currency,
                'status'   => $p->status,
                'source'   => $p->source,
                'reference' => $p->reference,
                'methods'  => $p->methods->map(fn($m) => [
                    'method_code' => $m->method_code,
                    'amount'      => (float) $m->amount,
                    'reference'   => $m->reference,
                    'transaction_id' => $m->transaction_id,
                ])->values()->all(),
            ])->values()->all(),

            // Dados AGT — hash chain e assinaturas
            'agt' => [
                'document_type'          => $invoice->document_type,
                'document_no'            => $invoice->document_no,
                'series_code'            => $invoice->series_code,
                'fiscal_year'            => $invoice->fiscal_year,
                'sequence_number'        => $invoice->sequence_number,
                'invoice_hash'           => $invoice->invoice_hash,
                'previous_invoice_hash'  => $invoice->previous_invoice_hash,
                'hash_control'           => $invoice->hash_control,
                'jws_document_signature' => $invoice->jws_document_signature,
                'jws_software_signature' => $invoice->jws_software_signature,
                'submission_uuid'        => $invoice->submission_uuid,
                'agt_status'             => $invoice->agt_status,
            ],

            // Configuração de impressão no momento da emissão
            'print_config' => $configImpressao ? $configImpressao->toArray() : [
                'paper_size'     => 'A4',
                'output_format'  => 'pdf',
                'copies'         => 1,
                'show_logo'      => true,
                'show_qr_code'   => true,
                'open_in_memory' => true,
            ],

            // Metadados do snapshot
            '_snapshot_meta' => [
                'criado_em'      => now()->toISOString(),
                'versao_pacote'  => '1.2.0',
                'imutavel'       => true,
                'fonte'          => 'InvoiceObserver@created',
            ],
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        InvoiceSnapshot::withoutGlobalScopes()->create([
            'organizationId'   => $invoice->organizationId,
            'invoiceId'        => $invoice->id,
            'snapshot_version' => 1,
            'payload_json'     => $json,
            'hash'             => hash('sha256', $json),
        ]);

        Log::info('OnsoftAgt: Snapshot imutável criado', [
            'invoice_id'  => $invoice->id,
            'document_no' => $invoice->document_no,
        ]);
    }
}
