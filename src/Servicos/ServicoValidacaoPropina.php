<?php

namespace Onsoft\Agt\Servicos;

use App\Models\Invoice\Billing\BillingPropina;
use App\Models\Payment\Mes;
use Illuminate\Support\Facades\DB;
use Onsoft\Agt\Excecoes\ExcecaoFaturaAgt;

/**
 * ServicoValidacaoPropina
 *
 * Valida que as propinas (mensalidades) são pagas em ORDEM SEQUENCIAL.
 *
 * REGRA DE NEGÓCIO:
 * ─────────────────────────────────────────────────────────────────
 * O campo `meses.orderNumber` define a ordem (1, 2, 3, ... 12).
 * Um aluno NUNCA pode pagar o mês 7 sem ter pago 1, 2, 3, 4, 5 e 6
 * antes (excluindo meses com `anularpagamento = true`, que são
 * ignorados na sequência).
 *
 * "Pago" significa: existe BillingPropina com:
 *   - mensalidadeId, alunoId, anolectivoId correctos
 *   - mesid = meses.mesId do mês em causa
 *   - status != 'cancelled'
 *
 * PERFORMANCE (10.000 req/seg):
 * ─────────────────────────────────────────────────────────────────
 * - 1 única query agregada (NÃO uma query por mês)
 * - Usa LEFT JOIN + GROUP BY em vez de N queries em loop
 * - Lock pessimista (lockForUpdate) APENAS nos registos billing_propinas
 *   do aluno+mensalidade+anolectivo — lock estreito, não bloqueia
 *   outros alunos nem outras mensalidades
 * - Tudo dentro de DB::transaction com isolation level adequado
 */
