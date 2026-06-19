<?php

namespace Onsoft\Agt\Http\Controladores;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Onsoft\Agt\Servicos\ServicoModoFaturacao;
use Onsoft\Agt\Servicos\ServicoSaftAo;

/**
 * ControladorModoFaturacao
 *
 * Endpoints para:
 * - Consultar e alternar entre Faturação Eletrónica AGT e SAF-T(AO)
 * - Gerar o ficheiro SAF-T(AO) entre uma data de início e uma data de fim
 *
 * ROTAS (ver routes/onsoft-agt.php):
 * GET  /onsoft-agt/modo-faturacao/estado
 * POST /onsoft-agt/modo-faturacao/alternar
 * GET  /onsoft-agt/saft/previsualizar?data_inicio=&data_fim=
 * GET  /onsoft-agt/saft/exportar?data_inicio=&data_fim=        -> stream XML
 * GET  /onsoft-agt/saft/exportar-base64?data_inicio=&data_fim= -> JSON base64
 */
class ControladorModoFaturacao extends Controller
{
    public function __construct(
        private ServicoModoFaturacao $servicoModo,
        private ServicoSaftAo        $servicoSaft
    ) {}

    /**
     * GET /onsoft-agt/modo-faturacao/estado
     */
    public function estado(): JsonResponse
    {
        return response()->json([
            'sucesso' => true,
            'dados'   => $this->servicoModo->estado($this->orgId()),
        ]);
    }

    /**
     * POST /onsoft-agt/modo-faturacao/alternar
     *
     * Body: { "modo": "saft_ao" }  ou  { "modo": "electronic" }
     *
     * Reversível em qualquer direcção. Nunca afecta faturas já emitidas.
     */
    public function alternar(Request $request): JsonResponse
    {
        $request->validate([
            'modo' => ['required', 'string', 'in:electronic,saft_ao'],
        ]);

        try {
            $resultado = $this->servicoModo->alternarModo(
                $this->orgId(),
                $request->input('modo'),
                auth()->id()
            );

            return response()->json([
                'sucesso' => true,
                'dados'   => $resultado,
            ]);

        } catch (\Throwable $e) {
            return response()->json(['sucesso' => false, 'mensagem' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /onsoft-agt/modo-faturacao/auditoria
     *
     * Mostra quantas faturas existem em cada estado de origem
     * (electronic vs saft_ao aguardando/já exportadas). Esclarece
     * que faturas SAF-T nunca migram para submissão em tempo real.
     */
    public function auditoria(): JsonResponse
    {
        return response()->json([
            'sucesso' => true,
            'dados'   => $this->servicoModo->auditoriaTransicao($this->orgId()),
        ]);
    }

    /**
     * GET /onsoft-agt/saft/previsualizar?data_inicio=2026-06-01&data_fim=2026-06-30
     *
     * Pré-visualizar contagens/valores do período SEM gerar o ficheiro.
     */
    public function previsualizarSaft(Request $request): JsonResponse
    {
        $request->validate([
            'data_inicio' => ['required', 'date'],
            'data_fim'    => ['required', 'date'],
        ]);

        try {
            $resultado = $this->servicoSaft->previsualizar(
                $this->orgId(),
                $request->query('data_inicio'),
                $request->query('data_fim')
            );

            return response()->json(['sucesso' => true, 'dados' => $resultado]);

        } catch (\Throwable $e) {
            return response()->json(['sucesso' => false, 'mensagem' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /onsoft-agt/saft/exportar?data_inicio=2026-06-01&data_fim=2026-06-30
     *
     * Gerar e devolver o ficheiro SAF-T(AO) directamente como download XML.
     */
    public function exportarSaft(Request $request): Response
    {
        $request->validate([
            'data_inicio' => ['required', 'date'],
            'data_fim'    => ['required', 'date'],
        ]);

        $resultado = $this->servicoSaft->gerar(
            $this->orgId(),
            $request->query('data_inicio'),
            $request->query('data_fim')
        );

        return response(
            $resultado['xml'],
            200,
            [
                'Content-Type'        => 'application/xml',
                'Content-Disposition' => 'attachment; filename="' . $resultado['nome_ficheiro'] . '"',
                'Cache-Control'       => 'no-store, no-cache',
            ]
        );
    }

    /**
     * GET /onsoft-agt/saft/exportar-base64?data_inicio=2026-06-01&data_fim=2026-06-30
     *
     * Gerar o ficheiro SAF-T(AO) e devolver em base64 - útil quando o
     * frontend precisa de processar a resposta como JSON em vez de
     * receber o XML diretamente (ex: SPA que depois oferece o download).
     */
    public function exportarSaftBase64(Request $request): JsonResponse
    {
        $request->validate([
            'data_inicio' => ['required', 'date'],
            'data_fim'    => ['required', 'date'],
        ]);

        try {
            $resultado = $this->servicoSaft->gerar(
                $this->orgId(),
                $request->query('data_inicio'),
                $request->query('data_fim')
            );

            return response()->json([
                'sucesso' => true,
                'dados'   => [
                    'base64'           => base64_encode($resultado['xml']),
                    'nome_ficheiro'    => $resultado['nome_ficheiro'],
                    'mime_type'        => 'application/xml',
                    'total_documentos' => $resultado['total_documentos'],
                    'resumo'           => $resultado['resumo'],
                ],
            ]);

        } catch (\Throwable $e) {
            return response()->json(['sucesso' => false, 'mensagem' => $e->getMessage()], 422);
        }
    }

    private function orgId(): int
    {
        $orgId = (int) (
            auth()->user()?->organizationId
            ?? (app()->bound('currentOrganizationId') ? app('currentOrganizationId') : 0)
        );
        abort_if($orgId <= 0, 403, 'Organização não definida.');
        return $orgId;
    }
}
