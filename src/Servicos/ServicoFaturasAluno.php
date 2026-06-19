<?php

namespace Onsoft\Agt\Servicos;

use App\Models\Invoice\Invoice;
use App\Models\Invoice\InvoiceItem;
use App\Models\Config\Academico\EstudanteAnoClasse;
use App\Models\Config\Financeiro\Mensalidade;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ServicoFaturasAluno
 *
 * Resolve TODAS as faturas de um aluno, mesmo quando:
 *
 * 1. A fatura tem MÚLTIPLOS alunos (uma fatura paga propinas de 2 filhos)
 *    → O aluno vê a fatura completa com todos os itens, mas marcado
 *      quais itens são seus.
 *
 * 2. O aluno mudou de mensalidade (mudou de turma/classe/curso)
 *    → Mostra faturas de TODAS as mensalidades históricas do aluno.
 *
 * 3. A fatura foi criada pelo encarregado (via encarregadoId)
 *    → O aluno vê porque os seus itens estão na fatura.
 *
 * ESTRATÉGIA DE LOOKUP:
 * ─────────────────────────────────────────────────────────────
 * Passo 1: Todas as mensalidadeIds do aluno (histórico completo via estudanteanoclasse)
 * Passo 2: Todos os invoiceIds onde invoice_items.alunoId = alunoId
 * Passo 3: Todos os invoiceIds onde invoices.studentId = alunoId
 * Passo 4: Todos os invoiceIds via billing morph (invoiceable_id = billingId que tem alunoId)
 * Passo 5: União dos 3 conjuntos → faturas únicas sem duplicados
 */
class ServicoFaturasAluno
{
    /**
     * Obter todas as faturas de um aluno com contexto completo.
     *
     * @param int $alunoId        ID do aluno
     * @param int $organizacaoId  ID da organização
     * @param array $filtros      Filtros opcionais: de, ate, document_type, payment_status
     * @return array
     */
    public function faturasDoAluno(int $alunoId, int $organizacaoId, array $filtros = []): array
    {
        // ── Passo 1: Histórico de mensalidades do aluno ──────────────
        $mensalidadeIds = $this->todasMensalidadesDoAluno($alunoId, $organizacaoId);

        // ── Passo 2+3+4: Todos os IDs de faturas do aluno ────────────
        $invoiceIds = $this->resolverTodosInvoiceIds($alunoId, $organizacaoId, $mensalidadeIds);

        if ($invoiceIds->isEmpty()) {
            return [
                'aluno_id'        => $alunoId,
                'faturas'         => [],
                'resumo'          => $this->resumoVazio(),
                'mensalidades'    => [],
                'total_faturas'   => 0,
            ];
        }

        // ── Passo 5: Carregar faturas ─────────────────────────────────
        $faturas = Invoice::withoutGlobalScopes()
            ->whereIn('id', $invoiceIds)
            ->where('organizationId', $organizacaoId)
            ->when(!empty($filtros['de']), fn($q) => $q->where('issued_at', '>=', $filtros['de']))
            ->when(!empty($filtros['ate']), fn($q) => $q->where('issued_at', '<=', $filtros['ate'] . ' 23:59:59'))
            ->when(!empty($filtros['document_type']), fn($q) => $q->where('document_type', $filtros['document_type']))
            ->when(!empty($filtros['payment_status']), fn($q) => $q->where('payment_status', $filtros['payment_status']))
            ->with([
                'items'            => fn($q) => $q->with('taxes'),
                'payments.methods',
                'agtSeries',
                'snapshotRecord',
            ])
            ->orderByDesc('issued_at')
            ->get();

        // ── Passo 6: Formatar cada fatura com contexto do aluno ───────
        $faturaFormatadas = $faturas->map(fn($f) =>
            $this->formatarFaturaParaAluno($f, $alunoId, $mensalidadeIds)
        )->values()->all();

        // ── Passo 7: Mensalidades do aluno com detalhes ───────────────
        $mensalidades = $this->mensalidadesDetalhadas($alunoId, $organizacaoId);

        return [
            'aluno_id'        => $alunoId,
            'total_faturas'   => count($faturaFormatadas),
            'resumo'          => $this->calcularResumo($faturas),
            'faturas'         => $faturaFormatadas,
            'mensalidades'    => $mensalidades,
        ];
    }

