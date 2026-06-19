<?php

namespace Onsoft\Agt\Http\Controladores;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Onsoft\Agt\Servicos\ServicoFaturasAluno;
use Onsoft\Agt\Servicos\ServicoPdf;

/**
 * ControladorFaturasAluno
 *
 * Endpoints para consultar faturas de um aluno.
 *
 * ══════════════════════════════════════════════════════════════════════
 * PADRÃO DO PROJECTO (On-School):
 * ══════════════════════════════════════════════════════════════════════
 * O alunoId é SEMPRE passado via query string — igual ao padrão existente
 * em EstudanteInfoController, EstudanteAnoClasseController, etc.
 *
 * Exemplos do projecto:
 *   GET /estudantedetalhes?alunoId=101&mensalidadeId=5
 *   GET /estudanteanoclasse?alunoId=101
 *   GET /attendance?alunoId=101
 *
 * Este controlador segue o mesmo padrão:
 *   GET /onsoft-agt/aluno/faturas?alunoId=101
 *
 * ══════════════════════════════════════════════════════════════════════
 * DOIS CONTEXTOS DE USO:
 * ══════════════════════════════════════════════════════════════════════
 *
 * 1. SECRETARIA / ADMIN / ENCARREGADO
 *    → Passam alunoId=X na query string
 *    → Protegido pelo middleware do projecto (jwt.auth + org + role)
 *    → Podem ver as faturas de qualquer aluno da organização
 *
 * 2. O PRÓPRIO ALUNO AUTENTICADO
 *    → Não passa alunoId — usa auth()->id() automaticamente
 *    → Rota separada protegida com role:estudante
 *    → Só vê as suas próprias faturas
 *
 * ══════════════════════════════════════════════════════════════════════
 * ROTAS (ver routes/onsoft-agt.php):
 * ══════════════════════════════════════════════════════════════════════
 *
 * Para secretaria/admin (alunoId na query):
 *   GET /onsoft-agt/aluno/faturas?alunoId=101
 *   GET /onsoft-agt/aluno/faturas/{id}?alunoId=101
 *   GET /onsoft-agt/aluno/faturas/{id}/pdf?alunoId=101
 *   GET /onsoft-agt/aluno/faturas/{id}/pdf-base64?alunoId=101
 *   GET /onsoft-agt/aluno/mensalidades?alunoId=101
 *   GET /onsoft-agt/aluno/resumo?alunoId=101
 *
 * Para o aluno autenticado (sem alunoId — usa auth()->id()):
 *   GET /onsoft-agt/eu/faturas
 *   GET /onsoft-agt/eu/faturas/{id}
 *   GET /onsoft-agt/eu/faturas/{id}/pdf
 *   GET /onsoft-agt/eu/faturas/{id}/pdf-base64
 *   GET /onsoft-agt/eu/mensalidades
 *   GET /onsoft-agt/eu/resumo
 */
class ControladorFaturasAluno extends Controller
{
    public function __construct(
        private ServicoFaturasAluno $servico,
        private ServicoPdf          $pdf
    ) {}

    // ══════════════════════════════════════════════════════════════════
    // ENDPOINTS COM alunoId NA QUERY STRING
    // (padrão do projecto On-School — para secretaria/admin/encarregado)
    // ══════════════════════════════════════════════════════════════════

