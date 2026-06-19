<?php

namespace Onsoft\Agt\Servicos;

use Onsoft\Agt\Excecoes\ExcecaoAssinaturaAgt;

/**
 * ServicoAssinatura
 *
 * ══════════════════════════════════════════════════════════════════════
 * RECONSTRUÍDO a partir da documentação OFICIAL da AGT:
 * https://quiosqueagt.minfin.gov.ao/doc-agt/faturacao-electronica/1/estrutura.html
 *
 * O algoritmo anterior (RSA-SHA1 sobre string ";"-concatenada, baseado
 * no Decreto Executivo / Anexo II) NÃO corresponde à API REST real da
 * AGT. A API REST usa JWS RS256 (RSA+SHA256) sobre o OBJECTO JSON
 * COMPLETO, em Base64URL sem padding. Esta classe foi reescrita do
 * zero para reflectir exactamente essa especificação.
 * ══════════════════════════════════════════════════════════════════════
 *
 * TRÊS ASSINATURAS DISTINTAS (todas RS256, todas sobre o JSON completo
 * do respectivo objecto — nunca concatenação de campos):
 *
 *   jwsSoftwareSignature — assina softwareInfoDetail
 *     { productId, productVersion, softwareValidationNumber }
 *     com a CHAVE DO SOFTWARE (gerada localmente pelo produtor)
 *
 *   jwsDocumentSignature — assina os campos principais do documento
 *     { documentNo, taxRegistrationNumber, documentType, documentDate,
 *       customerTaxID, customerCountry, companyName, documentTotals }
 *     com a CHAVE DO CONTRIBUINTE (emitida pela AGT, no portal do
 *     contribuinte — NUNCA gerada localmente)
 *
 *   jwsSignature — assina o payload do PEDIDO em si (varia por serviço,
 *     ex: {taxRegistrationNumber, requestID} para obterEstado)
 *     com a CHAVE DO CONTRIBUINTE
 *
 * Regras de JSON canónico (documentação "Estrutura das Assinaturas"):
 *   1. Sem quebras de linha.
 *   2. Sem espaços ou indentação.
 *   3. Aspas duplas sempre obrigatórias.
 *   4. Números sem formatação adicional.
 */
class ServicoAssinatura
{
    /**
     * Assinar um objecto com RS256, devolvendo o JWS Compact
     * Serialization completo (header.payload.signature).
     *
     * Usar para jwsSoftwareSignature, jwsDocumentSignature e
     * jwsSignature — a única diferença entre os três é QUE objecto se
     * passa em $payload e QUE chave privada se usa.
     *
     * @param array  $payload       Objecto a assinar — EXACTAMENTE os
     *                               campos exigidos pela documentação
     *                               para esta assinatura, nem mais nem
     *                               menos.
     * @param string $chavePrivadaPem Chave privada RSA em formato PEM
     *                               (mínimo 2048 bits)
     * @return string JWS compacto: base64url(header).base64url(payload).base64url(signature)
     */
    public function assinarJws(array $payload, string $chavePrivadaPem): string
    {
        $cabecalho = ['alg' => 'RS256', 'typ' => 'JWT'];

        $cabecalhoB64 = $this->base64UrlCodificar($this->jsonCanonico($cabecalho));
        $payloadB64   = $this->base64UrlCodificar($this->jsonCanonico($payload));
        $entradaAssinar = $cabecalhoB64 . '.' . $payloadB64;

        $chave = openssl_pkey_get_private($chavePrivadaPem);

        if (!$chave) {
            throw new ExcecaoAssinaturaAgt(
                'Chave privada RSA inválida ao assinar JWS: ' . openssl_error_string()
            );
        }

        $assinaturaBinaria = '';
        $resultado = openssl_sign($entradaAssinar, $assinaturaBinaria, $chave, OPENSSL_ALGO_SHA256);

        if (!$resultado) {
            throw new ExcecaoAssinaturaAgt(
                'Falha ao gerar assinatura RS256: ' . openssl_error_string()
            );
        }

        return $entradaAssinar . '.' . $this->base64UrlCodificar($assinaturaBinaria);
    }