    /**
     * Obter UMA fatura específica com contexto completo do aluno.
     * Mesmo que a fatura tenha múltiplos alunos, devolve completa
     * mas marca quais itens pertencem a este aluno.
     */
    public function faturaDoAluno(int $faturaId, int $alunoId, int $organizacaoId): array
    {
        $mensalidadeIds = $this->todasMensalidadesDoAluno($alunoId, $organizacaoId);

        // Verificar que o aluno tem acesso a esta fatura
        $temAcesso = $this->alunoTemAcessoAFatura($faturaId, $alunoId, $organizacaoId, $mensalidadeIds);

        if (!$temAcesso) {
            abort(403, 'Acesso negado — esta fatura não pertence ao aluno.');
        }

        $fatura = Invoice::withoutGlobalScopes()
            ->where('id', $faturaId)
            ->where('organizationId', $organizacaoId)
            ->with([
                'items.taxes',
                'payments.methods',
                'payments.allocations',
                'agtSeries',
                'snapshotRecord',
            ])
            ->firstOrFail();

        return $this->formatarFaturaParaAluno($fatura, $alunoId, $mensalidadeIds);
    }

    // ══════════════════════════════════════════════════════════════════
    // RESOLUÇÃO DE IDs
    // ══════════════════════════════════════════════════════════════════

    /**
     * Todas as mensalidadeIds históricas do aluno (incluindo mudanças de turma).
     */
    private function todasMensalidadesDoAluno(int $alunoId, int $organizacaoId): Collection
    {
        return EstudanteAnoClasse::withoutGlobalScopes()
            ->where('organizationId', $organizacaoId)
            ->where('alunoId', $alunoId)
            ->pluck('mensalidadeId')
            ->unique()
            ->values();
    }

    /**
     * Resolver TODOS os IDs de faturas do aluno de 3 formas diferentes.
     */
    private function resolverTodosInvoiceIds(
        int        $alunoId,
        int        $organizacaoId,
        Collection $mensalidadeIds
    ): Collection {

        // Forma 1: invoice_items.alunoId = alunoId
        // → faturas onde o aluno é mencionado directamente na linha
        $porItemAlunoId = InvoiceItem::withoutGlobalScopes()
            ->where('organizationId', $organizacaoId)
            ->where('alunoId', $alunoId)
            ->pluck('invoiceId');

        // Forma 2: invoices.studentId = alunoId
        // → faturas criadas directamente para este aluno
        $porStudentId = Invoice::withoutGlobalScopes()
            ->where('organizationId', $organizacaoId)
            ->where('studentId', $alunoId)
            ->pluck('id');

        // Forma 3: Via billing morph — billing_propinas.alunoId = alunoId
        // → faturas onde a linha billing aponta para este aluno
        $porBillingMorph = $this->resolverPorBillingMorph($alunoId, $organizacaoId);

        return $porItemAlunoId
            ->merge($porStudentId)
            ->merge($porBillingMorph)
            ->unique()
            ->values();
    }

