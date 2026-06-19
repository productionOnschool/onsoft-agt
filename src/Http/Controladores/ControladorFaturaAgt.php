<?php

namespace Onsoft\Agt\Http\Controladores;

use App\Models\Agt\AgtInvoiceSubmission;
use App\Models\Invoice\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Onsoft\Agt\Servicos\ServicoContextoOrganizacao;
use Onsoft\Agt\Servicos\ServicoFatura;
use Onsoft\Agt\Servicos\ServicoPdf;
use Onsoft\Agt\Servicos\ServicoSeries;

/**
 * ControladorFaturaAgt
 *
 * Controlador HTTP do pacote Onsoft AGT.
 *
 * Rotas disponíveis (ver routes/onsoft-agt.php):
 * ─────────────────────────────────────────────────
 * POST   /onsoft-agt/faturas                → criar fatura
 * POST   /onsoft-agt/faturas/pre-visualizar → pré-visualizar totais
 * GET    /onsoft-agt/faturas/{id}/pdf       → gerar PDF (stream)
 * GET    /onsoft-agt/faturas/{id}/pdf-base64 → gerar PDF (base64 para frontend)
 * POST   /onsoft-agt/faturas/{id}/submeter  → submeter à AGT
 * POST   /onsoft-agt/faturas/{id}/cancelar  → cancelar fatura
 * GET    /onsoft-agt/faturas/{id}/estado    → consultar estado na AGT
 * POST   /onsoft-agt/series/sincronizar     → sincronizar séries da AGT
 * GET    /onsoft-agt/configuracao/validar   → validar configuração AGT
 */
class ControladorFaturaAgt extends Controller
{
    public function __construct(
        private ServicoFatura  $servicoFatura,
        private ServicoPdf     $servicoPdf,
        private ServicoSeries  $servicoSeries
    ) {}

    // ══════════════════════════════════════════════════════════════════
    // CRIAR FATURA
    // ══════════════════════════════════════════════════════════════════

