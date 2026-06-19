<?php

namespace Onsoft\Agt\Servicos;

use Onsoft\Agt\Excecoes\ExcecaoFaturaAgt;

/**
 * ServicoFaturaProforma
 *
 * FACTURA PRO-FORMA - "FP"
 *
 * "FP" NAO existe nos 18 tipos de documento reais da API AGT (FA, FT,
 * FR, FG, GF, AC, AR, TV, RC, RG, RE, ND, NC, AF, RP, RA, CS, LD -
 * ver Onsoft\Agt\Enums\TipoDocumento). Isto e coerente com a pratica:
 * uma factura pro-forma NUNCA e um documento fiscal - e apenas uma
 * estimativa de valores mostrada ao cliente antes de qualquer
 * compromisso ou pagamento.
 *
 * Este servico e completamente independente de ServicoFatura,
 * InvoiceObserver, InvoiceSnapshotGuard, ServicoSeries e
 * ServicoSubmissao:
 *
 *   - NUNCA cria um registo Invoice (nem rascunho)
 *   - NUNCA cria InvoiceItem, InvoiceSnapshot, AgtInvoiceSubmission
 *   - NUNCA consome numero de serie fiscal
 *   - NUNCA assina nada (sem jwsDocumentSignature, sem hash)
 *   - NUNCA contacta a API AGT
 *   - NUNCA conta para o limite diario de emissao
 *
 * Tudo acontece em memoria, dentro de UMA chamada: calcular os
 * totais a partir dos itens recebidos, gerar o HTML, devolver o PDF.
 * Quando a chamada termina, nao fica NENHUM rasto na base de dados.
 */
class ServicoFaturaProforma
{
    /**
     * Calcular os totais de uma pro-forma a partir dos itens recebidos.
     * Nao persiste nada - apenas processa os dados em memoria.
     *
     * @param array $dados ['items' => [...], 'customer_name' => ?, 'customer_nif' => ?, 'validade_dias' => ?]
     * @return array Estrutura completa pronta para o PDF
     */
    public function calcular(array $dados): array
    {
        $itens = collect($dados['items'] ?? []);

        if ($itens->isEmpty()) {
            throw new ExcecaoFaturaAgt('A factura pro-forma deve ter pelo menos um item.');
        }

        $linhasCalculadas = $itens->values()->map(function ($item, $indice) {
            $quantidade    = (float) ($item['quantity'] ?? 1);
            $precoUnitario = (float) ($item['unit_price'] ?? 0);
            $desconto      = (float) ($item['discount_amount'] ?? 0);
            $taxaPerc      = (float) ($item['tax_percentage'] ?? $item['tax_rate'] ?? 0);

            $isento = in_array(
                strtoupper($item['tax_code'] ?? $item['tax_type'] ?? ''),
                ['ISE', 'ISENTO', 'M00', 'NS'],
                true
            );

            $base    = round(($quantidade * $precoUnitario) - $desconto, 2);
            $ivaItem = $isento ? 0.0 : round($base * ($taxaPerc / 100), 2);
            $total   = round($base + $ivaItem, 2);

            return [
                'numero_linha'    => $indice + 1,
                'descricao'       => $item['description'] ?? 'Item',
                'quantidade'      => $quantidade,
                'preco_unitario'  => $precoUnitario,
                'desconto'        => $desconto,
                'isento'          => $isento,
                'taxa_iva'        => $isento ? 0.0 : $taxaPerc,
                'motivo_isencao'  => $isento ? ($item['tax_reason'] ?? 'M00') : null,
                'base_tributavel' => $base,
                'iva'             => $ivaItem,
                'total_linha'     => $total,
            ];
        });

        $subtotal      = round($linhasCalculadas->sum('base_tributavel'), 2);
        $ivaTotal      = round($linhasCalculadas->sum('iva'), 2);
        $descontoTotal = round($linhasCalculadas->sum('desconto'), 2);
        $totalGeral    = round($subtotal + $ivaTotal, 2);
        $validadeDias  = (int) ($dados['validade_dias'] ?? 15);

        return [
            'documento' => [
                'tipo'          => 'FP',
                'label'         => config('onsoft-agt.tipos_documento.FP', 'Factura Pró-forma'),
                'gerado_em'     => now()->toISOString(),
                'valido_ate'    => now()->addDays($validadeDias)->toDateString(),
                'validade_dias' => $validadeDias,
                'referencia'    => $dados['referencia'] ?? ('PROFORMA-' . now()->format('YmdHis')),
            ],
            'cliente' => [
                'nome' => $dados['customer_name'] ?? 'Cliente',
                'nif'  => $dados['customer_nif'] ?? null,
            ],
            'linhas' => $linhasCalculadas->values()->all(),
            'totais' => [
                'subtotal'    => $subtotal,
                'desconto'    => $descontoTotal,
                'iva'         => $ivaTotal,
                'total_geral' => $totalGeral,
            ],
        ];
    }

