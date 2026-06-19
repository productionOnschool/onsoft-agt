<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Adiciona colunas de exclusividade à tabela `tipodepagamento` JÁ EXISTENTE.
 *
 * NÃO cria nenhuma tabela nova — usa exactamente a tabela que o projecto
 * já tem (App\Models\Config\Financeiro\TipoDePagamento), apenas com
 * colunas extra para suportar a regra de exclusividade de métodos
 * como Multicaixa Express e Referência Multicaixa.
 *
 * appCode estático de referência (já existente nos dados, ver imagem):
 *   1001 Dinheiro                  -> exclusivo=false
 *   1002 TPA                       -> exclusivo=false
 *   1003 Transferência Bancária    -> exclusivo=false
 *   1004 Depósito Bancário         -> exclusivo=false
 *   1005 Multicaixa Express        -> exclusivo=true  (online, ProxyPay)
 *   1006 Referência Multicaixa     -> exclusivo=true  (online, ProxyPay)
 *   1007 Cheque                    -> exclusivo=false
 *   1008 Carteira Interna          -> exclusivo=false
 *   1009 POS Online                -> exclusivo=true  (online, ProxyPay)
 *   1010 Pagamento Parcial         -> exclusivo=false (informativo)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tipodepagamento')) {
            // Tabela não existe neste projecto — nada a fazer.
            // O pacote assume que o projecto já a criou (como é o caso
            // do On-School). Ver TipoDePagamentoSeeder do projecto.
            return;
        }

        Schema::table('tipodepagamento', function (Blueprint $table) {
            if (!Schema::hasColumn('tipodepagamento', 'exclusivo')) {
                $table->boolean('exclusivo')->default(false)->after('appCode');
            }
            if (!Schema::hasColumn('tipodepagamento', 'requer_consulta_online')) {
                $table->boolean('requer_consulta_online')->default(false)->after('exclusivo');
            }
            if (!Schema::hasColumn('tipodepagamento', 'provider')) {
                // Nome do provider em organization_payment_configs.provider
                // (ex: 'proxypay'). Permite, no futuro, ligar cada appCode
                // a um provider diferente sem alterar código.
                $table->string('provider', 60)->nullable()->after('requer_consulta_online');
            }
        });

        // Marcar os métodos exclusivos conhecidos, SE já existirem
        // (popostos pelo TipoDePagamentoSeeder do projecto via appCode).
        // Não falha se os registos ainda não existirem.
        DB::table('tipodepagamento')->whereIn('appCode', [1005, 1006, 1009])->update([
            'exclusivo'              => true,
            'requer_consulta_online' => true,
            'provider'               => 'proxypay',
        ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('tipodepagamento')) {
            return;
        }

        Schema::table('tipodepagamento', function (Blueprint $table) {
            foreach (['exclusivo', 'requer_consulta_online', 'provider'] as $coluna) {
                if (Schema::hasColumn('tipodepagamento', $coluna)) {
                    $table->dropColumn($coluna);
                }
            }
        });
    }
};
