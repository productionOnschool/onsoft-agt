<?php

namespace Onsoft\Agt\Servicos;

use App\Models\Agt\AgtSeries;
use Illuminate\Support\Facades\DB;
use Onsoft\Agt\Excecoes\ExcecaoSerieAgt;

/**
 * ServicoSeries
 *
 * Gestão de séries fiscais AGT.
 *
 * IMPORTANTE: Este serviço é 100% compatível com o AgtSeriesService
 * existente no projecto V-TEST. Mantém os mesmos métodos públicos
 * e usa o mesmo modelo AgtSeries e a tabela agt_series.
 *
 * Adiciona: sincronização com API AGT real, pedido de nova série à AGT.
 */
class ServicoSeries
{
    /**
     * Garantir que existe uma série fiscal para org + tipo de documento.
     * Cria automaticamente se não existir.
     *
     * Compatível com: AgtSeriesService::ensureFiscalYearSeries()
     */
    public function garantirSerieFiscal(int $organizacaoId, string $tipoDocumento): AgtSeries
    {
        $ano        = (int) now()->format('Y');
        $codigoSerie = strtoupper($tipoDocumento) . '-' . $ano;

        return AgtSeries::firstOrCreate(
            [
                'organizationId' => $organizacaoId,
                'document_type'  => strtoupper($tipoDocumento),
                'fiscal_year'    => $ano,
            ],
            [
                'series_code' => $codigoSerie,
                'active'      => true,
                'agt_payload' => ['current_number' => 0, 'source' => 'onsoft_agt_auto'],
            ]
        );
    }

    /**
     * Obter o próximo número de documento para uma série (thread-safe).
     * Retorna string formatada: "FR FR-2026/000001"
     *
     * Compatível com: AgtSeriesService::nextDocumentNumber()
     */
    public function proximoNumeroDocumento(AgtSeries $serie): string
    {
        return DB::transaction(function () use ($serie) {
            $bloqueada = AgtSeries::whereKey($serie->id)->lockForUpdate()->firstOrFail();

            $proximo = ((int) ($bloqueada->agt_payload['current_number'] ?? 0)) + 1;

            $payload                   = $bloqueada->agt_payload ?? [];
            $payload['current_number'] = $proximo;
            $bloqueada->update(['agt_payload' => $payload]);

            return sprintf(
                '%s %s/%06d',
                strtoupper($bloqueada->document_type),
                $bloqueada->series_code,
                $proximo
            );
        });
    }

    /**
     * Extrair o número sequencial de um número de documento formatado.
     * Ex: "FR FR-2026/000001" => 1
     */
    public function extrairNumeroSequencial(string $numeroDocumento): int
    {
        if (preg_match('/\/(\d+)$/', $numeroDocumento, $correspondencias)) {
            return (int) $correspondencias[1];
        }
        return 1;
    }

    /**
     * Inicializar/repor séries do ano fiscal para todos os tipos de documento.
     *
     * Compatível com: AgtSeriesService::resetFiscalYearSequences()
     */
    public function inicializarSeriesAnoFiscal(int $organizacaoId, int $ano): array
    {
        // Usar a mesma restrição de âmbito configurada em
        // onsoft-agt.tipos_activos (por defeito: FT, FR, NC, ND) — em
        // vez de uma lista fixa independente, que podia divergir e
        // criar séries para tipos que este sistema concreto não usa.
        $tipos   = array_map('strtoupper', config('onsoft-agt.tipos_activos', ['FT', 'FR', 'NC', 'ND']));
        $criadas = [];

        DB::transaction(function () use ($organizacaoId, $ano, $tipos, &$criadas) {
            foreach ($tipos as $tipo) {
                $criadas[] = AgtSeries::firstOrCreate(
                    [
                        'organizationId' => $organizacaoId,
                        'document_type'  => $tipo,
                        'fiscal_year'    => $ano,
                    ],
                    [
                        'series_code' => $tipo . '-' . $ano,
                        'active'      => true,
                        'agt_payload' => ['current_number' => 0, 'source' => 'onsoft_agt_init'],
                    ]
                );
            }
        });

        return $criadas;
    }

    /**
     * Obter a série activa para um tipo de documento.
     */
    public function obterSerieActiva(int $organizacaoId, string $tipoDocumento): ?AgtSeries
    {
        return AgtSeries::where('organizationId', $organizacaoId)
            ->where('document_type', strtoupper($tipoDocumento))
            ->where('fiscal_year', now()->year)
            ->where('active', true)
            ->latest()
            ->first();
    }

