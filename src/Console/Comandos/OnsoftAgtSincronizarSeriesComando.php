<?php
namespace Onsoft\Agt\Console\Comandos;
use Illuminate\Console\Command;
use Onsoft\Agt\Servicos\ServicoContextoOrganizacao;
use Onsoft\Agt\Servicos\ServicoSeries;
use App\Models\Agt\OrganizationAgtConfig;

class OnsoftAgtSincronizarSeriesComando extends Command
{
    protected $signature   = 'onsoft-agt:sincronizar-series {organizacaoId? : ID da organização}';
    protected $description = 'Sincronizar séries fiscais da API AGT para a base de dados local';

    public function handle(ServicoSeries $servico): int
    {
        $orgId = $this->argument('organizacaoId');

        $orgs = $orgId
            ? OrganizationAgtConfig::withoutGlobalScopes()->where('organizationId', $orgId)->get()
            : OrganizationAgtConfig::withoutGlobalScopes()->where('agt_enabled', true)->get();

        if ($orgs->isEmpty()) {
            $this->warn('Nenhuma organização AGT activa encontrada.');
            return self::SUCCESS;
        }

        foreach ($orgs as $config) {
            $this->line("🔄 Sincronizando organização [{$config->organizationId}]...");
            try {
                $ctx      = new ServicoContextoOrganizacao($config->organizationId);
                $resultado = $servico->sincronizarDaAgt($config->organizationId, $ctx->servicoApi());
                $this->info("   ✅ {$resultado['sincronizadas']} séries sincronizadas.");
            } catch (\Throwable $e) {
                $this->error("   ✗ Erro: " . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
