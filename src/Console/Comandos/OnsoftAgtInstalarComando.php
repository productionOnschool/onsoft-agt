<?php

namespace Onsoft\Agt\Console\Comandos;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class OnsoftAgtInstalarComando extends Command
{
    protected $signature   = 'onsoft-agt:instalar {--forcar : Substituir ficheiros existentes}';
    protected $description = 'Instalar e configurar o pacote Onsoft AGT de Faturação Eletrónica';

    public function handle(): int
    {
        $this->newLine();
        $this->line('╔══════════════════════════════════════════════════════╗');
        $this->line('║   Onsoft AGT — Faturação Eletrónica Angola           ║');
        $this->line('║   Desenvolvedor: Adilson Miguel                      ║');
        $this->line('║   Email: adilson2012jose@gmail.com                   ║');
        $this->line('╚══════════════════════════════════════════════════════╝');
        $this->newLine();

        // Publicar configuração
        $this->call('vendor:publish', [
            '--provider' => 'Onsoft\\Agt\\OnsoftAgtServiceProvider',
            '--tag'      => 'onsoft-agt-config',
            '--force'    => $this->option('forcar'),
        ]);

        // Publicar vistas
        $this->call('vendor:publish', [
            '--provider' => 'Onsoft\\Agt\\OnsoftAgtServiceProvider',
            '--tag'      => 'onsoft-agt-vistas',
            '--force'    => $this->option('forcar'),
        ]);

        // Executar migrações
        // Adiciona colunas exclusivo/requer_consulta_online/provider
        // à tabela tipodepagamento JÁ EXISTENTE — não cria tabela nova.
        $this->call('migrate');

        // Criar directório de chaves AGT
        $dirChaves = storage_path('app/agt');
        if (!File::exists($dirChaves)) {
            File::makeDirectory($dirChaves, 0750, true);
            $this->line("  📁 Directório criado: {$dirChaves}");
        }

        $this->newLine();
        $this->info('✅ Onsoft AGT instalado com sucesso!');
        $this->newLine();
        $this->line('  <comment>Próximos passos:</comment>');
        $this->line('  1. Configure as variáveis de ambiente em <info>.env</info>:');
        $this->line('     AGT_AMBIENTE=sandbox');
        $this->line('     AGT_MULTI_TENANT=true');
        $this->line('  2. Configure cada organização em: AGT → Configuração');
        $this->line('  3. Execute <info>php artisan onsoft-agt:estado</info> para verificar');
        $this->newLine();

        return self::SUCCESS;
    }
}
