<?php

namespace Onsoft\Agt\Testes\Feature;

use App\Models\Invoice\Invoice;
use App\Models\Invoice\InvoiceSnapshot;
use Onsoft\Agt\Servicos\ServicoFatura;
use Onsoft\Agt\Servicos\ServicoModoFaturacao;
use Onsoft\Agt\Testes\TestCaseOnsoftAgt;

/**
 * SnapshotIntegridadeTest
 *
 * ══════════════════════════════════════════════════════════════════════
 * PORQUÊ ESTE TESTE EXISTE
 * ══════════════════════════════════════════════════════════════════════
 * A v1.14.4 corrigiu uma falha grave: InvoiceObserver::created() criava
 * o snapshot imutável da fatura ANTES de qualquer item ou pagamento
 * existir na base de dados, porque o evento `created` do Eloquent
 * dispara imediatamente após o INSERT — dentro da mesma transacção,
 * não depois do commit.
 *
 * Resultado: TODOS os snapshots criados antes da v1.14.4 tinham os
 * arrays `items` e `payments` vazios, mesmo que a fatura tivesse
 * múltiplas linhas e pagamentos reais.
 *
 * Este teste exerce a criação real de uma fatura através de
 * ServicoFatura::criar() e verifica, no payload_json persistido em
 * InvoiceSnapshot, que os itens e pagamentos estão de facto presentes
 * — não vazios. Se a ordem de execução regredir no futuro (por
 * exemplo, alguém mover criarSnapshotAgora() para antes da criação
 * dos itens), este teste falha imediatamente.
 *
 * ══════════════════════════════════════════════════════════════════════
 * COMO CORRER
 * ══════════════════════════════════════════════════════════════════════
 *   composer require --dev orchestra/testbench
 *   vendor/bin/phpunit tests/Feature/SnapshotIntegridadeTest.php
 *
 * Requer um projecto Laravel com os modelos Invoice, InvoiceItem,
 * InvoicePayment, OrganizationAgtConfig já criados (testbench usa o
 * projecto hospedeiro como base — ver TestCaseOnsoftAgt).
 */
class SnapshotIntegridadeTest extends TestCaseOnsoftAgt
{
    /**
     * Caso 1 — fatura em modo ELECTRONIC.
     * O snapshot deve conter itens, pagamentos E o hash AGT.
     */
    public function test_snapshot_contem_itens_e_pagamentos_em_modo_electronic(): void
    {
        $organizacaoId = $this->criarOrganizacaoComAgtActivo();

        $servico = app(ServicoFatura::class);

        $fatura = $servico->criar([
            'document_type' => 'FR',
            'customer_nif'  => '500123456',
            'customer_name' => 'Cliente Teste',
            'items' => [
                [
                    'description'    => 'Propina Teste',
                    'quantity'       => 1,
                    'unit_price'     => 45000,
                    'tax_code'       => 'ISE',
                    'tax_percentage' => 0,
                ],
                [
                    'description'    => 'Material Escolar',
                    'quantity'       => 2,
                    'unit_price'     => 2500,
                    'tax_code'       => 'IVA',
                    'tax_percentage' => 14,
                ],
            ],
            'payments' => [
                ['method_code' => 'NU', 'amount' => 50700],
            ],
        ], $organizacaoId, verificarAutoSubmit: false);

        $snapshot = InvoiceSnapshot::withoutGlobalScopes()
            ->where('invoiceId', $fatura->id)
            ->first();

        $this->assertNotNull($snapshot, 'O snapshot deveria ter sido criado.');

        $payload = json_decode($snapshot->payload_json, true);

        // ── A asserção central deste teste — o bug da v1.14.4 ──────────
        $this->assertNotEmpty(
            $payload['items'] ?? [],
            'FALHA CRÍTICA (regressão v1.14.4): o array "items" do snapshot ' .
            'está vazio. O snapshot foi criado antes dos itens existirem na BD.'
        );

        $this->assertCount(2, $payload['items'], 'O snapshot deveria conter exactamente 2 itens.');

        $this->assertNotEmpty(
            $payload['payments'] ?? [],
            'FALHA CRÍTICA (regressão v1.14.4): o array "payments" do snapshot ' .
            'está vazio. O snapshot foi criado antes dos pagamentos existirem na BD.'
        );

        // Em modo electronic, o hash AGT deve estar presente no snapshot
        $this->assertNotEmpty(
            $payload['agt']['invoice_hash'] ?? null,
            'Em modo electronic, o invoice_hash deveria estar presente no snapshot.'
        );

        // Os valores das linhas devem coincidir com o que foi pedido
        $this->assertEquals('Propina Teste', $payload['items'][0]['description']);
        $this->assertEquals(45000.0, (float) $payload['items'][0]['line_total']);
    }