    /**
     * Resolver IDs de faturas via billing morph tables.
     * Procura em todas as tabelas registadas no RegistoBillingMorph.
     */
    private function resolverPorBillingMorph(int $alunoId, int $organizacaoId): Collection
    {
        $ids = collect();

        $modelos = \Onsoft\Agt\Suporte\RegistoBillingMorph::todos();

        foreach ($modelos as $chave => $classe) {
            try {
                if (!class_exists($classe)) continue;

                $instancia = new $classe();
                $tabela    = $instancia->getTable();

                // Verificar se a tabela tem alunoId
                if (!DB::getSchemaBuilder()->hasColumn($tabela, 'alunoId')) continue;

                // IDs dos registos billing deste aluno
                $billingIds = DB::table($tabela)
                    ->where('alunoId', $alunoId)
                    ->pluck('id');

                if ($billingIds->isEmpty()) continue;

                // Faturas que referenciam estes registos billing
                $invoiceIds = InvoiceItem::withoutGlobalScopes()
                    ->where('organizationId', $organizacaoId)
                    ->where('invoiceable_type', $classe)
                    ->whereIn('invoiceable_id', $billingIds)
                    ->pluck('invoiceId');

                $ids = $ids->merge($invoiceIds);

            } catch (\Throwable) {
                // Tabela não existe ou erro — continuar
                continue;
            }
        }

        return $ids->unique()->values();
    }

    /**
     * Verificar se um aluno tem acesso a uma fatura específica.
     */
    private function alunoTemAcessoAFatura(
        int        $faturaId,
        int        $alunoId,
        int        $organizacaoId,
        Collection $mensalidadeIds
    ): bool {
        // Acesso directo via studentId
        $porStudentId = Invoice::withoutGlobalScopes()
            ->where('id', $faturaId)
            ->where('organizationId', $organizacaoId)
            ->where('studentId', $alunoId)
            ->exists();

        if ($porStudentId) return true;

        // Acesso via item da fatura
        $porItem = InvoiceItem::withoutGlobalScopes()
            ->where('invoiceId', $faturaId)
            ->where('organizationId', $organizacaoId)
            ->where('alunoId', $alunoId)
            ->exists();

        if ($porItem) return true;

        // Acesso via billing morph
        $ids = $this->resolverPorBillingMorph($alunoId, $organizacaoId);
        return $ids->contains($faturaId);
    }

    // ══════════════════════════════════════════════════════════════════
    // FORMATAÇÃO
    // ══════════════════════════════════════════════════════════════════

