<?php

namespace Onsoft\Agt\Servicos;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Onsoft\Agt\Excecoes\ExcecaoApiAgt;

/**
 * ServicoApiAgt
 *
 * RECONSTRUIDO a partir da documentacao OFICIAL da AGT:
 * https://quiosqueagt.minfin.gov.ao/doc-agt/faturacao-electronica/1/
 *
 * Mudancas face a versao anterior (que usava endpoints/paths/metodo
 * HTTP inventados, sem base na documentacao real):
 *
 *   - Autenticacao: HTTP Basic Auth (username:password em Base64),
 *     EM TODOS os pedidos - adicional as assinaturas JWS.
 *   - Hosts reais:
 *       Homologacao: https://sifphml.minfin.gov.ao
 *       Producao:    https://sifp.minfin.gov.ao
 *   - Prefixo real: /sigt/fe/v1/{servico}
 *   - TODOS os servicos sao POST (mesmo consultas/listagens).
 *   - Envelope de pedido uniforme: schemaVersion, submissionUUID,
 *     taxRegistrationNumber, submissionTimeStamp, softwareInfo, mais
 *     os campos especificos do servico e a respectiva jwsSignature.
 *   - "anularFactura" REMOVIDO - nao existe na documentacao oficial.
 *     A anulacao de um documento e feita atraves de uma Nota de
 *     Credito (NC) submetida via registarFactura.
 *   - Adicionados "listarFacturas" e "consultarFactura" com a
 *     estrutura real.
 */
class ServicoApiAgt
{
    private Client $http;
    private string $urlBase;
    private string $schemaVersion;

    public function __construct(
        private ServicoAssinatura $assinatura,
        private string $taxRegistrationNumber,
        private string $chavePrivadaContribuinte,
        private string $chavePrivadaSoftware,
        private string $productId,
        private string $productVersion,
        private string $softwareValidationNumber,
        private string $basicAuthUsername,
        private string $basicAuthPassword,
    ) {
        $ambiente            = config('onsoft-agt.ambiente', 'sandbox');
        $this->urlBase       = config("onsoft-agt.urls_base.{$ambiente}");
        $this->schemaVersion = config('onsoft-agt.schema_version', '1.2');

        $this->http = new Client([
            'base_uri' => $this->urlBase,
            'timeout'  => config('onsoft-agt.http.timeout', 30),
            'verify'   => config('onsoft-agt.http.verificar_ssl', true),
            'auth'     => [$this->basicAuthUsername, $this->basicAuthPassword],
        ]);
    }

    /**
     * POST /sigt/fe/v1/registarFactura
     */
    public function registarFactura(array $documentos): array
    {
        $limite = config('onsoft-agt.tamanho_lote', 30);
        if (count($documentos) > $limite) {
            throw new ExcecaoApiAgt("Limite de {$limite} facturas por lote excedido (documentacao AGT).");
        }

        $envelope = $this->envelopeBase();
        $envelope['numberOfEntries'] = count($documentos);
        $envelope['documents']       = $documentos;

        return $this->post('/sigt/fe/v1/registarFactura', $envelope);
    }

    /**
     * POST /sigt/fe/v1/obterEstado
     *
     * @param string $requestID Devolvido por registarFactura (NUNCA o
     *                           submissionUUID gerado pelo cliente).
     */
    public function obterEstado(string $requestID): array
    {
        $envelope = $this->envelopeBase();
        $envelope['requestID']    = $requestID;
        $envelope['jwsSignature'] = $this->assinatura->assinarPedido([
            'taxRegistrationNumber' => $this->taxRegistrationNumber,
            'requestID'             => $requestID,
        ], $this->chavePrivadaContribuinte);

        return $this->post('/sigt/fe/v1/obterEstado', $envelope);
    }

