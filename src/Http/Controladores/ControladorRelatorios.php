<?php

namespace Onsoft\Agt\Http\Controladores;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Onsoft\Agt\Servicos\ServicoRelatorios;
use Onsoft\Agt\Servicos\ServicoPdf;
use Onsoft\Agt\Servicos\ServicoLimiteDiario;

/**
 * ControladorRelatorios
 *
 * Todos os endpoints de relatórios financeiros e estatísticas.
 *
 * ROTAS (ver routes/onsoft-agt.php):
 * ────────────────────────────────────────────────────────────
 * GET /onsoft-agt/relatorios/resumo-financeiro
 * GET /onsoft-agt/relatorios/receita-por-dia
 * GET /onsoft-agt/relatorios/receita-por-mes
 * GET /onsoft-agt/relatorios/receita-por-hora
 * GET /onsoft-agt/relatorios/por-categoria
 * GET /onsoft-agt/relatorios/meios-pagamento
 * GET /onsoft-agt/relatorios/resumo-iva
 * GET /onsoft-agt/relatorios/estado-agt
 * GET /onsoft-agt/relatorios/top-clientes
 * GET /onsoft-agt/relatorios/maiores-devedores
 * GET /onsoft-agt/relatorios/emissoes-30-dias
 * GET /onsoft-agt/relatorios/limite-diario
 * GET /onsoft-agt/relatorios/pdf-listagem         ← PDF A4
 * GET /onsoft-agt/relatorios/pdf-resumo-financeiro ← PDF A4
 * GET /onsoft-agt/relatorios/pdf-iva              ← PDF A4 fiscal
 * GET /onsoft-agt/relatorios/billing-types        ← tipos morph registados
 */
class ControladorRelatorios extends Controller
{
    public function __construct(
        private ServicoRelatorios  $relatorios,
        private ServicoPdf         $pdf,
        private ServicoLimiteDiario $limiteDiario
    ) {}

    // ══════════════════════════════════════════════════════════════════
    // ESTATÍSTICAS JSON (para frontend / gráficos)
    // ══════════════════════════════════════════════════════════════════

    /**
     * GET /onsoft-agt/relatorios/resumo-financeiro
     *
     * Resumo financeiro geral do período.
     * Parâmetros: de, ate, document_type, payment_status, agt_status
     */
    public function resumoFinanceiro(Request $request): JsonResponse
    {
        $orgId   = $this->orgId();
        $filtros = $this->filtros($request);

        return response()->json([
            'sucesso' => true,
            'dados'   => $this->relatorios->resumoFinanceiro($orgId, $filtros),
        ]);
    }

    /**
     * GET /onsoft-agt/relatorios/receita-por-dia
     * Para gráfico de linha diário no dashboard.
     */
    public function receitaPorDia(Request $request): JsonResponse
    {
        return response()->json([
            'sucesso' => true,
            'dados'   => $this->relatorios->receitaPorDia($this->orgId(), $this->filtros($request)),
        ]);
    }

    /**
     * GET /onsoft-agt/relatorios/receita-por-mes
     * Para gráfico de barras mensal.
     */
    public function receitaPorMes(Request $request): JsonResponse
    {
        return response()->json([
            'sucesso' => true,
            'dados'   => $this->relatorios->receitaPorMes($this->orgId(), $this->filtros($request)),
        ]);
    }

    /**
     * GET /onsoft-agt/relatorios/receita-por-hora
     * Pico de emissão por hora do dia.
     */
    public function receitaPorHora(Request $request): JsonResponse
    {
        return response()->json([
            'sucesso' => true,
            'dados'   => $this->relatorios->receitaPorHora($this->orgId(), $this->filtros($request)),
        ]);
    }

    /**
     * GET /onsoft-agt/relatorios/por-categoria
     * Receita por categoria de billing (propina, matrícula, transporte...).
     */
    public function porCategoria(Request $request): JsonResponse
    {
        return response()->json([
            'sucesso' => true,
            'dados'   => $this->relatorios->receitaPorCategoria($this->orgId(), $this->filtros($request)),
        ]);
    }

