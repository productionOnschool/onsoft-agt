<?php

namespace Onsoft\Agt\Testes;

use App\Models\Agt\OrganizationAgtConfig;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Onsoft\Agt\OnsoftAgtServiceProvider;

/**
 * TestCaseOnsoftAgt
 *
 * Classe base para os testes do pacote, usando Orchestra Testbench.
 *
 * IMPORTANTE: estes testes assumem um ambiente Laravel com os modelos
 * do projecto hospedeiro (Invoice, InvoiceItem, OrganizationAgtConfig,
 * etc.) já migrados. Correr dentro do projecto real, apontando para
 * uma base de dados de teste (SQLite em memória é suficiente desde
 * que as migrações do projecto + do pacote sejam ambas carregadas).
 *
 * Configuração mínima do phpunit.xml do projecto hospedeiro:
 *
 *   <env name="DB_CONNECTION" value="sqlite"/>
 *   <env name="DB_DATABASE" value=":memory:"/>
 */
abstract class TestCaseOnsoftAgt extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [OnsoftAgtServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $par = $this->gerarChaveRsaDeTeste();

        $app['config']->set('onsoft-agt.software.chave_privada', str_replace("\n", '\\n', $par['privada']));
        $app['config']->set('onsoft-agt.software.chave_publica', str_replace("\n", '\\n', $par['publica']));
        $app['config']->set('onsoft-agt.software.numero_certificacao', '9999');
    }

    /**
     * Criar uma organização com configuração AGT completa e activa -
     * cenário de modo 'electronic' totalmente operacional.
     */
    protected function criarOrganizacaoComAgtActivo(): int
    {
        static $contador = 0;
        $orgId = 9000 + (++$contador);

        $par = $this->gerarChaveRsaDeTeste();

        OrganizationAgtConfig::withoutGlobalScopes()->create([
            'organizationId'                 => $orgId,
            'agt_enabled'                    => true,
            'invoicing_mode'                 => 'electronic',
            'environment'                    => 'sandbox',
            'tax_registration_number'        => '500000000',
            'software_validation_number'     => '9999',
            'taxpayer_private_key_encrypted' => encrypt($par['privada']),
            'auto_submit_invoices'           => false,
        ]);

        return $orgId;
    }

    /**
     * Criar uma organização SEM nenhuma configuração AGT - cenário do
     * default automático introduzido na v1.14.0 (deve resultar em
     * modo 'saft_ao' sem nenhuma acção adicional).
     */
    protected function criarOrganizacaoSemConfigAgt(): int
    {
        static $contador = 0;
        return 8000 + (++$contador); // ID válido, sem registo em organization_agt_configs
    }

    /**
     * Gerar (uma vez por processo de teste) um par de chaves RSA válido
     * para assinatura - nunca usar chaves reais de produção em testes.
     */
    private function gerarChaveRsaDeTeste(): array
    {
        static $par = null;

        if ($par === null) {
            $recurso = openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]);
            openssl_pkey_export($recurso, $privada);
            $detalhes = openssl_pkey_get_details($recurso);

            $par = ['privada' => $privada, 'publica' => $detalhes['key']];
        }

        return $par;
    }
}
