<?php

namespace Onsoft\Agt\Http\Controladores;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Onsoft\Agt\Servicos\ServicoExclusividadePagamento;

/**
 * ControladorMetodosPagamento
 *
 * Endpoints leves que se apoiam na infra-estrutura REAL já existente
 * no projecto (TipoDePagamento, OnlinePaymentIntent, ProxyPayService,
 * OnlinePaymentIntentService). Não duplica staging nem tabelas.
 *
 * Para o fluxo completo de pagamento online (criar referência,
 * webhook, criação da fatura), continue a usar:
 *   POST /api/payment-references          → PaymentReferenceController::store
 *   POST /api/payment-references/{id}/cancel
 *   POST /webhooks/proxypay                → ProxyPayWebhookController::handle
 *
 * Este controlador só acrescenta:
 *   GET  /onsoft-agt/metodos-pagamento              → listar com exclusivo/provider
 *   POST /onsoft-agt/metodos-pagamento/validar      → validar combinação antes de submeter
 */
class ControladorMetodosPagamento extends Controller
{
    public function __construct(private ServicoExclusividadePagamento $servico) {}

    /**
     * GET /onsoft-agt/metodos-pagamento
     *
     * Lista os métodos de `tipodepagamento` com a coluna `exclusivo`
     * já resolvida — para o frontend desenhar o selector.
     */
    public function listar(): JsonResponse
    {
        return response()->json([
            'sucesso' => true,
            'dados'   => $this->servico->listarTodos(),
        ]);
    }

    /**
     * POST /onsoft-agt/metodos-pagamento/validar
     *
     * Body: { "payments": [{ "tipodepagamentoId": 1005, "amount": 50000 }] }
     *
     * Valida SEM criar nada — feedback imediato ao frontend antes de
     * chamar o fluxo real (PaymentReferenceController / InvoiceController).
     */
    public function validar(Request $request): JsonResponse
    {
        $request->validate(['payments' => ['required', 'array', 'min:1']]);

        $resultado = $this->servico->validar($request->input('payments'));

        return response()->json([
            'sucesso' => $resultado['valido'],
            'dados'   => $resultado,
        ]);
    }
}
