<?php

namespace Onsoft\Agt\Servicos;

use App\Models\Agt\OrganizationAgtConfig;
use App\Models\Invoice\Invoice;
use App\Models\Organization;
use Illuminate\Support\Carbon;
use Onsoft\Agt\Excecoes\ExcecaoConfiguracaoAgt;
use Onsoft\Agt\Excecoes\ExcecaoFaturaAgt;

/**
 * ServicoSaftAo
 *
 * Gera o ficheiro SAF-T(AO) — Standard Audit File for Tax (Angola) —
 * entre uma data de início e uma data de fim, conforme exigido pela
 * AGT para contribuintes que operam no regime SAF-T(AO) em vez do
 * regime de Faturação Eletrónica em tempo real.
 *
 * ⚠️ HONESTIDADE SOBRE O ÂMBITO DESTA AUDITORIA (importante):
 * ─────────────────────────────────────────────────────────────────
 * A reconciliação completa feita neste pacote (ServicoAssinatura,
 * ServicoApiAgt, ServicoConstrutorPayloadAgt, etc.) foi validada
 * contra a documentação OFICIAL da API REST de Faturação Eletrónica:
 * https://quiosqueagt.minfin.gov.ao/doc-agt/faturacao-electronica/1/
 *
 * Esse portal documenta exclusivamente o regime de Faturação
 * Eletrónica em tempo real — NÃO contém a especificação técnica
 * completa do esquema XML SAF-T(AO) (estrutura exacta de elementos,
 * namespaces, validações de schema XSD). Este ficheiro implementa a
 * estrutura genérica OECD/SAF-T (AuditFile > Header > MasterFiles >
 * SourceDocuments), amplamente usada como base por várias
 * administrações fiscais que adoptam este padrão, mas NÃO está
 * confirmado, com a mesma certeza que o resto do pacote, que cada
 * elemento e atributo corresponde exactamente ao XSD oficial AGT
 * para SAF-T(AO). Antes de usar isto para entrega real à AGT,
 * validar o XML gerado contra o esquema XSD oficial do SAF-T(AO),
 * se disponível, ou confirmar directamente com a AGT.
 *
 * Estrutura mínima exportada (conforme especificação SAF-T AO):
 * ─────────────────────────────────────────────────────────────────
 *   <AuditFile>
 *     <Header>                    NIF, nome, período, data de geração
 *     <MasterFiles>
 *       <Customer>                Clientes referenciados no período
 *       <Product>                 Artigos/serviços referenciados
 *       <TaxTable>                Tabela de taxas de IVA usadas
 *     <SourceDocuments>
 *       <SalesInvoices>           Todas as faturas emitidas no período
 *         <Invoice>               Uma por documento
 *           <Line>                Uma por linha de item
 *           <DocumentTotals>
 *
 * O período (DataInicio / DataFim) é obrigatório e definido pelo
 * utilizador — normalmente um mês civil completo, mas o pacote aceita
 * qualquer intervalo de datas.
 */
