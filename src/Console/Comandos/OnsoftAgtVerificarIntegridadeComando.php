<?php

namespace Onsoft\Agt\Console\Comandos;

use Illuminate\Console\Command;
use App\Models\Invoice\Invoice;
use Onsoft\Agt\Servicos\InvoiceSnapshotGuard;

class OnsoftAgtVerificarIntegridadeComando extends Command
{
    protected $signature = 'onsoft-agt:verificar-integridade
                            {--organizacaoId= : ID da organização}
                            {--desde= : Data de início (YYYY-MM-DD)}
                            {--ate= : Data de fim (YYYY-MM-DD)}';

    protected $description = 'Verificar integridade dos snapshots fiscais (detecta alterações não autorizadas)';

    public function handle(): int
    {
        $this->info('🔍 OnsoftAgt — Verificação de Integridade Fiscal');
        $this->newLine();

        // CORRIGIDO: o filtro anterior (whereNotNull('invoice_hash'))
        // só verificava faturas em modo 'electronic' — faturas SAF-T
        // (que nunca têm invoice_hash, mas TÊM snapshot e precisam da
        // mesma protecção de imutabilidade) ficavam invisíveis a esta
        // verificação. Agora verificamos qualquer fatura emitida
        // (issued_at preenchido), independentemente do regime — o
        // próprio InvoiceSnapshotGuard::verificarIntegridade() já
        // sabe lidar correctamente com a ausência de hash em SAF-T.
        $query = Invoice::withoutGlobalScopes()->whereNotNull('issued_at');

        if ($orgId = $this->option('organizacaoId')) {
            $query->where('organizationId', (int) $orgId);
        }

        if ($desde = $this->option('desde')) {
            $query->where('issued_at', '>=', $desde);
        }

        if ($ate = $this->option('ate')) {
            $query->where('issued_at', '<=', $ate . ' 23:59:59');
        }

        $total    = $query->count();
        $problemas = 0;

        $this->line("  Total de faturas a verificar: {$total}");
        $this->newLine();

        $query->chunk(100, function ($faturas) use (&$problemas) {
            foreach ($faturas as $fatura) {
                $resultado = InvoiceSnapshotGuard::verificarIntegridade($fatura);

                if (!$resultado['integro']) {
                    $problemas++;
                    $this->error("  ✗ {$resultado['document_no']} (ID: {$resultado['invoice_id']})");
                    foreach ($resultado['problemas'] as $problema) {
                        $this->line("    → {$problema}");
                    }
                }
            }
        });

        $this->newLine();

        if ($problemas === 0) {
            $this->info("  ✅ Todas as {$total} faturas estão íntegras.");
        } else {
            $this->error("  ✗ {$problemas} fatura(s) com problemas de integridade detectados.");
            $this->warn('  ATENÇÃO: Reportar imediatamente ao responsável fiscal.');
        }

        return $problemas === 0 ? self::SUCCESS : self::FAILURE;
    }
}
