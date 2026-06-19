<?php

namespace Onsoft\Agt\Servicos;

use App\Models\Invoice\Invoice;

/**
 * ServicoFlagsUiFatura
 *
 * Centraliza, num único lugar, as regras de "que botões mostrar" no
 * frontend para uma fatura - em função do invoicing_mode (separador
 * explícito entre regimes Electronic/SAF-T) e do agt_status.
 *
 * NUNCA decidir isto no frontend com lógica própria - usar sempre
 * este serviço como fonte única da verdade, para que a regra de
 * "não misturar regimes" seja sempre coerente em toda a aplicação.
 *
 * Devolve um objecto plano de flags booleanas + motivo (quando
 * desactivado), pronto para o frontend ligar directamente a
 * disabled={!flags.pode_submeter} em cada botão.
 */
class ServicoFlagsUiFatura
{
    /**
     * Calcular todas as flags de UI para uma fatura.
     */
    public function calcular(Invoice $fatura): array
    {
        $modo         = $fatura->invoicing_mode ?? ServicoModoFaturacao::ELECTRONIC;
        $ehSaft       = $modo === ServicoModoFaturacao::SAFT_AO;
        $ehElectronic = !$ehSaft;
        $agtStatus    = $fatura->agt_status ?? 'draft';
        $cancelado    = ($fatura->payment_status ?? '') === 'cancelled';

        return [
            // ── Identificador explícito do regime - para badges/cores ──
            'invoicing_mode'       => $modo,
            'invoicing_mode_label' => $ehSaft ? 'SAF-T (AO)' : 'Faturação Eletrónica',
            'badge_cor'            => $ehSaft ? 'amber' : 'blue',

            // ── Botão: "Submeter à AGT" ─────────────────────────────────
            // CORRIGIDO nesta auditoria: 'rejected' foi REMOVIDO desta
            // lista. A documentação AGT (erro E46) confirma que
            // resubmeter o MESMO documentNo de uma fatura rejeitada é
            // sempre recusado pela AGT — a correcção exige um NOVO
            // documento (ver ServicoFatura::corrigirFaturaRejeitada()).
            'mostrar_botao_submeter' => $ehElectronic, // nunca aparece em faturas SAF-T
            'pode_submeter'          => $ehElectronic
                && in_array($agtStatus, ['draft', 'failed'], true)
                && !$cancelado,
            'motivo_submeter_desactivado' => $ehSaft
                ? 'Fatura emitida em regime SAF-T(AO) - reportada apenas via exportação do ficheiro SAF-T, nunca em tempo real.'
                : ($agtStatus === 'rejected'
                    ? 'Esta fatura foi rejeitada pela AGT. Use "Corrigir e Resubmeter" — a AGT não aceita o mesmo número de documento duas vezes.'
                    : (in_array($agtStatus, ['accepted', 'submitted', 'pending'], true)
                        ? 'Fatura já submetida ou em processamento.'
                        : ($cancelado ? 'Fatura cancelada.' : null))),

            // ── Botão: "Corrigir e Resubmeter" ───────────────────────────
            // Único caminho válido para uma fatura rejeitada — cria uma
            // NOVA fatura com novo documentNo, referenciando a rejeitada
            // (campo rejectedDocumentNo no payload AGT, exigência E46).
            'mostrar_botao_corrigir_rejeitada' => $ehElectronic && $agtStatus === 'rejected' && !$cancelado,

            // ── Botão: "Retentar Submissão" ─────────────────────────────
            'mostrar_botao_retentar' => $ehElectronic && $agtStatus === 'failed',

            // ── Botão: "Exportar para SAF-T" ────────────────────────────
            'mostrar_botao_exportar_saft' => $ehSaft,
            'pode_exportar_saft'          => $ehSaft && $agtStatus === 'saft_pending_export',
            'ja_exportada_saft'           => $ehSaft && $agtStatus === 'saft_exported',

            // ── Botão: "Cancelar" / "Gerar Nota de Crédito" ─────────────
            'mostrar_botao_cancelar'        => !$cancelado && $fatura->document_type !== 'NC',
            'gera_nota_credito_ao_cancelar' => $ehElectronic && in_array($agtStatus, ['submitted', 'accepted'], true),

            // ── Indicadores gerais ───────────────────────────────────────
            'pode_editar_pagamento'     => !$cancelado,
            'mostra_aviso_regime_misto' => false, // nunca true - regimes não se misturam por desenho
        ];
    }

    /**
     * Aplicar calcular() sobre uma colecção de faturas - para listagens.
     */
    public function calcularParaColecao($faturas): array
    {
        return $faturas->map(fn(Invoice $f) => array_merge(
            ['invoice_id' => $f->id],
            $this->calcular($f)
        ))->values()->all();
    }
}