class ServicoValidacaoPropina
{
    /**
     * Validar que os meses pedidos para pagamento respeitam a ordem sequencial.
     *
     * @param int   $mensalidadeId
     * @param int   $alunoId
     * @param int   $anolectivoId
     * @param array $mesIdsAPagar    Array de meses.mesId que se pretende pagar agora
     * @param bool  $classComExam    Se a classe tem exame (afecta quais meses contam)
     *
     * @throws ExcecaoFaturaAgt  Se a ordem for violada
     * @return array             Lista ordenada de meses.id que serão pagos (para uso posterior)
     */
    public function validarOrdem(
        int   $mensalidadeId,
        int   $alunoId,
        int   $anolectivoId,
        array $mesIdsAPagar,
        bool  $classComExam = false
    ): array {
        if (empty($mesIdsAPagar)) {
            throw new ExcecaoFaturaAgt('Nenhum mês de propina indicado para pagamento.');
        }

        // ── 1 ÚNICA QUERY: todos os meses activos do ano + se já estão pagos ──
        // LEFT JOIN evita N+1 — uma query para todo o ano lectivo.
        //
        // CORRIGIDO nesta auditoria: a query anterior só devolvia
        // bp.id (existe/não existe), tratando QUALQUER registo não
        // cancelado (mesmo 'partial') como "já pago" — o que bloqueava
        // permanentemente qualquer tentativa de completar um pagamento
        // parcial. Agora devolvemos também bp.status, para distinguir
        // 'paid' (totalmente pago, nunca repetir) de 'partial'/'pending'
        // (ocupa a posição na sequência, mas pode ser complementado).
        $linhas = DB::table('meses as m')
            ->leftJoin('billing_propinas as bp', function ($join) use ($mensalidadeId, $alunoId, $anolectivoId) {
                $join->on('bp.mesid', '=', 'm.mesId')
                    ->where('bp.mensalidadeId', '=', $mensalidadeId)
                    ->where('bp.alunoId', '=', $alunoId)
                    ->where('bp.anolectivoId', '=', $anolectivoId)
                    ->where('bp.status', '!=', 'cancelled');
            })
            ->where('m.anolectivoId', $anolectivoId)
            ->where('m.anularpagamento', 0)
            ->when(!$classComExam, fn($q) => $q->where('m.classComExam', false))
            ->orderBy('m.orderNumber')
            ->select([
                'm.id',
                'm.mesId',
                'm.name',
                'm.orderNumber',
                DB::raw('CASE WHEN bp.id IS NOT NULL THEN 1 ELSE 0 END as ja_pago'),
                'bp.status as billing_status',
            ])
            ->get();

        if ($linhas->isEmpty()) {
            throw new ExcecaoFaturaAgt('Nenhum mês activo encontrado para este ano lectivo.');
        }

        // Mapear mesId → linha para lookup O(1)
        $porMesId = $linhas->keyBy('mesId');

        // ── Validar que os mesIds pedidos existem no ano lectivo ──────
        foreach ($mesIdsAPagar as $mesId) {
            if (!$porMesId->has($mesId)) {
                throw new ExcecaoFaturaAgt(
                    "O mês [mesId={$mesId}] não pertence a este ano lectivo/mensalidade ou está anulado."
                );
            }
        }

        // ── Encontrar a posição esperada do próximo pagamento ───────────
        // CORRIGIDO: "esperado" deve ser o orderNumber do PRIMEIRO mês
        // que não está totalmente pago — que pode ser um mês 'partial'
        // já existente (a completar) ou o primeiro 'pendente' depois de
        // todos os 'paid'. Antes, qualquer mês com registo (incluindo
        // 'partial') avançava a sequência, tornando esse mês parcial
        // impossível de voltar a pedir.
        $primeiroNaoPago = $linhas->first(fn($l) => $l->billing_status !== 'paid');
        $esperado         = $primeiroNaoPago->orderNumber ?? 1;
        $pedidosOrdenados = collect($mesIdsAPagar)
            ->map(fn($mesId) => $porMesId->get($mesId))
            ->sortBy('orderNumber')
            ->values();

        // ── REGRA 1: não pode repetir um mês JÁ TOTALMENTE PAGO ──────────
        // CORRIGIDO: antes bloqueava qualquer mês com QUALQUER registo
        // não cancelado, incluindo 'partial' — impedindo completar um
        // pagamento parcial para sempre. Agora só bloqueia 'paid'.
        // Meses 'partial' ocupam a posição na sequência (ver REGRA 2),
        // mas podem ser pedidos novamente para completar o saldo.
        $jaPagos = $pedidosOrdenados->filter(fn($m) => $m->billing_status === 'paid');
        if ($jaPagos->isNotEmpty()) {
            $nomes = $jaPagos->pluck('name')->implode(', ');
            throw new ExcecaoFaturaAgt(
                "Os seguintes meses já estão totalmente pagos e não podem ser pagos novamente: {$nomes}."
            );
        }

        // ── REGRA 2: sequência estrita — não pode haver buracos ─────────
        // O primeiro mês a pagar tem de ser exactamente $esperado
        // (já calculado acima como o primeiro mês não totalmente pago).
        // Se pedir vários meses de uma vez, têm de ser consecutivos.
        foreach ($pedidosOrdenados as $mes) {
            if ((int) $mes->orderNumber !== $esperado) {
                $nomeEsperado = $linhas->firstWhere('orderNumber', $esperado)?->name ?? "posição {$esperado}";

                throw new ExcecaoFaturaAgt(
                    "ORDEM DE PAGAMENTO VIOLADA — Não é possível pagar '{$mes->name}' " .
                    "(posição {$mes->orderNumber}) sem primeiro pagar '{$nomeEsperado}' " .
                    "(posição {$esperado}). As propinas devem ser pagas em ordem sequencial: " .
                    "1, 2, 3... sem saltar meses."
                );
            }
            $esperado++;
        }

        return $pedidosOrdenados->pluck('id')->values()->all();
    }

