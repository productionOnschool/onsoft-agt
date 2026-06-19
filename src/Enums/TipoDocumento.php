<?php

namespace Onsoft\Agt\Enums;

/**
 * TipoDocumento
 *
 * ══════════════════════════════════════════════════════════════════════
 * RECONSTRUÍDO a partir da documentação OFICIAL da AGT:
 * https://quiosqueagt.minfin.gov.ao/doc-agt/faturacao-electronica/1/
 *
 * O conjunto anterior (FT, FR, FS, NC, ND, RC) estava incompleto e
 * incluía um valor ("FS" — Fatura Simplificada) que NÃO EXISTE na
 * especificação real da AGT. Substituído pelo conjunto completo de
 * 18 tipos de documento definidos em "Registar Fatura Eletrónica" e
 * "Solicitar Criação de Série".
 * ══════════════════════════════════════════════════════════════════════
 */
enum TipoDocumento: string
{
    case FATURA_ADIANTAMENTO          = 'FA';
    case FATURA                       = 'FT';
    case FATURA_RECIBO                = 'FR';
    case FATURA_GLOBAL                = 'FG';
    case FATURA_GENERICA               = 'GF';
    case AVISO_COBRANCA                = 'AC';
    case AVISO_COBRANCA_RECIBO         = 'AR';
    case TALAO_VENDA                   = 'TV';
    case RECIBO                        = 'RC'; // Recibo em numerário (cash)
    case RECIBO_GERAL                  = 'RG';
    case ESTORNO                       = 'RE'; // Estorno ou Recibo de Estorno
    case NOTA_DEBITO                   = 'ND';
    case NOTA_CREDITO                  = 'NC';
    case FATURA_RECIBO_AUTOFACTURACAO  = 'AF';
    case PREMIO_RECIBO_PREMIO          = 'RP';
    case RESSEGURO_ACEITE              = 'RA';
    case IMPUTACAO_COSEGURADORAS       = 'CS';
    case IMPUTACAO_COSEGURADORA_LIDER  = 'LD';

    public function etiqueta(): string
    {
        return match ($this) {
            self::FATURA_ADIANTAMENTO         => 'Factura de Adiantamento',
            self::FATURA                      => 'Factura',
            self::FATURA_RECIBO               => 'Factura/Recibo',
            self::FATURA_GLOBAL               => 'Factura Global',
            self::FATURA_GENERICA             => 'Factura Genérica',
            self::AVISO_COBRANCA              => 'Aviso de Cobrança',
            self::AVISO_COBRANCA_RECIBO       => 'Aviso de Cobrança/Recibo',
            self::TALAO_VENDA                 => 'Talão de Venda',
            self::RECIBO                      => 'Recibo Emitido (numerário)',
            self::RECIBO_GERAL                => 'Recibo Geral',
            self::ESTORNO                     => 'Estorno / Recibo de Estorno',
            self::NOTA_DEBITO                 => 'Nota de Débito',
            self::NOTA_CREDITO                => 'Nota de Crédito',
            self::FATURA_RECIBO_AUTOFACTURACAO => 'Factura/Recibo de Autofacturação',
            self::PREMIO_RECIBO_PREMIO        => 'Prémio ou Recibo de Prémio',
            self::RESSEGURO_ACEITE            => 'Resseguro Aceite',
            self::IMPUTACAO_COSEGURADORAS     => 'Imputação a Co-seguradoras',
            self::IMPUTACAO_COSEGURADORA_LIDER => 'Imputação a Co-seguradora Líder',
        };
    }

    /**
     * NC usa debitAmount; os outros usam creditAmount (ponto "lines",
     * documentação Registar Factura — "Só um dos campos debitAmount e
     * creditAmount poderá estar preenchido").
     */
    public function usaDebito(): bool
    {
        return $this === self::NOTA_CREDITO;
    }

    /**
     * Tipos para os quais o array "lines" NÃO é preenchido — usa-se
     * "paymentReceipt" em vez disso (documentação Registar Factura,
     * campo "lines": "sendo não preenchido para os tipos de factura:
     * AR, RC, RG").
     */
    public function usaPaymentReceiptEmVezDeLines(): bool
    {
        return in_array($this, [self::AVISO_COBRANCA_RECIBO, self::RECIBO, self::RECIBO_GERAL], true);
    }

    /**
     * Recibo (numerário) e Recibo Geral não requerem jwsDocumentSignature
     * da mesma forma que os documentos de facturação — mantido como
     * compatibilidade com a regra de negócio existente do projecto
     * (Anexo I, ponto 6.c: "Emitido por programa validado").
     * NOTA: a documentação REST não distingue explicitamente isenção
     * de assinatura por tipo — todos os "documents" enviados a
     * registarFactura incluem jwsDocumentSignature como campo
     * obrigatório. Este método é mantido para a lógica de IMPRESSÃO
     * (linha de certificação no PDF), não para decidir se o documento
     * é ou não enviado a registarFactura.
     */
    public function usaLinhaRecibo(): bool
    {
        return in_array($this, [self::RECIBO, self::RECIBO_GERAL], true);
    }

    /** FR deve ser totalmente pago — regra de negócio do projecto, não da AGT */
    public function devePagarTotal(): bool
    {
        return $this === self::FATURA_RECIBO;
    }

    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function etiquetas(): array
    {
        return array_combine(
            array_column(self::cases(), 'value'),
            array_map(fn($c) => $c->etiqueta(), self::cases())
        );
    }
}
