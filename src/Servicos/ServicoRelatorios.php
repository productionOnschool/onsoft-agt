<?php

namespace Onsoft\Agt\Servicos;

use App\Models\Invoice\Invoice;
use App\Models\Invoice\InvoiceItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

/**
 * ServicoRelatorios
 *
 * Todos os relatórios financeiros e estatísticas do sistema AGT.
 *
 * Dados prontos para:
 * - Frontend (gráficos Chart.js, Recharts, ApexCharts)
 * - PDF A4 (via ServicoPdf)
 * - Exportação JSON
 *
 * Todos os métodos são scoped por organizationId (multi-tenant).
 */
class ServicoRelatorios
{
    // ══════════════════════════════════════════════════════════════════
    // RESUMO FINANCEIRO
    // ══════════════════════════════════════════════════════════════════

    /**
     * Resumo financeiro geral do período.
     * Usado no dashboard principal.
     */
    public function resumoFinanceiro(int $orgId, array $filtros = []): array
    {
        $query = $this->baseInvoiceQuery($orgId, $filtros);

        $totais = (clone $query)
            ->selectRaw('
                COUNT(*) as total_documentos,
                COALESCE(SUM(gross_total), 0) as total_emitido,
                COALESCE(SUM(paid_total), 0) as total_pago,
                COALESCE(SUM(remaining_balance), 0) as total_divida,
                COALESCE(SUM(tax_total), 0) as total_iva,
                COALESCE(SUM(discount_total), 0) as total_desconto,
                COALESCE(SUM(change_amount), 0) as total_troco_carteira
            ')
            ->first();

        $porEstado = (clone $query)
            ->selectRaw('payment_status, COUNT(*) as total, COALESCE(SUM(gross_total), 0) as valor')
            ->groupBy('payment_status')
            ->get()
            ->keyBy('payment_status');

        $porTipo = (clone $query)
            ->selectRaw('document_type, COUNT(*) as total, COALESCE(SUM(gross_total), 0) as valor')
            ->groupBy('document_type')
            ->get();

        return [
            'periodo'           => $this->formatarPeriodo($filtros),
            'total_documentos'  => (int) $totais->total_documentos,
            'total_emitido'     => (float) $totais->total_emitido,
            'total_pago'        => (float) $totais->total_pago,
            'total_divida'      => (float) $totais->total_divida,
            'total_iva'         => (float) $totais->total_iva,
            'total_desconto'    => (float) $totais->total_desconto,
            'total_troco_carteira' => (float) $totais->total_troco_carteira,
            'taxa_cobranca'     => $totais->total_emitido > 0
                ? round(($totais->total_pago / $totais->total_emitido) * 100, 2)
                : 0,
            'por_estado'        => $porEstado,
            'por_tipo_documento' => $porTipo->map(fn($r) => [
                'tipo'  => $r->document_type,
                'label' => config('onsoft-agt.tipos_documento.' . $r->document_type, $r->document_type),
                'total' => (int) $r->total,
                'valor' => (float) $r->valor,
            ])->values()->all(),
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    // EVOLUÇÃO TEMPORAL (gráficos de linha/barra)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Receita por dia — para gráfico de linha diário.
     */
    public function receitaPorDia(int $orgId, array $filtros = []): array
    {
        return $this->baseInvoiceQuery($orgId, $filtros)
            ->selectRaw('DATE(issued_at) as data, COUNT(*) as faturas, COALESCE(SUM(gross_total),0) as total_emitido, COALESCE(SUM(paid_total),0) as total_pago')
            ->groupBy('data')
            ->orderBy('data')
            ->get()
            ->map(fn($r) => [
                'data'          => $r->data,
                'faturas'       => (int) $r->faturas,
                'total_emitido' => (float) $r->total_emitido,
                'total_pago'    => (float) $r->total_pago,
            ])
            ->values()->all();
    }

    /**
     * Receita por mês — para gráfico de barras mensal.
     */
    public function receitaPorMes(int $orgId, array $filtros = []): array
    {
        return $this->baseInvoiceQuery($orgId, $filtros)
            ->selectRaw('YEAR(issued_at) as ano, MONTH(issued_at) as mes, COUNT(*) as faturas, COALESCE(SUM(gross_total),0) as total_emitido, COALESCE(SUM(paid_total),0) as total_pago, COALESCE(SUM(remaining_balance),0) as divida')
            ->groupByRaw('YEAR(issued_at), MONTH(issued_at)')
            ->orderByRaw('YEAR(issued_at), MONTH(issued_at)')
            ->get()
            ->map(fn($r) => [
                'ano'           => (int) $r->ano,
                'mes'           => (int) $r->mes,
                'mes_label'     => $this->nomeMes((int) $r->mes) . ' ' . $r->ano,
                'faturas'       => (int) $r->faturas,
                'total_emitido' => (float) $r->total_emitido,
                'total_pago'    => (float) $r->total_pago,
                'divida'        => (float) $r->divida,
            ])
            ->values()->all();
    }

    /**
     * Receita por hora do dia — para análise de pico de emissão.
     */
    public function receitaPorHora(int $orgId, array $filtros = []): array
    {
        return $this->baseInvoiceQuery($orgId, $filtros)
            ->selectRaw('HOUR(issued_at) as hora, COUNT(*) as faturas, COALESCE(SUM(gross_total),0) as valor')
            ->groupBy('hora')
            ->orderBy('hora')
            ->get()
            ->map(fn($r) => [
                'hora'   => (int) $r->hora,
                'label'  => str_pad($r->hora, 2, '0', STR_PAD_LEFT) . ':00',
                'faturas' => (int) $r->faturas,
                'valor'  => (float) $r->valor,
            ])
            ->values()->all();
    }

    // ══════════════════════════════════════════════════════════════════
    // POR TIPO DE BILLING (morph)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Receita por categoria de billing (propina, matrícula, transporte, etc.)
     * Usa o item_category do InvoiceItem.
     */
    public function receitaPorCategoria(int $orgId, array $filtros = []): array
    {
        $query = DB::table('invoice_items as ii')
            ->join('invoices as i', 'i.id', '=', 'ii.invoiceId')
            ->where('i.organizationId', $orgId)
            ->whereNotIn('i.payment_status', ['cancelled'])
            ->when(!empty($filtros['de']), fn($q) => $q->where('i.issued_at', '>=', $filtros['de']))
            ->when(!empty($filtros['ate']), fn($q) => $q->where('i.issued_at', '<=', $filtros['ate'] . ' 23:59:59'))
            ->selectRaw('
                COALESCE(ii.item_category, "outro") as categoria,
                ii.invoiceable_type,
                COUNT(DISTINCT i.id) as faturas,
                COUNT(ii.id) as linhas,
                COALESCE(SUM(ii.line_total), 0) as total,
                COALESCE(SUM(ii.tax_amount), 0) as iva
            ')
            ->groupBy('categoria', 'ii.invoiceable_type')
            ->orderByDesc('total')
            ->get();

        return $query->map(fn($r) => [
            'categoria'        => $r->categoria,
            'invoiceable_type' => $r->invoiceable_type,
            'tipo_label'       => $this->labelParaTipo($r->invoiceable_type),
            'faturas'          => (int) $r->faturas,
            'linhas'           => (int) $r->linhas,
            'total'            => (float) $r->total,
            'iva'              => (float) $r->iva,
        ])->values()->all();
    }

    // ══════════════════════════════════════════════════════════════════
    // MEIOS DE PAGAMENTO
    // ══════════════════════════════════════════════════════════════════

    /**
     * Distribuição por meio de pagamento.
     * Para gráfico donut/pie no frontend.
     */
    public function porMeioPagamento(int $orgId, array $filtros = []): array
    {
        return DB::table('invoice_payment_methods as ipm')
            ->join('invoice_payments as ip', 'ip.id', '=', 'ipm.invoicePaymentId')
            ->join('invoices as i', 'i.id', '=', 'ip.invoiceId')
            ->where('i.organizationId', $orgId)
            ->whereNotIn('i.payment_status', ['cancelled'])
            ->when(!empty($filtros['de']), fn($q) => $q->where('i.issued_at', '>=', $filtros['de']))
            ->when(!empty($filtros['ate']), fn($q) => $q->where('i.issued_at', '<=', $filtros['ate'] . ' 23:59:59'))
            ->selectRaw('
                UPPER(COALESCE(ipm.method_code, "OUTRO")) as metodo,
                COUNT(DISTINCT i.id) as faturas,
                COALESCE(SUM(ipm.amount), 0) as total
            ')
            ->groupBy('metodo')
            ->orderByDesc('total')
            ->get()
            ->map(fn($r) => [
                'metodo'  => $r->metodo,
                'label'   => config('onsoft-agt.meios_pagamento.' . strtolower($r->metodo), $r->metodo),
                'faturas' => (int) $r->faturas,
                'total'   => (float) $r->total,
            ])
            ->values()->all();
    }

    // ══════════════════════════════════════════════════════════════════
    // IVA — RELATÓRIO FISCAL
    // ══════════════════════════════════════════════════════════════════

    /**
     * Resumo de IVA por taxa — para declaração fiscal mensal.
     */
    public function resumoIva(int $orgId, array $filtros = []): array
    {
        return DB::table('invoice_item_taxes as iit')
            ->join('invoice_items as ii', 'ii.id', '=', 'iit.invoiceItemId')
            ->join('invoices as i', 'i.id', '=', 'ii.invoiceId')
            ->where('i.organizationId', $orgId)
            ->whereNotIn('i.payment_status', ['cancelled'])
            ->when(!empty($filtros['de']), fn($q) => $q->where('i.issued_at', '>=', $filtros['de']))
            ->when(!empty($filtros['ate']), fn($q) => $q->where('i.issued_at', '<=', $filtros['ate'] . ' 23:59:59'))
            ->selectRaw('
                iit.tax_type,
                iit.tax_code,
                iit.tax_percentage,
                COUNT(DISTINCT i.id) as faturas,
                COALESCE(SUM(ii.subtotal), 0) as base_tributavel,
                COALESCE(SUM(iit.tax_contribution), 0) as iva_total
            ')
            ->groupBy('iit.tax_type', 'iit.tax_code', 'iit.tax_percentage')
            ->orderByDesc('iva_total')
            ->get()
            ->map(fn($r) => [
                'tax_type'       => $r->tax_type,
                'tax_code'       => $r->tax_code,
                'taxa'           => (float) $r->tax_percentage,
                'taxa_label'     => $r->tax_type === 'ISENTO' ? 'Isento' : $r->tax_percentage . '%',
                'faturas'        => (int) $r->faturas,
                'base_tributavel' => (float) $r->base_tributavel,
                'iva_total'      => (float) $r->iva_total,
            ])
            ->values()->all();
    }

    // ══════════════════════════════════════════════════════════════════
    // ESTADO AGT
    // ══════════════════════════════════════════════════════════════════

    /**
     * Estatísticas de submissão AGT — totais + detalhes por organização.
     * Suporta: uma organização específica ou TODAS (admin).
     */
    public function estadoAgt(int $orgId, array $filtros = []): array
    {
        $totais = $this->baseInvoiceQuery($orgId, $filtros)
            ->selectRaw('agt_status, COUNT(*) as total, COALESCE(SUM(gross_total),0) as valor')
            ->groupBy('agt_status')
            ->get()
            ->keyBy('agt_status');

        // Submissões com detalhes (últimas 50)
        $ultimasSubmissoes = \App\Models\Agt\AgtInvoiceSubmission::withoutGlobalScopes()
            ->where('organizationId', $orgId)
            ->when(!empty($filtros['de']), fn($q) => $q->where('submitted_at', '>=', $filtros['de']))
            ->when(!empty($filtros['ate']), fn($q) => $q->where('submitted_at', '<=', $filtros['ate'] . ' 23:59:59'))
            ->orderByDesc('submitted_at')
            ->limit(50)
            ->get(['id','invoiceId','status','attempts','submitted_at','accepted_at','rejected_at','error_message'])
            ->map(fn($s) => [
                'id'           => $s->id,
                'invoiceId'    => $s->invoiceId,
                'status'       => $s->status,
                'attempts'     => $s->attempts,
                'submitted_at' => optional($s->submitted_at)?->toISOString(),
                'accepted_at'  => optional($s->accepted_at)?->toISOString(),
                'rejected_at'  => optional($s->rejected_at)?->toISOString(),
                'error_message' => $s->error_message,
            ])->values()->all();

        return [
            // Contadores por estado — regime Electronic (submissão em tempo real)
            'draft'     => ['total' => (int)($totais['draft']?->total ?? 0),     'valor' => (float)($totais['draft']?->valor ?? 0)],
            'pending'   => ['total' => (int)($totais['pending']?->total ?? 0),   'valor' => (float)($totais['pending']?->valor ?? 0)],
            'submitted' => ['total' => (int)($totais['submitted']?->total ?? 0), 'valor' => (float)($totais['submitted']?->valor ?? 0)],
            'accepted'  => ['total' => (int)($totais['accepted']?->total ?? 0),  'valor' => (float)($totais['accepted']?->valor ?? 0)],
            'rejected'  => ['total' => (int)($totais['rejected']?->total ?? 0),  'valor' => (float)($totais['rejected']?->valor ?? 0)],
            'failed'    => ['total' => (int)($totais['failed']?->total ?? 0),    'valor' => (float)($totais['failed']?->valor ?? 0)],
            'cancelled' => ['total' => (int)($totais['cancelled']?->total ?? 0), 'valor' => (float)($totais['cancelled']?->valor ?? 0)],
            // Contadores por estado — regime SAF-T(AO) (sem submissão em tempo real)
            'saft_pending_export' => ['total' => (int)($totais['saft_pending_export']?->total ?? 0), 'valor' => (float)($totais['saft_pending_export']?->valor ?? 0)],
            'saft_exported'       => ['total' => (int)($totais['saft_exported']?->total ?? 0),       'valor' => (float)($totais['saft_exported']?->valor ?? 0)],
            // Métricas calculadas
            'taxa_submissao'  => $this->calcularTaxaSubmissao($totais),
            'total_documentos' => $totais->sum('total'),
            'total_documentos_electronic' => $totais->except(['saft_pending_export', 'saft_exported'])->sum('total'),
            'total_documentos_saft'       => (int)($totais['saft_pending_export']?->total ?? 0) + (int)($totais['saft_exported']?->total ?? 0),
            // Últimas submissões para tabela no frontend
            'ultimas_submissoes' => $ultimasSubmissoes,
        ];
    }

    /**
     * Estatísticas AGT para TODAS as organizações (visão admin/multi-tenant).
     * Retorna totais agrupados por organização.
     */
    public function estadoAgtTodasOrganizacoes(array $filtros = []): array
    {
        $porOrg = \Illuminate\Support\Facades\DB::table('invoices')
            ->when(!empty($filtros['de']), fn($q) => $q->where('issued_at', '>=', $filtros['de']))
            ->when(!empty($filtros['ate']), fn($q) => $q->where('issued_at', '<=', $filtros['ate'] . ' 23:59:59'))
            ->selectRaw('
                organizationId,
                agt_status,
                COUNT(*) as total,
                COALESCE(SUM(gross_total),0) as valor
            ')
            ->groupBy('organizationId', 'agt_status')
            ->get();

        // Agrupar por organização
        $resultado = [];
        foreach ($porOrg as $row) {
            $orgId = $row->organizationId;
            if (!isset($resultado[$orgId])) {
                $resultado[$orgId] = [
                    'organizationId' => $orgId,
                    'draft'     => ['total' => 0, 'valor' => 0],
                    'pending'   => ['total' => 0, 'valor' => 0],
                    'submitted' => ['total' => 0, 'valor' => 0],
                    'accepted'  => ['total' => 0, 'valor' => 0],
                    'rejected'  => ['total' => 0, 'valor' => 0],
                    'failed'    => ['total' => 0, 'valor' => 0],
                    'cancelled' => ['total' => 0, 'valor' => 0],
                    'saft_pending_export' => ['total' => 0, 'valor' => 0],
                    'saft_exported'       => ['total' => 0, 'valor' => 0],
                    'total_documentos' => 0,
                    'total_valor'      => 0,
                ];
            }
            $status = $row->agt_status ?? 'draft';
            if (isset($resultado[$orgId][$status])) {
                $resultado[$orgId][$status]['total'] += (int)$row->total;
                $resultado[$orgId][$status]['valor'] += (float)$row->valor;
            }
            $resultado[$orgId]['total_documentos'] += (int)$row->total;
            $resultado[$orgId]['total_valor']      += (float)$row->valor;
        }

        return array_values($resultado);
    }

    // ══════════════════════════════════════════════════════════════════
    // TOP CLIENTES / DEVEDORES
    // ══════════════════════════════════════════════════════════════════

    /**
     * Top clientes por valor faturado.
     */
    public function topClientes(int $orgId, array $filtros = [], int $limite = 10): array
    {
        return $this->baseInvoiceQuery($orgId, $filtros)
            ->selectRaw('
                encarregadoId,
                JSON_UNQUOTE(JSON_EXTRACT(customer_snapshot, "$.name")) as nome,
                JSON_UNQUOTE(JSON_EXTRACT(customer_snapshot, "$.nif")) as nif,
                COUNT(*) as faturas,
                COALESCE(SUM(gross_total),0) as total_faturado,
                COALESCE(SUM(paid_total),0) as total_pago,
                COALESCE(SUM(remaining_balance),0) as divida
            ')
            ->whereNotNull('encarregadoId')
            ->groupBy('encarregadoId', 'nome', 'nif')
            ->orderByDesc('total_faturado')
            ->limit($limite)
            ->get()
            ->map(fn($r) => [
                'encarregadoId'  => $r->encarregadoId,
                'nome'           => $r->nome ?? 'Consumidor Final',
                'nif'            => $r->nif ?? '999999999',
                'faturas'        => (int) $r->faturas,
                'total_faturado' => (float) $r->total_faturado,
                'total_pago'     => (float) $r->total_pago,
                'divida'         => (float) $r->divida,
            ])
            ->values()->all();
    }

    /**
     * Maiores devedores.
     */
    public function maioresDevedores(int $orgId, array $filtros = [], int $limite = 10): array
    {
        return $this->baseInvoiceQuery($orgId, $filtros)
            ->where('remaining_balance', '>', 0)
            ->selectRaw('
                encarregadoId,
                JSON_UNQUOTE(JSON_EXTRACT(customer_snapshot, "$.name")) as nome,
                JSON_UNQUOTE(JSON_EXTRACT(customer_snapshot, "$.nif")) as nif,
                COUNT(*) as faturas_em_divida,
                COALESCE(SUM(remaining_balance),0) as divida_total
            ')
            ->whereNotNull('encarregadoId')
            ->groupBy('encarregadoId', 'nome', 'nif')
            ->orderByDesc('divida_total')
            ->limit($limite)
            ->get()
            ->map(fn($r) => [
                'encarregadoId'    => $r->encarregadoId,
                'nome'             => $r->nome ?? 'N/D',
                'nif'              => $r->nif ?? '999999999',
                'faturas_em_divida' => (int) $r->faturas_em_divida,
                'divida_total'     => (float) $r->divida_total,
            ])
            ->values()->all();
    }

    // ══════════════════════════════════════════════════════════════════
    // LIMITE DIÁRIO
    // ══════════════════════════════════════════════════════════════════

    /**
     * Evolução da emissão de faturas por dia do mês actual.
     */
    public function emissoesUltimos30Dias(int $orgId): array
    {
        return Invoice::withoutGlobalScopes()
            ->where('organizationId', $orgId)
            ->where('issued_at', '>=', now()->subDays(30))
            ->whereNotIn('payment_status', ['cancelled'])
            ->selectRaw('DATE(issued_at) as data, COUNT(*) as faturas, COALESCE(SUM(gross_total),0) as valor')
            ->groupBy('data')
            ->orderBy('data')
            ->get()
            ->map(fn($r) => [
                'data'    => $r->data,
                'faturas' => (int) $r->faturas,
                'valor'   => (float) $r->valor,
            ])
            ->values()->all();
    }

    // ══════════════════════════════════════════════════════════════════
    // LISTAGEM PARA PDF
    // ══════════════════════════════════════════════════════════════════

    /**
     * Listagem completa de faturas para relatório PDF A4.
     */
    public function listagemParaPdf(int $orgId, array $filtros = []): array
    {
        $faturas = $this->baseInvoiceQuery($orgId, $filtros)
            ->with(['payments.methods'])
            ->orderBy('issued_at')
            ->get();

        $resumo = $this->resumoFinanceiro($orgId, $filtros);

        return [
            'faturas' => $faturas->map(fn($f) => [
                'id'              => $f->id,
                'document_no'     => $f->document_no,
                'document_type'   => $f->document_type,
                'issued_at'       => optional($f->issued_at)->format('d/m/Y H:i'),
                'cliente'         => data_get($f->customer_snapshot, 'name', 'Consumidor Final'),
                'nif'             => data_get($f->customer_snapshot, 'nif', '999999999'),
                'gross_total'     => (float) $f->gross_total,
                'paid_total'      => (float) $f->paid_total,
                'divida'          => (float) $f->remaining_balance,
                'payment_status'  => $f->payment_status,
                'agt_status'      => $f->agt_status,
                'hash_control'    => $f->hash_control,
                'meios_pagamento' => $f->payments->flatMap(fn($p) => $p->methods)->map(fn($m) => $m->method_code)->unique()->implode(', '),
            ])->values()->all(),
            'resumo'   => $resumo,
            'filtros'  => $filtros,
            'gerado_em' => now()->format('d/m/Y H:i:s'),
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    // AUXILIARES PRIVADOS
    // ══════════════════════════════════════════════════════════════════

    private function baseInvoiceQuery(int $orgId, array $filtros)
    {
        return Invoice::withoutGlobalScopes()
            ->where('organizationId', $orgId)
            ->when(!empty($filtros['de']),             fn($q) => $q->where('issued_at', '>=', $filtros['de']))
            ->when(!empty($filtros['ate']),            fn($q) => $q->where('issued_at', '<=', $filtros['ate'] . ' 23:59:59'))
            ->when(!empty($filtros['document_type']),  fn($q) => $q->where('document_type', $filtros['document_type']))
            ->when(!empty($filtros['payment_status']), fn($q) => $q->where('payment_status', $filtros['payment_status']))
            ->when(!empty($filtros['agt_status']),     fn($q) => $q->where('agt_status', $filtros['agt_status']))
            ->when(isset($filtros['excluir_canceladas']) && $filtros['excluir_canceladas'],
                fn($q) => $q->whereNotIn('payment_status', ['cancelled'])
            );
    }

    private function formatarPeriodo(array $filtros): array
    {
        return [
            'de'  => $filtros['de'] ?? null,
            'ate' => $filtros['ate'] ?? null,
        ];
    }

    private function nomeMes(int $mes): string
    {
        return ['', 'Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'][$mes] ?? '?';
    }

    private function labelParaTipo(?string $fqcn): string
    {
        if (!$fqcn) return 'Sem categoria';
        $partes = explode('\\', $fqcn);
        return end($partes);
    }

    /**
     * Taxa de submissão — apenas sobre faturas em regime ELECTRONIC.
     * Faturas SAF-T(AO) são excluídas do denominador: esta métrica
     * mede sucesso de submissão em tempo real, que não se aplica ao
     * regime SAF-T (sem submissão por documento). Misturar os dois
     * regimes distorceria a taxa para organizações com volume SAF-T.
     */
    private function calcularTaxaSubmissao($totais): float
    {
        $semSaft = $totais->except(['saft_pending_export', 'saft_exported']);
        $aceites = (int) ($totais['accepted']?->total ?? 0);
        $total   = array_sum(array_map(fn($r) => (int) $r->total, $semSaft->all()));
        return $total > 0 ? round(($aceites / $total) * 100, 2) : 0;
    }
}
