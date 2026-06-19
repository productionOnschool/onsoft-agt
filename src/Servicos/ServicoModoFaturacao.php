<?php

namespace Onsoft\Agt\Servicos;

use App\Models\Agt\OrganizationAgtConfig;
use Illuminate\Support\Facades\DB;
use Onsoft\Agt\Excecoes\ExcecaoConfiguracaoAgt;

/**
 * ServicoModoFaturacao
 *
 * Controla a alternância entre os dois regimes fiscais suportados:
 *
 *   'electronic' — Faturação Eletrónica AGT
 *     Cada fatura é assinada (RS256/JWS) e submetida em tempo real.
 *     jwsDocumentSignature, jwsSoftwareSignature, QR Code.
 *     Comportamento actual por defeito do pacote.
 *
 *   'saft_ao' — Regime SAF-T (AO)
 *     Faturas continuam a ser criadas normalmente na BD, mas NÃO são
 *     submetidas em tempo real à AGT. Periodicamente é gerado um
 *     ficheiro XML SAF-T(AO) com todas as faturas do período
 *     (data de início e data de fim), entregue à AGT pelo canal
 *     próprio desse regime.
 *
 * A TROCA É SEMPRE REVERSÍVEL — em qualquer momento a organização
 * pode voltar de 'saft_ao' para 'electronic' e vice-versa. A troca
 * NUNCA apaga ou altera faturas já emitidas; afecta apenas o
 * comportamento das faturas criadas A PARTIR do momento da troca.
 */
class ServicoModoFaturacao
{
    public const ELECTRONIC = 'electronic';
    public const SAFT_AO    = 'saft_ao';

    public const MODOS_VALIDOS = [self::ELECTRONIC, self::SAFT_AO];

    /**
     * Obter o modo de faturação actual da organização.
     *
     * REGRA DE DEFAULT SEGURO:
     * ─────────────────────────────────────────────────────────────
     * Se não existir configuração AGT (organization_agt_configs sem
     * registo), OU se agt_enabled = false (AGT desligado pela escola),
     * o modo por defeito é 'saft_ao' — NUNCA 'electronic'.
     *
     * Razão: assumir 'electronic' sem chaves configuradas e sem AGT
     * activo faria o sistema tentar assinar e submeter documentos a
     * uma integração que não está pronta — falhando silenciosamente
     * ou gerando faturas com hash inválido. É mais seguro assumir
     * SAF-T(AO) (sem submissão em tempo real) até que a organização
     * configure e active explicitamente o regime electrónico.
     *
     * Só devolve 'electronic' quando existe config com agt_enabled=true
     * E invoicing_mode='electronic' explicitamente gravado.
     */
    public function modoActual(int $organizacaoId): string
    {
        return cache()->remember(
            "onsoft_agt_invoicing_mode_{$organizacaoId}",
            300,
            function () use ($organizacaoId) {
                $config = OrganizationAgtConfig::withoutGlobalScopes()
                    ->where('organizationId', $organizacaoId)
                    ->first();

                // Sem configuração nenhuma -> SAF-T(AO) por segurança
                if (!$config) {
                    return self::SAFT_AO;
                }

                // AGT explicitamente desligado pela organização -> SAF-T(AO)
                if (!$config->agt_enabled) {
                    return self::SAFT_AO;
                }

                // AGT activo mas invoicing_mode nunca foi definido
                // explicitamente -> assume electronic (comportamento
                // histórico para organizações já operacionais antes
                // desta coluna existir).
                return $config->invoicing_mode ?? self::ELECTRONIC;
            }
        );
    }

    public function estaEmModoEletronico(int $organizacaoId): bool
    {
        return $this->modoActual($organizacaoId) === self::ELECTRONIC;
    }

    public function estaEmModoSaft(int $organizacaoId): bool
    {
        return $this->modoActual($organizacaoId) === self::SAFT_AO;
    }

