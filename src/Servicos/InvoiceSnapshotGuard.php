<?php

namespace Onsoft\Agt\Servicos;

use App\Models\Invoice\Invoice;
use App\Models\Invoice\InvoiceSnapshot;
use Onsoft\Agt\Excecoes\ExcecaoFaturaAgt;
use Illuminate\Support\Facades\Log;

/**
 * InvoiceSnapshotGuard
 *
 * Garante a IMUTABILIDADE dos dados fiscais após a criação da fatura.
 *
 * ╔═══════════════════════════════════════════════════════════════════╗
 * ║  DECRETO EXECUTIVO AGT — Anexo I, ponto 3:                       ║
 * ║  "Os sistemas não podem ter funções que permitam alterar,         ║
 * ║   de forma directa ou indirecta, a informação de natureza         ║
 * ║   fiscal, sem gerar evidência agregada à informação original."    ║
 * ║                                                                   ║
 * ║  Ponto 12 (l):                                                    ║
 * ║  "A aplicação não pode permitir que num documento já assinado     ║
 * ║   seja alterada qualquer informação fiscalmente relevante."        ║
 * ╚═══════════════════════════════════════════════════════════════════╝
 *
 * Campos IMUTÁVEIS (nunca podem mudar após criação):
 * ─────────────────────────────────────────────────
 * - document_no / document_number    (número do documento)
 * - document_type                    (tipo: FT, FR, NC...)
 * - series_code / fiscal_year        (série fiscal)
 * - sequence_number                  (número sequencial)
 * - subtotal / tax_total / gross_total / total  (valores)
 * - discount_total                   (descontos)
 * - invoice_hash / hash_control      (hash AGT)
 * - previous_invoice_hash            (encadeamento)
 * - jws_document_signature           (assinatura contribuinte)
 * - jws_software_signature           (assinatura software)
 * - issued_at                        (data de emissão)
 * - organization_snapshot            (dados da organização no momento)
 * - customer_snapshot                (dados do cliente no momento)
 * - encarregado_snapshot             (dados do encarregado no momento)
 * - currency                         (moeda)
 *
 * Campos MUTÁVEIS (podem mudar legitimamente):
 * ────────────────────────────────────────────
 * - agt_status                       (estado na AGT: draft→submitted→accepted)
 * - payment_status                   (estado de pagamento: paid, cancelled...)
 * - submission_uuid                  (UUID de submissão AGT)
 * - cancel_reason / cancelled_at / cancelled_by  (cancelamento)
 * - sourceInvoiceId                  (referência à NC gerada)
 * - idempotency_key                  (chave de idempotência)
 *
 * USO:
 * ────
 * // No InvoiceObserver (registado automaticamente pelo pacote):
 * InvoiceSnapshotGuard::verificarAntesDeAtualizar($invoice);
 *
 * // No ServicoFatura, antes de qualquer forceFill:
 * InvoiceSnapshotGuard::permitirMutacao($invoice, ['agt_status', 'submission_uuid']);
 */
class InvoiceSnapshotGuard
{
    /**
     * Campos fiscais que NUNCA podem ser alterados após a criação.
     */
    public const CAMPOS_IMUTAVEIS = [
        'document_no',
        'document_number',
        'document_type',
        'series_code',
        'fiscal_year',
        'sequence_number',
        'agtSeriesId',
        'invoicing_mode',
        'subtotal',
        'tax_total',
        'gross_total',
        'total',
        'discount_total',
        'paid_total',
        'remaining_balance',
        'balance_due',
        'change_amount',
        'wallet_generated_amount',
        'invoice_hash',
        'hash_control',
        'previous_invoice_hash',
        'jws_document_signature',
        'jws_software_signature',
        'issued_at',
        'organization_snapshot',
        'customer_snapshot',
        'encarregado_snapshot',
        'currency',
        'encarregadoId',
        'customerId',
        'studentId',
    ];

    /**
     * Campos que podem mudar legitimamente após a criação.
     */
    public const CAMPOS_MUTAVEIS = [
        'agt_status',
        'payment_status',
        'invoice_status',
        'submission_uuid',
        'cancel_reason',
        'cancelled_at',
        'cancelled_by',
        'sourceInvoiceId',
        'idempotency_key',
        'snapshot',            // snapshot JSON da fatura (gerado pelo InvoiceSnapshotService)
        'onlinePaymentIntentId',
        'updated_at',
    ];

    /**
     * Verificar se a fatura já tem snapshot (foi emitida e é imutável).
     */
    public static function estaLocked(Invoice $invoice): bool
    {
        // Considera locked se tem hash (foi assinada) OU tem snapshot
        return !empty($invoice->invoice_hash)
            || !empty($invoice->hash_control)
            || InvoiceSnapshot::withoutGlobalScopes()
                ->where('organizationId', $invoice->organizationId)
                ->where('invoiceId', $invoice->id)
                ->exists();
    }