    /**
     * GET /onsoft-agt/relatorios/meios-pagamento
     * Distribuição por meio de pagamento (para donut chart).
     */
    public function meiosPagamento(Request $request): JsonResponse
    {
        return response()->json([
            'sucesso' => true,
            'dados'   => $this->relatorios->porMeioPagamento($this->orgId(), $this->filtros($request)),
        ]);
    }

    /**
     * GET /onsoft-agt/relatorios/resumo-iva
     * Resumo de IVA por taxa — para declaração fiscal mensal à AGT.
     */
    public function resumoIva(Request $request): JsonResponse
    {
        return response()->json([
            'sucesso' => true,
            'dados'   => $this->relatorios->resumoIva($this->orgId(), $this->filtros($request)),
        ]);
    }

    /**
     * GET /onsoft-agt/relatorios/estado-agt
     * Estatísticas de submissão à AGT para a organização actual.
     * Inclui totais por estado + últimas 50 submissões.
     */
    public function estadoAgt(Request $request): JsonResponse
    {
        return response()->json([
            'sucesso' => true,
            'dados'   => $this->relatorios->estadoAgt($this->orgId(), $this->filtros($request)),
        ]);
    }

    /**
     * GET /onsoft-agt/relatorios/estado-agt-todas-organizacoes
     * Estatísticas AGT para TODAS as organizações (visão admin).
     * Mostra quantas faturas cada organização tem em cada estado.
     */
    public function estadoAgtTodasOrganizacoes(Request $request): JsonResponse
    {
        return response()->json([
            'sucesso' => true,
            'dados'   => $this->relatorios->estadoAgtTodasOrganizacoes($this->filtros($request)),
        ]);
    }

    /**
     * GET /onsoft-agt/relatorios/top-clientes?limite=10
     * Top clientes por valor faturado.
     */
    public function topClientes(Request $request): JsonResponse
    {
        return response()->json([
            'sucesso' => true,
            'dados'   => $this->relatorios->topClientes(
                $this->orgId(),
                $this->filtros($request),
                (int) $request->get('limite', 10)
            ),
        ]);
    }

    /**
     * GET /onsoft-agt/relatorios/maiores-devedores?limite=10
     * Maiores devedores.
     */
    public function maioresDevedores(Request $request): JsonResponse
    {
        return response()->json([
            'sucesso' => true,
            'dados'   => $this->relatorios->maioresDevedores(
                $this->orgId(),
                $this->filtros($request),
                (int) $request->get('limite', 10)
            ),
        ]);
    }

    /**
     * GET /onsoft-agt/relatorios/emissoes-30-dias
     * Emissões nos últimos 30 dias (para gráfico de linha recente).
     */
    public function emissoes30Dias(): JsonResponse
    {
        return response()->json([
            'sucesso' => true,
            'dados'   => $this->relatorios->emissoesUltimos30Dias($this->orgId()),
        ]);
    }

    /**
     * GET /onsoft-agt/relatorios/limite-diario
     * Estado actual do limite diário de emissão.
     */
    public function limiteDiario(): JsonResponse
    {
        return response()->json([
            'sucesso' => true,
            'dados'   => $this->limiteDiario->estado($this->orgId()),
        ]);
    }