    /**
     * Gerar o HTML completo do PDF da pro-forma a partir dos dados
     * calculados - nunca toca na BD em ponto nenhum desta funcao.
     */
    public function gerarHtml(array $dadosCalculados, array $organizacao = []): string
    {
        $doc     = $dadosCalculados['documento'];
        $cliente = $dadosCalculados['cliente'];
        $linhas  = $dadosCalculados['linhas'];
        $totais  = $dadosCalculados['totais'];

        $linhasHtml = '';
        foreach ($linhas as $l) {
            $isencaoTexto = $l['isento']
                ? '<br><span style="font-size:7pt;color:#888">Isento - ' . htmlspecialchars($l['motivo_isencao']) . '</span>'
                : '';

            $linhasHtml .= '<tr>'
                . '<td style="text-align:center">' . $l['numero_linha'] . '</td>'
                . '<td>' . htmlspecialchars($l['descricao']) . $isencaoTexto . '</td>'
                . '<td style="text-align:right">' . number_format($l['quantidade'], 2, ',', '.') . '</td>'
                . '<td style="text-align:right">' . number_format($l['preco_unitario'], 2, ',', '.') . '</td>'
                . '<td style="text-align:center">' . ($l['isento'] ? 'Isento' : number_format($l['taxa_iva'], 0) . '%') . '</td>'
                . '<td style="text-align:right">' . number_format($l['iva'], 2, ',', '.') . '</td>'
                . '<td style="text-align:right"><strong>' . number_format($l['total_linha'], 2, ',', '.') . '</strong></td>'
                . '</tr>';
        }

        $nomeOrg     = htmlspecialchars($organizacao['nome_fiscal'] ?? $organizacao['nome_comercial'] ?? '');
        $nifOrg      = htmlspecialchars($organizacao['nif'] ?? '');
        $nomeCliente = htmlspecialchars($cliente['nome']);
        $nifClienteHtml = !empty($cliente['nif'])
            ? '&nbsp;&nbsp;<strong>NIF:</strong> ' . htmlspecialchars($cliente['nif'])
            : '';

        $css = $this->css();

        $html = '<!DOCTYPE html><html lang="pt"><head><meta charset="UTF-8"><style>' . $css . '</style></head><body>';
        $html .= '<div class="marca-dagua">PRO-FORMA</div>';
        $html .= '<div class="aviso"><strong>AVISO: DOCUMENTO PRO-FORMA - NAO E FACTURA, SEM VALOR FISCAL</strong>';
        $html .= '<span>Este documento e apenas uma estimativa de valores. Nao tem numero de serie fiscal, ';
        $html .= 'nao e assinado, nao e submetido a AGT, e NAO E REGISTADO em nenhum sistema apos a sua impressao. ';
        $html .= 'Valido until ' . $doc['valido_ate'] . '.</span></div>';

        $html .= '<div class="cabecalho"><div class="cab-esq">';
        $html .= '<div class="nome-org">' . $nomeOrg . '</div>';
        $html .= '<div style="font-size:8pt;color:#555">NIF: ' . $nifOrg . '</div>';
        $html .= '</div><div class="cab-dir">';
        $html .= '<div class="tipo-doc">FACTURA PRO-FORMA</div>';
        $html .= '<div style="font-size:8.5pt">Ref: ' . htmlspecialchars($doc['referencia']) . '</div>';
        $html .= '<div style="font-size:8pt;color:#555">Gerado em: ' . htmlspecialchars($doc['gerado_em']) . '</div>';
        $html .= '</div></div>';

        $html .= '<div style="margin-bottom:10px;font-size:9pt"><strong>Cliente:</strong> ' . $nomeCliente . $nifClienteHtml . '</div>';

        $html .= '<table class="itens"><thead><tr>';
        $html .= '<th style="width:5%">#</th><th style="width:35%">Descricao</th>';
        $html .= '<th style="width:10%">Qtd</th><th style="width:12%">Preco Unit.</th>';
        $html .= '<th style="width:10%">IVA %</th><th style="width:13%">IVA</th><th style="width:15%">Total</th>';
        $html .= '</tr></thead><tbody>' . $linhasHtml . '</tbody></table>';

        $html .= '<div class="totais"><table>';
        $html .= '<tr><td>Base Tributavel</td><td style="text-align:right">' . $this->fmt($totais['subtotal']) . ' AOA</td></tr>';
        $html .= '<tr><td>Desconto</td><td style="text-align:right">' . $this->fmt($totais['desconto']) . ' AOA</td></tr>';
        $html .= '<tr><td>IVA</td><td style="text-align:right">' . $this->fmt($totais['iva']) . ' AOA</td></tr>';
        $html .= '<tr class="geral"><td>TOTAL ESTIMADO</td><td style="text-align:right">' . $this->fmt($totais['total_geral']) . ' AOA</td></tr>';
        $html .= '</table></div>';

        $html .= '<div class="rodape">Documento gerado apenas para fins informativos - valido until ' . $doc['valido_ate'] . '<br>';
        $html .= 'Nao constitui factura, recibo, ou qualquer documento fiscal. Nao submetido a AGT.</div>';
        $html .= '</body></html>';

        return $html;
    }

