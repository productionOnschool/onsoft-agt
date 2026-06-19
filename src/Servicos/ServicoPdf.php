<?php

namespace Onsoft\Agt\Servicos;

use App\Models\Invoice\Invoice;
use App\Models\Invoice\InvoicePrintConfig;
use App\Models\Invoice\InvoiceSnapshot;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

/**
 * ServicoPdf
 *
 * Geração de PDF de faturas em memória — NUNCA guardado em disco.
 *
 * Funciona de DUAS formas:
 * ─────────────────────────────────────────────────────────────
 * 1. A partir do modelo Invoice (dados live da BD)
 * 2. A partir do InvoiceSnapshot (payload JSON guardado)
 *    → Garante que re-impressões mostram SEMPRE os dados
 *      originais, mesmo que o cliente mude o nome/NIF depois.
 *
 * QR Code gerado localmente — bacon/bacon-qr-code — sem internet.
 * QR Code incluído em TODOS os formatos: A4, 88mm, 58mm.
 *
 * Formatos de papel:
 * ─────────────────────────────────────────────────────────────
 * A4   → layout completo com logo, tabelas, QR, rodapé
 * 88mm → layout térmico largo com QR
 * 58mm → layout térmico estreito com QR compacto
 *
 * Sem registo em invoice_print_configs → A4 por defeito.
 */
class ServicoPdf
{
    private ServicoQrCode      $qrCode;
    private ServicoViaImpressao $viaImpressao;

    public function __construct()
    {
        $this->qrCode       = new ServicoQrCode();
        $this->viaImpressao = new ServicoViaImpressao();
    }

    // ══════════════════════════════════════════════════════════════════
    // MÉTODOS PÚBLICOS
    // ══════════════════════════════════════════════════════════════════

    /**
     * Gerar PDF como stream directo para o browser.
     * Usa snapshot se disponível — dados live como fallback.
     */
    public function gerarStream(Invoice $fatura): Response
    {
        $config = $this->obterConfiguracaoImpressao($fatura->organizationId);
        $dados  = $this->resolverDadosFatura($fatura);

        // Registar esta geração — decide Original (1.ª vez) vs Cópia
        // do documento original (qualquer geração seguinte).
        $via = $this->viaImpressao->registarGeracao(
            $fatura,
            $config['paper_size'] ?? 'A4',
            'pdf',
            auth()->id()
        );

        $html   = $this->construirHtml($dados, $config, $via);
        $pdf    = $this->configurarPdf($html, $config);

        return response(
            $pdf->output(),
            200,
            [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $this->nomeFicheiro($dados) . '"',
                'Cache-Control'       => 'no-store, no-cache',
                'Pragma'              => 'no-cache',
            ]
        );
    }

    /**
     * Gerar PDF como Base-64 para o frontend.
     *
     * Frontend:
     *   const url = `data:application/pdf;base64,${base64}`;
     *   window.open(url);  // abre no browser — nunca guardado
     */
    public function gerarBase64(Invoice $fatura): array
    {
        $config = $this->obterConfiguracaoImpressao($fatura->organizationId);
        $dados  = $this->resolverDadosFatura($fatura);

        $via = $this->viaImpressao->registarGeracao(
            $fatura,
            $config['paper_size'] ?? 'A4',
            'pdf-base64',
            auth()->id()
        );

        $html   = $this->construirHtml($dados, $config, $via);
        $pdf    = $this->configurarPdf($html, $config);

        return [
            'base64'                => base64_encode($pdf->output()),
            'nome_ficheiro'         => $this->nomeFicheiro($dados),
            'mime_type'             => 'application/pdf',
            'tipo_documento'        => $dados['invoice']['document_type'],
            'numero_documento'      => $dados['invoice']['document_no'],
            'tamanho_papel'         => $config['paper_size'] ?? 'A4',
            'formato_saida'         => $config['output_format'] ?? 'pdf',
            'copias'                => (int) ($config['copies'] ?? 1),
            'mostrar_qr'            => (bool) ($config['show_qr_code'] ?? true),
            'abrir_automaticamente' => (bool) ($config['open_in_memory'] ?? true),
            'fonte'                 => $dados['_fonte'],
            'via'                   => $via['via_label'],
            'e_original'            => $via['e_original'],
        ];
    }

