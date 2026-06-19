<?php

namespace Onsoft\Agt\Servicos;

use Illuminate\Support\Facades\DB;

/**
 * ServicoExclusividadePagamento
 *
 * Serviço leve de apoio — NÃO duplica nenhuma tabela do projecto.
 *
 * Lê directamente de `tipodepagamento` (App\Models\Config\Financeiro\TipoDePagamento)
 * usando as colunas `exclusivo`, `requer_consulta_online` e `provider`
 * adicionadas pela migração 2024_01_01_000004_add_exclusivity_to_tipodepagamento.
 *
 * O ciclo de vida real do pagamento online (criar referência, webhook,
 * criação atómica da fatura) já está implementado no projecto em:
 *   - App\Services\Payment\OnlinePaymentIntentService
 *   - App\Http\Controllers\PaymentProvider\ProxyPayWebhookController
 *
 * Este serviço apenas acrescenta a validação de EXCLUSIVIDADE que
 * faltava — sem reinventar nada que já existe e funciona.
 */
class ServicoExclusividadePagamento
{
    /**
     * Verificar se uma combinação de métodos de pagamento é válida.
     *
     * @param array $payments  [['tipodepagamentoId' => 1005, 'amount' => 50000], ...]
     *                          (ou 'appCode' como alias de tipodepagamentoId)
     */
    public function validar(array $payments): array
    {
        if (count($payments) <= 1) {
            return ['valido' => true, 'erro' => null];
        }

        $appCodes = collect($payments)
            ->map(fn($p) => (int) ($p['tipodepagamentoId'] ?? $p['appCode'] ?? 0))
            ->filter(fn($c) => $c > 0)
            ->unique();

        if ($appCodes->isEmpty()) {
            return ['valido' => true, 'erro' => null];
        }

        $tipos = $this->tiposPorAppCode($appCodes->all());

        $exclusivos = $appCodes->filter(fn($c) => (bool) ($tipos->get($c)?->exclusivo ?? false));

        if ($exclusivos->isEmpty()) {
            return ['valido' => true, 'erro' => null];
        }

        $nomes = $exclusivos->map(fn($c) => $tipos->get($c)?->name ?? "appCode {$c}")->implode(', ');

        return [
            'valido' => false,
            'erro'   => "'{$nomes}' é um método de pagamento exclusivo e não pode ser combinado com outros métodos na mesma fatura.",
        ];
    }

    /**
     * Verificar se um único appCode é exclusivo e requer consulta online.
     */
    public function ehExclusivoOnline(int $appCode): bool
    {
        $tipo = $this->tiposPorAppCode([$appCode])->get($appCode);
        return (bool) ($tipo?->exclusivo ?? false) && (bool) ($tipo?->requer_consulta_online ?? false);
    }

    /**
     * Obter o nome do provider configurado para um appCode
     * (ex: 'proxypay'), usado para resolver o OrganizationPaymentConfig certo.
     */
    public function providerDoAppCode(int $appCode): ?string
    {
        return $this->tiposPorAppCode([$appCode])->get($appCode)?->provider;
    }

    /**
     * Listar todos os métodos de pagamento (vindos de tipodepagamento)
     * com a respectiva informação de exclusividade — útil para o
     * frontend construir o selector dinamicamente.
     */
    public function listarTodos(): \Illuminate\Support\Collection
    {
        return cache()->remember('onsoft_agt_tipodepagamento_lista', 300, function () {
            return DB::table('tipodepagamento')
                ->select(['id', 'appCode', 'name', 'info', 'status', 'exclusivo', 'requer_consulta_online', 'provider'])
                ->orderBy('appCode')
                ->get();
        });
    }

    public static function invalidarCache(): void
    {
        cache()->forget('onsoft_agt_tipodepagamento_lista');
        cache()->forget('onsoft_agt_tipodepagamento_todos');
    }

    private function tiposPorAppCode(array $appCodes): \Illuminate\Support\Collection
    {
        return DB::table('tipodepagamento')
            ->whereIn('appCode', $appCodes)
            ->get()
            ->keyBy('appCode');
    }
}
