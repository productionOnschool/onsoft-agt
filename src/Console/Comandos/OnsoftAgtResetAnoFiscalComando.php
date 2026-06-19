<?php

namespace Onsoft\Agt\Console\Comandos;

use Illuminate\Console\Command;
use App\Models\Agt\AgtSeries;
use App\Models\Agt\OrganizationAgtConfig;
use Onsoft\Agt\Servicos\ServicoSeries;
use Illuminate\Support\Facades\DB;

class OnsoftAgtResetAnoFiscalComando extends Command
{
    protected $signature = 'onsoft-agt:reset-ano-fiscal
                            {ano? : Ano fiscal a inicializar (padrão: ano actual)}
                            {--fechar-anteriores : Fechar séries do ano anterior}
                            {--todas-orgs : Executar para todas as organizações activas}
                            {--organizacaoId= : Executar apenas para esta organização}';

    protected $description = 'Inicializar séries do novo ano fiscal e fechar séries do ano anterior';

    public function handle(ServicoSeries $servico): int
    {
        $ano = (int) ($this->argument('ano') ?? now()->year);

        $this->newLine();
        $this->line("╔══════════════════════════════════════════════════════╗");
        $this->line("║   OnsoftAgt — Reset Ano Fiscal {$ano}                  ║");
        $this->line("╚══════════════════════════════════════════════════════╝");
        $this->newLine();

        // Determinar organizações a processar
        if ($this->option('organizacaoId')) {
            $orgIds = [(int) $this->option('organizacaoId')];
        } elseif ($this->option('todas-orgs')) {
            $orgIds = $this->todasOrganizacoesComFaturacao();
        } else {
            // Perguntar ao utilizador
            $orgId = $this->ask('ID da organização (ou "todas" para todas as organizações activas)');

            if (strtolower($orgId) === 'todas') {
                $orgIds = $this->todasOrganizacoesComFaturacao();
            } else {
                $orgIds = [(int) $orgId];
            }
        }

        if (empty($orgIds)) {
            $this->warn('Nenhuma organização encontrada.');
            return self::SUCCESS;
        }

        $this->info("Organizações a processar: " . implode(', ', $orgIds));
        $this->newLine();

        foreach ($orgIds as $orgId) {
            $this->processarOrganizacao($orgId, $ano, $servico);
        }

        $this->newLine();
        $this->info('✅ Reset do ano fiscal concluído.');

        return self::SUCCESS;
    }

    /**
     * Identificar TODAS as organizações que precisam de séries fiscais
     * para o novo ano — não apenas as com agt_enabled=true.
     *
     * Inclui:
     * 1. Organizações com OrganizationAgtConfig.agt_enabled = true
     *    (regime electronic configurado)
     * 2. Organizações com QUALQUER fatura emitida (independentemente
     *    de terem config — uma organização em modo SAF-T(AO) por
     *    default automático, sem nenhuma configuração AGT, ainda
     *    precisa de séries fiscais válidas para o novo ano; sem isto,
     *    o reset automático do scheduler (1 de Janeiro) ignorava-as
     *    silenciosamente e as séries nunca avançavam de ano).
     */
    private function todasOrganizacoesComFaturacao(): array
    {
        $comConfigActiva = OrganizationAgtConfig::withoutGlobalScopes()
            ->where('agt_enabled', true)
            ->pluck('organizationId');

        $comFaturasEmitidas = \App\Models\Invoice\Invoice::withoutGlobalScopes()
            ->distinct()
            ->pluck('organizationId');

        return $comConfigActiva->merge($comFaturasEmitidas)->unique()->values()->toArray();
    }

    private function processarOrganizacao(int $orgId, int $ano, ServicoSeries $servico): void
    {
        $this->line("  🏢 Organização [{$orgId}]...");

        DB::transaction(function () use ($orgId, $ano, $servico) {

            // 1. Fechar séries do ano ANTERIOR (AGT spec: nunca apagar, só inactivar)
            if ($this->option('fechar-anteriores') || $this->confirm("  Fechar séries do ano " . ($ano - 1) . " para org [{$orgId}]?", true)) {
                $fechadas = AgtSeries::withoutGlobalScopes()
                    ->where('organizationId', $orgId)
                    ->where('fiscal_year', $ano - 1)
                    ->where('active', true)
                    ->update([
                        'active'      => false,
                        'agt_payload' => DB::raw("JSON_SET(COALESCE(agt_payload, '{}'), '$.fechada_em', '" . now()->toDateTimeString() . "', '$.motivo_fecho', 'Reset ano fiscal {$ano}')"),
                    ]);

                $this->line("     ✓ {$fechadas} séries do ano " . ($ano - 1) . " fechadas.");
            }

            // 2. Criar séries para o NOVO ano
            $criadas = $servico->inicializarSeriesAnoFiscal($orgId, $ano);
            $this->line("     ✓ " . count($criadas) . " séries criadas/confirmadas para {$ano}: " .
                implode(', ', array_map(fn($s) => $s->document_type, $criadas)));
        });
    }
}