    /**
     * Verificar uma assinatura JWS RS256 contra uma chave pública.
     */
    public function verificarJws(string $jws, string $chavePublicaPem): bool
    {
        $partes = explode('.', $jws);
        if (count($partes) !== 3) {
            return false;
        }

        [$cabecalhoB64, $payloadB64, $sigB64] = $partes;
        $entradaAssinar = $cabecalhoB64 . '.' . $payloadB64;
        $assinatura     = $this->base64UrlDecodificar($sigB64);

        return openssl_verify($entradaAssinar, $assinatura, $chavePublicaPem, OPENSSL_ALGO_SHA256) === 1;
    }

    // ══════════════════════════════════════════════════════════════════
    // HELPERS DE ALTO NÍVEL — UM POR TIPO DE ASSINATURA
    // ══════════════════════════════════════════════════════════════════

    /**
     * jwsSoftwareSignature — assina softwareInfoDetail.
     *
     * Campos EXACTOS exigidos pela documentação (Registar Factura,
     * secção "Composição properties do object softwareInfo"):
     *   "É utilizado o conteúdo total do objecto softwareInfoDetail"
     *
     * IMPORTANTE — campo signatureVersion encontrado nesta auditoria:
     * a documentação ("Solicitar Criação de Série", secção
     * "Composição properties do object softwareInfoDetail") lista
     * signatureVersion como campo OBRIGATÓRIO de softwareInfoDetail —
     * "Número de assinatura a aplicar... permite a transição de uma
     * assinatura em fase de descontinuação para outra que entrou em
     * vigor." Omitido em versões anteriores desta reconstrução.
     *
     * Incerteza documentada: não está 100% claro se signatureVersion
     * entra no payload ASSINADO (interpretação adoptada aqui, por ser
     * a leitura mais conservadora de "conteúdo total do objecto") ou
     * se é apenas incluído no envelope fora da assinatura. Ajustar se
     * a AGT rejeitar com erro de assinatura inválida em sandbox.
     */
    public function assinarSoftwareInfo(
        string $productId,
        string $productVersion,
        string $softwareValidationNumber,
        string $chavePrivadaSoftwarePem,
        int    $signatureVersion = 1
    ): string {
        return $this->assinarJws([
            'productId'                => $productId,
            'productVersion'           => $productVersion,
            'softwareValidationNumber' => $softwareValidationNumber,
            'signatureVersion'         => $signatureVersion,
        ], $chavePrivadaSoftwarePem);
    }

    /**
     * jwsDocumentSignature — assina os campos principais do documento
     * fiscal. Campos EXACTOS exigidos pela documentação (Registar
     * Factura, campo "jwsDocumentSignature"):
     *   documentNo, taxRegistrationNumber, documentType, documentDate,
     *   customerTaxID, customerCountry, companyName, documentTotals
     */
    public function assinarDocumento(
        string $documentNo,
        string $taxRegistrationNumber,
        string $documentType,
        string $documentDate,
        string $customerTaxID,
        string $customerCountry,
        string $companyName,
        array  $documentTotals, // ['taxPayable' => float, 'netTotal' => float, 'grossTotal' => float, ...]
        string $chavePrivadaContribuintePem
    ): string {
        return $this->assinarJws([
            'documentNo'            => $documentNo,
            'taxRegistrationNumber' => $taxRegistrationNumber,
            'documentType'          => $documentType,
            'documentDate'          => $documentDate,
            'customerTaxID'         => $customerTaxID,
            'customerCountry'       => $customerCountry,
            'companyName'           => $companyName,
            'documentTotals'        => $documentTotals,
        ], $chavePrivadaContribuintePem);
    }

