<?php
namespace Onsoft\Agt\Console\Comandos;
use Illuminate\Console\Command;
use App\Models\Invoice\Invoice;
use Onsoft\Agt\Jobs\SubmeterFaturaAgtJob;
use Onsoft\Agt\Servicos\ServicoModoFaturacao;

class OnsoftAgtRetentarFalhasComando extends Command
{
    protected $signature   = 'onsoft-agt:retentar-falhas {--limite=30}';
    protected $description = 'Retentar submissão de faturas com falha à AGT';

    public function handle(): int
    {
        $limite   = (int) $this->option('limite');

        // 'failed' é um estado exclusivo do regime electronic — uma
        // fatura SAF-T nunca o atinge — mas filtramos explicitamente
        // por invoicing_mode também, como segunda linha de defesa
        // contra qualquer dado legado ou corrupção de estado.
        $faturas  = Invoice::withoutGlobalScopes()
            ->where('agt_status', 'failed')
            ->where('invoicing_mode', ServicoModoFaturacao::ELECTRONIC)
            ->limit($limite)
            ->get();

        if ($faturas->isEmpty()) {
            $this->info('Sem faturas com falha para retentar.');
            return self::SUCCESS;
        }

        $this->info("🔁 Retentando {$faturas->count()} fatura(s)...");
        $faturas->each(fn($f) => SubmeterFaturaAgtJob::dispatch($f->id));
        $this->info('✅ Jobs colocados na fila com sucesso.');

        return self::SUCCESS;
    }
}
