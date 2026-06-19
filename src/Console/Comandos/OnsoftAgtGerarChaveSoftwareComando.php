<?php

namespace Onsoft\Agt\Console\Comandos;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Onsoft\Agt\Servicos\ServicoAssinatura;

/**
 * OnsoftAgtGerarChaveSoftwareComando
 *
 * ══════════════════════════════════════════════════════════════════════
 * PORQUÊ ESTE COMANDO EXISTE
 * ══════════════════════════════════════════════════════════════════════
 * Antes deste comando, gerar a chave do SOFTWARE exigia abrir
 * `php artisan tinker` e colar manualmente um bloco de código com
 * `file_put_contents()` — sujeito a erros de copy/paste (heredocs,
 * escaping, esquecer de guardar o caminho certo). Este comando faz o
 * mesmo de forma directa, fiável, e sem sair da linha de comandos.
 *
 * IMPORTANTE — qual chave é esta:
 * Esta é a chave do SOFTWARE (gerada localmente por ti, o produtor),
 * NUNCA a chave do CONTRIBUINTE (essa é emitida pela AGT e obtida no
 * portal do contribuinte — não pode ser gerada por este comando nem
 * por nenhum outro mecanismo local).
 *
 * Fluxo correcto:
 *   1. php artisan onsoft-agt:gerar-chave-software
 *   2. Copiar a chave PÚBLICA gerada para o Portal do Parceiro AGT
 *      (sandbox: https://portaldoparceiro.hml.minfin.gov.ao/)
 *   3. A AGT devolve um número de certificação — colar em
 *      AGT_SOFTWARE_NUMERO_CERTIFICACAO no .env
 *   4. Colar a chave PRIVADA gerada em AGT_SOFTWARE_CHAVE_PRIVADA
 */
class OnsoftAgtGerarChaveSoftwareComando extends Command
{
    protected $signature = 'onsoft-agt:gerar-chave-software
                            {--bits=2048 : Tamanho da chave RSA (mínimo 2048, recomendado 4096)}
                            {--forcar : Substituir chaves existentes sem perguntar}
                            {--mostrar-env : Imprimir as linhas prontas para colar no .env}';

    protected $description = 'Gerar o par de chaves RSA do SOFTWARE e guardar em storage/app/agt/';

    public function handle(): int
    {
        $bits = (int) $this->option('bits');

        $caminhoPrivada = storage_path('app/agt/software_privada.pem');
        $caminhoPublica = storage_path('app/agt/software_publica.pem');

        if ((File::exists($caminhoPrivada) || File::exists($caminhoPublica)) && !$this->option('forcar')) {
            $this->warn('Já existem chaves do software em storage/app/agt/.');
            if (!$this->confirm('Substituir as chaves existentes? Documentos assinados com a chave antiga deixam de poder ser verificados com a nova.', false)) {
                $this->info('Operação cancelada — chaves existentes mantidas.');
                return self::SUCCESS;
            }
        }

        $this->info("🔑 Gerando par de chaves RSA de {$bits} bits...");

        try {
            $par = (new ServicoAssinatura())->gerarParDeChavesRsa($bits);
        } catch (\Throwable $e) {
            $this->error('Falha ao gerar as chaves: ' . $e->getMessage());
            return self::FAILURE;
        }

        // Garantir que o directório existe (mesma pasta que
        // OnsoftAgtInstalarComando já cria, mas confirmamos por segurança
        // — este comando pode correr antes ou independentemente dele).
        $dirChaves = storage_path('app/agt');
        if (!File::exists($dirChaves)) {
            File::makeDirectory($dirChaves, 0750, true);
        }

        File::put($caminhoPrivada, $par['chave_privada']);
        File::put($caminhoPublica, $par['chave_publica']);

        // Permissões restritivas na chave privada — só o utilizador
        // dono do processo deve poder ler este ficheiro.
        @chmod($caminhoPrivada, 0600);

        $this->info('✅ Chaves geradas e guardadas:');
        $this->line("   Privada: {$caminhoPrivada}");
        $this->line("   Pública: {$caminhoPublica}");
        $this->newLine();

        $this->warn('⚠️  Esta é a chave do SOFTWARE — gerada localmente por ti.');
        $this->warn('    A chave do CONTRIBUINTE é diferente: é emitida pela AGT e');
        $this->warn('    obtida no portal do contribuinte — este comando NÃO a gera.');
        $this->newLine();

        $this->line('<comment>Próximos passos:</comment>');
        $this->line('  1. Copie a chave PÚBLICA (acima) para o Portal do Parceiro AGT:');
        $this->line('     Sandbox:   https://portaldoparceiro.hml.minfin.gov.ao/');
        $this->line('     Produção:  https://portaldoparceiro.minfin.gov.ao/');
        $this->line('  2. A AGT devolve um número de certificação (ex: C_134).');
        $this->line('  3. Configure o .env com os valores abaixo.');
        $this->newLine();

        if ($this->option('mostrar-env') || $this->confirm('Mostrar as linhas prontas para colar no .env?', true)) {
            $this->mostrarBlocoEnv($par['chave_privada'], $par['chave_publica']);
        }

        return self::SUCCESS;
    }

    /**
     * Converter o PEM (com quebras de linha reais) para o formato de
     * uma única linha com "\n" literal, exigido pelo ficheiro .env
     * (que não suporta valores multi-linha directamente).
     */
    private function paraFormatoEnv(string $pem): string
    {
        return str_replace(["\r\n", "\n"], '\n', trim($pem));
    }

    private function mostrarBlocoEnv(string $chavePrivada, string $chavePublica): void
    {
        $this->newLine();
        $this->line('<comment># ── Cole estas linhas no seu .env ──────────────────────────</comment>');
        $this->line('AGT_SOFTWARE_CHAVE_PRIVADA="' . $this->paraFormatoEnv($chavePrivada) . '"');
        $this->line('AGT_SOFTWARE_CHAVE_PUBLICA="' . $this->paraFormatoEnv($chavePublica) . '"');
        $this->line('AGT_SOFTWARE_NUMERO_CERTIFICACAO=  # preencher depois do registo no Portal do Parceiro');
        $this->newLine();
    }
}