    /**
     * Alternar o modo de faturação da organização.
     *
     * Reversível em qualquer direcção:
     *   electronic -> saft_ao
     *   saft_ao    -> electronic
     *
     * @param int      $organizacaoId
     * @param string   $novoModo      'electronic' | 'saft_ao'
     * @param int|null $alteradoPor   ID do utilizador que fez a alteração (auditoria)
     *
     * @throws ExcecaoConfiguracaoAgt
     */
    public function alternarModo(int $organizacaoId, string $novoModo, ?int $alteradoPor = null): array
    {
        if (!in_array($novoModo, self::MODOS_VALIDOS, true)) {
            throw new ExcecaoConfiguracaoAgt(
                "Modo de faturação inválido: '{$novoModo}'. Valores permitidos: " .
                implode(', ', self::MODOS_VALIDOS)
            );
        }

        $config = OrganizationAgtConfig::withoutGlobalScopes()
            ->where('organizationId', $organizacaoId)
            ->first();

        // Sem nenhuma configuração ainda — permitir criar uma agora.
        // SAF-T(AO) pode sempre ser escolhido sem mais requisitos (é o
        // regime sem submissão em tempo real). 'electronic' exige
        // configuração completa — ver validarRequisitosElectronic().
        if (!$config) {
            if ($novoModo === self::ELECTRONIC) {
                throw new ExcecaoConfiguracaoAgt(
                    "A organização [{$organizacaoId}] não tem nenhuma configuração AGT. " .
                    "Não é possível activar a Faturação Eletrónica sem configurar primeiro " .
                    "o NIF, as chaves e o número de certificação em: AGT -> Configuração. " .
                    "Pode, no entanto, seleccionar o modo 'saft_ao' sem mais requisitos."
                );
            }

            $config = OrganizationAgtConfig::withoutGlobalScopes()->create([
                'organizationId' => $organizacaoId,
                'agt_enabled'    => false,
                'invoicing_mode' => self::SAFT_AO,
            ]);

            self::invalidarCache($organizacaoId);

            return [
                'alterado'      => true,
                'modo_anterior' => self::SAFT_AO, // default automático antes da escolha explícita
                'modo_actual'   => self::SAFT_AO,
                'alterado_em'   => now()->toISOString(),
                'mensagem'      => 'Configuração AGT criada com modo SAF-T(AO) seleccionado explicitamente.',
            ];
        }

        // Modo anterior REAL — usa a mesma resolução com default seguro
        // (nunca assume 'electronic' silenciosamente).
        $modoAnterior = $this->modoActual($organizacaoId);

        if ($modoAnterior === $novoModo) {
            return [
                'alterado'      => false,
                'modo_anterior' => $modoAnterior,
                'modo_actual'   => $novoModo,
                'mensagem'      => "A organização já está no modo '{$novoModo}'.",
            ];
        }

        // Validar requisitos mínimos antes de permitir o modo Electronic
        // — é este modo que assina e submete em tempo real, por isso é
        // o que exige configuração completa. SAF-T(AO) nunca é bloqueado.
        if ($novoModo === self::ELECTRONIC) {
            $this->validarRequisitosElectronic($config);
        }

        DB::transaction(function () use ($config, $novoModo, $alteradoPor) {
            $config->update([
                'invoicing_mode'            => $novoModo,
                // Activar agt_enabled automaticamente ao escolher
                // 'electronic' explicitamente — escolher o modo é a
                // intenção clara de ligar a submissão em tempo real.
                'agt_enabled'               => $novoModo === self::ELECTRONIC ? true : $config->agt_enabled,
                'invoicing_mode_changed_at' => now(),
                'invoicing_mode_changed_by' => $alteradoPor,
            ]);
        });

        self::invalidarCache($config->organizationId);

        return [
            'alterado'      => true,
            'modo_anterior' => $modoAnterior,
            'modo_actual'   => $novoModo,
            'alterado_em'   => now()->toISOString(),
            'mensagem'      => $novoModo === self::SAFT_AO
                ? 'Organização alternada para regime SAF-T (AO). Faturas a partir de agora deixam de ser submetidas em tempo real à AGT.'
                : 'Organização alternada para Faturação Eletrónica AGT. Faturas a partir de agora voltam a ser assinadas e submetidas em tempo real.',
        ];
    }

