<?php

namespace Onsoft\Agt\Console\Comandos;

use App\Models\Invoice\Invoice;
use App\Models\Invoice\InvoiceSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Onsoft\Agt\Observers\InvoiceObserver;

/**
 * OnsoftAgtRegenerarSnapshotsComando
 *
 * ══════════════════════════════════════════════════════════════════════
 * CONTEXTO — PORQUÊ ESTE COMANDO EXISTE
 * ══════════════════════════════════════════════════════════════════════
 * A v1.14.4 corrigiu uma falha em que InvoiceObserver::created()
 * construía o snapshot imutável de uma fatura ANTES de os itens e
 * pagamentos existirem na base de dados — porque o evento `created`
 * do Eloquent dispara imediatamente após o INSERT, dentro da mesma
 * transacção, não depois do commit.
 *
 * Consequência: TODAS as faturas criadas com versões anteriores à
 * v1.14.4 têm snapshots com os arrays `items` e `payments` vazios.
 *
 * Este comando identifica esses snapshots incompletos e oferece
 * regenerá-los a partir dos dados ACTUAIS da fatura — com avisos
 * claros sobre o que isso implica.
 *
 * ══════════════════════════════════════════════════════════════════════
 * ⚠️ IMPORTANTE — LIMITAÇÃO DESTA REGENERAÇÃO
 * ══════════════════════════════════════════════════════════════════════
 * Um snapshot existe para preservar os dados EXACTOS do momento da
 * emissão, para fins de auditoria e reimpressão fiel. Regenerá-lo
 * a partir dos dados actuais da fatura SIGNIFICA usar os valores
 * ACTUAIS, não os originais — que nunca foram capturados, porque o
 * snapshot original estava vazio.
 *
 * Para faturas em modo 'electronic': isto é geralmente seguro, porque
 * o InvoiceSnapshotGuard (ainda que a verificar um snapshot vazio)
 * continuava a bloquear alterações a campos imutáveis com base em
 * invoice_hash != null — a fatura estava protegida por essa via.
 *
 * Para faturas em modo 'saft_ao' criadas ANTES da v1.14.3: a
 * protecção de imutabilidade NÃO existia (nem por snapshot nem por
 * hash). Isto significa que os dados actuais dessas faturas PODEM
 * já ter divergido dos valores originais de emissão, sem qualquer
 * registo de quando ou como. A regeneração para essas faturas
 * reflecte os dados como estão HOJE — não uma garantia do que
 * foram no momento da emissão original.
 *
 * Este comando assinala explicitamente este cenário (ver coluna
 * "risco" na tabela de saída) e regista a regeneração com timestamp
 * e motivo no próprio payload do novo snapshot, para que a auditoria
 * nunca confunda um snapshot regenerado com um original genuíno.
 */
class OnsoftAgtRegenerarSnapshotsComando extends Command
{
    protected $signature = 'onsoft-agt:regenerar-snapshots
                            {--organizacaoId= : Limitar a uma organização}
                            {--apenas-detectar : Só listar, não regenerar nada}
                            {--forcar : Regenerar sem pedir confirmação interactiva}';

    protected $description = 'Detectar e regenerar snapshots vazios criados antes da correcção v1.14.4';

