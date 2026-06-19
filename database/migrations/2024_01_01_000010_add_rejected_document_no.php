<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona `rejected_document_no` à tabela `invoices` JA EXISTENTE.
 *
 * ══════════════════════════════════════════════════════════════════════
 * PORQUÊ ESTA COLUNA É NECESSÁRIA — LACUNA ENCONTRADA NESTA AUDITORIA
 * ══════════════════════════════════════════════════════════════════════
 * A documentação oficial da AGT (Registar Factura, regra FE-RNG-073,
 * erro E46) confirma:
 *
 *   "A emissão de documentos com o mesmo número de identificação no
 *    campo documentNo de outro documento previamente enviado e
 *    rejeitado pela AGT não é aceite. As correcções de documentos
 *    rejeitados deverão ser efectuados com a utilização de um novo
 *    número de documento."
 *
 * Isto significa que uma fatura rejeitada NUNCA pode ser resubmetida
 * com o mesmo documentNo — precisa de um NOVO número de documento,
 * com documentStatus='C' (Correcção) e o campo rejectedDocumentNo
 * apontando para o documento original rejeitado.
 *
 * ServicoConstrutorPayloadAgt já lia este campo (rejectedDocumentNo)
 * sem que ele existisse em parte alguma da base de dados — a coluna
 * estava ausente e nenhum fluxo a preenchia. Esta migração adiciona
 * a coluna; ver ServicoFatura::corrigirFaturaRejeitada() para o fluxo
 * que a preenche.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('invoices')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'rejected_document_no')) {
                $table->string('rejected_document_no', 60)->nullable()->after('sourceInvoiceId');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('invoices')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'rejected_document_no')) {
                $table->dropColumn('rejected_document_no');
            }
        });
    }
};
