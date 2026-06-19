<?php

namespace Onsoft\Agt\Enums;

/**
 * EstadoDocumentoRegisto
 *
 * Campo "documentStatus" do objecto "document" enviado a
 * registarFactura - NAO confundir com o estado de validacao devolvido
 * por obterEstado (ver EstadoValidacaoAgt).
 *
 * Fonte: https://quiosqueagt.minfin.gov.ao/doc-agt/faturacao-electronica/1/servicos/registar.html
 */
enum EstadoDocumentoRegisto: string
{
    case NORMAL    = 'N';
    case CORRECCAO = 'C'; // gerado para corrigir um documento anteriormente rejeitado pela AGT

    public function etiqueta(): string
    {
        return match ($this) {
            self::NORMAL    => 'Normal',
            self::CORRECCAO => 'Correccao de documento rejeitado',
        };
    }
}
