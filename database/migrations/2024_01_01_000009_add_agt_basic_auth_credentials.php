<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona o campo `establishment_number` à tabela
 * `organization_agt_configs` JÁ EXISTENTE.
 *
 * ══════════════════════════════════════════════════════════════════════
 * CORRIGIDO NESTA AUDITORIA — colunas de Basic Auth duplicadas
 * ══════════════════════════════════════════════════════════════════════
 * A versão original desta migração criava `agt_basic_auth_username` e
 * `agt_basic_auth_password_encrypted` — mas o modelo OrganizationAgtConfig
 * do projecto hospedeiro JÁ TEM `agt_username` e `agt_password_encrypted`
 * no seu $fillable, com mutators automáticos (getAgtPasswordAttribute/
 * setAgtPasswordAttribute) que já fazem a encriptação/desencriptação via
 * Laravel Crypt. Criar colunas novas duplicava o conceito e ignorava
 * lógica já existente e testada no projecto.
 *
 * Esta migração agora só cria `establishment_number` — que é a única
 * coluna genuinamente ausente, exigida pela documentação oficial da
 * AGT ("Solicitar Criação de Série") para identificar o estabelecimento
 * emissor. Usar "SEDE" como default em sandbox ou organizações com um
 * único estabelecimento.
 *
 * A documentação OFICIAL da AGT (Autenticação & Autorização) confirma
 * que TODOS os pedidos à API REST exigem HTTP Basic Auth (username +
 * password emitidos pela AGT por email) — já suportado pelo modelo
 * existente via agt_username/agt_password.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('organization_agt_configs')) {
            return;
        }

        Schema::table('organization_agt_configs', function (Blueprint $table) {
            if (!Schema::hasColumn('organization_agt_configs', 'establishment_number')) {
                // Documentação "Solicitar Criação de Série": establishmentNumber
                // obrigatório. Usar "SEDE" como default em sandbox/single-site.
                $table->string('establishment_number', 200)->default('SEDE')->after('tax_registration_number');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('organization_agt_configs')) {
            return;
        }

        Schema::table('organization_agt_configs', function (Blueprint $table) {
            if (Schema::hasColumn('organization_agt_configs', 'establishment_number')) {
                $table->dropColumn('establishment_number');
            }
        });
    }
};