    /**
     * Formatar uma fatura com contexto do aluno.
     *
     * Distingue:
     * - Itens deste aluno (meus_itens)
     * - Outros itens da fatura (outros_itens) — quando há múltiplos alunos
     * - Se é uma fatura partilhada (fatura_partilhada = true)
     */
    private function formatarFaturaParaAluno(
        Invoice    $fatura,
        int        $alunoId,
        Collection $mensalidadeIds
    ): array {
        $todosItens    = $fatura->items ?? collect();
        $meusItens     = $todosItens->filter(fn($i) => (int) $i->alunoId === $alunoId);
        $outrosItens   = $todosItens->filter(fn($i) => (int) $i->alunoId !== $alunoId && $i->alunoId !== null);
        $isPartilhada  = $meusItens->isNotEmpty() && $outrosItens->isNotEmpty();

        // Totais só dos itens deste aluno
        $meuSubtotal   = $meusItens->sum(fn($i) => (float) $i->subtotal);
        $meuIva        = $meusItens->sum(fn($i) => (float) $i->tax_amount);
        $meuTotal      = $meusItens->sum(fn($i) => (float) ($i->line_total ?? $i->total));

        // Extrair mensalidadeId dos itens via billing morph
        $mensalidadeDoItem = $this->extrairMensalidadeDoItem($meusItens->first());

        return [
            // ── Dados da fatura ──────────────────────────────────────
            'id'              => $fatura->id,
            'document_no'     => $fatura->document_no ?? $fatura->document_number,
            'document_type'   => $fatura->document_type,
            'label_tipo'      => config('onsoft-agt.tipos_documento.' . $fatura->document_type, $fatura->document_type),
            'issued_at'       => optional($fatura->issued_at)->toISOString(),
            'issued_at_fmt'   => optional($fatura->issued_at)->format('d/m/Y H:i'),
            'payment_status'  => $fatura->payment_status,
            'agt_status'      => $fatura->agt_status,
            'currency'        => $fatura->currency ?? 'AOA',

            // ── Totais globais da fatura ─────────────────────────────
            'gross_total'     => (float) ($fatura->gross_total ?? $fatura->total),
            'paid_total'      => (float) $fatura->paid_total,
            'remaining_balance' => (float) ($fatura->remaining_balance ?? $fatura->balance_due),
            'tax_total'       => (float) $fatura->tax_total,

            // ── Totais só deste aluno ────────────────────────────────
            'meu_subtotal'    => round($meuSubtotal, 2),
            'meu_iva'         => round($meuIva, 2),
            'meu_total'       => round($meuTotal, 2),

            // ── Fatura partilhada com outros alunos? ─────────────────
            'fatura_partilhada'    => $isPartilhada,
            'total_alunos_fatura'  => $todosItens->pluck('alunoId')->filter()->unique()->count(),

            // ── Itens ────────────────────────────────────────────────
            'meus_itens'     => $meusItens->map(fn($i) => $this->formatarItem($i))->values()->all(),
            'outros_itens'   => $isPartilhada
                ? $outrosItens->map(fn($i) => $this->formatarItem($i))->values()->all()
                : [],

            // ── Pagamentos ───────────────────────────────────────────
            'payments'       => $fatura->payments ? $fatura->payments->map(fn($p) => [
                'amount'  => (float) $p->amount,
                'status'  => $p->status,
                'methods' => $p->methods->map(fn($m) => [
                    'method_code' => $m->method_code,
                    'label'       => config('onsoft-agt.meios_pagamento.' . strtolower($m->method_code ?? ''), $m->method_code),
                    'amount'      => (float) $m->amount,
                    'reference'   => $m->reference,
                ])->values()->all(),
            ])->values()->all() : [],

            // ── Informação AGT ───────────────────────────────────────
            'hash_control'    => $fatura->hash_control,
            'invoice_hash'    => $fatura->invoice_hash,

            // ── Contexto académico ───────────────────────────────────
            'mensalidade_id'  => $mensalidadeDoItem,
            'source_invoice_id' => $fatura->sourceInvoiceId,

            // ── Acções disponíveis ───────────────────────────────────
            'pode_ver_pdf'    => true,
            'pode_submeter'   => in_array($fatura->agt_status, ['draft', 'failed']),
            'pdf_url'         => url("/onsoft-agt/faturas/{$fatura->id}/pdf"),
            'pdf_base64_url'  => url("/onsoft-agt/faturas/{$fatura->id}/pdf-base64"),
        ];
    }

    private function formatarItem($item): array
    {
        return [
            'id'              => $item->id,
            'line_number'     => $item->line_number,
            'description'     => $item->description,
            'quantity'        => (float) $item->quantity,
            'unit_price'      => (float) $item->unit_price,
            'discount_amount' => (float) ($item->discount_amount ?? 0),
            'tax_type'        => $item->tax_type,
            'tax_percentage'  => (float) $item->tax_percentage,
            'tax_amount'      => (float) $item->tax_amount,
            'line_total'      => (float) ($item->line_total ?? $item->total),
            'item_category'   => $item->item_category,
            'alunoId'         => $item->alunoId,
            'aluno_snapshot'  => $item->aluno_snapshot,
            'invoiceable_type' => $item->invoiceable_type,
            'invoiceable_id'   => $item->invoiceable_id,
            'taxes'           => $item->taxes ? $item->taxes->map(fn($t) => [
                'tax_type'       => $t->tax_type,
                'tax_code'       => $t->tax_code,
                'tax_percentage' => (float) $t->tax_percentage,
                'tax_contribution' => (float) $t->tax_contribution,
                'exemption_code' => $t->exemption_code,
                'tax_reason'     => $t->tax_reason,
            ])->values()->all() : [],
        ];
    }

