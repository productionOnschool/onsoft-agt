<?php

namespace Onsoft\Agt\Enums;

/**
 * EstadoValidacaoAgt
 *
 * Vocabularios REAIS de estado devolvidos pela API AGT - substituem o
 * vocabulario inventado (accepted/rejected/pending/failed) usado nas
 * versoes anteriores do pacote.
 *
 * A AGT usa quatro vocabularios distintos, em servicos diferentes:
 *
 * 1. obterEstado - resultCode (nivel de LOTE/requestID)
 * 2. obterEstado - documentStatusList[].documentStatus (nivel de DOCUMENTO)
 * 3. consultarFactura - validationStatus (nivel de DOCUMENTO, historico)
 * 4. validarDocumento - documentStatusCode (estado antes da accao do adquirente)
 *
 * Fonte: https://quiosqueagt.minfin.gov.ao/doc-agt/faturacao-electronica/1/servicos/
 */
class EstadoValidacaoAgt
{
    // -- obterEstado.resultCode - nivel de LOTE --------------------------
    public const LOTE_CONCLUIDO_SEM_INVALIDAS   = 0;
    public const LOTE_CONCLUIDO_COM_MISTAS       = 1;
    public const LOTE_CONCLUIDO_SEM_VALIDAS      = 2;
    public const LOTE_PREMATURO                  = 7;
    public const LOTE_EM_CURSO                    = 8;
    public const LOTE_CANCELADO                   = 9;

    public static function etiquetaResultCode(int $codigo): string
    {
        return match ($codigo) {
            self::LOTE_CONCLUIDO_SEM_INVALIDAS => 'Processamento concluido - todas as facturas validas',
            self::LOTE_CONCLUIDO_COM_MISTAS     => 'Processamento concluido - facturas validas e invalidas',
            self::LOTE_CONCLUIDO_SEM_VALIDAS    => 'Processamento concluido - nenhuma factura valida',
            self::LOTE_PREMATURO                 => 'Solicitacao prematura/repetitiva - aguardar antes de repetir',
            self::LOTE_EM_CURSO                   => 'Processamento ainda em curso',
            self::LOTE_CANCELADO                  => 'Processamento cancelado',
            default                                => "Codigo de resultado desconhecido ({$codigo})",
        };
    }

    /** Se o lote ja tem resposta definitiva (nao precisa de retentar polling) */
    public static function loteEstaFinalizado(int $resultCode): bool
    {
        return in_array($resultCode, [
            self::LOTE_CONCLUIDO_SEM_INVALIDAS,
            self::LOTE_CONCLUIDO_COM_MISTAS,
            self::LOTE_CONCLUIDO_SEM_VALIDAS,
            self::LOTE_CANCELADO,
        ], true);
    }

    // -- obterEstado.documentStatusList[].documentStatus - nivel DOCUMENTO --
    public const DOCUMENTO_VALIDO   = 'V';
    public const DOCUMENTO_INVALIDO = 'I';

    public static function etiquetaDocumentStatus(string $estado): string
    {
        return match ($estado) {
            self::DOCUMENTO_VALIDO   => 'Valida',
            self::DOCUMENTO_INVALIDO => 'Invalida',
            default                   => "Estado de documento desconhecido ({$estado})",
        };
    }

    // -- consultarFactura.validationStatus - nivel DOCUMENTO (historico) --
    public const VALIDACAO_VALIDA                 = 'V';
    public const VALIDACAO_VALIDA_COM_PENALIZACAO = 'P';

    public static function etiquetaValidationStatus(string $estado): string
    {
        return match ($estado) {
            self::VALIDACAO_VALIDA                 => 'Valida',
            self::VALIDACAO_VALIDA_COM_PENALIZACAO => 'Valida com penalizacao (enviada com mais de 24h de atraso, sem contingencia aprovada)',
            default                                  => "Estado de validacao desconhecido ({$estado})",
        };
    }

    // -- validarDocumento.documentStatusCode - estado ANTES da accao -----
    public const DOC_ANULADO    = 'S_A';
    public const DOC_CONFIRMADO = 'S_C';
    public const DOC_INVALIDO   = 'S_I';
    public const DOC_REGISTADO  = 'S_RG';
    public const DOC_REJEITADO  = 'S_RJ';
    public const DOC_VALIDO     = 'S_V';

    // -- validarDocumento.actionResultCode --------------------------------
    public const ACCAO_CONFIRMACAO_OK     = 'C_OK';
    public const ACCAO_REJEICAO_OK         = 'R_OK';
    public const ACCAO_CONFIRMACAO_FALHOU = 'C_NOK';
    public const ACCAO_REJEICAO_FALHOU     = 'R_NOK';

    /**
     * Mapear o vocabulario REAL da AGT para o vocabulario INTERNO usado
     * historicamente nas colunas agt_status do projecto, para manter
     * compatibilidade com codigo/relatorios ja existentes que usam
     * 'accepted'/'rejected'/'pending'/'failed'.
     *
     * IMPORTANTE: esta funcao existe apenas como PONTE de compatibilidade
     * - o estado AUTORITATIVO e sempre o devolvido pela AGT (resultCode +
     * documentStatus), guardado tal e qual em agt_response. O mapeamento
     * para o vocabulario interno e so para nao obrigar a reescrever toda
     * a UI/relatorios que ja usam essas 4 palavras.
     */
    public static function mapearParaVocabularioInterno(int $resultCode, ?string $documentStatus): string
    {
        if (!self::loteEstaFinalizado($resultCode)) {
            return 'pending';
        }

        if ($resultCode === self::LOTE_CANCELADO) {
            return 'cancelled';
        }

        if ($documentStatus === self::DOCUMENTO_VALIDO) {
            return 'accepted';
        }

        if ($documentStatus === self::DOCUMENTO_INVALIDO) {
            return 'rejected';
        }

        // resultCode finalizado mas sem documentStatus individual ainda
        // disponivel (nao devia acontecer segundo a documentacao, mas
        // por seguranca nao assumimos sucesso silenciosamente).
        return 'pending';
    }
}