    /**
     * Validar e CRIAR os registos BillingPropina de forma atómica.
     *
     * Usa transação com lock pessimista estreito — apenas bloqueia
     * os registos billing_propinas deste aluno+mensalidade+anolectivo,
     * nunca a tabela inteira. Isto permite que milhares de outros
     * alunos paguem em paralelo sem qualquer contenção.
     *
     * @param int   $organizacaoId
     * @param int   $mensalidadeId
     * @param int   $alunoId
     * @param int   $encarregadoId
     * @param int   $anolectivoId
     * @param array $mesesAPagar    [['mesId' => int, 'valor' => float, 'desconto' => float, 'multa' => float], ...]
     * @param bool  $classComExam
     *
     * @return \Illuminate\Support\Collection<BillingPropina>  Registos criados, na ordem certa
     */
    public function validarECriarPropinas(
        int   $organizacaoId,
        int   $mensalidadeId,
        int   $alunoId,
        int   $encarregadoId,
        int   $anolectivoId,
        array $mesesAPagar,
        bool  $classComExam = false
    ) {
        $mesIds = array_column($mesesAPagar, 'mesId');

        return DB::transaction(function () use (
            $organizacaoId, $mensalidadeId, $alunoId, $encarregadoId,
            $anolectivoId, $mesesAPagar, $mesIds, $classComExam
        ) {
            // ── LOCK ESTREITO — apenas as linhas deste aluno+mensalidade ──
            // SELECT ... FOR UPDATE garante que, se dois pedidos chegarem
            // ao mesmo tempo para o MESMO aluno, o segundo espera o primeiro
            // terminar. Pedidos de OUTROS alunos não são afectados — o lock
            // é por linha (row-level), não por tabela.
            BillingPropina::withoutGlobalScopes()
                ->where('mensalidadeId', $mensalidadeId)
                ->where('alunoId', $alunoId)
                ->where('anolectivoId', $anolectivoId)
                ->lockForUpdate()
                ->get();

            // ── Validar ordem (dentro do lock — garante atomicidade) ──────
            $mesesIdsValidados = $this->validarOrdem(
                $mensalidadeId, $alunoId, $anolectivoId, $mesIds, $classComExam
            );

            // ── Mapear dados extra (valor, desconto, multa) por mesId ─────
            $dadosPorMesId = collect($mesesAPagar)->keyBy('mesId');

            // ── Buscar detalhes dos meses para preencher referencia_mes ──
            $mesesDetalhes = Mes::query()
                ->whereIn('mesId', $mesIds)
                ->where('anolectivoId', $anolectivoId)
                ->get()
                ->keyBy('mesId');

            $criados = collect();

            // ── Criar OU COMPLETAR registos NA ORDEM CERTA (já validada) ──
            // CORRIGIDO nesta auditoria: antes, esta função SEMPRE criava
            // um novo registo BillingPropina — mesmo quando já existia
            // um registo 'partial' para o mesmo mês (agora permitido
            // pela correcção de validarOrdem() acima). Isso produziria
            // DOIS registos billing_propinas para o mesmo mesId — um
            // 'partial' antigo esquecido e um 'pending' novo. Agora
            // reutilizamos o registo parcial existente, somando ao
            // saldo em vez de duplicar.
            foreach ($mesIds as $mesId) {
                $dados      = $dadosPorMesId->get($mesId, []);
                $mesDetalhe = $mesesDetalhes->get($mesId);

                $valor    = (float) ($dados['valor'] ?? 0);
                $desconto = (float) ($dados['desconto'] ?? 0);
                $multa    = (float) ($dados['multa'] ?? 0);
                $total    = round($valor - $desconto + $multa, 2);

                $existente = BillingPropina::withoutGlobalScopes()
                    ->where('mensalidadeId', $mensalidadeId)
                    ->where('alunoId', $alunoId)
                    ->where('anolectivoId', $anolectivoId)
                    ->where('mesid', $mesId)
                    ->where('status', '!=', 'cancelled')
                    ->first();

                if ($existente) {
                    // Mês 'partial' a ser complementado — somar o novo
                    // valor ao registo existente, nunca duplicar.
                    // NOTA: o campo 'status' (pending|partial|paid) NÃO
                    // é alterado aqui — essa transição é responsabilidade
                    // do processo de confirmação de pagamento do projecto
                    // hospedeiro (fora deste pacote), tipicamente quando
                    // InvoicePayment/InvoicePaymentAllocation confirma o
                    // valor recebido. Este método só garante que o
                    // REGISTO correcto é reutilizado, não duplicado.
                    $existente->update([
                        'valor'             => round($existente->valor + $total, 2),
                        'remaining_balance' => round(($existente->remaining_balance ?? $existente->valor) + $total, 2),
                        'snapshot'          => array_merge((array) ($existente->snapshot ?? []), ['complemento' => $dados]),
                    ]);
                    $registo = $existente;
                } else {
                    $registo = BillingPropina::withoutGlobalScopes()->create([
                        'organizationId'    => $organizacaoId,
                        'mensalidadeId'     => $mensalidadeId,
                        'alunoId'           => $alunoId,
                        'encarregadoId'     => $encarregadoId,
                        'anolectivoId'      => $anolectivoId,
                        'mes'               => $mesDetalhe?->name,
                        'mesid'             => $mesId,
                        'referencia_mes'    => $mesDetalhe?->data,
                        'valor'             => $total,
                        'desconto'          => $desconto,
                        'multa'             => $multa,
                        'paid_total'        => 0,
                        'remaining_balance' => $total,
                        'due_date'          => $dados['due_date'] ?? null,
                        'status'            => 'pending',
                        'snapshot'          => $dados,
                        'appCode'           => 1,
                    ]);
                }

                $criados->push($registo);
            }

            return $criados;
        }); // nota: retry de deadlock acontece no nível exterior (ServicoFatura::criar)
    }