    public function handle(): int
    {
        $this->info('🔍 OnsoftAgt — Detecção de Snapshots Incompletos (regressão pré-v1.14.4)');
        $this->newLine();

        $snapshotsQuery = InvoiceSnapshot::withoutGlobalScopes();

        if ($orgId = $this->option('organizacaoId')) {
            $snapshotsQuery->where('organizationId', (int) $orgId);
        }

        $candidatos = [];

        $snapshotsQuery->chunk(200, function ($snapshots) use (&$candidatos) {
            foreach ($snapshots as $snapshot) {
                $payload = json_decode($snapshot->payload_json, true);
                $itemsVazios    = empty($payload['items'] ?? []);
                $paymentsVazios = empty($payload['payments'] ?? []);

                if ($itemsVazios || $paymentsVazios) {
                    $candidatos[] = [
                        'snapshot_id'     => $snapshot->id,
                        'invoice_id'      => $snapshot->invoiceId,
                        'organizationId'  => $snapshot->organizationId,
                        'items_vazios'    => $itemsVazios,
                        'payments_vazios' => $paymentsVazios,
                    ];
                }
            }
        });

        if (empty($candidatos)) {
            $this->info('✅ Nenhum snapshot incompleto encontrado. Nada a regenerar.');
            return self::SUCCESS;
        }

        $this->warn("Encontrados " . count($candidatos) . " snapshot(s) incompleto(s):");
        $this->newLine();

        $linhas = [];
        foreach ($candidatos as $c) {
            $fatura = Invoice::withoutGlobalScopes()->find($c['invoice_id']);
            $modo   = $fatura?->invoicing_mode ?? 'desconhecido';

            // Risco maior para faturas SAF-T pré-v1.14.3 — nunca tiveram
            // qualquer protecção de imutabilidade, por isso os dados
            // actuais podem genuinamente ter divergido dos originais.
            $risco = $modo === 'saft_ao'
                ? '<fg=red>ALTO — sem protecção histórica, dados podem ter mudado</>'
                : '<fg=yellow>BAIXO — protegida por invoice_hash desde a emissão</>';

            $linhas[] = [
                $c['invoice_id'],
                $fatura?->document_no ?? '(fatura não encontrada)',
                $modo,
                $c['items_vazios'] ? 'SIM' : 'não',
                $c['payments_vazios'] ? 'SIM' : 'não',
                $risco,
            ];
        }

        $this->table(
            ['Invoice ID', 'Documento', 'Modo', 'Items vazios', 'Payments vazios', 'Risco da regeneração'],
            $linhas
        );

        if ($this->option('apenas-detectar')) {
            $this->newLine();
            $this->info('Modo --apenas-detectar: nenhuma alteração foi feita.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn(
            'ATENÇÃO: regenerar um snapshot substitui o registo imutável actual ' .
            'pelos dados ACTUAIS da fatura — não pelos dados originais da emissão, ' .
            'que nunca foram capturados correctamente. Para faturas SAF-T sem ' .
            'protecção histórica, os dados actuais podem já ter divergido dos ' .
            'originais sem qualquer registo disso.'
        );

        if (!$this->option('forcar') && !$this->confirm('Continuar com a regeneração?', false)) {
            $this->info('Operação cancelada pelo utilizador.');
            return self::SUCCESS;
        }

        $regenerados = 0;
        $falhas      = 0;

        foreach ($candidatos as $c) {
            $fatura = Invoice::withoutGlobalScopes()->find($c['invoice_id']);

            if (!$fatura) {
                $this->error("  ✗ Invoice ID {$c['invoice_id']} não encontrada — snapshot orfão ignorado.");
                $falhas++;
                continue;
            }

            try {
                DB::transaction(function () use ($fatura, $c) {
                    // Remover o snapshot incompleto e recriar do zero.
                    InvoiceSnapshot::withoutGlobalScopes()
                        ->where('id', $c['snapshot_id'])
                        ->delete();

                    $fatura->refresh();
                    InvoiceObserver::criarSnapshotAgora($fatura);

                    // Marcar o novo snapshot como regenerado — em PHP,
                    // não em SQL bruto (mais seguro e portável entre
                    // motores de BD que o JSON_SET específico do MySQL).
                    // Nunca confundir com um snapshot original genuíno.
                    $novoSnapshot = InvoiceSnapshot::withoutGlobalScopes()
                        ->where('organizationId', $fatura->organizationId)
                        ->where('invoiceId', $fatura->id)
                        ->first();

                    if ($novoSnapshot) {
                        $payload = json_decode($novoSnapshot->payload_json, true) ?? [];
                        $payload['_snapshot_meta']['regenerado_em']     = now()->toDateTimeString();
                        $payload['_snapshot_meta']['regenerado_motivo'] = 'snapshot_original_vazio_pre_v1.14.4';

                        $novoJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                        $novoSnapshot->update([
                            'payload_json' => $novoJson,
                            'hash'         => hash('sha256', $novoJson),
                        ]);
                    }
                });

                $this->info("  ✓ Invoice {$fatura->document_no} (ID {$fatura->id}) regenerado.");
                $regenerados++;

            } catch (\Throwable $e) {
                $this->error("  ✗ Falha ao regenerar Invoice ID {$c['invoice_id']}: " . $e->getMessage());
                $falhas++;
            }
        }

        $this->newLine();
        $this->info("✅ Regenerados: {$regenerados}");
        if ($falhas > 0) {
            $this->error("✗ Falhas: {$falhas}");
        }

        return $falhas === 0 ? self::SUCCESS : self::FAILURE;
    }
}