    public function criar(Request $request): JsonResponse
    {
        $orgId = $this->obterOrganizacaoId();

        try {
            $fatura = $this->servicoFatura->criar($request->all(), $orgId);

            return response()->json([
                'sucesso'   => true,
                'mensagem'  => 'Fatura criada com sucesso.',
                'dados'     => $fatura,
            ], 201);

        } catch (\Throwable $e) {
            return response()->json([
                'sucesso'  => false,
                'mensagem' => $e->getMessage(),
            ], 422);
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // PRÉ-VISUALIZAR TOTAIS (sem guardar)
    // ══════════════════════════════════════════════════════════════════

    public function preVisualizar(Request $request, ServicoFatura $servico): JsonResponse
    {
        $orgId = $this->obterOrganizacaoId();
        $dados = $request->all();

        try {
            $itens     = collect($dados['items'] ?? []);
            $pagamentos = collect($dados['payments'] ?? []);
            $subtotal  = 0; $iva = 0;

            foreach ($itens as $item) {
                $base    = ((float)$item['quantity'] * (float)$item['unit_price']) - (float)($item['discount_amount'] ?? 0);
                $isento  = in_array(strtoupper($item['tax_code'] ?? $item['tax_type'] ?? ''), ['ISE', 'ISENTO', 'M00']);
                $ivaItem = $isento ? 0 : round($base * ((float)($item['tax_percentage'] ?? $item['tax_rate'] ?? 0) / 100), 2);
                $subtotal += $base;
                $iva      += $ivaItem;
            }

            $grossTotal = round($subtotal + $iva, 2);
            $totalPago  = round($pagamentos->sum(fn($p) => (float)$p['amount']), 2);
            $troco      = max(0, round($totalPago - $grossTotal, 2));
            $emFalta    = max(0, round($grossTotal - $totalPago, 2));

            return response()->json([
                'sucesso' => true,
                'dados'   => [
                    'tipo_documento' => strtoupper($dados['document_type'] ?? 'FR'),
                    'subtotal'       => round($subtotal, 2),
                    'iva_total'      => round($iva, 2),
                    'gross_total'    => $grossTotal,
                    'total_pago'     => $totalPago,
                    'troco'          => $troco,
                    'em_falta'       => $emFalta,
                    'estado_pagamento' => $emFalta > 0 ? 'parcial' : ($troco > 0 ? 'excedido' : 'pago'),
                    'itens'          => $itens->values()->all(),
                ],
            ]);

        } catch (\Throwable $e) {
            return response()->json(['sucesso' => false, 'mensagem' => $e->getMessage()], 422);
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // PDF — NUNCA GUARDADO EM DISCO
    // ══════════════════════════════════════════════════════════════════

    /**
     * Gerar PDF como stream directo para o browser.
     * O frontend abre directamente com <embed> ou window.open().
     */
    public function pdf(int $id): Response
    {
        $fatura = $this->obterFatura($id);
        return $this->servicoPdf->gerarStream($fatura);
    }

    /**
     * GET /onsoft-agt/faturas/{id}/pdf-snapshot
     * PDF exclusivamente do snapshot — re-impressão com dados originais.
     * Garante que nomes/NIF do cliente não mudam em re-impressões.
     */
    public function pdfSnapshot(int $id): Response
    {
        $orgId = $this->obterOrganizacaoId();
        return $this->servicoPdf->gerarStreamDeSnapshot($id, $orgId);
    }

    /**
     * Gerar PDF como Base-64 para o frontend renderizar.
     *
     * O frontend usa:
     *   <embed src="data:application/pdf;base64,{base64}" />
     * ou:
     *   window.open('data:application/pdf;base64,' + base64)
     */
    public function pdfBase64(int $id): JsonResponse
    {
        $fatura   = $this->obterFatura($id);
        $resultado = $this->servicoPdf->gerarBase64($fatura);

        return response()->json([
            'sucesso' => true,
            'dados'   => $resultado,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    // SUBMETER À AGT
    // ══════════════════════════════════════════════════════════════════

    public function submeter(int $id): JsonResponse
    {
        $fatura = $this->obterFatura($id);

        // Bloqueio explícito ANTES de tentar — fatura SAF-T nunca chega
        // a tocar no ServicoSubmissao/API AGT.
        if (($fatura->invoicing_mode ?? 'electronic') === \Onsoft\Agt\Servicos\ServicoModoFaturacao::SAFT_AO) {
            return response()->json([
                'sucesso'  => false,
                'mensagem' => "Esta fatura foi criada em modo SAF-T(AO) e não pode ser submetida à " .
                              "Faturação Eletrónica. Reporte-a via exportação do ficheiro SAF-T " .
                              "(GET /onsoft-agt/saft/exportar).",
                'ui'       => (new \Onsoft\Agt\Servicos\ServicoFlagsUiFatura())->calcular($fatura),
            ], 422);
        }

        $ctx = new ServicoContextoOrganizacao($fatura->organizationId);

        try {
            $submissao = $ctx->servicoSubmissao()->submeter($fatura);

            return response()->json([
                'sucesso'   => true,
                'mensagem'  => $ctx->estaActivo()
                    ? 'Fatura submetida ao AGT com sucesso.'
                    : 'AGT desactivado — submissão simulada localmente.',
                'dados'     => $submissao,
                'ui'        => (new \Onsoft\Agt\Servicos\ServicoFlagsUiFatura())->calcular($fatura->fresh()),
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'sucesso'  => false,
                'mensagem' => $e->getMessage(),
                'ui'       => (new \Onsoft\Agt\Servicos\ServicoFlagsUiFatura())->calcular($fatura->fresh()),
            ], 422);
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // CORRIGIR FATURA REJEITADA — endpoint que faltava (auditoria)
    // ══════════════════════════════════════════════════════════════════

    /**
     * POST /onsoft-agt/faturas/{id}/corrigir-rejeitada
     *
     * Cria uma NOVA fatura corrigindo uma fatura rejeitada pela AGT —
     * único caminho válido, conforme documentação oficial (erro E46):
     * a AGT nunca aceita resubmissão do mesmo documentNo rejeitado.
     *
     * Body opcional: { "alteracoes": { "customer_nif": "novo NIF", ... } }
     * para corrigir o(s) campo(s) que causou(aram) a rejeição.
     */
    public function corrigirRejeitada(Request $request, int $id): JsonResponse
    {
        $orgId = $this->obterOrganizacaoId();

        try {
            $rejeitada = Invoice::withoutGlobalScopes()
                ->where('organizationId', $orgId)
                ->where('id', $id)
                ->firstOrFail();

            $novaFatura = $this->servicoFatura->corrigirFaturaRejeitada(
                $rejeitada,
                $orgId,
                $request->input('alteracoes', [])
            );

            return response()->json([
                'sucesso'  => true,
                'mensagem' => "Nova fatura {$novaFatura->document_no} criada, referenciando a rejeitada {$rejeitada->document_no}.",
                'dados'    => $novaFatura,
            ]);

        } catch (\Throwable $e) {
            return response()->json(['sucesso' => false, 'mensagem' => $e->getMessage()], 422);
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // CANCELAR
    // ══════════════════════════════════════════════════════════════════

    public function cancelar(Request $request, int $id): JsonResponse
    {
        $orgId  = $this->obterOrganizacaoId();
        $motivo = $request->input('motivo') ?? $request->input('reason', '');

        if (empty($motivo)) {
            return response()->json(['sucesso' => false, 'mensagem' => 'O motivo de cancelamento é obrigatório.'], 422);
        }

        try {
            $resultado = $this->servicoFatura->cancelar($id, $orgId, $motivo);

            return response()->json([
                'sucesso'  => true,
                'mensagem' => $resultado->document_type === 'NC'
                    ? 'Nota de Crédito emitida automaticamente.'
                    : 'Fatura cancelada localmente.',
                'dados'    => $resultado,
            ]);

        } catch (\Throwable $e) {
            return response()->json(['sucesso' => false, 'mensagem' => $e->getMessage()], 422);
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // ESTADO AGT
    // ══════════════════════════════════════════════════════════════════

    public function estado(int $id): JsonResponse
    {
        $fatura    = $this->obterFatura($id);
        $fatura    = $fatura->fresh();
        $submissao = AgtInvoiceSubmission::withoutGlobalScopes()
            ->where('organizationId', $fatura->organizationId)
            ->where('invoiceId', $id)
            ->latest()
            ->first();

        $flagsUi = (new \Onsoft\Agt\Servicos\ServicoFlagsUiFatura())->calcular($fatura);

        return response()->json([
            'sucesso' => true,
            'dados'   => [
                'fatura_id'      => $id,
                'invoicing_mode' => $fatura->invoicing_mode,
                'agt_status'     => $fatura->agt_status,
                'submissao'      => $submissao,
                'ui'             => $flagsUi,
            ],
        ]);
    }

    /**
     * GET /onsoft-agt/faturas/{id}/estado/consultar-agora
     *
     * Força uma consulta IMEDIATA à AGT pelo estado real desta
     * submissão, em vez de esperar pelo próximo ciclo do scheduler
     * (onsoft-agt:consultar-submissoes, a correr de 5 em 5 minutos).
     * Útil quando o utilizador quer confirmação rápida depois de
     * submeter manualmente uma fatura.
     */
    public function consultarEstadoAgora(int $id): JsonResponse
    {
        $fatura    = $this->obterFatura($id);
        $submissao = AgtInvoiceSubmission::withoutGlobalScopes()
            ->where('organizationId', $fatura->organizationId)
            ->where('invoiceId', $id)
            ->latest()
            ->first();

        if (!$submissao) {
            return response()->json([
                'sucesso'  => false,
                'mensagem' => 'Esta fatura ainda não foi submetida à AGT.',
            ], 422);
        }

        try {
            $ctx = new \Onsoft\Agt\Servicos\ServicoContextoOrganizacao($fatura->organizationId);
            $submissaoActualizada = $ctx->servicoSubmissao()->consultarEstado($submissao);

            return response()->json([
                'sucesso' => true,
                'dados'   => [
                    'fatura_id'      => $id,
                    'agt_status'     => $fatura->fresh()->agt_status,
                    'submissao'      => $submissaoActualizada,
                    'ui'             => (new \Onsoft\Agt\Servicos\ServicoFlagsUiFatura())->calcular($fatura->fresh()),
                ],
            ]);

        } catch (\Throwable $e) {
            return response()->json(['sucesso' => false, 'mensagem' => $e->getMessage()], 422);
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // SÉRIES — SINCRONIZAR
    // ══════════════════════════════════════════════════════════════════

    public function sincronizarSeries(): JsonResponse
    {
        $orgId     = $this->obterOrganizacaoId();
        $ctx       = new ServicoContextoOrganizacao($orgId);
        $resultado = $this->servicoSeries->sincronizarDaAgt($orgId, $ctx->servicoApi());

        return response()->json([
            'sucesso'  => empty($resultado['erros']),
            'mensagem' => "Sincronizadas {$resultado['sincronizadas']} séries.",
            'dados'    => $resultado,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    // CONFIGURAÇÃO — VALIDAR
    // ══════════════════════════════════════════════════════════════════

    public function validarConfiguracao(): JsonResponse
    {
        $orgId  = $this->obterOrganizacaoId();
        $ctx    = new ServicoContextoOrganizacao($orgId);
        $erros  = $ctx->validar();

        return response()->json([
            'sucesso'  => empty($erros),
            'valido'   => empty($erros),
            'erros'    => $erros,
            'mensagem' => empty($erros)
                ? 'Configuração AGT válida e pronta.'
                : 'Existem erros na configuração AGT.',
        ]);
    }

    /**
     * GET /onsoft-agt/propinas/mapa-meses?mensalidadeId=5&alunoId=101&anolectivoId=3&propinaAnual=540000
     *
     * Mapa completo dos meses do ano lectivo: pago, parcial, pendente —
     * com propinaMensal calculada (propinaAnual / totalMeses) e respeitando
     * a ordem sequencial de pagamento.
     */
    public function mapaMesesPropina(Request $request): JsonResponse
    {
        $request->validate([
            'mensalidadeId' => ['required', 'integer'],
            'alunoId'       => ['required', 'integer'],
            'anolectivoId'  => ['required', 'integer'],
            'propinaAnual'  => ['required', 'numeric', 'min:0'],
        ]);

        $servico = new \Onsoft\Agt\Servicos\ServicoValidacaoPropina();

        $resultado = $servico->mapaMeses(
            (int) $request->query('mensalidadeId'),
            (int) $request->query('alunoId'),
            (int) $request->query('anolectivoId'),
            (float) $request->query('propinaAnual'),
            (bool) $request->query('classComExam', false)
        );

        return response()->json([
            'sucesso' => true,
            'dados'   => $resultado,
        ]);
    }

    /**
     * GET /onsoft-agt/propinas/proximo-mes?mensalidadeId=5&alunoId=101&anolectivoId=3
     *
     * Devolve o próximo mês que o aluno deve pagar, respeitando a ordem.
     * Útil para o frontend pré-seleccionar o mês certo automaticamente.
     */
    public function proximoMesPropina(Request $request): JsonResponse
    {
        $request->validate([
            'mensalidadeId' => ['required', 'integer'],
            'alunoId'       => ['required', 'integer'],
            'anolectivoId'  => ['required', 'integer'],
        ]);

        $servico = new \Onsoft\Agt\Servicos\ServicoValidacaoPropina();

        $proximo = $servico->proximoMesAPagar(
            (int) $request->query('mensalidadeId'),
            (int) $request->query('alunoId'),
            (int) $request->query('anolectivoId'),
            (bool) $request->query('classComExam', false)
        );

        return response()->json([
            'sucesso' => true,
            'dados'   => [
                'proximo_mes'      => $proximo,
                'todos_meses_pagos' => $proximo === null,
            ],
        ]);
    }

    /**
     * POST /onsoft-agt/propinas/validar-ordem
     *
     * Pré-validar (sem criar nada) se um conjunto de meses pode ser pago
     * respeitando a ordem sequencial. Usado pelo frontend antes de
     * submeter a fatura, para dar feedback imediato ao utilizador.
     */
    public function validarOrdemPropina(Request $request): JsonResponse
    {
        $request->validate([
            'mensalidadeId' => ['required', 'integer'],
            'alunoId'       => ['required', 'integer'],
            'anolectivoId'  => ['required', 'integer'],
            'mesIds'        => ['required', 'array', 'min:1'],
            'mesIds.*'      => ['integer'],
        ]);

        $servico = new \Onsoft\Agt\Servicos\ServicoValidacaoPropina();

        $resultado = $servico->podePagar(
            (int) $request->input('mensalidadeId'),
            (int) $request->input('alunoId'),
            (int) $request->input('anolectivoId'),
            $request->input('mesIds'),
            (bool) $request->input('classComExam', false)
        );

        return response()->json([
            'sucesso' => $resultado['pode_pagar'],
            'dados'   => $resultado,
        ]);
    }

    /**
     * GET /onsoft-agt/faturas/{id}/historico-impressao
     *
     * Histórico de todas as gerações do PDF desta fatura — quem viu,
     * quando, e se foi a via Original ou uma Cópia do documento original.
     */
    public function historicoImpressao(int $id): JsonResponse
    {
        $fatura  = $this->obterFatura($id);
        $servico = new \Onsoft\Agt\Servicos\ServicoViaImpressao();

        return response()->json([
            'sucesso' => true,
            'dados'   => [
                'fatura_id'      => $id,
                'ja_tem_original' => $servico->jaTemOriginal($id),
                'historico'       => $servico->historico($id),
            ],
        ]);
    }

    /**
     * GET /onsoft-agt/faturas/flags-ui?ids=101,102,103
     *
     * Devolve as flags de UI (regime, botões a mostrar/desactivar) para
     * múltiplas faturas de uma vez — usado pela tabela/listagem do
     * frontend para desenhar badges e desactivar botões sem 1 pedido
     * por linha.
     */
    public function flagsUiEmMassa(Request $request): JsonResponse
    {
        $request->validate(['ids' => ['required', 'string']]);

        $orgId = $this->obterOrganizacaoId();
        $ids   = array_filter(array_map('intval', explode(',', $request->query('ids'))));

        $faturas = Invoice::withoutGlobalScopes()
            ->where('organizationId', $orgId)
            ->whereIn('id', $ids)
            ->get();

        $servico = new \Onsoft\Agt\Servicos\ServicoFlagsUiFatura();

        return response()->json([
            'sucesso' => true,
            'dados'   => $servico->calcularParaColecao($faturas),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    // AUXILIARES PRIVADOS
    // ══════════════════════════════════════════════════════════════════

    private function obterOrganizacaoId(): int
    {
        $orgId = (int) (
            auth()->user()?->organizationId
            ?? (app()->bound('currentOrganizationId') ? app('currentOrganizationId') : 0)
        );

        abort_if($orgId <= 0, 403, 'Organização não definida na sessão.');

        return $orgId;
    }

    private function obterFatura(int $id): Invoice
    {
        $orgId = $this->obterOrganizacaoId();

        return Invoice::withoutGlobalScopes()
            ->where('organizationId', $orgId)
            ->where('id', $id)
            ->firstOrFail();
    }
}