    /**
     * Verificação rápida (sem lock) — usada em pré-visualização.
     * Não usar antes de criar registos reais — apenas para feedback ao frontend.
     */
    public function podePagar(
        int   $mensalidadeId,
        int   $alunoId,
        int   $anolectivoId,
        array $mesIdsAPagar,
        bool  $classComExam = false
    ): array {
        try {
            $this->validarOrdem($mensalidadeId, $alunoId, $anolectivoId, $mesIdsAPagar, $classComExam);
            return ['pode_pagar' => true, 'erro' => null];
        } catch (ExcecaoFaturaAgt $e) {
            return ['pode_pagar' => false, 'erro' => $e->getMessage()];
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // MAPA COMPLETO DE MESES — pago / parcial / pendente
    // ══════════════════════════════════════════════════════════════════

    /**
     * Construir o mapa completo dos 12 (ou N) meses do ano lectivo,
     * indicando para cada mês: pago, parcialmente pago, ou pendente —
     * SEMPRE respeitando a ordem sequencial (orderNumber).
     *
     * Regras aplicadas:
     * ─────────────────────────────────────────────────────────────────
     * - propinaMensal = propinaAnual / totalMeses (mesma fórmula do
     *   Mes::paidAndUnpaidMeses() já usado no projecto)
     * - Um mês conta como "pago" se existir BillingPropina com
     *   status='paid' para esse mesId
     * - Um mês conta como "parcial" se existir BillingPropina com
     *   status='partial' para esse mesId (remaining_balance > 0)
     * - Um mês SEM registo em billing_propinas, ou com status
     *   'cancelled', é tratado como "pendente" — nunca é ignorado,
     *   é sempre contado na sequência de ordem
     * - O campo `pode_pagar_agora` indica se este é exactamente o
     *   próximo mês na ordem (orderNumber = último_pago_ou_parcial + 1)
     *
     * 1 ÚNICA QUERY (LEFT JOIN), sem N+1 — performance igual à
     * validação de ordem.
     *
     * @return array{
     *   resumo: array,
     *   meses: array<int, array>
     * }
     */
    public function mapaMeses(
        int   $mensalidadeId,
        int   $alunoId,
        int   $anolectivoId,
        float $propinaAnual,
        bool  $classComExam = false
    ): array {
        // ── 1 ÚNICA QUERY: todos os meses + estado de pagamento ─────────
        $linhas = DB::table('meses as m')
            ->leftJoin('billing_propinas as bp', function ($join) use ($mensalidadeId, $alunoId, $anolectivoId) {
                $join->on('bp.mesid', '=', 'm.mesId')
                    ->where('bp.mensalidadeId', '=', $mensalidadeId)
                    ->where('bp.alunoId', '=', $alunoId)
                    ->where('bp.anolectivoId', '=', $anolectivoId)
                    ->where('bp.status', '!=', 'cancelled');
            })
            ->where('m.anolectivoId', $anolectivoId)
            ->where('m.anularpagamento', 0)
            ->when(!$classComExam, fn($q) => $q->where('m.classComExam', false))
            ->orderBy('m.orderNumber')
            ->select([
                'm.id', 'm.mesId', 'm.name', 'm.orderNumber', 'm.data',
                'bp.id as billing_id',
                'bp.status as billing_status',
                'bp.valor as billing_valor',
                'bp.desconto as billing_desconto',
                'bp.multa as billing_multa',
                'bp.paid_total as billing_paid_total',
                'bp.remaining_balance as billing_remaining_balance',
                'bp.due_date as billing_due_date',
            ])
            ->get();

        if ($linhas->isEmpty()) {
            return [
                'resumo' => $this->resumoMapaVazio($propinaAnual),
                'meses'  => [],
            ];
        }

        $totalMeses     = $linhas->count();
        $propinaMensal  = $totalMeses > 0 ? round($propinaAnual / $totalMeses, 2) : 0.0;

        // ── Determinar o orderNumber a partir do qual se pode pagar ─────
        // "Já tratado" = pago OU parcial (parcial conta como ocupando a
        // posição na sequência — não se pode saltar um mês parcialmente pago).
        $tratados        = $linhas->filter(fn($l) => in_array($l->billing_status, ['paid', 'partial'], true));
        $ultimoOrderTrat = $tratados->max('orderNumber') ?? 0;
        $proximoOrder    = $ultimoOrderTrat + 1;

        $mesesFormatados = $linhas->map(function ($linha) use ($propinaMensal, $proximoOrder) {
            $estado = match ($linha->billing_status) {
                'paid'    => 'pago',
                'partial' => 'parcial',
                default   => 'pendente', // sem registo, ou registo cancelado (ignorado e tratado como pendente)
            };

            $valorDevido    = $linha->billing_valor !== null
                ? (float) $linha->billing_valor
                : $propinaMensal;

            $valorPago      = (float) ($linha->billing_paid_total ?? 0);
            $valorRestante  = $linha->billing_remaining_balance !== null
                ? (float) $linha->billing_remaining_balance
                : ($estado === 'pendente' ? $valorDevido : 0.0);

            return [
                'id'                 => $linha->id,
                'mesId'              => $linha->mesId,
                'name'               => $linha->name,
                'orderNumber'        => $linha->orderNumber,
                'data'               => $linha->data,
                'estado'             => $estado, // pago | parcial | pendente
                'propina_mensal'     => $propinaMensal,
                'valor_devido'       => round($valorDevido, 2),
                'valor_pago'         => round($valorPago, 2),
                'valor_restante'     => round($valorRestante, 2),
                'desconto'           => round((float) ($linha->billing_desconto ?? 0), 2),
                'multa'              => round((float) ($linha->billing_multa ?? 0), 2),
                'due_date'           => $linha->billing_due_date,
                'billing_propina_id' => $linha->billing_id,
                // Só é pagável agora se for exactamente a próxima posição
                // na sequência E ainda não estiver totalmente pago.
                'pode_pagar_agora'   => $estado !== 'pago' && (int) $linha->orderNumber === $proximoOrder,
            ];
        })->values();

        $pagos     = $mesesFormatados->where('estado', 'pago');
        $parciais  = $mesesFormatados->where('estado', 'parcial');
        $pendentes = $mesesFormatados->where('estado', 'pendente');

        return [
            'resumo' => [
                'total_meses'           => $totalMeses,
                'propina_anual'         => round($propinaAnual, 2),
                'propina_mensal'        => $propinaMensal,
                'total_meses_pagos'     => $pagos->count(),
                'total_meses_parciais'  => $parciais->count(),
                'total_meses_pendentes' => $pendentes->count(),
                'total_pago'            => round($mesesFormatados->sum('valor_pago'), 2),
                'total_em_divida'       => round($mesesFormatados->sum('valor_restante'), 2),
                'proximo_mes_a_pagar'   => $mesesFormatados->firstWhere('pode_pagar_agora', true)['name'] ?? null,
                'proximo_order_number'  => $proximoOrder <= $totalMeses ? $proximoOrder : null,
                'todos_meses_pagos'     => $pagos->count() === $totalMeses,
            ],
            'meses' => $mesesFormatados->values()->all(),
        ];
    }

    private function resumoMapaVazio(float $propinaAnual): array
    {
        return [
            'total_meses'           => 0,
            'propina_anual'         => round($propinaAnual, 2),
            'propina_mensal'        => 0.0,
            'total_meses_pagos'     => 0,
            'total_meses_parciais'  => 0,
            'total_meses_pendentes' => 0,
            'total_pago'            => 0.0,
            'total_em_divida'       => 0.0,
            'proximo_mes_a_pagar'   => null,
            'proximo_order_number'  => null,
            'todos_meses_pagos'     => false,
        ];
    }

    /**
     * Obter o próximo mês que o aluno deve pagar (para sugestão no frontend).
     */
    public function proximoMesAPagar(
        int  $mensalidadeId,
        int  $alunoId,
        int  $anolectivoId,
        bool $classComExam = false
    ): ?array {
        $linhas = DB::table('meses as m')
            ->leftJoin('billing_propinas as bp', function ($join) use ($mensalidadeId, $alunoId, $anolectivoId) {
                $join->on('bp.mesid', '=', 'm.mesId')
                    ->where('bp.mensalidadeId', '=', $mensalidadeId)
                    ->where('bp.alunoId', '=', $alunoId)
                    ->where('bp.anolectivoId', '=', $anolectivoId)
                    ->where('bp.status', '!=', 'cancelled');
            })
            ->where('m.anolectivoId', $anolectivoId)
            ->where('m.anularpagamento', 0)
            ->when(!$classComExam, fn($q) => $q->where('m.classComExam', false))
            ->whereNull('bp.id')
            ->orderBy('m.orderNumber')
            ->select(['m.id', 'm.mesId', 'm.name', 'm.orderNumber', 'm.data'])
            ->first();

        if (!$linhas) {
            return null; // Todos os meses já estão pagos
        }

        return [
            'id'          => $linhas->id,
            'mesId'       => $linhas->mesId,
            'name'        => $linhas->name,
            'orderNumber' => $linhas->orderNumber,
            'data'        => $linhas->data,
        ];
    }
}