    /**
     * Pedir uma nova série à API AGT e guardar localmente.
     *
     * Resposta REAL da AGT (documentação "Solicitar Criação de Série"):
     *   { resultCode, errorList, seriesFEResult: { seriesCode,
     *     authorizedQuantity, firstDocumentNo, lastDocumentNo } }
     *
     * @param array $dados ['documentType' => 'FT', 'establishmentNumber' => 'SEDE',
     *                       'seriesYear' => 2026, 'seriesContingencyIndicator' => 'N']
     */
    public function pedirSerieNaAgt(int $organizacaoId, array $dados, ServicoApiAgt $apiAgt): AgtSeries
    {
        $resposta = $apiAgt->solicitarSerie(
            seriesYear:                 (int) ($dados['seriesYear'] ?? now()->year),
            documentType:               strtoupper($dados['documentType']),
            establishmentNumber:        $dados['establishmentNumber'] ?? 'SEDE',
            seriesContingencyIndicator: $dados['seriesContingencyIndicator'] ?? 'N',
        );

        if (!empty($resposta['errorList']) || empty($resposta['seriesFEResult']['seriesCode'])) {
            $erro = $resposta['errorList'][0]['descriptionError']
                ?? $resposta['errorList'][0]['errorDescription']
                ?? 'Erro desconhecido';
            throw new ExcecaoSerieAgt("AGT rejeitou o pedido de série: {$erro}");
        }

        $resultado = $resposta['seriesFEResult'];

        return AgtSeries::updateOrCreate(
            [
                'organizationId' => $organizacaoId,
                'document_type'  => strtoupper($dados['documentType']),
                'fiscal_year'    => (int) ($dados['seriesYear'] ?? now()->year),
                'series_code'    => $resultado['seriesCode'],
            ],
            [
                'active'      => true,
                'agt_payload' => [
                    'current_number'     => 0,
                    'authorized_quantity' => $resultado['authorizedQuantity'] ?? null,
                    'first_document_no'   => $resultado['firstDocumentNo'] ?? null,
                    'last_document_no'    => $resultado['lastDocumentNo'] ?? null,
                    'establishment_number' => $dados['establishmentNumber'] ?? 'SEDE',
                    'contingency_indicator' => $dados['seriesContingencyIndicator'] ?? 'N',
                ],
            ]
        );
    }

    /**
     * Sincronizar séries da API AGT para a base de dados local.
     *
     * Resposta REAL da AGT (documentação "Listar Séries"):
     *   { resultCode, seriesResultCount, seriesInfo: [ { seriesCode,
     *     seriesYear, documentType, seriesStatus (A|U|F),
     *     seriesCreationDate, firstDocumentApproved, lastDocumentApproved,
     *     firstDocumentCreated, lastDocumentCreated, invoicingMethod
     *     (FEPC|FESF|SF), seriesContingencyIndicator } ] }
     */
    public function sincronizarDaAgt(int $organizacaoId, ServicoApiAgt $apiAgt): array
    {
        $resposta      = $apiAgt->listarSeries();
        $sincronizadas = 0;
        $erros         = [];

        foreach ($resposta['seriesInfo'] ?? [] as $item) {
            try {
                $ano = (int) ($item['seriesYear'] ?? now()->year);

                AgtSeries::updateOrCreate(
                    [
                        'organizationId' => $organizacaoId,
                        'document_type'  => $item['documentType'] ?? '',
                        'series_code'    => $item['seriesCode'] ?? '',
                        'fiscal_year'    => $ano,
                    ],
                    [
                        // seriesStatus: A-aberta, U-em utilização, F-fechada
                        'active'      => ($item['seriesStatus'] ?? '') !== 'F',
                        'agt_payload' => [
                            'series_status'           => $item['seriesStatus'] ?? null,
                            'series_creation_date'    => $item['seriesCreationDate'] ?? null,
                            'first_document_approved' => $item['firstDocumentApproved'] ?? null,
                            'last_document_approved'  => $item['lastDocumentApproved'] ?? null,
                            'first_document_created'  => $item['firstDocumentCreated'] ?? null,
                            'last_document_created'   => $item['lastDocumentCreated'] ?? null,
                            'invoicing_method'        => $item['invoicingMethod'] ?? null, // FEPC|FESF|SF
                            'contingency_indicator'   => $item['seriesContingencyIndicator'] ?? null,
                            'current_number'          => 0,
                        ],
                    ]
                );
                $sincronizadas++;
            } catch (\Throwable $e) {
                $erros[] = $e->getMessage();
            }
        }

        return ['sincronizadas' => $sincronizadas, 'erros' => $erros];
    }
}
