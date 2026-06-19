<?php

namespace Onsoft\Agt\Http\Controladores;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Onsoft\Agt\Servicos\ServicoFaturaProforma;

/**
 * ControladorFaturaProforma
 *
 * Endpoints da Factura Pro-forma ("FP") - documento interno, NUNCA
 * fiscal, NUNCA persistido, NUNCA submetido a AGT.
 *
 * GARANTIA DE NAO-PERSISTENCIA:
 * Nenhum metodo deste controlador escreve em nenhuma tabela. Nao ha
 * ::create(), ::save(), DB::table(...)->insert(...) em parte
 * nenhuma deste ficheiro nem do ServicoFaturaProforma que ele usa.
 * Cada pedido e processado inteiramente em memoria e esquecido
 * assim que a resposta HTTP e enviada.
 *
 * ROTAS:
 * POST /onsoft-agt/proforma/pdf          -> PDF (stream, nunca guardado)
 * POST /onsoft-agt/proforma/pdf-base64   -> PDF em base64 (para SPA)
 * POST /onsoft-agt/proforma/calcular     -> apenas os totais (JSON, sem PDF)
 */
class ControladorFaturaProforma extends Controller
{
    public function __construct(private ServicoFaturaProforma $servico) {}

    /**
     * POST /onsoft-agt/proforma/calcular
     *
     * Devolve apenas os totais calculados, sem gerar PDF - util para
     * o frontend mostrar um resumo antes de pedir a impressao.
     */
    public function calcular(Request $request): JsonResponse
    {
        try {
            $resultado = $this->servico->calcular($request->all());

            return response()->json([
                'sucesso' => true,
                'dados'   => $resultado,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['sucesso' => false, 'mensagem' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /onsoft-agt/proforma/pdf
     *
     * Gera o PDF em stream directo para o browser. Nada e persistido
     * antes, durante, ou depois desta chamada.
     */
    public function pdf(Request $request): Response
    {
        $resultado   = $this->servico->calcular($request->all());
        $organizacao = $this->obterOrganizacaoParaExibicao();

        $html = $this->servico->gerarHtml($resultado, $organizacao);
        $pdf  = Pdf::loadHTML($html)
            ->setPaper('A4', 'portrait')
            ->setWarnings(false);

        $nomeFicheiro = 'proforma-' . now()->format('Y-m-d-His') . '.pdf';

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

    /**
     * POST /onsoft-agt/proforma/pdf-base64
     *
     * Mesmo PDF, devolvido em base64 dentro de uma resposta JSON -
     * para frontends SPA que preferem processar a resposta como dados
     * antes de abrir o PDF.
     */
    public function pdfBase64(Request $request): JsonResponse
    {
        try {
            $resultado   = $this->servico->calcular($request->all());
            $organizacao = $this->obterOrganizacaoParaExibicao();

            $html = $this->servico->gerarHtml($resultado, $organizacao);
            $pdf  = Pdf::loadHTML($html)
                ->setPaper('A4', 'portrait')
                ->setWarnings(false);

            return response()->json([
                'sucesso' => true,
                'dados'   => [
                    'base64'        => base64_encode($pdf->output()),
                    'nome_ficheiro' => 'proforma-' . now()->format('Y-m-d-His') . '.pdf',
                    'mime_type'     => 'application/pdf',
                    'totais'        => $resultado['totais'],
                    'persistido'    => false,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['sucesso' => false, 'mensagem' => $e->getMessage()], 422);
        }
    }

    /**
     * Dados da organizacao apenas para EXIBICAO no cabecalho do PDF -
     * lidos da BD (read-only), nunca escritos.
     */
    private function obterOrganizacaoParaExibicao(): array
    {
        $orgId = (int) (
            auth()->user()?->organizationId
            ?? (app()->bound('currentOrganizationId') ? app('currentOrganizationId') : 0)
        );

        if ($orgId <= 0) {
            return [];
        }

        $org = \App\Models\Organization::find($orgId);

        if (!$org) {
            return [];
        }

        return [
            'nome_fiscal'    => $org->nome_fiscal,
            'nome_comercial' => $org->nome_comercial,
            'nif'            => $org->nif,
        ];
    }
}