    /**
     * GET /onsoft-agt/aluno/faturas?alunoId=101
     *
     * Todas as faturas de um aluno.
     * Filtros: de, ate, document_type, payment_status, mensalidadeId
     *
     * Inclui:
     * - Faturas de TODAS as mensalidades históricas do aluno
     * - Faturas com múltiplos alunos (mostra completa com distinção)
     * - Faturas via billing morph (propinas, transporte, etc.)
     */
    public function faturas(Request $request): JsonResponse
    {
        $request->validate([
            'alunoId' => ['required_without:eu', 'integer'],
        ]);

        $alunoId = $this->resolverAlunoId($request);
        $orgId   = $this->orgId();
        $filtros = $this->filtros($request);

        try {
            return response()->json([
                'sucesso' => true,
                'dados'   => $this->servico->faturasDoAluno($alunoId, $orgId, $filtros),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['sucesso' => false, 'mensagem' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /onsoft-agt/aluno/faturas/{id}?alunoId=101
     *
     * Detalhes de uma fatura específica do aluno.
     * Verifica que a fatura pertence ao aluno.
     */
    public function fatura(Request $request, int $id): JsonResponse
    {
        $alunoId = $this->resolverAlunoId($request);
        $orgId   = $this->orgId();

        try {
            return response()->json([
                'sucesso' => true,
                'dados'   => $this->servico->faturaDoAluno($id, $alunoId, $orgId),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['sucesso' => false, 'mensagem' => 'Fatura não encontrada.'], 404);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json(['sucesso' => false, 'mensagem' => $e->getMessage()], $e->getStatusCode());
        } catch (\Throwable $e) {
            return response()->json(['sucesso' => false, 'mensagem' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /onsoft-agt/aluno/faturas/{id}/pdf?alunoId=101
     *
     * PDF da fatura em stream directo para o browser.
     */
    public function pdfFatura(Request $request, int $id): Response
    {
        $alunoId = $this->resolverAlunoId($request);
        $orgId   = $this->orgId();

        // Verificar acesso antes de gerar PDF
        $this->servico->faturaDoAluno($id, $alunoId, $orgId);

        $fatura = \App\Models\Invoice\Invoice::withoutGlobalScopes()
            ->where('id', $id)
            ->where('organizationId', $orgId)
            ->firstOrFail();

        return $this->pdf->gerarStream($fatura);
    }

    /**
     * GET /onsoft-agt/aluno/faturas/{id}/pdf-base64?alunoId=101
     *
     * PDF em base64 para o frontend renderizar.
     */
    public function pdfBase64Fatura(Request $request, int $id): JsonResponse
    {
        $alunoId = $this->resolverAlunoId($request);
        $orgId   = $this->orgId();

        $this->servico->faturaDoAluno($id, $alunoId, $orgId);

        $fatura = \App\Models\Invoice\Invoice::withoutGlobalScopes()
            ->where('id', $id)
            ->where('organizationId', $orgId)
            ->firstOrFail();

        return response()->json([
            'sucesso' => true,
            'dados'   => $this->pdf->gerarBase64($fatura),
        ]);
    }

    /**
     * GET /onsoft-agt/aluno/mensalidades?alunoId=101
     *
     * Histórico de todas as mensalidades do aluno.
     * Mostra todas as turmas/classes por que passou.
     */
    public function mensalidades(Request $request): JsonResponse
    {
        $alunoId  = $this->resolverAlunoId($request);
        $orgId    = $this->orgId();
        $resultado = $this->servico->faturasDoAluno($alunoId, $orgId);

        return response()->json([
            'sucesso' => true,
            'dados'   => [
                'aluno_id'     => $alunoId,
                'mensalidades' => $resultado['mensalidades'],
            ],
        ]);
    }

    /**
     * GET /onsoft-agt/aluno/resumo?alunoId=101
     *
     * Resumo financeiro do aluno: total pago, dívida, por tipo e estado AGT.
     */
    public function resumo(Request $request): JsonResponse
    {
        $alunoId  = $this->resolverAlunoId($request);
        $orgId    = $this->orgId();
        $resultado = $this->servico->faturasDoAluno($alunoId, $orgId);

        return response()->json([
            'sucesso' => true,
            'dados'   => [
                'aluno_id'      => $alunoId,
                'resumo'        => $resultado['resumo'],
                'total_faturas' => $resultado['total_faturas'],
                'mensalidades'  => $resultado['mensalidades'],
            ],
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    // ENDPOINTS SEM alunoId — PARA O PRÓPRIO ALUNO AUTENTICADO
    // (rota /eu/ protegida com role:estudante)
    // ══════════════════════════════════════════════════════════════════

    /**
     * GET /onsoft-agt/eu/faturas
     * O aluno autenticado vê as suas próprias faturas.
     * Não aceita alunoId na query — usa sempre auth()->id().
     */
    public function minhasFaturas(Request $request): JsonResponse
    {
        $alunoId = $this->alunoAutenticadoId();
        $orgId   = $this->orgId();

        try {
            return response()->json([
                'sucesso' => true,
                'dados'   => $this->servico->faturasDoAluno($alunoId, $orgId, $this->filtros($request)),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['sucesso' => false, 'mensagem' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /onsoft-agt/eu/faturas/{id}
     */
    public function minhaFatura(int $id): JsonResponse
    {
        $alunoId = $this->alunoAutenticadoId();
        $orgId   = $this->orgId();

        try {
            return response()->json([
                'sucesso' => true,
                'dados'   => $this->servico->faturaDoAluno($id, $alunoId, $orgId),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['sucesso' => false, 'mensagem' => 'Fatura não encontrada.'], 404);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json(['sucesso' => false, 'mensagem' => $e->getMessage()], $e->getStatusCode());
        }
    }

    /**
     * GET /onsoft-agt/eu/faturas/{id}/pdf
     */
    public function minhaPdfFatura(int $id): Response
    {
        $alunoId = $this->alunoAutenticadoId();
        $orgId   = $this->orgId();

        $this->servico->faturaDoAluno($id, $alunoId, $orgId);

        $fatura = \App\Models\Invoice\Invoice::withoutGlobalScopes()
            ->where('id', $id)->where('organizationId', $orgId)->firstOrFail();

        return $this->pdf->gerarStream($fatura);
    }

    /**
     * GET /onsoft-agt/eu/faturas/{id}/pdf-base64
     */
    public function minhaPdfBase64(int $id): JsonResponse
    {
        $alunoId = $this->alunoAutenticadoId();
        $orgId   = $this->orgId();

        $this->servico->faturaDoAluno($id, $alunoId, $orgId);

        $fatura = \App\Models\Invoice\Invoice::withoutGlobalScopes()
            ->where('id', $id)->where('organizationId', $orgId)->firstOrFail();

        return response()->json(['sucesso' => true, 'dados' => $this->pdf->gerarBase64($fatura)]);
    }

    /**
     * GET /onsoft-agt/eu/mensalidades
     */
    public function minhasMensalidades(): JsonResponse
    {
        $alunoId  = $this->alunoAutenticadoId();
        $orgId    = $this->orgId();
        $resultado = $this->servico->faturasDoAluno($alunoId, $orgId);

        return response()->json([
            'sucesso' => true,
            'dados'   => ['aluno_id' => $alunoId, 'mensalidades' => $resultado['mensalidades']],
        ]);
    }

    /**
     * GET /onsoft-agt/eu/resumo
     */
    public function meuResumo(): JsonResponse
    {
        $alunoId  = $this->alunoAutenticadoId();
        $orgId    = $this->orgId();
        $resultado = $this->servico->faturasDoAluno($alunoId, $orgId);

        return response()->json([
            'sucesso' => true,
            'dados'   => [
                'aluno_id'      => $alunoId,
                'resumo'        => $resultado['resumo'],
                'total_faturas' => $resultado['total_faturas'],
                'mensalidades'  => $resultado['mensalidades'],
            ],
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    // AUXILIARES PRIVADOS
    // ══════════════════════════════════════════════════════════════════

    /**
     * Resolver o alunoId — do mesmo modo que EstudanteInfoController:
     * Lê da query string ?alunoId=X (padrão do projecto On-School).
     */
    private function resolverAlunoId(Request $request): int
    {
        $alunoId = (int) $request->query('alunoId');
        abort_if($alunoId <= 0, 422, 'alunoId é obrigatório e deve ser um inteiro positivo.');
        return $alunoId;
    }

    /**
     * Para o próprio aluno autenticado — usa auth()->id() directamente.
     * Não aceita alunoId da query string.
     */
    private function alunoAutenticadoId(): int
    {
        $id = (int) auth()->id();
        abort_if($id <= 0, 401, 'Aluno não autenticado.');
        return $id;
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

    private function filtros(Request $request): array
    {
        return [
            'de'             => $request->query('de'),
            'ate'            => $request->query('ate'),
            'document_type'  => $request->query('document_type'),
            'payment_status' => $request->query('payment_status'),
        ];
    }
}