    /**
     * Caso 2 — fatura em modo SAF-T(AO).
     * O snapshot deve conter itens e pagamentos, mas NUNCA hash.
     */
    public function test_snapshot_contem_itens_em_modo_saft_sem_hash(): void
    {
        $organizacaoId = $this->criarOrganizacaoSemConfigAgt(); // default automático -> saft_ao

        $servico = app(ServicoFatura::class);

        $fatura = $servico->criar([
            'document_type' => 'FR',
            'customer_nif'  => '500999888',
            'customer_name' => 'Cliente SAFT',
            'items' => [
                ['description' => 'Propina SAFT', 'quantity' => 1, 'unit_price' => 30000, 'tax_code' => 'ISE', 'tax_percentage' => 0],
            ],
            'payments' => [
                ['method_code' => 'NU', 'amount' => 30000],
            ],
        ], $organizacaoId, verificarAutoSubmit: false);

        $this->assertEquals(ServicoModoFaturacao::SAFT_AO, $fatura->invoicing_mode);

        $snapshot = InvoiceSnapshot::withoutGlobalScopes()
            ->where('invoiceId', $fatura->id)
            ->first();

        $this->assertNotNull(
            $snapshot,
            'FALHA CRÍTICA (regressão v1.14.3): faturas SAF-T também precisam ' .
            'de snapshot para imutabilidade — não apenas faturas electronic.'
        );

        $payload = json_decode($snapshot->payload_json, true);

        $this->assertNotEmpty($payload['items'] ?? [], 'Itens vazios no snapshot SAF-T.');
        $this->assertNotEmpty($payload['payments'] ?? [], 'Pagamentos vazios no snapshot SAF-T.');
        $this->assertEmpty(
            $payload['agt']['invoice_hash'] ?? null,
            'Uma fatura SAF-T nunca deve ter invoice_hash — por desenho desse regime.'
        );
    }

    /**
     * Caso 3 — imutabilidade real: alterar um campo fiscal depois da
     * criação deve ser bloqueado, em AMBOS os regimes.
     */
    public function test_alterar_campo_fiscal_depois_de_criado_e_bloqueado_em_qualquer_regime(): void
    {
        $organizacaoElectronic = $this->criarOrganizacaoComAgtActivo();
        $organizacaoSaft       = $this->criarOrganizacaoSemConfigAgt();

        $servico = app(ServicoFatura::class);

        foreach ([$organizacaoElectronic, $organizacaoSaft] as $orgId) {
            $fatura = $servico->criar([
                'document_type' => 'FR',
                'customer_nif'  => '500111222',
                'items'   => [['description' => 'Item', 'quantity' => 1, 'unit_price' => 10000, 'tax_code' => 'ISE', 'tax_percentage' => 0]],
                'payments' => [['method_code' => 'NU', 'amount' => 10000]],
            ], $orgId, verificarAutoSubmit: false);

            $fatura->gross_total = 99999; // tentativa de alterar campo imutável

            $this->expectException(\Onsoft\Agt\Excecoes\ExcecaoFaturaAgt::class);
            $fatura->save();
        }
    }
}
