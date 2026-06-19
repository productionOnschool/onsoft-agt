<?php

namespace Onsoft\Agt;

use Illuminate\Support\ServiceProvider;
use Onsoft\Agt\Console\Comandos\OnsoftAgtInstalarComando;
use Onsoft\Agt\Console\Comandos\OnsoftAgtStatusComando;
use Onsoft\Agt\Console\Comandos\OnsoftAgtSincronizarSeriesComando;
use Onsoft\Agt\Console\Comandos\OnsoftAgtRetentarFalhasComando;
use Onsoft\Agt\Console\Comandos\OnsoftAgtResetAnoFiscalComando;
use Onsoft\Agt\Console\Comandos\OnsoftAgtVerificarIntegridadeComando;
use Onsoft\Agt\Console\Comandos\OnsoftAgtRegenerarSnapshotsComando;
use Onsoft\Agt\Console\Comandos\OnsoftAgtConsultarSubmissoesComando;
use Onsoft\Agt\Console\Comandos\OnsoftAgtGerarChaveSoftwareComando;
use Onsoft\Agt\Servicos\ServicoAssinatura;
use Onsoft\Agt\Servicos\ServicoApiAgt;
use Onsoft\Agt\Servicos\ServicoSeries;
use Onsoft\Agt\Servicos\ServicoFatura;
use Onsoft\Agt\Servicos\ServicoPdf;
use Onsoft\Agt\Servicos\ServicoQrCode;
use Onsoft\Agt\Servicos\ServicoContextoOrganizacao;
use Onsoft\Agt\Servicos\ServicoFaturasAluno;
use Onsoft\Agt\Servicos\ServicoFaturaProforma;
use Onsoft\Agt\Servicos\ServicoValidacaoPropina;
use Onsoft\Agt\Servicos\ServicoExclusividadePagamento;
use Onsoft\Agt\Servicos\ServicoModoFaturacao;
use Onsoft\Agt\Servicos\ServicoSaftAo;
use Onsoft\Agt\Servicos\ServicoFlagsUiFatura;
use Onsoft\Agt\Servicos\ServicoViaImpressao;

class OnsoftAgtServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/onsoft-agt.php', 'onsoft-agt');

        // Serviço de assinatura criptográfica RSA
        $this->app->singleton(ServicoAssinatura::class, function () {
            return new ServicoAssinatura();
        });

        // Serviço principal da API AGT
        $this->app->singleton(ServicoApiAgt::class, function ($app) {
            return new ServicoApiAgt($app->make(ServicoAssinatura::class));
        });

        // Serviço de séries fiscais
        $this->app->singleton(ServicoSeries::class, function () {
            return new ServicoSeries();
        });

        // Serviço de faturas (criação, submissão, cancelamento)
        $this->app->singleton(ServicoFatura::class, function ($app) {
            return new ServicoFatura(
                $app->make(ServicoAssinatura::class),
                $app->make(ServicoSeries::class),
                $app->make(ServicoApiAgt::class)
            );
        });

        // Serviço de geração de PDF em memória
        $this->app->singleton(ServicoPdf::class, function () {
            return new ServicoPdf();
        });

        // Serviço de QR Code local (bacon/bacon-qr-code — sem internet)
        $this->app->singleton(ServicoQrCode::class, function () {
            return new ServicoQrCode();
        });

        // Serviço de exclusividade de métodos de pagamento (lê tipodepagamento)
        $this->app->singleton(ServicoExclusividadePagamento::class, function () {
            return new ServicoExclusividadePagamento();
        });

        // Serviço de alternância de modo de faturação (Eletrónica <-> SAF-T)
        $this->app->singleton(ServicoModoFaturacao::class, function () {
            return new ServicoModoFaturacao();
        });

        // Serviço de geração de ficheiros SAF-T(AO)
        $this->app->singleton(ServicoSaftAo::class, function () {
            return new ServicoSaftAo();
        });

        // Serviço de flags de UI por fatura (botões mostrar/desactivar)
        $this->app->singleton(ServicoFlagsUiFatura::class, function () {
            return new ServicoFlagsUiFatura();
        });

        // Serviço de via de impressão (Original vs Cópia do documento original)
        $this->app->singleton(ServicoViaImpressao::class, function () {
            return new ServicoViaImpressao();
        });

        // Serviço de validação de ordem de propinas
        $this->app->singleton(ServicoValidacaoPropina::class, function () {
            return new ServicoValidacaoPropina();
        });

        // Serviço de faturas do aluno
        $this->app->singleton(ServicoFaturasAluno::class, function () {
            return new ServicoFaturasAluno();
        });

        // Serviço de Factura Pró-forma — calcula e renderiza em
        // memória, nunca persiste nada (ver ServicoFaturaProforma)
        $this->app->singleton(ServicoFaturaProforma::class, function () {
            return new ServicoFaturaProforma();
        });

        // Serviço de limite diário de faturas
        $this->app->singleton(ServicoLimiteDiario::class, function () {
            return new ServicoLimiteDiario();
        });

        // Serviço de relatórios e estatísticas
        $this->app->singleton(ServicoRelatorios::class, function () {
            return new ServicoRelatorios();
        });
    }

    public function boot(): void
    {
        // Inicializar registo de billing morph (extensível pelo projecto)
        \Onsoft\Agt\Suporte\RegistoBillingMorph::inicializar();

        // Registar o Observer de imutabilidade fiscal
        // Bloqueia alterações a campos fiscais após emissão
        // e cria snapshot imutável automaticamente após criação
        if (class_exists(\App\Models\Invoice\Invoice::class)) {
            \App\Models\Invoice\Invoice::observe(\Onsoft\Agt\Observers\InvoiceObserver::class);
        }
        if ($this->app->runningInConsole()) {
            // Publicar configuração
            $this->publishes([
                __DIR__ . '/../config/onsoft-agt.php' => config_path('onsoft-agt.php'),
            ], 'onsoft-agt-config');

            // Publicar migrações
            $this->publishes([
                __DIR__ . '/../database/migrations/' => database_path('migrations'),
            ], 'onsoft-agt-migracoes');

            // Publicar vistas (templates PDF)
            $this->publishes([
                __DIR__ . '/../resources/views/' => resource_path('views/vendor/onsoft-agt'),
            ], 'onsoft-agt-vistas');

            $this->commands([
                OnsoftAgtInstalarComando::class,
                OnsoftAgtStatusComando::class,
                OnsoftAgtSincronizarSeriesComando::class,
                OnsoftAgtRetentarFalhasComando::class,
                OnsoftAgtResetAnoFiscalComando::class,
                OnsoftAgtVerificarIntegridadeComando::class,
                OnsoftAgtRegenerarSnapshotsComando::class,
                OnsoftAgtConsultarSubmissoesComando::class,
                OnsoftAgtGerarChaveSoftwareComando::class,
            ]);
        }

        // Agendador automático — reset ano fiscal a 1 de Janeiro às 00:01
        // Adiciona ao scheduler do Laravel sem alterar o Kernel do projecto.
        if (class_exists(\Illuminate\Console\Scheduling\Schedule::class)) {
            $this->callAfterResolving(
                \Illuminate\Console\Scheduling\Schedule::class,
                function (\Illuminate\Console\Scheduling\Schedule $schedule) {
                    $schedule->command('onsoft-agt:reset-ano-fiscal', [
                        '--todas-orgs'         => true,
                        '--fechar-anteriores'  => true,
                    ])
                    ->yearlyOn(1, 1, '00:01')  // 1 de Janeiro às 00:01
                    ->withoutOverlapping()
                    ->runInBackground()
                    ->appendOutputTo(storage_path('logs/onsoft-agt-reset-fiscal.log'));

                    // Consultar a AGT pelo estado de submissões pendentes
                    // de 5 em 5 minutos. Sem isto (ver Ronda 7 da auditoria
                    // de conformidade), faturas submetidas ficavam para
                    // sempre em agt_status='pending', mesmo depois de a
                    // AGT já ter respondido.
                    $schedule->command('onsoft-agt:consultar-submissoes', ['--limite' => 100])
                        ->everyFiveMinutes()
                        ->withoutOverlapping()
                        ->runInBackground()
                        ->appendOutputTo(storage_path('logs/onsoft-agt-consultar-submissoes.log'));
                }
            );
        }

        // Carregar migrações automaticamente
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Registar vistas do pacote
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'onsoft-agt');

        // Rotas opcionais do pacote
        if (file_exists($rotas = __DIR__ . '/../routes/onsoft-agt.php')) {
            $this->loadRoutesFrom($rotas);
        }
    }
}