    /**
     * POST /sigt/fe/v1/solicitarSerie
     */
    public function solicitarSerie(
        int    $seriesYear,
        string $documentType,
        string $establishmentNumber,
        string $seriesContingencyIndicator = 'N'
    ): array {
        $envelope = $this->envelopeBase();
        $envelope['seriesYear']                 = $seriesYear;
        $envelope['documentType']               = $documentType;
        $envelope['establishmentNumber']        = $establishmentNumber;
        $envelope['seriesContingencyIndicator'] = $seriesContingencyIndicator;
        $envelope['jwsSignature'] = $this->assinatura->assinarPedido([
            'taxRegistrationNumber'      => $this->taxRegistrationNumber,
            'seriesYear'                 => $seriesYear,
            'documentType'               => $documentType,
            'establishmentNumber'        => $establishmentNumber,
            'seriesContingencyIndicator' => $seriesContingencyIndicator,
        ], $this->chavePrivadaContribuinte);

        return $this->post('/sigt/fe/v1/solicitarSerie', $envelope);
    }

    /**
     * POST /sigt/fe/v1/listarSeries
     */
    public function listarSeries(array $filtros = []): array
    {
        $envelope = $this->envelopeBase();
        foreach (['seriesCode', 'seriesYear', 'seriesStatus', 'documentType', 'establishmentNumber'] as $campo) {
            if (isset($filtros[$campo])) {
                $envelope[$campo] = $filtros[$campo];
            }
        }
        $envelope['jwsSignature'] = $this->assinatura->assinarPedido([
            'taxRegistrationNumber' => $this->taxRegistrationNumber,
        ], $this->chavePrivadaContribuinte);

        return $this->post('/sigt/fe/v1/listarSeries', $envelope);
    }

    /**
     * POST /sigt/fe/v1/consultarFactura
     */
    public function consultarFactura(string $documentNo): array
    {
        $envelope = $this->envelopeBase();
        $envelope['invoiceNo'] = $documentNo;
        $envelope['jwsSignature'] = $this->assinatura->assinarPedido([
            'taxRegistrationNumber' => $this->taxRegistrationNumber,
            'documentNo'            => $documentNo,
        ], $this->chavePrivadaContribuinte);

        return $this->post('/sigt/fe/v1/consultarFactura', $envelope);
    }

    /**
     * POST /sigt/fe/v1/listarFacturas
     *
     * NOTA: a documentacao usa "submissionGUID" neste servico em vez
     * de "submissionUUID" (inconsistencia da propria documentacao
     * oficial) - replicado tal como documentado.
     */
    public function listarFacturas(string $queryStartDate, string $queryEndDate): array
    {
        $envelope = $this->envelopeBase();
        unset($envelope['submissionUUID']);
        $envelope['submissionGUID'] = (string) Str::uuid();
        $envelope['queryStartDate'] = $queryStartDate;
        $envelope['queryEndDate']   = $queryEndDate;
        $envelope['jwsSignature']   = $this->assinatura->assinarPedido([
            'taxRegistrationNumber' => $this->taxRegistrationNumber,
            'queryStartDate'        => $queryStartDate,
            'queryEndDate'          => $queryEndDate,
        ], $this->chavePrivadaContribuinte);

        return $this->post('/sigt/fe/v1/listarFacturas', $envelope);
    }

    /**
     * POST /sigt/fe/v1/validarDocumento
     *
     * Usado pelo ADQUIRENTE (nao pelo emissor) para confirmar/rejeitar
     * uma factura recebida e declarar a percentagem de IVA dedutivel.
     *
     * @param string     $action 'C' (confirmar) ou 'R' (rejeitar)
     */
    public function validarDocumento(
        string $documentNo,
        string $action,
        ?float $deductibleVATPercentage = null,
        ?float $nonDeductibleAmount = null
    ): array {
        if ($deductibleVATPercentage !== null && $nonDeductibleAmount !== null) {
            throw new ExcecaoApiAgt(
                'deductibleVATPercentage e nonDeductibleAmount sao mutuamente exclusivos (documentacao AGT).'
            );
        }

        $envelope = $this->envelopeBase();
        $envelope['documentNo'] = $documentNo;
        $envelope['action']     = $action;

        $camposAssinatura = [
            'taxRegistrationNumber' => $this->taxRegistrationNumber,
            'documentNo'            => $documentNo,
            'action'                => $action,
        ];

        if ($deductibleVATPercentage !== null) {
            $envelope['deductibleVATPercentage'] = $deductibleVATPercentage;
            $camposAssinatura['deductibleVATPercentage'] = $deductibleVATPercentage;
        }
        if ($nonDeductibleAmount !== null) {
            $envelope['nonDeductibleAmount'] = $nonDeductibleAmount;
            $camposAssinatura['nonDeductibleAmount'] = $nonDeductibleAmount;
        }

        $envelope['jwsSignature'] = $this->assinatura->assinarPedido($camposAssinatura, $this->chavePrivadaContribuinte);

        return $this->post('/sigt/fe/v1/validarDocumento', $envelope);
    }

