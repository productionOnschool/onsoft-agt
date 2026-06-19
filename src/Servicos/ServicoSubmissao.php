<?php

namespace Onsoft\Agt\Servicos;

use App\Models\Agt\AgtInvoiceSubmission;
use App\Models\Agt\AgtSubmissionLog;
use App\Models\Invoice\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Onsoft\Agt\Enums\EstadoValidacaoAgt;

/**
 * ServicoSubmissao
 *
 * RECONSTRUIDO a partir da documentacao OFICIAL da AGT:
 * https://quiosqueagt.minfin.gov.ao/doc-agt/faturacao-electronica/1/
 *
 * Mudancas principais face a versao anterior:
 *   - Usa ServicoConstrutorPayloadAgt (interno ao pacote) em vez da
 *     dependencia externa App\Services\Agt\AgtInvoicePayloadBuilder,
 *     cuja estrutura nunca foi verificada contra a documentacao real.
 *   - requestID e o identificador devolvido por registarFactura - nao
 *     um UUID gerado pelo cliente. Esta e a CORRECCAO CENTRAL desta
 *     reconstrucao: consultarEstado() usa sempre este requestID.
 *   - Vocabulario de estado real: resultCode (nivel de lote) +
 *     documentStatus V/I (nivel de documento) - ver EstadoValidacaoAgt.
 *   - Callback/webhook removido (a documentacao confirma "Disponivel
 *     nas proximas versoes" - nao existe ainda). Unico mecanismo:
 *     polling via obterEstado.
 *
 * Fluxo:
 * 1. Construir o "document" com ServicoConstrutorPayloadAgt
 * 2. Enviar via ServicoApiAgt::registarFactura() - devolve requestID
 * 3. Guardar AgtInvoiceSubmission com o requestID REAL
 * 4. consultarEstado() faz polling com esse requestID via obterEstado()
 * 5. Propagar resultCode/documentStatus para agt_status da fatura
 */
class ServicoSubmissao
{
    public function __construct(private ServicoContextoOrganizacao $ctx) {}

    /**
     * Submeter uma fatura a AGT (ou simular se AGT desactivado).
     */
    public function submeter(Invoice $fatura): AgtInvoiceSubmission
    {
        $fatura->loadMissing(['items.taxes', 'payments.allocations']);

        if (($fatura->invoicing_mode ?? ServicoModoFaturacao::ELECTRONIC) === ServicoModoFaturacao::SAFT_AO) {
            throw new \RuntimeException(
                "Esta fatura [{$fatura->document_no}] foi criada em modo SAF-T(AO) e nao pode " .
                "ser submetida a Facturacao Eletronica retroactivamente. Documentos emitidos em " .
                "regime SAF-T devem ser reportados exclusivamente via exportacao do ficheiro " .
                "SAF-T(AO) (GET /onsoft-agt/saft/exportar)."
            );
        }

        if (!$fatura->jws_document_signature) {
            throw new \RuntimeException(
                'Fatura sem jwsDocumentSignature. Recrie ou repare a fatura antes de submeter a AGT.'
            );
        }

        $construtor = new ServicoConstrutorPayloadAgt();
        $documento  = $construtor->construir($fatura, $fatura->jws_document_signature);

        $agtActivo = $this->ctx->estaActivo();

        if ($agtActivo) {
            [$estado, $respostaAgt, $erroMsg, $requestId] = $this->enviarParaAgt($documento);
        } else {
            [$estado, $respostaAgt, $erroMsg, $requestId] = $this->simular($fatura);
        }

        return DB::transaction(function () use (
            $fatura, $documento, $requestId, $estado, $respostaAgt, $erroMsg
        ) {
            $submissao = AgtInvoiceSubmission::updateOrCreate(
                [
                    'organizationId' => $fatura->organizationId,
                    'invoiceId'      => $fatura->id,
                ],
                [
                    'submission_uuid'        => $fatura->submission_uuid,
                    'request_id'             => $requestId,
                    'status'                 => $estado,
                    'agt_payload'            => $documento,
                    'jws_signature'          => $fatura->jws_document_signature,
                    'jws_software_signature' => $fatura->jws_software_signature,
                    'agt_response'           => $respostaAgt,
                    'error_message'          => $erroMsg,
                    'attempts'               => DB::raw('COALESCE(attempts, 0) + 1'),
                    'submitted_at'           => now(),
                ]
            );

            if (class_exists(AgtSubmissionLog::class)) {
                AgtSubmissionLog::create([
                    'organizationId'         => $fatura->organizationId,
                    'agtInvoiceSubmissionId' => $submissao->id,
                    'action'                 => 'submit',
                    'status'                 => $estado,
                    'request_payload'        => $this->sanitizarParaLog($documento),
                    'response_payload'       => $respostaAgt,
                    'message'                => $erroMsg,
                ]);
            }

            InvoiceSnapshotGuard::permitirMutacao($fatura, ['agt_status']);
            $fatura->agt_status = match ($estado) {
                'simulated' => 'draft',
                'failed'    => 'failed',
                default     => 'submitted',
            };
            $fatura->saveQuietly();

            return $submissao;
        });
    }