    /**
     * Tentar extrair mensalidadeId do primeiro item via billing morph.
     */
    private function extrairMensalidadeDoItem($item): ?int
    {
        if (!$item || empty($item->invoiceable_type) || empty($item->invoiceable_id)) {
            return null;
        }

        try {
            $billing = $item->invoiceable_type::find($item->invoiceable_id);
            return $billing?->mensalidadeId ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // MENSALIDADES HISTÓRICAS
    // ══════════════════════════════════════════════════════════════════

    /**
     * Obter todas as mensalidades do aluno com detalhes completos.
     * Inclui histórico de mudanças de turma/classe.
     */
    private function mensalidadesDetalhadas(int $alunoId, int $organizacaoId): array
    {
        $registos = EstudanteAnoClasse::withoutGlobalScopes()
            ->where('organizationId', $organizacaoId)
            ->where('alunoId', $alunoId)
            ->with([
                'mensalidade.anolectivo',
                'mensalidade.curso',
                'mensalidade.classe',
                'mensalidade.periodo',
                'mensalidade.turma',
                'mensalidade.sala',
                'mensalidade.pagamento',
            ])
            ->orderByDesc('id')
            ->get();

        return $registos->map(fn($r) => [
            'estudante_ano_classe_id' => $r->id,
            'mensalidade_id'          => $r->mensalidadeId,
            'status'                  => $r->status,
            'anolectivo'    => ['id' => $r->mensalidade?->anolectivo?->id, 'name' => $r->mensalidade?->anolectivo?->name],
            'curso'         => ['id' => $r->mensalidade?->curso?->id,      'name' => $r->mensalidade?->curso?->name],
            'classe'        => ['id' => $r->mensalidade?->classe?->id,     'name' => $r->mensalidade?->classe?->name],
            'turma'         => ['id' => $r->mensalidade?->turma?->id,      'name' => $r->mensalidade?->turma?->name],
            'sala'          => ['id' => $r->mensalidade?->sala?->id,       'name' => $r->mensalidade?->sala?->name],
            'periodo'       => ['id' => $r->mensalidade?->periodo?->id,    'name' => $r->mensalidade?->periodo?->name],
            'pagamento'     => [
                'propinaAnual'     => (float) ($r->mensalidade?->pagamento?->propinaAnual ?? 0),
                'propinaMensal'    => (float) ($r->mensalidade?->pagamento?->propinaMensal ?? 0),
                'confirmacaoPreco' => (float) ($r->mensalidade?->pagamento?->confirmacaoPreco ?? 0),
                'matriculaPreco'   => (float) ($r->mensalidade?->pagamento?->matriculaPreco ?? 0),
                'multaValor'       => (float) ($r->mensalidade?->pagamento?->multaValor ?? 0),
            ],
            'criado_em' => optional($r->created_at)->toISOString(),
        ])->values()->all();
    }

    // ══════════════════════════════════════════════════════════════════
    // RESUMO
    // ══════════════════════════════════════════════════════════════════

    private function calcularResumo(Collection $faturas): array
    {
        $nao_canceladas = $faturas->filter(fn($f) => $f->payment_status !== 'cancelled');

        return [
            'total_faturas'    => $faturas->count(),
            'total_emitido'    => round($nao_canceladas->sum(fn($f) => (float) ($f->gross_total ?? $f->total)), 2),
            'total_pago'       => round($nao_canceladas->sum(fn($f) => (float) $f->paid_total), 2),
            'total_divida'     => round($nao_canceladas->sum(fn($f) => (float) ($f->remaining_balance ?? $f->balance_due)), 2),
            'total_canceladas' => $faturas->filter(fn($f) => $f->payment_status === 'cancelled')->count(),
            'por_estado_agt'   => $faturas->groupBy('agt_status')->map(fn($g) => $g->count())->toArray(),
            'por_tipo'         => $faturas->groupBy('document_type')->map(fn($g) => $g->count())->toArray(),
        ];
    }

    private function resumoVazio(): array
    {
        return [
            'total_faturas'    => 0,
            'total_emitido'    => 0,
            'total_pago'       => 0,
            'total_divida'     => 0,
            'total_canceladas' => 0,
            'por_estado_agt'   => [],
            'por_tipo'         => [],
        ];
    }
}