    /**
     * GET /onsoft-agt/relatorios/billing-types
     * Lista todos os tipos de billing morph registados.
     * Útil para o frontend saber que tipos existem.
     */
    public function billingTypes(): JsonResponse
    {
        return response()->json([
            'sucesso' => true,
            'dados'   => \Onsoft\Agt\Suporte\RegistoBillingMorph::listar(),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    // RELATÓRIOS PDF A4
    // ══════════════════════════════════════════════════════════════════

    /**
     * GET /onsoft-agt/relatorios/pdf-listagem
     * PDF A4 com listagem completa de faturas do período.
     * Stream directo para o browser — nunca guardado em disco.
     */
    public function pdfListagem(Request $request): Response
    {
        $orgId   = $this->orgId();
        $filtros = $this->filtros($request);
        $dados   = $this->relatorios->listagemParaPdf($orgId, $filtros);

        $html = $this->construirHtmlRelatorio('onsoft-agt::relatorios.listagem-faturas', [
            'dados'   => $dados,
            'orgId'   => $orgId,
            'filtros' => $filtros,
        ]);

        return $this->streamPdfA4($html, 'relatorio-faturas-' . now()->format('Y-m-d') . '.pdf');
    }

    /**
     * GET /onsoft-agt/relatorios/pdf-resumo-financeiro
     * PDF A4 com resumo financeiro do período.
     */
    public function pdfResumoFinanceiro(Request $request): Response
    {
        $orgId   = $this->orgId();
        $filtros = $this->filtros($request);

        $dados = [
            'resumo'           => $this->relatorios->resumoFinanceiro($orgId, $filtros),
            'por_mes'          => $this->relatorios->receitaPorMes($orgId, $filtros),
            'meios_pagamento'  => $this->relatorios->porMeioPagamento($orgId, $filtros),
            'por_categoria'    => $this->relatorios->receitaPorCategoria($orgId, $filtros),
            'estado_agt'       => $this->relatorios->estadoAgt($orgId, $filtros),
        ];

        $html = $this->construirHtmlRelatorio('onsoft-agt::relatorios.resumo-financeiro', [
            'dados'   => $dados,
            'filtros' => $filtros,
        ]);

        return $this->streamPdfA4($html, 'resumo-financeiro-' . now()->format('Y-m-d') . '.pdf');
    }

    /**
     * GET /onsoft-agt/relatorios/pdf-iva
     * PDF A4 com relatório de IVA — para entrega à AGT.
     */
    public function pdfIva(Request $request): Response
    {
        $orgId   = $this->orgId();
        $filtros = $this->filtros($request);

        $dados = [
            'iva'    => $this->relatorios->resumoIva($orgId, $filtros),
            'resumo' => $this->relatorios->resumoFinanceiro($orgId, $filtros),
        ];

        $html = $this->construirHtmlRelatorio('onsoft-agt::relatorios.iva', [
            'dados'   => $dados,
            'filtros' => $filtros,
        ]);

        return $this->streamPdfA4($html, 'relatorio-iva-' . now()->format('Y-m-d') . '.pdf');
    }

    /**
     * GET /onsoft-agt/relatorios/pdf-devedores
     * PDF A4 com lista de devedores.
     */
    public function pdfDevedores(Request $request): Response
    {
        $orgId   = $this->orgId();
        $filtros = $this->filtros($request);

        $dados = [
            'devedores' => $this->relatorios->maioresDevedores($orgId, $filtros, 100),
            'resumo'    => $this->relatorios->resumoFinanceiro($orgId, $filtros),
        ];

        $html = $this->construirHtmlRelatorio('onsoft-agt::relatorios.devedores', [
            'dados'   => $dados,
            'filtros' => $filtros,
        ]);

        return $this->streamPdfA4($html, 'devedores-' . now()->format('Y-m-d') . '.pdf');
    }

    // ══════════════════════════════════════════════════════════════════
    // AUXILIARES PRIVADOS
    // ══════════════════════════════════════════════════════════════════

    private function orgId(): int
    {
        $orgId = (int) (
            auth()->user()?->organizationId
            ?? (app()->bound('currentOrganizationId') ? app('currentOrganizationId') : 0)
        );
        abort_if($orgId <= 0, 403, 'Organização não definida.');
        return $orgId;
    }

    private function filtros(Request $request): array
    {
        return [
            'de'                  => $request->get('de'),
            'ate'                 => $request->get('ate'),
            'document_type'       => $request->get('document_type'),
            'payment_status'      => $request->get('payment_status'),
            'agt_status'          => $request->get('agt_status'),
            'excluir_canceladas'  => (bool) $request->get('excluir_canceladas', true),
        ];
    }

    private function construirHtmlRelatorio(string $view, array $dados): string
    {
        // Fallback se a view não existir — gera HTML básico
        if (!view()->exists($view)) {
            return $this->htmlRelatorioBasico($dados);
        }
        return view($view, $dados)->render();
    }

    private function htmlRelatorioBasico(array $dados): string
    {
        $json = json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return "<html><body><pre style='font-size:9pt'>{$json}</pre></body></html>";
    }

    private function streamPdfA4(string $html, string $nomeFicheiro): Response
    {
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)
            ->setPaper('A4', 'portrait')
            ->setWarnings(false);

        return response(
            $pdf->output(),
            200,
            [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => "inline; filename=\"{$nomeFicheiro}\"",
                'Cache-Control'       => 'no-store, no-cache',
            ]
        );
    }
}
