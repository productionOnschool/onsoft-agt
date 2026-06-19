<?php

use Illuminate\Support\Facades\Route;
use Onsoft\Agt\Http\Controladores\ControladorFaturaAgt;

/*
|──────────────────────────────────────────────────────────────────────────────
| Rotas do Pacote Onsoft AGT
|──────────────────────────────────────────────────────────────────────────────
| Proteja estas rotas com o seu middleware de autenticação.
|
| Sugestão — no RouteServiceProvider da sua aplicação:
|   Route::middleware(['api', 'auth:sanctum'])
|       ->prefix('api')
|       ->group(base_path('vendor/onsoft/agt/routes/onsoft-agt.php'));
|
| ── CORRIGIDO NESTA AUDITORIA ──────────────────────────────────────────────
| Antes desta correcção, apenas as rotas de "Faturas", "Séries",
| "Configuração" e "Propinas" estavam dentro do grupo
| Route::prefix('onsoft-agt'). Todas as restantes secções (modo de
| faturação, SAF-T, métodos de pagamento, aluno, eu, relatórios,
| pró-forma) estavam FORA desse grupo — ficavam disponíveis em
| /modo-faturacao/estado, /saft/exportar, etc. directamente na raiz da
| aplicação, em vez de /onsoft-agt/modo-faturacao/estado. Isto criava
| risco real de colisão com rotas do projecto hospedeiro e divergia de
| tudo o que o README sempre documentou. Agora TUDO está dentro do
| mesmo grupo /onsoft-agt — sem excepções.
*/

