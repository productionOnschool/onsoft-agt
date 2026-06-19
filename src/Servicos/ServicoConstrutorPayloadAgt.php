<?php

namespace Onsoft\Agt\Servicos;

use App\Models\Invoice\Invoice;
use Onsoft\Agt\Enums\EstadoDocumentoRegisto;

/**
 * ServicoConstrutorPayloadAgt
 *
 * Constrói o objecto "document" exigido por registarFactura, EXACTAMENTE
 * conforme a documentação oficial AGT:
 * https://quiosqueagt.minfin.gov.ao/doc-agt/faturacao-electronica/1/servicos/registar.html
 *
 * Substitui a dependência em App\Services\Agt\AgtInvoicePayloadBuilder
 * (classe do projecto hospedeiro, fora do controlo deste pacote, cuja
 * estrutura de saída nunca foi verificada contra a documentação real).
 *
 * Construir o "document" aqui — dentro do pacote — garante que a
 * estrutura está sempre alinhada com a especificação oficial.
 */
class ServicoConstrutorPayloadAgt
{
    /**
     * Construir o objecto "document" completo para uma fatura.
     *
     * @return array Estrutura "document" pronta para o array
     *               "documents" do envelope de registarFactura.
     */
    public function construir(Invoice $fatura, string $jwsDocumentSignature): array
    {
        $fatura->loadMissing(['items.taxes', 'payments.allocations']);

        $documento = [
            'documentNo'           => $fatura->document_no ?? $fatura->document_number,
            'documentStatus'       => $fatura->rejected_document_no
                ? EstadoDocumentoRegisto::CORRECCAO->value
                : EstadoDocumentoRegisto::NORMAL->value,
            'jwsDocumentSignature' => $jwsDocumentSignature,
            'documentDate'         => $fatura->issued_at?->format('Y-m-d') ?? now()->format('Y-m-d'),
            'documentType'         => $fatura->document_type,
            'systemEntryDate'      => $fatura->created_at?->utc()->format('Y-m-d\TH:i:s\Z') ?? now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'customerCountry'      => data_get($fatura->customer_snapshot, 'country_code', 'AO'),
            'customerTaxID'        => data_get($fatura->customer_snapshot, 'nif', '999999999'),
            'companyName'          => data_get($fatura->customer_snapshot, 'name', 'Consumidor Final'),
            'documentTotals'       => $this->construirDocumentTotals($fatura),
        ];

        if (!empty($fatura->rejected_document_no)) {
            $documento['rejectedDocumentNo'] = $fatura->rejected_document_no;
        }

        // "lines" não é preenchido para AR, RC, RG (usa-se paymentReceipt)
        if (!$this->usaPaymentReceipt($fatura->document_type)) {
            $documento['lines'] = $this->construirLines($fatura);
        } else {
            $documento['paymentReceipt'] = $this->construirPaymentReceipt($fatura);
        }

        $retencoes = $this->construirWithholdingTaxList($fatura);
        if (!empty($retencoes)) {
            $documento['withholdingTaxList'] = $retencoes;
        }

        return $documento;
    }

    private function usaPaymentReceipt(string $documentType): bool
    {
        return in_array(strtoupper($documentType), ['AR', 'RC', 'RG'], true);
    }

