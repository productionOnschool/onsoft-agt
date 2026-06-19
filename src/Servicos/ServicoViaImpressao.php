<?php

namespace Onsoft\Agt\Servicos;

use App\Models\Invoice\Invoice;
use Illuminate\Support\Facades\DB;

/**
 * ServicoViaImpressao
 *
 * Determina se uma geração de PDF é a "1.ª via" (Original) ou uma
 * "2.ª via" (Cópia) - conforme exigido pelo Decreto Executivo AGT,
 * Anexo I, ponto 6, alíneas h) e n).
 *
 * REGRA:
 * A PRIMEIRA vez que o PDF de uma fatura é gerado APÓS ela estar
 * paga/confirmada conta como ORIGINAL. Qualquer geração subsequente
 * - reimpressão, novo download, reenvio por email, abrir de novo no
 * frontend - é registada como CÓPIA e o PDF deve obrigatoriamente
 * mostrar "Cópia do documento original".
 *
 * O conteúdo do documento NUNCA muda entre Original e Cópia - apenas
 * a menção impressa muda. Os valores, NIF, hash, etc. são sempre os
 * mesmos (lidos do snapshot imutável).
 *
 * Implementado com lock atómico (lockForUpdate na própria tabela de
 * log) para que dois pedidos simultâneos ao mesmo PDF nunca marquem
 * ambos como Original.
 */
class ServicoViaImpressao
{
    /**
     * Registar uma geração de PDF e devolver se é Original ou Cópia.
     *
     * @return array{e_original: bool, via_label: string, primeira_geracao_em: ?string}
     */
    public function registarGeracao(
        Invoice $fatura,
        string  $formatoPapel = 'A4',
        string  $canal = 'pdf',
        ?int    $geradoPor = null
    ): array {
        return DB::transaction(function () use ($fatura, $formatoPapel, $canal, $geradoPor) {

            // Lock na tabela de log para esta fatura - impede duas
            // gerações simultâneas de ambas pensarem que são a primeira.
            $jaTemOriginal = DB::table('onsoft_agt_invoice_print_log')
                ->where('invoiceId', $fatura->id)
                ->where('is_original', true)
                ->lockForUpdate()
                ->exists();

            $eOriginal = !$jaTemOriginal;

            DB::table('onsoft_agt_invoice_print_log')->insert([
                'organizationId' => $fatura->organizationId,
                'invoiceId'      => $fatura->id,
                'is_original'    => $eOriginal,
                'formato_papel'  => $formatoPapel,
                'canal'          => $canal,
                'gerado_por'     => $geradoPor,
                'gerado_em'      => now(),
            ]);

            $primeiraGeracao = $eOriginal
                ? now()->toISOString()
                : DB::table('onsoft_agt_invoice_print_log')
                    ->where('invoiceId', $fatura->id)
                    ->where('is_original', true)
                    ->value('gerado_em');

            return [
                'e_original'          => $eOriginal,
                'via_label'           => $eOriginal ? 'Original' : 'Cópia do documento original',
                'primeira_geracao_em' => $primeiraGeracao,
            ];
        });
    }

    /**
     * Consultar (sem registar) se uma fatura já teve a sua via Original
     * gerada - útil para mostrar aviso no frontend antes de gerar.
     */
    public function jaTemOriginal(int $invoiceId): bool
    {
        return DB::table('onsoft_agt_invoice_print_log')
            ->where('invoiceId', $invoiceId)
            ->where('is_original', true)
            ->exists();
    }

    /**
     * Histórico completo de gerações de PDF de uma fatura - para
     * auditoria (quem viu/imprimiu o documento e quando).
     */
    public function historico(int $invoiceId): array
    {
        return DB::table('onsoft_agt_invoice_print_log')
            ->where('invoiceId', $invoiceId)
            ->orderBy('gerado_em')
            ->get()
            ->map(fn($r) => [
                'is_original'   => (bool) $r->is_original,
                'via_label'     => $r->is_original ? 'Original' : 'Cópia do documento original',
                'formato_papel' => $r->formato_papel,
                'canal'         => $r->canal,
                'gerado_por'    => $r->gerado_por,
                'gerado_em'     => $r->gerado_em,
            ])
            ->values()
            ->all();
    }
}