Route::prefix('onsoft-agt')->name('onsoft-agt.')->group(function () {

    // ── Faturas ──────────────────────────────────────────────────────
    Route::post('/faturas',              [ControladorFaturaAgt::class, 'criar'])->name('faturas.criar');
    Route::post('/faturas/pre-visualizar', [ControladorFaturaAgt::class, 'preVisualizar'])->name('faturas.pre-visualizar');
    Route::get('/faturas/flags-ui',       [ControladorFaturaAgt::class, 'flagsUiEmMassa'])->name('faturas.flags-ui');
    Route::get('/faturas/{id}/pdf',          [ControladorFaturaAgt::class, 'pdf'])->name('faturas.pdf');
    Route::get('/faturas/{id}/pdf-snapshot', [ControladorFaturaAgt::class, 'pdfSnapshot'])->name('faturas.pdf-snapshot');
    Route::get('/faturas/{id}/pdf-base64',   [ControladorFaturaAgt::class, 'pdfBase64'])->name('faturas.pdf-base64');
    Route::post('/faturas/{id}/submeter', [ControladorFaturaAgt::class, 'submeter'])->name('faturas.submeter');
    Route::post('/faturas/{id}/corrigir-rejeitada', [ControladorFaturaAgt::class, 'corrigirRejeitada'])->name('faturas.corrigir-rejeitada');
    Route::post('/faturas/{id}/cancelar', [ControladorFaturaAgt::class, 'cancelar'])->name('faturas.cancelar');
    Route::get('/faturas/{id}/estado',   [ControladorFaturaAgt::class, 'estado'])->name('faturas.estado');
    Route::post('/faturas/{id}/estado/consultar-agora', [ControladorFaturaAgt::class, 'consultarEstadoAgora'])->name('faturas.estado.consultar-agora');
    Route::get('/faturas/{id}/historico-impressao', [ControladorFaturaAgt::class, 'historicoImpressao'])->name('faturas.historico-impressao');

    // ── Séries ───────────────────────────────────────────────────────
    Route::post('/series/sincronizar',   [ControladorFaturaAgt::class, 'sincronizarSeries'])->name('series.sincronizar');

    // ── Configuração ─────────────────────────────────────────────────
    Route::get('/configuracao/validar',  [ControladorFaturaAgt::class, 'validarConfiguracao'])->name('configuracao.validar');

    // ── Propinas — ordem sequencial de pagamento ───────────────────────
    Route::get('/propinas/mapa-meses',      [ControladorFaturaAgt::class, 'mapaMesesPropina'])->name('propinas.mapa-meses');
    Route::get('/propinas/proximo-mes',     [ControladorFaturaAgt::class, 'proximoMesPropina'])->name('propinas.proximo-mes');
    Route::post('/propinas/validar-ordem',  [ControladorFaturaAgt::class, 'validarOrdemPropina'])->name('propinas.validar-ordem');

    // ── Modo de Faturação (Eletrónica AGT <-> SAF-T AO) ────────────────
    Route::prefix('modo-faturacao')->name('modo-faturacao.')->group(function () {
        Route::get('/estado',     [\Onsoft\Agt\Http\Controladores\ControladorModoFaturacao::class, 'estado'])->name('estado');
        Route::post('/alternar',  [\Onsoft\Agt\Http\Controladores\ControladorModoFaturacao::class, 'alternar'])->name('alternar');
        Route::get('/auditoria',  [\Onsoft\Agt\Http\Controladores\ControladorModoFaturacao::class, 'auditoria'])->name('auditoria');
    });

    // ── SAF-T (AO) — geração entre data de início e data de fim ───────────
    Route::prefix('saft')->name('saft.')->group(function () {
        Route::get('/previsualizar',     [\Onsoft\Agt\Http\Controladores\ControladorModoFaturacao::class, 'previsualizarSaft'])->name('previsualizar');
        Route::get('/exportar',          [\Onsoft\Agt\Http\Controladores\ControladorModoFaturacao::class, 'exportarSaft'])->name('exportar');
        Route::get('/exportar-base64',   [\Onsoft\Agt\Http\Controladores\ControladorModoFaturacao::class, 'exportarSaftBase64'])->name('exportar-base64');
    });

    // ── Métodos de Pagamento (integração com tipodepagamento existente) ──
    Route::prefix('metodos-pagamento')->name('metodos-pagamento.')->group(function () {
        Route::get('/',         [\Onsoft\Agt\Http\Controladores\ControladorMetodosPagamento::class, 'listar'])->name('listar');
        Route::post('/validar', [\Onsoft\Agt\Http\Controladores\ControladorMetodosPagamento::class, 'validar'])->name('validar');
    });

    // ── Para secretaria, admin, encarregado — mesmo padrão de EstudanteInfoController ──
    Route::prefix('aluno')->name('aluno.')->group(function () {
        Route::get('/faturas',                 [\Onsoft\Agt\Http\Controladores\ControladorFaturasAluno::class, 'faturas'])->name('faturas');
        Route::get('/faturas/{id}',            [\Onsoft\Agt\Http\Controladores\ControladorFaturasAluno::class, 'fatura'])->name('faturas.show');
        Route::get('/faturas/{id}/pdf',        [\Onsoft\Agt\Http\Controladores\ControladorFaturasAluno::class, 'pdfFatura'])->name('faturas.pdf');
        Route::get('/faturas/{id}/pdf-base64', [\Onsoft\Agt\Http\Controladores\ControladorFaturasAluno::class, 'pdfBase64Fatura'])->name('faturas.pdf-base64');
        Route::get('/mensalidades',            [\Onsoft\Agt\Http\Controladores\ControladorFaturasAluno::class, 'mensalidades'])->name('mensalidades');
        Route::get('/resumo',                  [\Onsoft\Agt\Http\Controladores\ControladorFaturasAluno::class, 'resumo'])->name('resumo');
    });

    // ── Área do Aluno Autenticado (sem alunoId — usa auth()->id()) ────────
    // Para o próprio aluno — proteger com role:estudante no projecto
    Route::prefix('eu')->name('eu.')->group(function () {
        Route::get('/faturas',                 [\Onsoft\Agt\Http\Controladores\ControladorFaturasAluno::class, 'minhasFaturas'])->name('faturas');
        Route::get('/faturas/{id}',            [\Onsoft\Agt\Http\Controladores\ControladorFaturasAluno::class, 'minhaFatura'])->name('faturas.show');
        Route::get('/faturas/{id}/pdf',        [\Onsoft\Agt\Http\Controladores\ControladorFaturasAluno::class, 'minhaPdfFatura'])->name('faturas.pdf');
        Route::get('/faturas/{id}/pdf-base64', [\Onsoft\Agt\Http\Controladores\ControladorFaturasAluno::class, 'minhaPdfBase64'])->name('faturas.pdf-base64');
        Route::get('/mensalidades',            [\Onsoft\Agt\Http\Controladores\ControladorFaturasAluno::class, 'minhasMensalidades'])->name('mensalidades');
        Route::get('/resumo',                  [\Onsoft\Agt\Http\Controladores\ControladorFaturasAluno::class, 'meuResumo'])->name('resumo');
    });

    Route::prefix('relatorios')->name('relatorios.')->group(function () {
        // JSON — para frontend / gráficos
        Route::get('/resumo-financeiro',    [\Onsoft\Agt\Http\Controladores\ControladorRelatorios::class, 'resumoFinanceiro'])->name('resumo-financeiro');
        Route::get('/receita-por-dia',      [\Onsoft\Agt\Http\Controladores\ControladorRelatorios::class, 'receitaPorDia'])->name('receita-por-dia');
        Route::get('/receita-por-mes',      [\Onsoft\Agt\Http\Controladores\ControladorRelatorios::class, 'receitaPorMes'])->name('receita-por-mes');
        Route::get('/receita-por-hora',     [\Onsoft\Agt\Http\Controladores\ControladorRelatorios::class, 'receitaPorHora'])->name('receita-por-hora');
        Route::get('/por-categoria',        [\Onsoft\Agt\Http\Controladores\ControladorRelatorios::class, 'porCategoria'])->name('por-categoria');
        Route::get('/meios-pagamento',      [\Onsoft\Agt\Http\Controladores\ControladorRelatorios::class, 'meiosPagamento'])->name('meios-pagamento');
        Route::get('/resumo-iva',           [\Onsoft\Agt\Http\Controladores\ControladorRelatorios::class, 'resumoIva'])->name('resumo-iva');
        Route::get('/estado-agt',                    [\Onsoft\Agt\Http\Controladores\ControladorRelatorios::class, 'estadoAgt'])->name('estado-agt');
        Route::get('/estado-agt-todas-organizacoes', [\Onsoft\Agt\Http\Controladores\ControladorRelatorios::class, 'estadoAgtTodasOrganizacoes'])->name('estado-agt-todas');
        Route::get('/top-clientes',         [\Onsoft\Agt\Http\Controladores\ControladorRelatorios::class, 'topClientes'])->name('top-clientes');
        Route::get('/maiores-devedores',    [\Onsoft\Agt\Http\Controladores\ControladorRelatorios::class, 'maioresDevedores'])->name('maiores-devedores');
        Route::get('/emissoes-30-dias',     [\Onsoft\Agt\Http\Controladores\ControladorRelatorios::class, 'emissoes30Dias'])->name('emissoes-30-dias');
        Route::get('/limite-diario',        [\Onsoft\Agt\Http\Controladores\ControladorRelatorios::class, 'limiteDiario'])->name('limite-diario');
        Route::get('/billing-types',        [\Onsoft\Agt\Http\Controladores\ControladorRelatorios::class, 'billingTypes'])->name('billing-types');

        // PDF A4 — stream directo para o browser
        Route::get('/pdf-listagem',          [\Onsoft\Agt\Http\Controladores\ControladorRelatorios::class, 'pdfListagem'])->name('pdf-listagem');
        Route::get('/pdf-resumo-financeiro', [\Onsoft\Agt\Http\Controladores\ControladorRelatorios::class, 'pdfResumoFinanceiro'])->name('pdf-resumo-financeiro');
        Route::get('/pdf-iva',               [\Onsoft\Agt\Http\Controladores\ControladorRelatorios::class, 'pdfIva'])->name('pdf-iva');
        Route::get('/pdf-devedores',         [\Onsoft\Agt\Http\Controladores\ControladorRelatorios::class, 'pdfDevedores'])->name('pdf-devedores');
    });

    // ── Factura Pró-forma ("FP") — NUNCA persistida, NUNCA fiscal ─────────
    // Documento interno mostrado ao cliente antes de qualquer compromisso.
    // Calculado e renderizado inteiramente em memória; nada é escrito em
    // nenhuma tabela em nenhum destes endpoints. Ver ServicoFaturaProforma.
    Route::prefix('proforma')->name('proforma.')->group(function () {
        Route::post('/calcular',    [\Onsoft\Agt\Http\Controladores\ControladorFaturaProforma::class, 'calcular'])->name('calcular');
        Route::post('/pdf',         [\Onsoft\Agt\Http\Controladores\ControladorFaturaProforma::class, 'pdf'])->name('pdf');
        Route::post('/pdf-base64',  [\Onsoft\Agt\Http\Controladores\ControladorFaturaProforma::class, 'pdfBase64'])->name('pdf-base64');
    });

});

// ── Callback/Webhook AGT — NÃO IMPLEMENTADO ────────────────────────────
// A documentação OFICIAL da AGT (Introdução, secção "Mecanismos
// disponíveis") confirma explicitamente: "Callback (se ativado) —
// Disponível nas próximas versões." Este mecanismo NÃO existe ainda
// na API AGT. O único mecanismo real de actualização de estado é
// POLLING via obterEstado (ver onsoft-agt:consultar-submissoes).
// Quando a AGT disponibilizar callbacks, este endpoint deve ser
// reintroduzido com a especificação real (payload, assinatura,
// eventos) confirmada na documentação actualizada — dentro do grupo
// /onsoft-agt, como tudo o resto.