    /**
     * Construir o array "lines" — uma entrada por InvoiceItem.
     *
     * Campos EXACTOS conforme documentação "Composição properties do
     * object line": lineNumber, operationType, productCode,
     * productDescription, quantity, unitOfMeasure, unitPriceBase,
     * unitPrice, debitAmount/creditAmount, taxes, settlementAmount.
     */
    private function construirLines(Invoice $fatura): array
    {
        $usaDebito = strtoupper($fatura->document_type) === 'NC';

        return $fatura->items->map(function ($item, $indice) use ($usaDebito) {
            $valorLinha = (float) ($item->line_total ?? $item->total ?? 0);
            $desconto   = (float) ($item->discount_amount ?? 0);

            $linha = [
                'lineNumber'         => $item->line_number ?? ($indice + 1),
                // operationType: mapeamento aproximado por categoria de
                // item — "SG" (Prestação de serviço geral) como default
                // seguro quando a categoria não corresponde a nenhum
                // código específico da documentação.
                'operationType'      => $this->mapearOperationType($item),
                'productCode'        => $item->product_code ?? $item->item_code ?? ('ITEM-' . ($indice + 1)),
                'productDescription' => $item->description ?? '',
                'quantity'           => (float) $item->quantity,
                'unitOfMeasure'      => $item->unit_of_measure ?? 'UN',
                'unitPriceBase'      => (float) $item->unit_price,
                'unitPrice'          => $this->money((float) $item->unit_price - ($desconto / max((float) $item->quantity, 1))),
                'taxes'              => $this->construirTaxesDaLinha($item),
                'settlementAmount'   => $this->money($desconto),
            ];

            if ($usaDebito) {
                $linha['debitAmount'] = $this->money($valorLinha);
            } else {
                $linha['creditAmount'] = $this->money($valorLinha);
            }

            // referenceInfo — obrigatório para NC (referência ao
            // documento base regularizado)
            if (strtoupper($fatura->document_type) === 'NC' && $fatura->sourceInvoiceId) {
                $linha['referenceInfo'] = [
                    'reference' => (string) $fatura->sourceInvoiceId,
                    'reason'    => $fatura->cancel_reason ?? '',
                ];
            }

            return $linha;
        })->values()->all();
    }

    /**
     * Mapear a categoria interna do item (item_category) para o código
     * "operationType" exigido pela AGT. Valores possíveis: SE, SS, STP,
     * SR, SIF, SHS, ST, SG, TB, AS, QT, RD — ver documentação "Registar
     * Factura", campo "operationType".
     *
     * Num contexto escolar, a generalidade dos itens (propinas,
     * matrículas, transporte) corresponde a "SE" (serviços de
     * educação) ou "TB" (transmissão de bens, ex: material escolar).
     */
    private function mapearOperationType($item): string
    {
        $categoria = strtolower($item->item_category ?? '');

        return match (true) {
            str_contains($categoria, 'propina')    => 'SE',
            str_contains($categoria, 'matricula')   => 'SE',
            str_contains($categoria, 'confirmacao') => 'SE',
            str_contains($categoria, 'recurso')     => 'SE',
            str_contains($categoria, 'transporte')  => 'STP',
            str_contains($categoria, 'produto')     => 'TB',
            default                                  => 'SG',
        };
    }

    /**
     * Construir o array "taxes" de uma linha.
     *
     * Campos EXACTOS: taxType, taxCountryRegion, taxCode, taxPercentage,
     * taxContribution, taxExemptionCode (obrigatório se taxCode=ISE).
     */
    private function construirTaxesDaLinha($item): array
    {
        if ($item->taxes->isEmpty()) {
            // Sem registo de imposto explícito — assumir isenção total
            // a partir dos campos da própria linha, nunca inventar IVA.
            $isento = in_array(strtoupper($item->tax_type ?? ''), ['ISENTO', 'ISE', 'NS'], true);

            return [[
                'taxType'         => $isento ? 'NS' : 'IVA',
                'taxCountryRegion' => 'AO',
                'taxCode'          => $isento ? 'ISE' : $this->mapearTaxCode((float) ($item->tax_percentage ?? 0)),
                'taxPercentage'    => (float) ($item->tax_percentage ?? 0),
                'taxContribution'  => $this->money((float) ($item->tax_amount ?? 0)),
                ...($isento ? ['taxExemptionCode' => $item->tax_reason ?: 'M99'] : []),
            ]];
        }

        return $item->taxes->map(function ($imposto) {
            $isento = strtoupper($imposto->tax_code ?? '') === 'ISE'
                || strtoupper($imposto->tax_type ?? '') === 'NS';

            return [
                'taxType'         => $imposto->tax_type ?: 'IVA',
                'taxCountryRegion' => $imposto->tax_country_region ?: 'AO',
                'taxCode'          => $imposto->tax_code ?: $this->mapearTaxCode((float) $imposto->tax_percentage),
                'taxPercentage'    => (float) $imposto->tax_percentage,
                'taxContribution'  => $this->money((float) $imposto->tax_contribution),
                ...($isento ? ['taxExemptionCode' => $imposto->tax_reason ?: 'M99'] : []),
            ];
        })->values()->all();
    }