class ServicoSaftAo
{
    /**
     * Gerar o XML SAF-T(AO) completo para um intervalo de datas.
     *
     * @param int    $organizacaoId
     * @param string $dataInicio    Formato Y-m-d (ex: '2026-06-01')
     * @param string $dataFim       Formato Y-m-d (ex: '2026-06-30')
     *
     * @return array{xml: string, nome_ficheiro: string, total_documentos: int, resumo: array}
     */
    public function gerar(int $organizacaoId, string $dataInicio, string $dataFim): array
    {
        $inicio = Carbon::parse($dataInicio)->startOfDay();
        $fim    = Carbon::parse($dataFim)->endOfDay();

        if ($inicio->greaterThan($fim)) {
            throw new ExcecaoFaturaAgt(
                "Data de início ({$dataInicio}) não pode ser posterior à data de fim ({$dataFim})."
            );
        }

        $organizacao = Organization::find($organizacaoId);

        if (!$organizacao) {
            throw new ExcecaoConfiguracaoAgt(
                "Organização [{$organizacaoId}] não encontrada."
            );
        }

        // Config pode legitimamente não existir — uma organização sem
        // nenhuma configuração AGT está, por defeito, em modo SAF-T(AO)
        // (ver ServicoModoFaturacao::modoActual()). Não bloquear a
        // exportação por falta de organization_agt_configs; usar
        // valores vazios/neutros nesse caso.
        $config = OrganizationAgtConfig::withoutGlobalScopes()
            ->where('organizationId', $organizacaoId)
            ->first();

        // Sem configuração — usar instância vazia. Não persistida,
        // apenas para fornecer valores neutros ao XML (CompanyID,
        // TaxAccountingBasis, etc. ficam vazios/defaults).
        $config ??= new OrganizationAgtConfig([
            'organizationId'             => $organizacaoId,
            'saft_tax_accounting_basis'  => 'F',
        ]);

        $faturas = Invoice::withoutGlobalScopes()
            ->where('organizationId', $organizacaoId)
            ->whereBetween('issued_at', [$inicio, $fim])
            ->whereNotIn('payment_status', ['cancelled'])
            ->with(['items.taxes'])
            ->orderBy('issued_at')
            ->get();

        $xml = $this->construirXml($organizacao, $config, $faturas, $inicio, $fim);

        // Marcar como exportadas apenas as faturas que estavam à espera
        // de exportação SAF-T (invoicing_mode = 'saft_ao' e ainda não
        // incluídas em nenhum ficheiro anterior). Faturas 'electronic'
        // do mesmo período também entram no XML (o SAF-T é um relato
        // fiscal completo do período), mas o seu invoicing_mode nunca
        // é alterado — já foram reportadas em tempo real.
        Invoice::withoutGlobalScopes()
            ->whereIn('id', $faturas->pluck('id'))
            ->where('invoicing_mode', \Onsoft\Agt\Servicos\ServicoModoFaturacao::SAFT_AO)
            ->where('agt_status', 'saft_pending_export')
            ->update(['agt_status' => 'saft_exported']);

        $nomeFicheiro = sprintf(
            'SAFT_AO_%s_%s_a_%s.xml',
            preg_replace('/[^A-Za-z0-9]/', '', $organizacao->nif ?? 'NIF'),
            $inicio->format('Ymd'),
            $fim->format('Ymd')
        );

        return [
            'xml'              => $xml,
            'nome_ficheiro'    => $nomeFicheiro,
            'total_documentos' => $faturas->count(),
            'resumo'           => [
                'data_inicio'    => $inicio->format('Y-m-d'),
                'data_fim'       => $fim->format('Y-m-d'),
                'total_faturas'  => $faturas->count(),
                'total_emitido'  => round($faturas->sum(fn($f) => (float) ($f->gross_total ?? $f->total)), 2),
                'total_iva'      => round($faturas->sum(fn($f) => (float) $f->tax_total), 2),
            ],
        ];
    }