    /**
     * Construir o envelope base partilhado por todos os servicos:
     * schemaVersion, submissionUUID, taxRegistrationNumber,
     * submissionTimeStamp, softwareInfo (com jwsSoftwareSignature).
     */
    private function envelopeBase(): array
    {
        $signatureVersion = (int) config('onsoft-agt.software.versao_chave', 1);

        return [
            'schemaVersion'         => $this->schemaVersion,
            'submissionUUID'        => (string) Str::uuid(),
            'taxRegistrationNumber' => $this->taxRegistrationNumber,
            'submissionTimeStamp'   => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'softwareInfo'          => [
                'softwareInfoDetail' => [
                    'productId'                => $this->productId,
                    'productVersion'           => $this->productVersion,
                    'softwareValidationNumber' => $this->softwareValidationNumber,
                    // Campo obrigatório conforme documentação "Solicitar
                    // Criação de Série" — permite à AGT distinguir entre
                    // versões de assinatura activas/em descontinuação.
                    'signatureVersion'         => $signatureVersion,
                ],
                'jwsSoftwareSignature' => $this->assinatura->assinarSoftwareInfo(
                    $this->productId,
                    $this->productVersion,
                    $this->softwareValidationNumber,
                    $this->chavePrivadaSoftware,
                    $signatureVersion
                ),
            ],
        ];
    }

    private function post(string $endpoint, array $dados): array
    {
        $inicio = microtime(true);

        try {
            $resposta = $this->http->post($endpoint, [
                'json'    => $dados,
                'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
            ]);

            $corpo = json_decode($resposta->getBody()->getContents(), true) ?? [];
            $this->registarLog('POST', $endpoint, $resposta->getStatusCode(), microtime(true) - $inicio);

            return $corpo;

        } catch (RequestException $e) {
            $this->registarErro('POST', $endpoint, $e, microtime(true) - $inicio);
            throw new ExcecaoApiAgt($this->extrairMensagemErro($e), (int) $e->getCode(), $e);
        }
    }

    private function registarLog(string $metodo, string $endpoint, int $estado, float $duracao): void
    {
        Log::channel('stack')->info("OnsoftAgt [{$metodo}] {$endpoint}", [
            'estado' => $estado,
            'ms'     => round($duracao * 1000),
            'url'    => $this->urlBase . $endpoint,
        ]);
    }

    private function registarErro(string $metodo, string $endpoint, RequestException $e, float $duracao): void
    {
        Log::channel('stack')->error("OnsoftAgt ERRO [{$metodo}] {$endpoint}", [
            'estado'   => $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0,
            'mensagem' => $e->getMessage(),
            'ms'       => round($duracao * 1000),
        ]);
    }

    private function extrairMensagemErro(RequestException $e): string
    {
        if (!$e->hasResponse()) {
            return $e->getMessage();
        }

        $corpo = json_decode($e->getResponse()->getBody()->getContents(), true);

        if (!empty($corpo['errorList']) && is_array($corpo['errorList'])) {
            $primeiro = $corpo['errorList'][0] ?? [];
            $codigo   = $primeiro['idError'] ?? $primeiro['errorCode'] ?? '?';
            $desc     = $primeiro['descriptionError'] ?? $primeiro['errorDescription'] ?? 'Erro desconhecido';
            return "[{$codigo}] {$desc}";
        }

        return $corpo['descriptionError'] ?? $corpo['message'] ?? $e->getMessage();
    }
}
