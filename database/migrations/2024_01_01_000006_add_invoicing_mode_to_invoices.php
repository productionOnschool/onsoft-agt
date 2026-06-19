<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Adiciona `invoicing_mode` directamente à tabela `invoices` JÁ EXISTENTE.
 *
 * MOTIVO DESTA MIGRAÇÃO:
 * ─────────────────────────────────────────────────────────────────
 * Até agora, o regime fiscal de uma fatura só era visível indirectamente
 * através de valores específicos de `agt_status` (ex: 'saft_pending_export').
 * Isto mistura "estado do processo" com "regime fiscal de origem" e não
 * dá ao frontend um identificador único e estável para:
 *
 *   1. Separar visualmente faturas Eletrónicas de faturas SAF-T
 *   2. Decidir, sem ambiguidade, que botões mostrar/esconder
 *      (ex: "Submeter à AGT" nunca deve aparecer numa fatura SAF-T)
 *   3. Filtrar relatórios e listagens por regime de origem
 *
 * Esta coluna é preenchida UMA VEZ no momento da criação da fatura,
 * reflectindo o modo da organização nesse instante, e é IMUTÁVEL
 * depois disso (faz parte de InvoiceSnapshotGuard::CAMPOS_IMUTAVEIS) —
 * mudar o modo da organização no futuro nunca altera faturas passadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('invoices')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'invoicing_mode')) {
                // 'electronic' | 'saft_ao' — regime no momento da criação
                $table->string('invoicing_mode', 20)->default('electronic')->after('agt_status');
            }
        });

        // Preencher retroactivamente faturas já existentes com base no
        // estado actual de agt_status, para não deixar nada como NULL.
        DB::table('invoices')
            ->whereIn('agt_status', ['saft_pending_export', 'saft_exported'])
            ->update(['invoicing_mode' => 'saft_ao']);

        DB::table('invoices')
            ->whereNotIn('agt_status', ['saft_pending_export', 'saft_exported'])
            ->update(['invoicing_mode' => 'electronic']);

        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table) {
                $this->addIndexIfNotExists($table);
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('invoices')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'invoicing_mode')) {
                $table->dropColumn('invoicing_mode');
            }
        });
    }

    private function addIndexIfNotExists(Blueprint $table): void
    {
        try {
            $table->index(['organizationId', 'invoicing_mode'], 'idx_inv_org_invoicing_mode');
        } catch (\Throwable) {
            // Índice já existe — ignorar
        }
    }
};