    /**
     * jwsSignature — assina o payload do pedido. O conjunto de campos
     * VARIA por serviço (ver documentação de cada serviço — "Payload
     * assinatura X"), por isso este método aceita o array já montado
     * pelo chamador em vez de assumir uma estrutura fixa.
     *
     * Exemplos de uso (campos exactos por serviço):
     *   obterEstado:        ['taxRegistrationNumber', 'requestID']
     *   solicitarSerie:     ['taxRegistrationNumber', 'seriesYear',
     *                         'documentType', 'establishmentNumber',
     *                         'seriesContingencyIndicator']
     *   consultarFactura:   ['taxRegistrationNumber', 'documentNo']
     *   validarDocumento:   ['taxRegistrationNumber', 'documentNo',
     *                         'action', 'deductibleVATPercentage'?,
     *                         'nonDeductibleAmount'?]
     *   listarFacturas:     ['taxRegistrationNumber', 'queryStartDate',
     *                         'queryEndDate']
     */
    public function assinarPedido(array $camposDoServico, string $chavePrivadaContribuintePem): string
    {
        return $this->assinarJws($camposDoServico, $chavePrivadaContribuintePem);
    }

    // ══════════════════════════════════════════════════════════════════
    // GERAÇÃO E VALIDAÇÃO DE CHAVES
    // ══════════════════════════════════════════════════════════════════

    /**
     * Gerar par de chaves RSA — usar APENAS para a chave do SOFTWARE.
     *
     * A chave do CONTRIBUINTE nunca é gerada localmente — é emitida
     * pela AGT e disponibilizada no portal do contribuinte (ver
     * documentação "Gestão de Certificados e Chaves").
     *
     * @param int $bits Mínimo 2048 (documentação recomenda 4096)
     */
    public function gerarParDeChavesRsa(int $bits = 2048): array
    {
        if ($bits < 2048) {
            throw new ExcecaoAssinaturaAgt(
                "Tamanho de chave inválido ({$bits} bits) — a AGT exige no mínimo 2048 bits."
            );
        }

        $recurso = openssl_pkey_new([
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if (!$recurso) {
            throw new ExcecaoAssinaturaAgt(
                'Não foi possível gerar par de chaves RSA: ' . openssl_error_string()
            );
        }

        openssl_pkey_export($recurso, $chavePrivada);
        $detalhes     = openssl_pkey_get_details($recurso);
        $chavePublica = $detalhes['key'] ?? null;

        if (!$chavePrivada || !$chavePublica) {
            throw new ExcecaoAssinaturaAgt('Erro ao exportar par de chaves RSA.');
        }

        return [
            'chave_privada' => $chavePrivada,
            'chave_publica' => $chavePublica,
        ];
    }

    /**
     * Validar se a chave privada corresponde à chave pública.
     */
    public function validarParDeChaves(string $chavePrivadaPem, string $chavePublicaPem): bool
    {
        $chavePrivada = openssl_pkey_get_private($chavePrivadaPem);

        if (!$chavePrivada) {
            return false;
        }

        $detalhes = openssl_pkey_get_details($chavePrivada);

        return trim($detalhes['key'] ?? '') === trim($chavePublicaPem);
    }

    // ══════════════════════════════════════════════════════════════════
    // AUXILIARES PRIVADOS
    // ══════════════════════════════════════════════════════════════════

    /**
     * Converter para JSON canónico conforme as 4 regras da documentação:
     * sem quebras de linha, sem espaços/indentação, aspas duplas
     * sempre, números sem formatação adicional.
     *
     * json_encode() do PHP já satisfaz isto por padrão (sem flags de
     * formatação) — esta função existe para tornar a intenção explícita
     * e centralizar os flags correctos (sem escapar unicode/slashes
     * desnecessariamente, o que alteraria a representação canónica).
     */
    private function jsonCanonico(array $dados): string
    {
        return json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function base64UrlCodificar(string $dados): string
    {
        return rtrim(strtr(base64_encode($dados), '+/', '-_'), '=');
    }

    private function base64UrlDecodificar(string $dados): string
    {
        return base64_decode(strtr($dados, '-_', '+/'));
    }
}