    /**
     * Mapear a taxa percentual para o código de taxa IVA documentado:
     * NOR (taxa normal), INT (intermédia), RED (reduzida), ISE (isento), OUT.
     * Os valores percentuais exactos por categoria não estão confirmados
     * nesta auditoria — usa-se NOR como default seguro para taxas > 0.
     */
    private function mapearTaxCode(float $percentagem): string
    {
        if ($percentagem <= 0) {
            return 'ISE';
        }
        return 'NOR';
    }

    /**
     * Construir documentTotals — taxPayable, netTotal, grossTotal, e
     * currency (apenas se a fatura NÃO for em AOA).
     */
    private function construirDocumentTotals(Invoice $fatura): array
    {
        $totals = [
            'taxPayable' => $this->money((float) $fatura->tax_total),
            'netTotal'   => $this->money((float) $fatura->subtotal),
            'grossTotal' => $this->money((float) ($fatura->gross_total ?? $fatura->total)),
        ];

        if (!empty($fatura->currency) && strtoupper($fatura->currency) !== 'AOA') {
            $totals['currency'] = [
                'currencyCode'   => strtoupper($fatura->currency),
                'currencyAmount' => $this->money((float) ($fatura->currency_amount ?? 0)),
                'exchangeRate'   => (float) ($fatura->exchange_rate ?? 1),
            ];
        }

        return $totals;
    }

    /**
     * Construir paymentReceipt — obrigatório para AR, RC, RG.
     * Campos: sourceDocuments[] com lineNo, sourceDocumentID
     * {OriginatingON, documentDate}, debitAmount/creditAmount.
     */
    private function construirPaymentReceipt(Invoice $fatura): array
    {
        $sourceDocuments = $fatura->payments->flatMap(function ($pagamento, $indice) use ($fatura) {
            return $pagamento->allocations->map(function ($alocacao) use ($indice, $fatura) {
                $usaDebito = strtoupper($fatura->document_type) === 'NC';
                $valor     = $this->money((float) ($alocacao->amount ?? $pagamento->amount ?? 0));

                $linha = [
                    'lineNo'           => $indice + 1,
                    'sourceDocumentID' => [
                        'OriginatingON' => (string) ($alocacao->invoiceId ?? $fatura->sourceInvoiceId ?? $fatura->id),
                        'documentDate'  => $fatura->issued_at?->format('Y-m-d') ?? now()->format('Y-m-d'),
                    ],
                ];

                if ($usaDebito) {
                    $linha['debitAmount'] = $valor;
                } else {
                    $linha['creditAmount'] = $valor;
                }

                return $linha;
            });
        })->values()->all();

        // Sem alocações detalhadas — fallback para uma única linha
        // cobrindo o total da fatura, para nunca enviar array vazio
        // num campo obrigatório.
        if (empty($sourceDocuments)) {
            $sourceDocuments[] = [
                'lineNo'           => 1,
                'sourceDocumentID' => [
                    'OriginatingON' => (string) ($fatura->sourceInvoiceId ?? $fatura->id),
                    'documentDate'  => $fatura->issued_at?->format('Y-m-d') ?? now()->format('Y-m-d'),
                ],
                'creditAmount' => $this->money((float) ($fatura->gross_total ?? $fatura->total)),
            ];
        }

        return ['sourceDocuments' => $sourceDocuments];
    }

    /**
     * Construir withholdingTaxList a partir de retenções configuradas
     * na fatura, se existirem. Campo opcional — devolve [] se não
     * houver nenhuma retenção aplicável.
     */
    private function construirWithholdingTaxList(Invoice $fatura): array
    {
        if (empty($fatura->withholding_tax_amount) || (float) $fatura->withholding_tax_amount <= 0) {
            return [];
        }

        return [[
            'withholdingTaxType'        => $fatura->withholding_tax_type ?? 'IRT',
            'withholdingTaxDescription' => $fatura->withholding_tax_description ?? '',
            'withholdingTaxAmount'      => $this->money((float) $fatura->withholding_tax_amount),
        ]];
    }

    private function money(float $valor): float
    {
        return round($valor, 2);
    }
}
