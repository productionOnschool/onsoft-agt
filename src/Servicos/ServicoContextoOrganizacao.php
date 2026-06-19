<?php

namespace Onsoft\Agt\Servicos;

use App\Models\Agt\OrganizationAgtConfig;
use Illuminate\Support\Facades\Crypt;
use Onsoft\Agt\Excecoes\ExcecaoConfiguracaoAgt;

/**
 * ServicoContextoOrganizacao
 *
 * Resolve todos os serviços AGT no contexto de uma organização específica.
 *
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║  ARQUITECTURA DE CHAVES (conforme Decreto Executivo AGT Angola) ║
 * ╠══════════════════════════════════════════════════════════════════╣
 * ║                                                                  ║
 * ║  CHAVE DO SOFTWARE (Fabricante — Onsoft/Adilson Miguel)          ║
 * ║  ─────────────────────────────────────────────────────────────   ║
 * ║  • Representa o fabricante do software de faturação              ║
 * ║  • Registada na AGT uma única vez via Declaração Modelo 8        ║
 * ║  • Partilhada por TODAS as organizações que usam este software   ║
 * ║  • Guardada no .env do servidor (AGT_SOFTWARE_CHAVE_PRIVADA)     ║
 * ║  • Nunca vai para a base de dados                                ║
 * ║  • Usada para: jwsSoftwareSignature                              ║
 * ║                                                                  ║
 * ║  CHAVE DO CONTRIBUINTE (Cada organização/escola)                 ║
 * ║  ─────────────────────────────────────────────────────────────   ║
 * ║  • Representa a empresa que emite as faturas (cada escola)       ║
 * ║  • Cada organização tem a sua própria chave                      ║
 * ║  • Guardada encriptada na BD (organization_agt_configs)          ║
 * ║  • Desencriptada em memória via Laravel Crypt (APP_KEY)          ║
 * ║  • Usada para: invoice_hash + jwsDocumentSignature               ║
 * ║                                                                  ║
 * ╚══════════════════════════════════════════════════════════════════╝
 */
class ServicoContextoOrganizacao
{
    private OrganizationAgtConfig $configuracao;
    private ?ServicoAssinatura    $assinatura = null;
    private ?ServicoApiAgt        $api        = null;
    private ?ServicoSeries        $series     = null;
    private ?ServicoSubmissao     $submissao  = null;

    public function __construct(private int $organizacaoId)
    {
        $config = OrganizationAgtConfig::withoutGlobalScopes()
            ->where('organizationId', $organizacaoId)
            ->first();

        if (!$config) {
            throw new ExcecaoConfiguracaoAgt(
                "A organização [{$organizacaoId}] não tem configuração AGT. " .
                "Configure em: AGT → Configuração."
            );
        }

        $this->configuracao = $config;
    }

    // ──────────────────────────────────────────────────────────────────
    // Informações da organização
    // ──────────────────────────────────────────────────────────────────

    public function organizacaoId(): int
    {
        return $this->organizacaoId;
    }

    public function configuracao(): OrganizationAgtConfig
    {
        return $this->configuracao;
    }

    public function estaActivo(): bool
    {
        return (bool) $this->configuracao->agt_enabled;
    }

    public function ambiente(): string
    {
        return $this->configuracao->environment ?? config('onsoft-agt.ambiente', 'sandbox');
    }

    public function nif(): string
    {
        return $this->configuracao->tax_registration_number ?? '';
    }

    // ──────────────────────────────────────────────────────────────────
    // CHAVE DO CONTRIBUINTE (por organização — vem da BD encriptada)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Chave privada do CONTRIBUINTE (cada organização/escola).
     *
     * Vem da tabela organization_agt_configs, campo taxpayer_private_key_encrypted.
     * Desencriptada automaticamente pelo accessor do modelo
     * (getTaxpayerPrivateKeyAttribute, via Laravel Crypt) — nunca escrita em disco.
     * Usada para: jwsDocumentSignature e jwsSignature (RS256 — NUNCA RSA-SHA1).
     * IMPORTANTE: esta chave é EMITIDA PELA AGT e disponibilizada no
     * portal do contribuinte — não é gerada localmente pelo software
     * (ver documentação "Gestão de Certificados e Chaves").
     */
    public function chavePrivadaContribuinte(): string
    {
        // Usa o accessor já existente no modelo (getTaxpayerPrivateKeyAttribute),
        // que desencripta taxpayer_private_key_encrypted automaticamente —
        // evita duplicar a lógica de desencriptação aqui.
        $chave = $this->configuracao->taxpayer_private_key ?? null;

        if (empty($chave)) {
            throw new ExcecaoConfiguracaoAgt(
                "Organização [{$this->organizacaoId}]: " .
                "chave privada do contribuinte não configurada. " .
                "Configure em: AGT → Configuração → Chaves do Contribuinte."
            );
        }

        return $chave;
    }

