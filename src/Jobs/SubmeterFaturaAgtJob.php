<?php

namespace Onsoft\Agt\Jobs;

use App\Models\Invoice\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Onsoft\Agt\Servicos\ServicoContextoOrganizacao;
use Onsoft\Agt\Servicos\ServicoModoFaturacao;

/**
 * SubmeterFaturaAgtJob
 *
 * Job de fila PRÓPRIO do pacote — substitui a dependência do antigo
 * App\Jobs\SubmitInvoiceToAgtJob do projecto hospedeiro.
 *
 * ══════════════════════════════════════════════════════════════════════
 * PORQUÊ ESTE JOB EXISTE
 * ══════════════════════════════════════════════════════════════════════
 * O projecto hospedeiro tinha um Job legado (App\Jobs\SubmitInvoiceToAgtJob)
 * que chamava App\Services\Agt\AgtInvoiceSubmissionService — um serviço
 * SEM qualquer noção do regime SAF-T(AO). Se uma fatura SAF-T fosse
 * processada por esse caminho (auto_submit_invoices via fila), seria
 * submetida à AGT em tempo real sem qualquer bloqueio, contornando
 * toda a protecção implementada em ServicoSubmissao::submeter().
 *
 * Este Job usa exclusivamente os serviços do próprio pacote — a mesma
 * verificação de invoicing_mode aplicada nos endpoints HTTP aplica-se
 * agora também ao fluxo assíncrono de auto-submissão.
 *
 * O ficheiro App\Jobs\SubmitInvoiceToAgtJob e o
 * App\Services\Agt\AgtInvoiceSubmissionService do projecto hospedeiro
 * devem ser eliminados após a instalação deste pacote (ver
 * FICHEIROS_A_ELIMINAR.txt).
 */
class SubmeterFaturaAgtJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 30;

    public function __construct(public int $invoiceId) {}

    public function handle(): void
    {
        $fatura = Invoice::withoutGlobalScopes()->findOrFail($this->invoiceId);

        // Bloqueio explícito — mesma regra do endpoint HTTP. Uma fatura
        // SAF-T(AO) nunca deve chegar a tocar na API AGT, mesmo que
        // tenha sido despachada para a fila por engano ou por código
        // legado. Falha graciosamente sem marcar como 'failed' — não é
        // um erro de submissão, é um job que nunca devia ter sido criado.
        if (($fatura->invoicing_mode ?? ServicoModoFaturacao::ELECTRONIC) === ServicoModoFaturacao::SAFT_AO) {
            \Illuminate\Support\Facades\Log::warning(
                'OnsoftAgt: SubmeterFaturaAgtJob recebeu fatura em modo SAF-T(AO) — ignorado.',
                ['invoice_id' => $this->invoiceId, 'document_no' => $fatura->document_no]
            );
            return;
        }

        $ctx = new ServicoContextoOrganizacao($fatura->organizationId);
        $ctx->servicoSubmissao()->submeter($fatura);
    }

    public function failed(\Throwable $exception): void
    {
        $fatura = Invoice::withoutGlobalScopes()->find($this->invoiceId);

        if ($fatura) {
            // Nunca marcar uma fatura SAF-T como 'failed' — esse estado
            // pertence exclusivamente ao fluxo de submissão electronic.
            if (($fatura->invoicing_mode ?? ServicoModoFaturacao::ELECTRONIC) !== ServicoModoFaturacao::SAFT_AO) {
                $fatura->agt_status = 'failed';
                $fatura->saveQuietly();
            }
        }
    }
}