    private function css(): string
    {
        return '
            * { margin:0; padding:0; box-sizing:border-box; }
            body { font-family: DejaVu Sans, Arial, sans-serif; font-size:9pt; color:#1a1a1a; }
            .marca-dagua { position:fixed; top:38%; left:5%; width:90%; text-align:center;
              font-size:46pt; color:rgba(192,57,43,0.10); transform:rotate(-30deg);
              font-weight:bold; z-index:-1; }
            .aviso { background:#fdecea; border:2px solid #c0392b; border-radius:5px;
              padding:10px 14px; margin-bottom:14px; text-align:center; }
            .aviso strong { color:#b71c1c; font-size:11pt; display:block; margin-bottom:3px; }
            .aviso span { color:#7a2020; font-size:8pt; }
            .cabecalho { display:table; width:100%; border-bottom:2px solid #1a1a72;
              padding-bottom:8px; margin-bottom:10px; }
            .cab-esq { display:table-cell; width:55%; }
            .cab-dir { display:table-cell; width:45%; text-align:right; }
            .nome-org { font-size:13pt; font-weight:bold; color:#1a1a72; }
            .tipo-doc { font-size:15pt; font-weight:bold; color:#c0392b; }
            table.itens { width:100%; border-collapse:collapse; margin:10px 0; }
            table.itens thead tr { background:#1a1a72; color:#fff; }
            table.itens th { padding:5px; font-size:7.5pt; text-align:left; }
            table.itens td { padding:5px; font-size:8pt; border-bottom:1px solid #eee; }
            .totais { width:45%; margin-left:55%; margin-top:6px; }
            .totais table { width:100%; border-collapse:collapse; }
            .totais td { padding:3px 6px; font-size:8.5pt; }
            .totais .geral { background:#1a1a72; color:#fff; font-weight:bold; font-size:10pt; }
            .rodape { margin-top:16px; text-align:center; font-size:7.5pt; color:#888;
              border-top:1px dashed #ccc; padding-top:8px; }
        ';
    }

    private function fmt(float $valor): string
    {
        return number_format($valor, 2, ',', '.');
    }
}
