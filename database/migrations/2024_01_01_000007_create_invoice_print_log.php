<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cria a tabela `onsoft_agt_invoice_print_log` — regista cada vez que
 * o PDF de uma fatura é gerado/visualizado.
 *
 * REGRA AGT (Decreto Executivo, Anexo I, ponto 6):
 * ─────────────────────────────────────────────────────────────────
 * Alínea n): "A impressão de uma 2.ª via de um documento deve
 *             preservar o seu conteúdo original, ainda que deva
 *             conter qualquer expressão que indique não se tratar
 *             de um original."
 *
 * Alínea h): "...deverá fazer menção desta qualidade, através da
 *             expressão 'Cópia do documento original' (sem aspas)..."
 *
 * IMPLEMENTAÇÃO:
 * ─────────────────────────────────────────────────────────────────
 * A PRIMEIRA geração do PDF depois de a fatura estar paga/confirmada
 * é marcada como ORIGINAL. Todas as gerações seguintes (reimpressões,
 * reenvios, novo download) são marcadas como CÓPIA e o PDF mostra
 * obrigatoriamente "Cópia do documento original" — mantendo sempre
 * o MESMO conteúdo (preço, cliente, hash, etc.) da primeira geração.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('onsoft_agt_invoice_print_log')) {
            return;
        }

        Schema::create('onsoft_agt_invoice_print_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organizationId');
            $table->unsignedBigInteger('invoiceId');
            $table->boolean('is_original')->default(false);
            $table->string('formato_papel', 10)->nullable(); // A4 | 88mm | 58mm
            $table->string('canal', 20)->default('pdf'); // pdf | pdf-base64 | pdf-snapshot
            $table->unsignedBigInteger('gerado_por')->nullable();
            $table->timestamp('gerado_em')->useCurrent();

            $table->index(['organizationId', 'invoiceId'], 'idx_print_log_invoice');
            $table->index(['invoiceId', 'is_original'], 'idx_print_log_original');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onsoft_agt_invoice_print_log');
    }
};