    /**
     * Devolver apenas o resumo (contagens/valores) de um período, SEM
     * gerar o XML — útil para pré-visualização antes de exportar.
     */
    public function previsualizar(int $organizacaoId, string $dataInicio, string $dataFim): array
    {
        $inicio = Carbon::parse($dataInicio)->startOfDay();
        $fim    = Carbon::parse($dataFim)->endOfDay();

        if ($inicio->greaterThan($fim)) {
            throw new ExcecaoFaturaAgt(
                "Data de início ({$dataInicio}) não pode ser posterior à data de fim ({$dataFim})."
            );
        }

        $query = Invoice::withoutGlobalScopes()
            ->where('organizationId', $organizacaoId)
            ->whereBetween('issued_at', [$inicio, $fim])
            ->whereNotIn('payment_status', ['cancelled']);

        $totalFaturas = $query->count();
        $totalEmitido = (clone $query)->sum('gross_total');
        $totalIva     = (clone $query)->sum('tax_total');

        $porTipo = (clone $query)
            ->selectRaw('document_type, COUNT(*) as total, COALESCE(SUM(gross_total),0) as valor')
            ->groupBy('document_type')
            ->get();

        return [
            'data_inicio'   => $inicio->format('Y-m-d'),
            'data_fim'      => $fim->format('Y-m-d'),
            'total_faturas' => $totalFaturas,
            'total_emitido' => round((float) $totalEmitido, 2),
            'total_iva'     => round((float) $totalIva, 2),
            'por_tipo'      => $porTipo->map(fn($r) => [
                'tipo'  => $r->document_type,
                'total' => (int) $r->total,
                'valor' => round((float) $r->valor, 2),
            ])->values()->all(),
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    // CONSTRUÇÃO DO XML
    // ══════════════════════════════════════════════════════════════════

    private function construirXml(
        Organization          $organizacao,
        OrganizationAgtConfig $config,
        $faturas,
        Carbon                 $inicio,
        Carbon                 $fim
    ): string {
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');
        $xml->startDocument('1.0', 'UTF-8');

        $xml->startElement('AuditFile');
        $xml->writeAttribute('xmlns', 'urn:OECD:StandardAuditFile-Tax:AO_1.01_01');

        $this->escreverHeader($xml, $organizacao, $config, $inicio, $fim);
        $this->escreverMasterFiles($xml, $faturas);
        $this->escreverSourceDocuments($xml, $faturas);

        $xml->endElement(); // AuditFile
        $xml->endDocument();

        return $xml->outputMemory();
    }

    private function escreverHeader(
        \XMLWriter             $xml,
        Organization           $organizacao,
        OrganizationAgtConfig  $config,
        Carbon                 $inicio,
        Carbon                 $fim
    ): void {
        $xml->startElement('Header');
        $xml->writeElement('AuditFileVersion', '1.01_01');
        $xml->writeElement('CompanyID', $config->saft_company_id ?: ($organizacao->nif ?? ''));
        $xml->writeElement('TaxRegistrationNumber', $organizacao->nif ?? '');
        $xml->writeElement('TaxAccountingBasis', $config->saft_tax_accounting_basis ?: 'F');
        $xml->writeElement('CompanyName', $organizacao->nome_fiscal ?? $organizacao->nome_comercial ?? '');

        $xml->startElement('CompanyAddress');
        $xml->writeElement('AddressDetail', $organizacao->endereco ?? '');
        $xml->writeElement('City', $organizacao->municipio ?? '');
        $xml->writeElement('Province', $organizacao->provincia ?? '');
        $xml->writeElement('Country', 'AO');
        $xml->endElement(); // CompanyAddress

        $xml->writeElement('FiscalYear', (string) $inicio->year);
        $xml->writeElement('StartDate', $inicio->format('Y-m-d'));
        $xml->writeElement('EndDate', $fim->format('Y-m-d'));
        $xml->writeElement('CurrencyCode', 'AOA');
        $xml->writeElement('DateCreated', now()->format('Y-m-d'));
        $xml->writeElement('TaxEntity', 'Global');
        $xml->writeElement('ProductCompanyTaxID', $config->software_validation_number ?? '');
        $xml->writeElement('SoftwareCertificateNumber', $config->software_validation_number ?? '');
        $xml->writeElement('ProductID', config('onsoft-agt.software.nome', 'Onsoft AGT'));
        $xml->writeElement('ProductVersion', config('onsoft-agt.software.versao', '1.0.0'));
        $xml->endElement(); // Header
    }

    private function escreverMasterFiles(\XMLWriter $xml, $faturas): void
    {
        $xml->startElement('MasterFiles');

        // ── Customer: clientes únicos referenciados no período ─────────
        $clientesVistos = [];
        foreach ($faturas as $fatura) {
            $nif = data_get($fatura->customer_snapshot, 'nif', '999999999');
            if (isset($clientesVistos[$nif])) continue;
            $clientesVistos[$nif] = true;

            $xml->startElement('Customer');
            $xml->writeElement('CustomerID', $nif);
            $xml->writeElement('CustomerTaxID', $nif);
            $xml->writeElement('CompanyName', data_get($fatura->customer_snapshot, 'name', 'Consumidor Final'));
            $xml->startElement('BillingAddress');
            $xml->writeElement('AddressDetail', data_get($fatura->customer_snapshot, 'address', 'Desconhecido'));
            $xml->writeElement('Country', 'AO');
            $xml->endElement(); // BillingAddress
            $xml->endElement(); // Customer
        }

        // ── Product: artigos/serviços únicos referenciados ──────────────
        $produtosVistos = [];
        foreach ($faturas as $fatura) {
            foreach ($fatura->items as $item) {
                $codigo = $item->product_code ?: $item->item_code ?: 'ITEM';
                if (isset($produtosVistos[$codigo])) continue;
                $produtosVistos[$codigo] = true;

                $xml->startElement('Product');
                $xml->writeElement('ProductType', 'S'); // S = Serviços (contexto escolar)
                $xml->writeElement('ProductCode', $codigo);
                $xml->writeElement('ProductDescription', $item->description ?? $codigo);
                $xml->writeElement('ProductNumberCode', $codigo);
                $xml->endElement(); // Product
            }
        }

        // ── TaxTable: taxas de IVA usadas ────────────────────────────────
        $taxasVistas = [];
        foreach ($faturas as $fatura) {
            foreach ($fatura->items as $item) {
                foreach ($item->taxes as $imposto) {
                    $chave = $imposto->tax_type . '|' . $imposto->tax_code . '|' . $imposto->tax_percentage;
                    if (isset($taxasVistas[$chave])) continue;
                    $taxasVistas[$chave] = true;

                    $xml->startElement('TaxTableEntry');
                    $xml->writeElement('TaxType', $imposto->tax_type ?? 'IVA');
                    $xml->writeElement('TaxCountryRegion', $imposto->tax_country_region ?? 'AO');
                    $xml->writeElement('TaxCode', $imposto->tax_code ?? 'IVA');
                    $xml->writeElement('Description', $imposto->tax_type === 'ISENTO' ? 'Isento de IVA' : 'IVA');
                    $xml->writeElement('TaxPercentage', number_format((float) $imposto->tax_percentage, 2, '.', ''));
                    $xml->endElement(); // TaxTableEntry
                }
            }
        }

        $xml->endElement(); // MasterFiles
    }

    private function escreverSourceDocuments(\XMLWriter $xml, $faturas): void
    {
        $xml->startElement('SourceDocuments');
        $xml->startElement('SalesInvoices');
        $xml->writeElement('NumberOfEntries', (string) $faturas->count());
        $xml->writeElement('TotalDebit', '0.00');
        $xml->writeElement(
            'TotalCredit',
            number_format($faturas->sum(fn($f) => (float) ($f->gross_total ?? $f->total)), 2, '.', '')
        );

        foreach ($faturas as $fatura) {
            $this->escreverFatura($xml, $fatura);
        }

        $xml->endElement(); // SalesInvoices
        $xml->endElement(); // SourceDocuments
    }

    private function escreverFatura(\XMLWriter $xml, Invoice $fatura): void
    {
        $isNC = $fatura->document_type === 'NC';

        $xml->startElement('Invoice');
        $xml->writeElement('InvoiceNo', $fatura->document_no ?? $fatura->document_number);
        $xml->writeElement('InvoiceStatus', $fatura->payment_status === 'cancelled' ? 'A' : 'N');
        $xml->writeElement('InvoiceStatusDate', optional($fatura->issued_at)->format('Y-m-d\TH:i:s'));
        $xml->writeElement('InvoiceType', $fatura->document_type ?? 'FT');
        $xml->writeElement('InvoiceDate', optional($fatura->issued_at)->format('Y-m-d'));
        $xml->writeElement('SystemEntryDate', optional($fatura->created_at)->format('Y-m-d\TH:i:s'));
        $xml->writeElement('CustomerID', data_get($fatura->customer_snapshot, 'nif', '999999999'));

        foreach ($fatura->items as $item) {
            $xml->startElement('Line');
            $xml->writeElement('LineNumber', (string) ($item->line_number ?? 1));
            $xml->writeElement('ProductCode', $item->product_code ?: $item->item_code ?: 'ITEM');
            $xml->writeElement('ProductDescription', $item->description ?? '');
            $xml->writeElement('Quantity', number_format((float) $item->quantity, 4, '.', ''));
            $xml->writeElement('UnitOfMeasure', $item->unit_of_measure ?: 'UN');
            $xml->writeElement('UnitPrice', number_format((float) $item->unit_price, 2, '.', ''));

            $totalLinha = (float) ($item->line_total ?? $item->total ?? 0);
            if ($isNC) {
                $xml->writeElement('DebitAmount', number_format($totalLinha, 2, '.', ''));
            } else {
                $xml->writeElement('CreditAmount', number_format($totalLinha, 2, '.', ''));
            }

            foreach ($item->taxes as $imposto) {
                $xml->startElement('Tax');
                $xml->writeElement('TaxType', $imposto->tax_type ?? 'IVA');
                $xml->writeElement('TaxCountryRegion', $imposto->tax_country_region ?? 'AO');
                $xml->writeElement('TaxCode', $imposto->tax_code ?? 'IVA');
                $xml->writeElement('TaxPercentage', number_format((float) $imposto->tax_percentage, 2, '.', ''));
                if (!empty($imposto->tax_reason)) {
                    $xml->writeElement('TaxExemptionReason', $imposto->tax_reason);
                }
                $xml->endElement(); // Tax
            }

            $xml->endElement(); // Line
        }

        $xml->startElement('DocumentTotals');
        $xml->writeElement('TaxPayable', number_format((float) $fatura->tax_total, 2, '.', ''));
        $xml->writeElement('NetTotal', number_format((float) $fatura->subtotal, 2, '.', ''));
        $xml->writeElement('GrossTotal', number_format((float) ($fatura->gross_total ?? $fatura->total), 2, '.', ''));
        $xml->endElement(); // DocumentTotals

        $xml->endElement(); // Invoice
    }
}