    /**
     * Chave pública do CONTRIBUINTE.
     * Guardada em texto simples na BD (não é secreta).
     */
    public function chavePublicaContribuinte(): string
    {
        return $this->configuracao->taxpayer_public_key ?? '';
    }

    // ──────────────────────────────────────────────────────────────────
    // CHAVE DO SOFTWARE (fabricante — vem do .env, partilhada por todos)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Chave privada do SOFTWARE (fabricante Onsoft).
     *
     * Vem do .env: AGT_SOFTWARE_CHAVE_PRIVADA
     * É a mesma para TODAS as organizações que usam este software.
     * Registada na AGT via Declaração Modelo 8 pelo fabricante.
     * Usada para: jwsSoftwareSignature (RS256)
     */
    public function chavePrivadaSoftware(): string
    {
        // Lê directamente do .env — nunca da BD
        $chave = config('onsoft-agt.software.chave_privada', '');

        if (empty($chave)) {
            throw new ExcecaoConfiguracaoAgt(
                "Chave privada do software não configurada no .env. " .
                "Adicione: AGT_SOFTWARE_CHAVE_PRIVADA=\"-----BEGIN RSA PRIVATE KEY-----\\n...\\n-----END RSA PRIVATE KEY-----\""
            );
        }

        // Converter \n em quebras de linha reais (para chaves guardadas numa linha no .env)
        return str_replace('\n', "\n", $chave);
    }

    /**
     * Chave pública do SOFTWARE (fabricante Onsoft).
     *
     * Vem do .env: AGT_SOFTWARE_CHAVE_PUBLICA
     * Entregue à AGT via Declaração Modelo 8.
     */
    public function chavePublicaSoftware(): string
    {
        $chave = config('onsoft-agt.software.chave_publica', '');
        return str_replace('\n', "\n", $chave);
    }

    /**
     * Número de certificação do software atribuído pela AGT.
     * Impresso em todas as faturas: "Processado por programa validado nº 0000/AGT"
     */
    public function numeroCertificacaoSoftware(): string
    {
        return config('onsoft-agt.software.numero_certificacao', '');
    }

    /**
     * Versão actual da chave privada do software (inteiro sequencial).
     * Usado no HashControl conforme AGT spec ponto 5.c.
     */
    public function versaoChaveSoftware(): int
    {
        return (int) config('onsoft-agt.software.versao_chave', 1);
    }

    // ──────────────────────────────────────────────────────────────────
    // Serviços (lazy, cached por instância)
    // ──────────────────────────────────────────────────────────────────

    public function servicoAssinatura(): ServicoAssinatura
    {
        return $this->assinatura ??= new ServicoAssinatura();
    }

    public function servicoApi(): ServicoApiAgt
    {
        return $this->api ??= new ServicoApiAgt(
            assinatura:               $this->servicoAssinatura(),
            taxRegistrationNumber:    $this->nif(),
            chavePrivadaContribuinte: $this->chavePrivadaContribuinte(),
            chavePrivadaSoftware:     $this->chavePrivadaSoftware(),
            productId:                config('onsoft-agt.software.nome', 'Onsoft AGT'),
            productVersion:           config('onsoft-agt.software.versao', '1.0.0'),
            softwareValidationNumber: $this->numeroCertificacaoSoftware(),
            basicAuthUsername:        $this->basicAuthUsername(),
            basicAuthPassword:        $this->basicAuthPassword(),
        );
    }

    /**
     * Credenciais de Basic Auth — emitidas pela AGT por email
     * (produtores.dfe.dcrr.agt@minfin.gov.ao), conforme documentação
     * "Autenticação & Autorização". São credenciais do CONTRIBUINTE
     * (não do software), guardadas em organization_agt_configs nos
     * campos agt_username / agt_password_encrypted — JÁ EXISTENTES no
     * modelo do projecto hospedeiro, com mutator/accessor próprios
     * (getAgtPasswordAttribute/setAgtPasswordAttribute) que já tratam
     * a encriptação via Laravel Crypt. Não duplicar esses campos.
     */
    public function basicAuthUsername(): string
    {
        return $this->configuracao->agt_username ?? '';
    }