    /**
     * Consultar o estado de uma submissao na AGT - POLLING.
     *
     * Unico mecanismo de actualizacao de estado disponivel: a
     * documentacao oficial confirma que callback/webhook esta
     * "Disponivel nas proximas versoes" - nao existe ainda.
     */
    public function consultarEstado(AgtInvoiceSubmission $submissao): AgtInvoiceSubmission
    {
        if (!$this->ctx->estaActivo()) {
            return $submissao;
        }

        // Defesa em profundidade — mesma verificação de submeter().
        // Uma fatura SAF-T nunca devia ter um registo AgtInvoiceSubmission
        // (criar() só chama submeter() em modo electronic), mas se isso
        // acontecer por qualquer falha noutro ponto, nunca consultar a
        // API AGT real para essa submissão.
        $invoiceAssociada = $submissao->invoice
            ?? \App\Models\Invoice\Invoice::withoutGlobalScopes()->find($submissao->invoiceId);

        if ($invoiceAssociada && ($invoiceAssociada->invoicing_mode ?? ServicoModoFaturacao::ELECTRONIC) === ServicoModoFaturacao::SAFT_AO) {
            Log::warning('OnsoftAgt: consultarEstado encontrou submissao associada a fatura SAF-T(AO) - ignorado.', [
                'submissao_id' => $submissao->id,
                'invoice_id'   => $submissao->invoiceId,
            ]);
            return $submissao;
        }

        if (empty($submissao->request_id)) {
            Log::warning('OnsoftAgt: consultarEstado chamado sem requestID - submissao nunca recebeu resposta da AGT.', [
                'submissao_id' => $submissao->id,
            ]);
            return $submissao;
        }

        try {
            $resposta = $this->ctx->servicoApi()->obterEstado($submissao->request_id);

            $resultCode = (int) ($resposta['resultCode'] ?? EstadoValidacaoAgt::LOTE_EM_CURSO);

            $documentStatus = null;
            foreach ($resposta['documentStatusList'] ?? [] as $itemDoc) {
                if (($itemDoc['documentNo'] ?? null) === $submissao->invoice?->document_no) {
                    $documentStatus = $itemDoc['documentStatus'] ?? null;
                    break;
                }
            }
            if ($documentStatus === null && count($resposta['documentStatusList'] ?? []) === 1) {
                $documentStatus = $resposta['documentStatusList'][0]['documentStatus'] ?? null;
            }

            $novoEstadoInterno = EstadoValidacaoAgt::mapearParaVocabularioInterno($resultCode, $documentStatus);

            $submissao->update([
                'status'         => $novoEstadoInterno,
                'agt_response'   => $resposta,
                'last_polled_at' => now(),
                'accepted_at'    => $novoEstadoInterno === 'accepted' ? now() : $submissao->accepted_at,
                'rejected_at'    => $novoEstadoInterno === 'rejected' ? now() : $submissao->rejected_at,
            ]);

            $invoice = \App\Models\Invoice\Invoice::withoutGlobalScopes()->find($submissao->invoiceId);

            if ($invoice && in_array($novoEstadoInterno, ['accepted', 'rejected'], true)) {
                InvoiceSnapshotGuard::permitirMutacao($invoice, ['agt_status']);
                $invoice->agt_status = $novoEstadoInterno;
                $invoice->saveQuietly();
            }

        } catch (\Throwable $e) {
            Log::warning('OnsoftAgt: Falha ao consultar estado AGT', [
                'submissao_id' => $submissao->id,
                'erro'         => $e->getMessage(),
            ]);
        }

        return $submissao->fresh();
    }

    private function enviarParaAgt(array $documento): array
    {
        $inicio = microtime(true);

        try {
            $resposta = $this->ctx->servicoApi()->registarFactura([$documento]);
            $ms       = round((microtime(true) - $inicio) * 1000);
            $resposta['_ms'] = $ms;

            $requestId = $resposta['requestID'] ?? null;
            $temErros  = !empty($resposta['errorList']);

            if ($temErros) {
                $primeiro = $resposta['errorList'][0] ?? [];
                $erro     = '[' . ($primeiro['idError'] ?? '?') . '] ' . ($primeiro['descriptionError'] ?? 'Erro desconhecido');
                return ['failed', $resposta, $erro, $requestId];
            }

            if (empty($requestId)) {
                Log::warning('OnsoftAgt: registarFactura nao devolveu requestID - resposta inesperada da AGT.', [
                    'resposta_agt' => $resposta,
                ]);
                return ['failed', $resposta, 'AGT nao devolveu requestID.', null];
            }

            return ['pending', $resposta, null, $requestId];

        } catch (\Throwable $e) {
            $ms = round((microtime(true) - $inicio) * 1000);
            return ['failed', ['erro' => $e->getMessage(), '_ms' => $ms], $e->getMessage(), null];
        }
    }

    private function simular(Invoice $fatura): array
    {
        return [
            'simulated',
            [
                'modo'      => 'simulacao_local',
                'mensagem'  => 'AGT desactivado - submissao simulada localmente.',
                'fatura_id' => $fatura->id,
            ],
            null,
            null,
        ];
    }

    private function sanitizarParaLog(array $dados): array
    {
        array_walk_recursive($dados, function (&$val, $chave) {
            if (is_string($chave) && (str_contains(strtolower($chave), 'signature') || str_contains(strtolower($chave), 'key'))) {
                $val = '[OCULTO]';
            }
        });
        return $dados;
    }
}
