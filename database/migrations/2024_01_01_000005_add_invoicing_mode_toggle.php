<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona o modo de faturação à tabela `organization_agt_configs` JÁ EXISTENTE.
 *
 * NÃO cria nenhuma tabela nova — apenas adiciona a coluna `invoicing_mode`
 * que permite à organização alternar entre:
 *
 *   'electronic' → Faturação Eletrónica AGT (submissão em tempo real,
 *                   assinaturas RS256/JWS, QR Code) — comportamento
 *                   actual por defeito do pacote.
 *
 *   'saft_ao'    → Regime SAF-T (AO) — sem submissão em tempo real;
 *                   a organização gera periodicamente um ficheiro XML
 *                   SAF-T(AO) (mensal/mensal-fraccionado) e envia-o
 *                   manualmente ou por outro canal à AGT.
 *
 * A alternância é bidirecional e reversível em qualquer momento —
 * nunca apaga faturas já emitidas em qualquer um dos modos; apenas
 * controla o comportamento de NOVAS faturas a partir do momento da troca.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('organization_agt_configs')) {
            return;
        }

        Schema::table('organization_agt_configs', function (Blueprint $table) {
            if (!Schema::hasColumn('organization_agt_configs', 'invoicing_mode')) {
                $table->string('invoicing_mode', 20)->default('electronic')->after('agt_enabled');
                // 'electronic' | 'saft_ao'
            }
            if (!Schema::hasColumn('organization_agt_configs', 'invoicing_mode_changed_at')) {
                $table->timestamp('invoicing_mode_changed_at')->nullable()->after('invoicing_mode');
            }
            if (!Schema::hasColumn('organization_agt_configs', 'invoicing_mode_changed_by')) {
                $table->unsignedBigInteger('invoicing_mode_changed_by')->nullable()->after('invoicing_mode_changed_at');
            }
            if (!Schema::hasColumn('organization_agt_configs', 'saft_company_id')) {
                // CompanyID exigido pelo SAF-T(AO) — normalmente NIF + nome curto
                $table->string('saft_company_id', 100)->nullable()->after('invoicing_mode_changed_by');
            }
            if (!Schema::hasColumn('organization_agt_configs', 'saft_tax_accounting_basis')) {
                // TaxAccountingBasis: 'F' (Faturação) é o valor padrão para emissores de facturas
                $table->string('saft_tax_accounting_basis', 5)->default('F')->after('saft_company_id');
            }
            if (!Schema::hasColumn('organization_agt_configs', 'taxpayer_key_version')) {
                // ⚠️ COLUNA ÓRFÃ desde a reconstrução v2.0.0 — nenhum código
                // do pacote lê ou escreve este campo. Foi criada para
                // suportar "AGT Anexo I, ponto 5.c" do Decreto Executivo
                // (texto legal), mas a documentação REST real da AGT não
                // inclui versão de chave do contribuinte no payload do
                // jwsDocumentSignature — em vez disso, "signatureVersion"
                // existe apenas dentro de softwareInfoDetail (chave do
                // SOFTWARE, não do contribuinte). Mantida na migração por
                // segurança (não remover colunas de uma migração já
                // publicada em produção), mas sem uso funcional actual.
                $table->unsignedInteger('taxpayer_key_version')->default(1)->after('taxpayer_private_key_encrypted');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('organization_agt_configs')) {
            return;
        }

        Schema::table('organization_agt_configs', function (Blueprint $table) {
            foreach ([
                'invoicing_mode', 'invoicing_mode_changed_at', 'invoicing_mode_changed_by',
                'saft_company_id', 'saft_tax_accounting_basis',
            ] as $coluna) {
                if (Schema::hasColumn('organization_agt_configs', $coluna)) {
                    $table->dropColumn($coluna);
                }
            }
        });
    }
};