    /**
     * Verificar uma actualização e bloquear se tentar mudar campos imutáveis.
     *
     * Lançar ExcecaoFaturaAgt se:
     * - A fatura já está locked (tem hash ou snapshot)
     * - A actualização toca em campos imutáveis
     *
     * Lançar nunca se:
     * - A actualização é só de campos mutáveis (agt_status, cancel_reason, etc.)
     */
    public static function verificarAntesDeAtualizar(Invoice $invoice): void
    {
        if (!$invoice->exists || !$invoice->isDirty()) {
            return;
        }

        // Usa estaLocked() — considera hash (regime electronic) OU
        // snapshot existente (qualquer regime, incluindo SAF-T). Uma
        // fatura SAF-T nunca tem hash mas TEM snapshot desde a sua
        // criação (ver InvoiceObserver::created()), por isso fica
        // correctamente protegida tal como uma fatura electronic.
        if (!self::estaLocked($invoice)) {
            return; // Ainda não emitida/sem snapshot — pode mudar livremente
        }

        // Verificar quais campos imutáveis estão a ser alterados
        $camposAlterados = array_intersect(
            array_keys($invoice->getDirty()),
            self::CAMPOS_IMUTAVEIS
        );

        if (!empty($camposAlterados)) {
            $lista = implode(', ', $camposAlterados);

            Log::warning('OnsoftAgt: Tentativa de alterar campos imutáveis de fatura emitida', [
                'invoice_id'      => $invoice->id,
                'document_no'     => $invoice->document_no,
                'campos_tentados' => $camposAlterados,
            ]);

            throw new ExcecaoFaturaAgt(
                "VIOLAÇÃO DE IMUTABILIDADE FISCAL — " .
                "A fatura {$invoice->document_no} já foi emitida. " .
                "Os seguintes campos fiscais não podem ser alterados: {$lista}. " .
                "Conforme Decreto Executivo AGT, Anexo I, ponto 12(l)."
            );
        }
    }

    /**
     * Verificar que um forceFill só toca em campos mutáveis.
     * Usar antes de qualquer ->forceFill() em faturas já emitidas.
     *
     * @param Invoice $invoice      A fatura
     * @param array   $campos       Os campos a actualizar
     * @throws ExcecaoFaturaAgt    Se tentar alterar campos imutáveis
     */
    public static function permitirMutacao(Invoice $invoice, array $campos): void
    {
        // Mesma fonte única da verdade que verificarAntesDeAtualizar() —
        // considera hash OU snapshot, nunca apenas invoice_hash isolado.
        if (!self::estaLocked($invoice)) {
            return; // Ainda não emitida/sem snapshot — sem restrições
        }

        $proibidos = array_intersect($campos, self::CAMPOS_IMUTAVEIS);

        if (!empty($proibidos)) {
            $lista = implode(', ', $proibidos);
            throw new ExcecaoFaturaAgt(
                "Não é possível alterar campos fiscais imutáveis após emissão: {$lista}. " .
                "Fatura: {$invoice->document_no}."
            );
        }
    }

    /**
     * Gerar relatório de integridade para auditoria.
     * Verifica se o hash actual corresponde ao snapshot guardado.
     */
    public static function verificarIntegridade(Invoice $invoice): array
    {
        $resultado = [
            'invoice_id'    => $invoice->id,
            'document_no'   => $invoice->document_no,
            'integro'       => true,
            'problemas'     => [],
        ];

        // Verificar se tem snapshot
        $snapshot = InvoiceSnapshot::withoutGlobalScopes()
            ->where('organizationId', $invoice->organizationId)
            ->where('invoiceId', $invoice->id)
            ->first();

        if (!$snapshot) {
            $resultado['integro']    = false;
            $resultado['problemas'][] = 'Sem snapshot registado — fatura pode ter sido criada antes do pacote.';
            return $resultado;
        }

        // Verificar hash do payload
        $hashActual = hash('sha256', $snapshot->payload_json);
        if ($hashActual !== $snapshot->hash) {
            $resultado['integro']    = false;
            $resultado['problemas'][] = 'Hash do snapshot não corresponde — payload pode ter sido alterado.';
        }

        // Verificar se os campos imutáveis do invoice correspondem ao snapshot
        $payloadSnapshot = json_decode($snapshot->payload_json, true);
        $invoiceNoSnap   = data_get($payloadSnapshot, 'invoice.document_no');
        $totalNoSnap     = data_get($payloadSnapshot, 'invoice.gross_total');

        if ($invoiceNoSnap !== $invoice->document_no) {
            $resultado['integro']    = false;
            $resultado['problemas'][] = "Número do documento diverge: snapshot={$invoiceNoSnap}, actual={$invoice->document_no}";
        }

        if ((float) $totalNoSnap !== (float) ($invoice->gross_total ?? $invoice->total)) {
            $resultado['integro']    = false;
            $resultado['problemas'][] = "Total diverge: snapshot={$totalNoSnap}, actual={$invoice->gross_total}";
        }

        // Detectar o sintoma exacto da regressão pré-v1.14.4: snapshot
        // criado antes de itens/pagamentos existirem na BD, resultando
        // em arrays vazios mesmo que a fatura tenha linhas reais.
        $itemsNoSnapshot = data_get($payloadSnapshot, 'items', []);
        if (empty($itemsNoSnapshot) && $invoice->items()->exists()) {
            $resultado['integro']    = false;
            $resultado['problemas'][] =
                'Snapshot sem itens mas a fatura tem linhas reais — sintoma da ' .
                'regressão pré-v1.14.4. Use: php artisan onsoft-agt:regenerar-snapshots';
        }

        $paymentsNoSnapshot = data_get($payloadSnapshot, 'payments', []);
        if (empty($paymentsNoSnapshot) && $invoice->payments()->exists()) {
            $resultado['integro']    = false;
            $resultado['problemas'][] =
                'Snapshot sem pagamentos mas a fatura tem pagamentos reais — sintoma ' .
                'da regressão pré-v1.14.4. Use: php artisan onsoft-agt:regenerar-snapshots';
        }

        return $resultado;
    }
}