    public function basicAuthPassword(): string
    {
        // Usa o accessor já existente no modelo (getAgtPasswordAttribute),
        // que desencripta agt_password_encrypted automaticamente.
        $password = $this->configuracao->agt_password ?? null;

        if (empty($password)) {
            throw new ExcecaoConfiguracaoAgt(
                "Organização [{$this->organizacaoId}]: credenciais de Basic Auth da AGT não " .
                "configuradas. Solicite as credenciais por email a produtores.dfe.dcrr.agt@minfin.gov.ao " .
                "e configure em: AGT → Configuração → Credenciais de Acesso."
            );
        }

        return $password;
    }

    public function servicoSeries(): ServicoSeries
    {
        return $this->series ??= new ServicoSeries();
    }

    public function servicoSubmissao(): ServicoSubmissao
    {
        return $this->submissao ??= new ServicoSubmissao($this);
    }

    // ──────────────────────────────────────────────────────────────────
    // softwareInfo (bloco enviado à AGT em cada pedido)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Construir o bloco softwareInfoDetail para envio à API AGT.
     *
     * Campos EXACTOS conforme documentação (Registar Factura, secção
     * "Composição properties do object softwareInfoDetail") — apenas
     * productId, productVersion, softwareValidationNumber. Os campos
     * "softwareSupplierNIF" e "schemaVersion" da versão anterior NÃO
     * existem nesta estrutura — schemaVersion pertence ao envelope
     * principal do pedido, não a softwareInfoDetail.
     */
    public function construirSoftwareInfo(): array
    {
        return [
            'productId'                => config('onsoft-agt.software.nome', 'Onsoft AGT'),
            'productVersion'           => config('onsoft-agt.software.versao', '1.0.0'),
            'softwareValidationNumber' => $this->numeroCertificacaoSoftware(),
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // Validação
    // ──────────────────────────────────────────────────────────────────

    /**
     * Validar que a organização e o software estão correctamente configurados.
     * Retorna array de erros (vazio = tudo OK).
     */
    public function validar(): array
    {
        $erros = [];

        // ── Validar configuração do CONTRIBUINTE (por organização na BD) ──
        if (empty($this->configuracao->tax_registration_number)) {
            $erros[] = '[Contribuinte] NIF fiscal não configurado na organização.';
        }

        if (empty($this->configuracao->taxpayer_private_key_encrypted)) {
            $erros[] = '[Contribuinte] Chave privada do contribuinte não configurada. Configure em: AGT → Configuração → Chaves do Contribuinte.';
        } else {
            try {
                $chave = openssl_pkey_get_private($this->chavePrivadaContribuinte());
                if (!$chave) {
                    $erros[] = '[Contribuinte] Chave privada do contribuinte é inválida ou corrompida.';
                }
            } catch (\Throwable $e) {
                $erros[] = '[Contribuinte] Erro ao verificar chave do contribuinte: ' . $e->getMessage();
            }
        }

        // ── Validar configuração do SOFTWARE (no .env) ──
        if (empty(config('onsoft-agt.software.numero_certificacao'))) {
            $erros[] = '[Software] AGT_SOFTWARE_NUMERO_CERTIFICACAO não definido no .env.';
        }

        if (empty(config('onsoft-agt.software.chave_privada'))) {
            $erros[] = '[Software] AGT_SOFTWARE_CHAVE_PRIVADA não definida no .env.';
        } else {
            try {
                $chave = openssl_pkey_get_private($this->chavePrivadaSoftware());
                if (!$chave) {
                    $erros[] = '[Software] AGT_SOFTWARE_CHAVE_PRIVADA no .env é inválida ou corrompida.';
                }
            } catch (\Throwable $e) {
                $erros[] = '[Software] Erro ao verificar chave do software: ' . $e->getMessage();
            }
        }

        if (empty(config('onsoft-agt.software.chave_publica'))) {
            $erros[] = '[Software] AGT_SOFTWARE_CHAVE_PUBLICA não definida no .env (deve ser a chave enviada à AGT no Modelo 8).';
        }

        // ── Validar credenciais de Basic Auth (documentação "Autenticação") ──
        if (empty($this->configuracao->agt_username)) {
            $erros[] = '[Autenticação] Username de Basic Auth (agt_username) não configurado. Solicite por email a produtores.dfe.dcrr.agt@minfin.gov.ao.';
        }
        if (empty($this->configuracao->agt_password_encrypted)) {
            $erros[] = '[Autenticação] Password de Basic Auth (agt_password) não configurada. Solicite por email a produtores.dfe.dcrr.agt@minfin.gov.ao.';
        }

        return $erros;
    }
}