    /**
     * Gerar PDF directamente a partir do snapshot (sem tocar nos dados live).
     * Usado para re-impressão — garante dados originais.
     */
    public function gerarStreamDeSnapshot(int $faturaId, int $organizacaoId): Response
    {
        $snapshot = InvoiceSnapshot::withoutGlobalScopes()
            ->where('organizationId', $organizacaoId)
            ->where('invoiceId', $faturaId)
            ->firstOrFail();

        $config = $this->obterConfiguracaoImpressao($organizacaoId);
        $dados  = $this->normalizarSnapshot($snapshot->payload);

        // IMPORTANTE: mesmo numa "reimpressão fiel ao original", o estado
        // ACTUAL de cancelamento deve ser sempre exibido. Reimprimir um
        // documento cancelado sem o banner de cancelamento apresentaria
        // um documento fiscalmente inválido como se fosse activo — um
        // risco real, não uma garantia de fidelidade histórica. Os
        // VALORES fiscais (itens, totais, hash) continuam fiéis ao
        // momento da emissão; apenas os campos de ESTADO reflectem a
        // realidade actual.
        $faturaAtual = Invoice::withoutGlobalScopes()
            ->where('organizationId', $organizacaoId)
            ->find($faturaId);

        if ($faturaAtual) {
            $dados['invoice']['payment_status']  = $faturaAtual->payment_status;
            $dados['invoice']['agt_status']      = $faturaAtual->agt_status;
            $dados['invoice']['cancel_reason']   = $faturaAtual->cancel_reason;
            $dados['invoice']['cancelled_at']    = optional($faturaAtual->cancelled_at)?->toISOString();
            $dados['invoice']['sourceInvoiceId'] = $faturaAtual->sourceInvoiceId;
            $dados['agt']['agt_status']          = $faturaAtual->agt_status;
        }

        // gerarStreamDeSnapshot é tipicamente usado para re-impressão —
        // mas registamos da mesma forma: só é Original se for genuinamente
        // a primeira geração desta fatura em qualquer canal.
        $via = $faturaAtual
            ? $this->viaImpressao->registarGeracao($faturaAtual, $config['paper_size'] ?? 'A4', 'pdf-snapshot', auth()->id())
            : ['e_original' => false, 'via_label' => 'Cópia do documento original', 'primeira_geracao_em' => null];

        $html   = $this->construirHtml($dados, $config, $via);
        $pdf    = $this->configurarPdf($html, $config);

        return response(
            $pdf->output(),
            200,
            [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $this->nomeFicheiro($dados) . '"',
                'Cache-Control'       => 'no-store, no-cache',
                'Pragma'              => 'no-cache',
            ]
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // RESOLVER DADOS — SNAPSHOT OU LIVE
    // ══════════════════════════════════════════════════════════════════

    /**
     * Resolver dados da fatura.
     *
     * Prioridade:
     * 1. InvoiceSnapshot (se existir) → dados imutáveis originais
     * 2. Invoice model (dados live da BD)
     *
     * Isto garante que a re-impressão de uma fatura antiga
     * mostra sempre os dados do momento da emissão,
     * mesmo que o cliente tenha alterado o nome/NIF depois.
     */
    private function resolverDadosFatura(Invoice $fatura): array
    {
        // Tentar carregar o snapshot
        $fatura->loadMissing(['snapshotRecord']);
        $snapshotRecord = $fatura->snapshotRecord;

        if ($snapshotRecord && !empty($snapshotRecord->payload_json)) {
            $payload = json_decode($snapshotRecord->payload_json, true);

            if (!empty($payload)) {
                $dados           = $this->normalizarSnapshot($payload);
                $dados['_fonte'] = 'snapshot';

                // ── Sobrepor campos de ESTADO com os valores LIVE actuais ──
                // O snapshot preserva fielmente os valores FISCAIS do
                // momento da emissão (itens, totais, hash) — é isso que
                // o garante a imutabilidade exige. Mas payment_status,
                // agt_status, cancel_reason e cancelled_at descrevem o
                // estado ACTUAL do documento, não o momento da emissão.
                // Sem esta sobreposição, reimprimir o PDF de uma fatura
                // cancelada DEPOIS de o snapshot ter sido criado nunca
                // mostraria o banner de cancelamento — apresentando um
                // documento anulado como se continuasse válido.
                $dados['invoice']['payment_status'] = $fatura->payment_status;
                $dados['invoice']['agt_status']     = $fatura->agt_status;
                $dados['invoice']['cancel_reason']  = $fatura->cancel_reason;
                $dados['invoice']['cancelled_at']   = optional($fatura->cancelled_at)?->toISOString();
                $dados['invoice']['sourceInvoiceId'] = $fatura->sourceInvoiceId;
                $dados['agt']['agt_status']         = $fatura->agt_status;

                return $dados;
            }
        }

        // Fallback: dados live
        $fatura->loadMissing(['items.taxes', 'payments.methods', 'payments.allocations', 'agtSeries']);
        $dados           = $this->normalizarInvoice($fatura);
        $dados['_fonte'] = 'live';
        return $dados;
    }

    /**
     * Normalizar dados vindos do InvoiceSnapshot (payload_json).
     * Mapeia a estrutura do snapshot para o formato interno do PDF.
     */
    private function normalizarSnapshot(array $payload): array
    {
        return [
            '_fonte'       => 'snapshot',
            'invoice'      => $payload['invoice'] ?? [],
            'organization' => $payload['organization'] ?? $payload['invoice']['organization_snapshot'] ?? [],
            'customer'     => $payload['customer'] ?? $payload['invoice']['customer_snapshot'] ?? [],
            'items'        => $payload['items'] ?? [],
            'payments'     => $payload['payments'] ?? [],
            'agt'          => $payload['agt'] ?? [],
            'print_config' => $payload['print_config'] ?? [],
        ];
    }

    /**
     * Normalizar dados vindos do modelo Invoice (dados live).
     * Mapeia para o mesmo formato interno do PDF.
     */
    private function normalizarInvoice(Invoice $fatura): array
    {
        return [
            '_fonte'       => 'live',
            'invoice'      => [
                'id'                     => $fatura->id,
                'organizationId'         => $fatura->organizationId,
                'document_type'          => $fatura->document_type,
                'document_no'            => $fatura->document_no ?? $fatura->document_number,
                'document_number'        => $fatura->document_number,
                'series_code'            => $fatura->series_code,
                'fiscal_year'            => $fatura->fiscal_year,
                'sequence_number'        => $fatura->sequence_number,
                'currency'               => $fatura->currency ?? 'AOA',
                'subtotal'               => (float) $fatura->subtotal,
                'tax_total'              => (float) $fatura->tax_total,
                'discount_total'         => (float) $fatura->discount_total,
                'gross_total'            => (float) ($fatura->gross_total ?? $fatura->total),
                'total'                  => (float) $fatura->total,
                'paid_total'             => (float) $fatura->paid_total,
                'remaining_balance'      => (float) ($fatura->remaining_balance ?? $fatura->balance_due),
                'balance_due'            => (float) $fatura->balance_due,
                'change_amount'          => (float) $fatura->change_amount,
                'wallet_generated_amount' => (float) $fatura->wallet_generated_amount,
                'payment_status'         => $fatura->payment_status,
                'agt_status'             => $fatura->agt_status,
                'invoicing_mode'         => $fatura->invoicing_mode,
                'cancel_reason'          => $fatura->cancel_reason,
                'sourceInvoiceId'        => $fatura->sourceInvoiceId,
                'issued_at'              => optional($fatura->issued_at)->toISOString(),
            ],
            'organization' => $fatura->organization_snapshot ?? [],
            'customer'     => $fatura->customer_snapshot ?? $fatura->encarregado_snapshot ?? [],
            'items'        => $fatura->items->map(fn($item) => [
                'id'                => $item->id,
                'line_number'       => $item->line_number,
                'description'       => $item->description,
                'quantity'          => (float) $item->quantity,
                'unit_price'        => (float) $item->unit_price,
                'discount_amount'   => (float) $item->discount_amount,
                'tax_type'          => $item->tax_type,
                'tax_code'          => $item->tax_code,
                'tax_percentage'    => (float) $item->tax_percentage,
                'tax_amount'        => (float) $item->tax_amount,
                'subtotal'          => (float) $item->subtotal,
                'line_total'        => (float) ($item->line_total ?? $item->total),
                'total'             => (float) $item->total,
                'unit_of_measure'   => $item->unit_of_measure ?? 'UN',
                'product_code'      => $item->product_code,
                'alunoId'           => $item->alunoId,
                'aluno_snapshot'    => $item->aluno_snapshot,
                'tax_reason'        => $item->tax_reason,
                'taxes'             => $item->taxes->map(fn($t) => $t->toArray())->values()->all(),
            ])->values()->all(),
            'payments'     => $fatura->payments->map(fn($p) => [
                'id'       => $p->id,
                'amount'   => (float) $p->amount,
                'currency' => $p->currency,
                'status'   => $p->status,
                'methods'  => $p->methods->map(fn($m) => $m->toArray())->values()->all(),
            ])->values()->all(),
            'agt'          => [
                'document_type'          => $fatura->document_type,
                'document_no'            => $fatura->document_no,
                'series_code'            => $fatura->series_code,
                'fiscal_year'            => $fatura->fiscal_year,
                'invoice_hash'           => $fatura->invoice_hash,
                'previous_invoice_hash'  => $fatura->previous_invoice_hash,
                'hash_control'           => $fatura->hash_control,
                'jws_document_signature' => $fatura->jws_document_signature,
                'jws_software_signature' => $fatura->jws_software_signature,
                'submission_uuid'        => $fatura->submission_uuid,
                'agt_status'             => $fatura->agt_status,
            ],
            'print_config' => [],
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    // CONSTRUIR HTML
    // ══════════════════════════════════════════════════════════════════

    private function construirHtml(array $dados, array $config, array $via = []): string
    {
        $tamanhoPapel       = $config['paper_size'] ?? 'A4';
        $tipoDocumento      = $dados['invoice']['document_type'] ?? 'FT';
        $numeroCertificacao = $this->obterNumeroCertificacao(
            (int) ($dados['invoice']['organizationId'] ?? 0)
        );

        // Estado de via — Original (1.ª impressão) vs Cópia do documento
        // original. Por defeito 'Original' se não for fornecido (ex:
        // chamadas internas que não passam por gerarStream/gerarBase64).
        $eOriginal = $via['e_original'] ?? true;
        $viaLabel  = $via['via_label'] ?? 'Original';

        // Linha AGT de certificação
        $linhaAgt = $this->construirLinhaCertificacao($dados, $numeroCertificacao);

        // QR Code — gerado localmente, conforme especificação oficial
        // AGT (URL para o portal de verificação, PNG 350x350px).
        $qrBase64   = null;
        $qrMimeType = 'image/png';
        if ((bool) ($config['show_qr_code'] ?? true)) {
            try {
                $qrBase64   = $this->qrCode->gerarBase64($dados);
                $qrMimeType = $this->qrCode->formatoGerado();
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('OnsoftAgt: QR Code falhou', [
                    'documento' => $dados['invoice']['document_no'] ?? '?',
                    'erro'      => $e->getMessage(),
                ]);
            }
        }

        // Variáveis partilhadas por TODOS os templates
        $variaveis = [
            'dados'               => $dados,
            'invoice'             => $dados['invoice'],
            'organization'        => $dados['organization'],
            'customer'            => $dados['customer'],
            'items'               => $dados['items'],
            'payments'            => $dados['payments'],
            'agt'                 => $dados['agt'],
            'config'              => $config,
            'tipo_documento'      => $tipoDocumento,
            'label_tipo'          => config('onsoft-agt.tipos_documento.' . $tipoDocumento, $tipoDocumento),
            'linha_agt'           => $linhaAgt,
            'numero_certificacao' => $numeroCertificacao,
            'mostrar_logo'        => (bool) ($config['show_logo'] ?? true),
            'mostrar_qr'          => (bool) ($config['show_qr_code'] ?? true),
            'qr_base64'           => $qrBase64,
            'qr_mime_type'        => $qrMimeType,
            'nome_cliente'        => data_get($dados['customer'], 'name', 'Consumidor Final'),
            'nif_cliente'         => data_get($dados['customer'], 'nif', '999999999'),
            'e_consumidor_final'  => $this->eConsumidorFinal($dados),
            'fatura_original_no'  => $dados['invoice']['sourceInvoiceId'] ?? null,
            // Via do documento — exigência AGT Anexo I, ponto 6, alíneas h) e n)
            'e_original'          => $eOriginal,
            'via_label'           => $viaLabel,
        ];

        $template = match ($tamanhoPapel) {
            '88mm'  => 'onsoft-agt::faturas.termica-88mm',
            '58mm'  => 'onsoft-agt::faturas.termica-58mm',
            default => 'onsoft-agt::faturas.a4',
        };

        return view($template, $variaveis)->render();
    }

    // ══════════════════════════════════════════════════════════════════
    // AUXILIARES PRIVADOS
    // ══════════════════════════════════════════════════════════════════

    private function obterConfiguracaoImpressao(int $organizacaoId): array
    {
        $registo = InvoicePrintConfig::withoutGlobalScopes()
            ->where('organizationId', $organizacaoId)
            ->first();

        if (!$registo) {
            return [
                'paper_size'     => 'A4',
                'output_format'  => 'pdf',
                'copies'         => 1,
                'show_logo'      => true,
                'show_qr_code'   => true,
                'open_in_memory' => true,
                'layout_json'    => null,
            ];
        }

        return $registo->toArray();
    }

    private function configurarPdf(string $html, array $config): \Barryvdh\DomPDF\PDF
    {
        $tamanhoPapel = $config['paper_size'] ?? 'A4';
        $pdf = Pdf::loadHTML($html)->setWarnings(false);

        match ($tamanhoPapel) {
            '88mm'  => $pdf->setPaper([0, 0, 249.45, 841.89], 'portrait'),
            '58mm'  => $pdf->setPaper([0, 0, 164.41, 841.89], 'portrait'),
            default => $pdf->setPaper('A4', 'portrait'),
        };

        return $pdf;
    }

    private function construirLinhaCertificacao(array $dados, string $numeroCertificacao): string
    {
        $tipo        = $dados['invoice']['document_type'] ?? '';
        $hash        = $dados['agt']['invoice_hash'] ?? null;
        $hashControl = $dados['agt']['hash_control'] ?? null;
        $modo        = $dados['invoice']['invoicing_mode'] ?? null;

        if ($tipo === 'RC') {
            return "Emitido por programa validado nº {$numeroCertificacao}/AGT";
        }

        // Documento emitido em regime SAF-T(AO) — NUNCA terá hash por
        // documento, porque este regime não usa assinatura individual.
        // Não confundir com 'a aguardar certificação' (que é o estado
        // transitório de um documento electronic ainda sem hash).
        if ($modo === \Onsoft\Agt\Servicos\ServicoModoFaturacao::SAFT_AO) {
            return "Documento emitido sob o regime SAF-T(AO) — nº {$numeroCertificacao}/AGT. " .
                   "Reportado via ficheiro SAF-T(AO), sem assinatura individual por documento.";
        }

        if (!$hash || !$hashControl) {
            return "Aguardando certificação — nº {$numeroCertificacao}/AGT";
        }

        // NOTA: $hashControl é derivado de jws_document_signature (ver
        // ServicoFatura::gerarEGuardarHashChain) — não corresponde a
        // "posições 1,11,21,31 de um hash RSA-SHA1" como em versões
        // anteriores, porque esse mecanismo não existe na API REST
        // real da AGT. A linha de certificação impressa (exigência do
        // Decreto Executivo, Anexo I, ponto 6.c) é mantida como
        // identificador visual de que o documento foi processado por
        // software validado, mesmo que o cálculo do código de controlo
        // tenha mudado de base.
        return "{$hashControl}-Processado por programa validado nº {$numeroCertificacao}/AGT";
    }

    private function obterNumeroCertificacao(int $organizacaoId): string
    {
        if ($organizacaoId > 0) {
            $cert = \App\Models\Agt\OrganizationAgtConfig::withoutGlobalScopes()
                ->where('organizationId', $organizacaoId)
                ->value('software_validation_number');

            if ($cert) {
                return $cert;
            }
        }

        return config('onsoft-agt.software.numero_certificacao', '0000');
    }

    private function eConsumidorFinal(array $dados): bool
    {
        $nif  = data_get($dados['customer'], 'nif', '');
        $nome = strtolower(data_get($dados['customer'], 'name', ''));
        return empty($nif)
            || $nif === '999999999'
            || $nif === '999999990'
            || $nome === 'consumidor final';
    }

    private function nomeFicheiro(array $dados): string
    {
        $tipo   = strtolower($dados['invoice']['document_type'] ?? 'fatura');
        $numero = str_replace(['/', ' '], ['-', '-'], $dados['invoice']['document_no'] ?? 'doc');
        return $tipo . '-' . $numero . '.pdf';
    }
}