    /**
     * Validar que a organização tem o mínimo necessário para gerar
     * ficheiros SAF-T(AO) válidos antes de permitir a troca de modo.
     */
    /**
     * Validar que a organização tem o mínimo necessário para activar
     * a Faturação Eletrónica (assinatura + submissão em tempo real)
     * antes de permitir a troca para 'electronic'.
     *
     * SAF-T(AO) nunca passa por esta validação — é o modo sem
     * submissão em tempo real, por isso não exige chaves de assinatura.
     */
    private function validarRequisitosElectronic(OrganizationAgtConfig $config): void
    {
        $erros = [];

        if (empty($config->tax_registration_number)) {
            $erros[] = 'NIF (tax_registration_number) não configurado.';
        }

        if (empty($config->software_validation_number)) {
            $erros[] = 'Número de certificação do software não configurado.';
        }

        if (empty($config->taxpayer_private_key_encrypted)) {
            $erros[] = 'Chave privada do contribuinte não configurada — necessária para assinar documentos.';
        }

        if (!empty($erros)) {
            throw new ExcecaoConfiguracaoAgt(
                'Não é possível activar a Faturação Eletrónica - configuração incompleta: ' .
                implode(' ', $erros) .
                ' A organização permanece em modo SAF-T(AO) até que isto seja resolvido.'
            );
        }
    }

    public static function invalidarCache(int $organizacaoId): void
    {
        cache()->forget("onsoft_agt_invoicing_mode_{$organizacaoId}");
    }

    /**
     * Estado completo do modo de faturação - para exibir no painel.
     */
    public function estado(int $organizacaoId): array
    {
        $config = OrganizationAgtConfig::withoutGlobalScopes()
            ->where('organizationId', $organizacaoId)
            ->first();

        // Usa SEMPRE modoActual() — fonte única da verdade — em vez de
        // ler invoicing_mode directamente, para nunca divergir da regra
        // de default seguro (sem config ou agt_enabled=false -> saft_ao).
        $modo = $this->modoActual($organizacaoId);

        return [
            'modo_actual'                  => $modo,
            'modo_label'                   => $modo === self::SAFT_AO ? 'SAF-T (AO)' : 'Faturação Eletrónica AGT',
            'pode_alternar'                => true,
            'configuracao_existe'          => $config !== null,
            'agt_enabled'                  => (bool) ($config?->agt_enabled ?? false),
            'modo_e_default_automatico'    => !$config || !$config->agt_enabled || $config->invoicing_mode === null,
            'alterado_em'                  => optional($config?->invoicing_mode_changed_at)?->toISOString(),
            'submissao_tempo_real_activa'  => $modo === self::ELECTRONIC,
            'requer_geracao_saft'          => $modo === self::SAFT_AO,
        ];
    }

    /**
     * Auditoria de transição entre regimes — mostra quantas faturas
     * existem em cada estado de origem (electronic vs saft_ao), para
     * o utilizador perceber exactamente o que aconteceu ao mudar de modo.
     *
     * Esclarece, em particular, que faturas SAF-T NUNCA migram para
     * submissão em tempo real, mesmo depois de voltar a 'electronic'.
     */
    public function auditoriaTransicao(int $organizacaoId): array
    {
        $contagens = \App\Models\Invoice\Invoice::withoutGlobalScopes()
            ->where('organizationId', $organizacaoId)
            ->selectRaw('agt_status, COUNT(*) as total')
            ->groupBy('agt_status')
            ->pluck('total', 'agt_status');

        $pendentesExportacao = (int) ($contagens->get('saft_pending_export') ?? 0);
        $jaExportadas        = (int) ($contagens->get('saft_exported') ?? 0);
        $electronicas        = $contagens->except(['saft_pending_export', 'saft_exported'])->sum();

        return [
            'modo_actual'                       => $this->modoActual($organizacaoId),
            'faturas_electronicas_total'         => (int) $electronicas,
            'faturas_saft_aguardando_exportacao' => $pendentesExportacao,
            'faturas_saft_ja_exportadas'          => $jaExportadas,
            'nota' => $pendentesExportacao > 0
                ? "Existem {$pendentesExportacao} fatura(s) em modo SAF-T(AO) ainda não exportadas. " .
                  "Gere o ficheiro SAF-T (GET /onsoft-agt/saft/exportar) antes do prazo de entrega à AGT. " .
                  "Estas faturas NUNCA são submetidas em tempo real, mesmo após voltar ao modo electronic."
                : "Não há faturas SAF-T pendentes de exportação.",
        ];
    }
}
