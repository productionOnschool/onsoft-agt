<?php
namespace Onsoft\Agt\Console\Comandos;
use Illuminate\Console\Command;
use App\Models\Agt\OrganizationAgtConfig;
use App\Models\Invoice\Invoice;
use Onsoft\Agt\Servicos\ServicoModoFaturacao;

class OnsoftAgtStatusComando extends Command
{
    protected $signature   = 'onsoft-agt:estado';
    protected $description = 'Verificar estado da configuração Onsoft AGT';

    public function handle(): int
    {
        $this->info('🔍 Onsoft AGT — Estado do Sistema');
        $this->newLine();

        $modoServico = new ServicoModoFaturacao();
        $configs     = OrganizationAgtConfig::withoutGlobalScopes()->get()->keyBy('organizationId');

        // Incluir TAMBÉM organizações que têm faturas mas nunca tiveram
        // nenhuma configuração AGT — estas estão em modo SAF-T(AO) por
        // default automático (ver ServicoModoFaturacao::modoActual()) e
        // ficavam invisíveis neste comando antes desta correcção.
        $orgIdsComFaturas = Invoice::withoutGlobalScopes()->distinct()->pluck('organizationId');
        $todosOrgIds      = $configs->keys()->merge($orgIdsComFaturas)->unique()->values();

        $rows = $todosOrgIds->map(function ($orgId) use ($configs, $modoServico) {
            $c    = $configs->get($orgId);
            $modo = $modoServico->modoActual($orgId);

            return [
                $orgId,
                $c?->tax_registration_number ?? '—',
                $c?->environment ?? '—',
                $modo === ServicoModoFaturacao::SAFT_AO
                    ? '<fg=yellow>SAF-T(AO)</>'
                    : ($c?->agt_enabled ? '<fg=green>Electronic</>' : '<fg=red>Inactivo</>'),
                !empty($c?->taxpayer_private_key_encrypted) ? '<fg=green>✓</>' : '<fg=red>✗</>',
                !empty($c?->software_private_key_encrypted) ? '<fg=green>✓</>' : '<fg=red>✗</>',
                Invoice::withoutGlobalScopes()->where('organizationId', $orgId)->count(),
                $c === null ? '<fg=yellow>sem config — default automático</>' : '',
            ];
        })->toArray();

        $this->table(
            ['Org ID', 'NIF', 'Ambiente', 'Modo', 'Chave Contribuinte', 'Chave Software', 'Faturas', 'Nota'],
            $rows
        );

        return self::SUCCESS;
    }
}
