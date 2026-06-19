<?php

namespace Onsoft\Agt\Servicos;

use App\Models\Invoice\OrganizationInvoiceDailyLimit;
use App\Models\Invoice\Invoice;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Onsoft\Agt\Excecoes\ExcecaoFaturaAgt;

/**
 * ServicoLimiteDiario
 *
 * Controla o limite diário de emissão de faturas por organização.
 *
 * REGRAS:
 * ─────────────────────────────────────────────────────────────
 * 1. Só activo quando Organization.appCode = true (licença activa)
 * 2. Lê o limite da tabela organization_invoice_daily_limits
 * 3. Conta faturas emitidas hoje (excluindo cancelled/draft)
 * 4. Se exceder → lança ExcecaoLimiteDiarioExcedido com detalhes
 * 5. Se appCode = false → lança ExcecaoLicencaInactiva
 * 6. Incrementa o contador de forma thread-safe (lockForUpdate)
 *
 * TABELA: organization_invoice_daily_limits
 * ─────────────────────────────────────────────────────────────
 * - max_daily_invoices        → limite máximo por dia
 * - current_daily_count       → contador actual (hoje)
 * - date_reference            → data de referência
 * - is_active                 → limite activo ou não
 * - allow_preview_when_blocked → permite pré-visualização quando bloqueado
 * - block_types_json          → tipos de doc bloqueados (ex: ["FR","FT"])
 * - blocked_message           → mensagem personalizada quando bloqueado
 */
class ServicoLimiteDiario
{
    /**
     * Verificar se a organização pode emitir mais faturas hoje.
     *
     * Chamar ANTES de criar a fatura.
     *
     * @param int    $organizacaoId
     * @param string $tipoDocumento  Tipo de documento (FT, FR, NC, etc.)
     * @throws ExcecaoFaturaAgt      Se limite excedido ou licença inactiva
     */
    public function verificar(int $organizacaoId, string $tipoDocumento = 'FR'): void
    {
        // 1. Verificar licença (appCode da organização)
        $this->verificarLicenca($organizacaoId);

        // 2. Verificar limite diário
        $this->verificarLimiteDiario($organizacaoId, $tipoDocumento);
    }

    /**
     * Incrementar o contador diário após criação bem-sucedida de fatura.
     * Thread-safe com lockForUpdate.
     *
     * Chamar APÓS criar a fatura com sucesso.
     */
    public function incrementar(int $organizacaoId): void
    {
        DB::transaction(function () use ($organizacaoId) {
            $limite = OrganizationInvoiceDailyLimit::withoutGlobalScopes()
                ->where('organizationId', $organizacaoId)
                ->where('date_reference', today()->toDateString())
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if ($limite) {
                $limite->increment('current_daily_count');
            }
        });
    }

    /**
     * Obter o estado actual do limite diário para uma organização.
     */
    public function estado(int $organizacaoId): array
    {
        $org = Organization::find($organizacaoId);

        $limite = OrganizationInvoiceDailyLimit::withoutGlobalScopes()
            ->where('organizationId', $organizacaoId)
            ->where('date_reference', today()->toDateString())
            ->first();

        // Contar faturas emitidas hoje (real, da BD)
        $emitidas = Invoice::withoutGlobalScopes()
            ->where('organizationId', $organizacaoId)
            ->whereDate('issued_at', today())
            ->whereNotIn('payment_status', ['cancelled'])
            ->whereNotIn('agt_status', ['draft', 'cancelled'])
            ->count();

        $licencaActiva = (bool) ($org?->appCode ?? false);
        $limiteActivo  = $limite && $limite->is_active;
        $maximo        = $limiteActivo ? (int) $limite->max_daily_invoices : null;
        $disponivel    = $maximo !== null ? max(0, $maximo - $emitidas) : null;

        return [
            'licenca_activa'    => $licencaActiva,
            'limite_activo'     => $limiteActivo,
            'data_referencia'   => today()->toDateString(),
            'emitidas_hoje'     => $emitidas,
            'maximo_diario'     => $maximo,
            'disponivel_hoje'   => $disponivel,
            'percentagem_uso'   => $maximo ? round(($emitidas / $maximo) * 100, 1) : null,
            'bloqueado'         => $limiteActivo && $emitidas >= $maximo,
            'mensagem_bloqueio' => $limite?->blocked_message,
            'tipos_bloqueados'  => $limite?->block_types_json ?? [],
        ];
    }

    /**
     * Repor o contador diário (para quando muda o dia).
     * Chamado pelo scheduler diariamente às 00:00.
     */
    public function reporContadorDiario(int $organizacaoId): void
    {
        OrganizationInvoiceDailyLimit::withoutGlobalScopes()
            ->where('organizationId', $organizacaoId)
            ->update([
                'current_daily_count' => 0,
                'date_reference'      => today()->toDateString(),
            ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // Privado
    // ──────────────────────────────────────────────────────────────────

    private function verificarLicenca(int $organizacaoId): void
    {
        $org = Organization::find($organizacaoId);

        if (!$org) {
            throw new ExcecaoFaturaAgt("Organização [{$organizacaoId}] não encontrada.");
        }

        // appCode = false → licença inactiva → não pode emitir faturas
        if (!(bool) $org->appCode) {
            throw new ExcecaoFaturaAgt(
                "LICENÇA INACTIVA — A organização '{$org->nome_comercial}' " .
                "não tem licença activa (appCode = false). " .
                "Contacte o suporte Onsoft para activar: adilson2012jose@gmail.com"
            );
        }
    }

    private function verificarLimiteDiario(int $organizacaoId, string $tipoDocumento): void
    {
        $limite = OrganizationInvoiceDailyLimit::withoutGlobalScopes()
            ->where('organizationId', $organizacaoId)
            ->where('date_reference', today()->toDateString())
            ->where('is_active', true)
            ->first();

        // Sem limite configurado → permitir
        if (!$limite) {
            return;
        }

        // Verificar se este tipo de documento está bloqueado
        $tiposBloqueados = $limite->block_types_json ?? [];
        if (!empty($tiposBloqueados) && in_array($tipoDocumento, $tiposBloqueados, true)) {
            throw new ExcecaoFaturaAgt(
                "LIMITE DIÁRIO — O tipo de documento '{$tipoDocumento}' " .
                "está bloqueado para hoje. " .
                ($limite->blocked_message ?? 'Contacte a administração.')
            );
        }

        // Contar faturas emitidas hoje (contagem real da BD)
        $emitidas = Invoice::withoutGlobalScopes()
            ->where('organizationId', $organizacaoId)
            ->whereDate('issued_at', today())
            ->whereNotIn('payment_status', ['cancelled'])
            ->count();

        if ($emitidas >= $limite->max_daily_invoices) {
            throw new ExcecaoFaturaAgt(
                "LIMITE DIÁRIO EXCEDIDO — Foram emitidas {$emitidas} de " .
                "{$limite->max_daily_invoices} faturas permitidas hoje " .
                "(" . today()->format('d/m/Y') . "). " .
                ($limite->blocked_message ?? 'O limite diário foi atingido. Tente amanhã ou contacte a administração.')
            );
        }
    }
}
