<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family: DejaVu Sans, Arial, sans-serif; font-size:9pt; color:#1a1a1a; background:#fff; }

/* ── Cabeçalho ─────────────────────────────────────────────── */
.cabecalho { display:table; width:100%; padding-bottom:8px; border-bottom:2px solid #1a1a72; margin-bottom:10px; }
.cab-esq   { display:table-cell; width:55%; vertical-align:top; }
.cab-dir   { display:table-cell; width:45%; vertical-align:top; text-align:right; }
.logo img  { max-height:55px; max-width:160px; }
.nome-org  { font-size:13pt; font-weight:bold; color:#1a1a72; margin-top:3px; }
.info-org  { font-size:8pt; color:#555; line-height:1.5; }
.tipo-doc  { font-size:16pt; font-weight:bold; color:#1a1a72; text-transform:uppercase; }
.tipo-doc.nc { color:#c0392b; }
.tipo-doc.nd { color:#d35400; }
.num-doc   { font-size:10pt; font-weight:bold; margin-top:2px; }
.via-doc   { font-size:8.5pt; font-weight:bold; margin-top:3px; padding:2px 8px; border-radius:3px; display:inline-block; }
.via-original { background:#e8f5e9; color:#1b5e20; border:1px solid #2e7d32; }
.via-copia     { background:#fff3e0; color:#7a4a00; border:1px solid #e08e00; }
.data-doc  { font-size:8.5pt; color:#444; margin-top:2px; }

/* ── Banners de estado AGT ─────────────────────────────────── */
.banner { border-radius:4px; padding:8px 12px; margin:8px 0; font-size:8.5pt; }
.banner-cancelado  { background:#fdecea; border:1.5px solid #e53935; color:#b71c1c; }
.banner-nc         { background:#fff3e0; border:1.5px solid #fb8c00; color:#e65100; }
.banner-draft      { background:#fff9c4; border:1.5px solid #f9a825; color:#6d4c00; }
.banner-pendente   { background:#e3f2fd; border:1.5px solid #1e88e5; color:#0d47a1; }
.banner-rejeitado  { background:#fce4ec; border:1.5px solid #e91e63; color:#880e4f; }
.banner-falhou     { background:#f3e5f5; border:1.5px solid #8e24aa; color:#4a148c; }
.banner-titulo     { font-weight:bold; font-size:9pt; margin-bottom:3px; }
.banner-detalhe    { font-size:8pt; line-height:1.6; }
.btn-submeter      { display:inline-block; background:#1565c0; color:#fff; padding:3px 10px; border-radius:3px; font-size:7.5pt; font-weight:bold; margin-top:4px; }

/* ── Referência NC/ND ──────────────────────────────────────── */
.ref-original { background:#fff3cd; border:1px solid #ffc107; border-radius:3px; padding:5px 8px; margin:6px 0; font-size:8.5pt; color:#856404; }

/* ── Emitente e Cliente ────────────────────────────────────── */
.sec-cliente { display:table; width:100%; margin:8px 0; }
.bloco-emit  { display:table-cell; width:50%; vertical-align:top; }
.bloco-cli   { display:table-cell; width:50%; vertical-align:top; padding-left:15px; }
.titulo-sec  { font-size:7.5pt; font-weight:bold; color:#fff; background:#1a1a72; padding:2px 6px; margin-bottom:3px; text-transform:uppercase; }
.titulo-sec.nc { background:#c0392b; }
.dado-linha  { font-size:8.5pt; line-height:1.6; }
.dado-label  { color:#777; font-size:7.5pt; }

/* ── Estudantes ─────────────────────────────────────────────── */
.alunos-lista  { background:#f0f4ff; border:1px solid #c5cfe6; border-radius:3px; padding:4px 8px; margin:4px 0; font-size:8pt; }
.alunos-titulo { font-weight:bold; color:#1a1a72; margin-bottom:2px; }
.isento-badge  { background:#e8f5e9; color:#2e7d32; border-radius:2px; padding:1px 4px; font-size:7pt; }

/* ── Tabela de itens ────────────────────────────────────────── */
table.itens { width:100%; border-collapse:collapse; margin:8px 0; }
table.itens thead tr { background:#1a1a72; color:#fff; }
table.itens thead.nc tr { background:#c0392b; }
table.itens th { padding:4px 5px; font-size:7.5pt; text-align:left; font-weight:bold; }
table.itens td { padding:4px 5px; font-size:8pt; border-bottom:1px solid #e8e8e8; vertical-align:top; }
table.itens tr:nth-child(even) td { background:#f9f9f9; }
table.itens .num { text-align:right; }
table.itens .ctr { text-align:center; }

/* ── Totais ─────────────────────────────────────────────────── */
.bloco-totais  { display:table; width:100%; margin-top:6px; }
.totais-espaco { display:table-cell; width:55%; }
.totais-tabela { display:table-cell; width:45%; }
table.totais   { width:100%; border-collapse:collapse; }
table.totais td { padding:3px 6px; font-size:8.5pt; }
table.totais .lbl { color:#444; }
table.totais .val { text-align:right; font-weight:bold; }
table.totais .gross { background:#1a1a72; color:#fff; font-size:10pt; font-weight:bold; }
table.totais .gross.nc { background:#c0392b; }

/* ── Pagamentos ─────────────────────────────────────────────── */
.sec-pag    { margin-top:8px; border-top:1px solid #ddd; padding-top:6px; }
.pag-titulo { font-size:8pt; font-weight:bold; color:#1a1a72; margin-bottom:4px; text-transform:uppercase; }
.pag-linha  { display:table; width:100%; font-size:8pt; padding:2px 0; border-bottom:1px dotted #eee; }
.pag-met    { display:table-cell; width:60%; }
.pag-val    { display:table-cell; width:40%; text-align:right; font-weight:bold; }
.troco-linha { background:#e8f5e9; border:1px solid #a5d6a7; border-radius:3px; padding:3px 6px; margin-top:4px; font-size:8pt; font-weight:bold; color:#2e7d32; }

/* ── Rodapé AGT ─────────────────────────────────────────────── */
.rodape-agt     { margin-top:10px; border-top:1px solid #ccc; padding-top:6px; }
.linha-cert     { font-size:7.5pt; color:#333; font-style:italic; background:#f5f5f5; padding:4px 8px; border-radius:3px; margin-bottom:4px; word-break:break-all; }
.hash-full      { font-size:6.5pt; color:#999; word-break:break-all; font-family:monospace; }
.rodape-bottom  { display:table; width:100%; margin-top:6px; }
.rodape-texto   { display:table-cell; vertical-align:middle; }
.rodape-qr      { display:table-cell; width:90px; text-align:right; vertical-align:middle; }
.rodape-qr img  { width:80px; height:80px; }
.rodape-qr-label { font-size:6pt; color:#888; text-align:center; display:block; margin-top:2px; }
.rodape-final   { margin-top:8px; text-align:center; font-size:7pt; color:#888; border-top:1px dashed #ddd; padding-top:6px; }

/* ── Estado AGT badge ───────────────────────────────────────── */
.agt-badge { display:inline-block; border-radius:3px; padding:1px 6px; font-size:7pt; font-weight:bold; }
.agt-badge.accepted  { background:#e8f5e9; color:#2e7d32; }
.agt-badge.submitted { background:#e3f2fd; color:#1565c0; }
.agt-badge.pending   { background:#fff9c4; color:#6d4c00; }
.agt-badge.draft     { background:#eceff1; color:#455a64; }
.agt-badge.rejected  { background:#fce4ec; color:#880e4f; }
.agt-badge.failed    { background:#f3e5f5; color:#4a148c; }
.agt-badge.cancelled { background:#ffebee; color:#b71c1c; }

/* ── Marca d'água NC ─────────────────────────────────────────── */
.marca-dagua { position:fixed; top:35%; left:10%; width:80%; text-align:center; font-size:60pt; color:rgba(192,57,43,0.07); transform:rotate(-35deg); font-weight:bold; z-index:-1; }
.marca-dagua-cancelado { position:fixed; top:35%; left:5%; width:90%; text-align:center; font-size:55pt; color:rgba(183,28,28,0.06); transform:rotate(-35deg); font-weight:bold; z-index:-1; }
</style>
</head>
<body>

@php
  $agtStatus      = data_get($agt, 'agt_status', data_get($invoice, 'agt_status', 'draft'));
  $paymentStatus  = data_get($invoice, 'payment_status', '');
  $estaCancelado  = in_array($paymentStatus, ['cancelled', 'canceled']);
  $naoSubmetido   = in_array($agtStatus, ['draft', '']);
  $emFila         = in_array($agtStatus, ['pending']);
  $submetido      = in_array($agtStatus, ['submitted']);
  $aceite         = in_array($agtStatus, ['accepted', 'aceite']);
  $rejeitado      = in_array($agtStatus, ['rejected', 'rejeitado']);
  $falhou         = in_array($agtStatus, ['failed']);
  $canceladoAgt   = in_array($agtStatus, ['cancelled', 'canceled', 'pending_nc']);
@endphp

{{-- Marca d'água --}}
@if($tipo_documento === 'NC')
<div class="marca-dagua">NOTA DE CRÉDITO</div>
@elseif($estaCancelado)
<div class="marca-dagua-cancelado">CANCELADO</div>
@endif

{{-- ══ BANNER DE ESTADO AGT (TOPO) ════════════════════════════ --}}

{{-- CANCELADO --}}
@if($estaCancelado && $tipo_documento !== 'NC')
<div class="banner banner-cancelado">
  <div class="banner-titulo">⛔ DOCUMENTO CANCELADO</div>
  <div class="banner-detalhe">
    Este documento foi cancelado e não tem validade fiscal.
    @if(data_get($invoice, 'cancel_reason'))
      <br><strong>Motivo:</strong> {{ data_get($invoice, 'cancel_reason') }}
    @endif
    @if(data_get($invoice, 'cancelled_at'))
      <br><strong>Data de cancelamento:</strong> {{ \Carbon\Carbon::parse(data_get($invoice, 'cancelled_at'))->format('d/m/Y H:i') }}
    @endif
    @if(data_get($invoice, 'sourceInvoiceId'))
      <br><strong>Nota de Crédito emitida:</strong> Ver documento NC associado (ID: {{ data_get($invoice, 'sourceInvoiceId') }})
    @endif
    <br><em>Conforme AGT: documentos cancelados após submissão requerem Nota de Crédito.</em>
  </div>
</div>
@endif

{{-- NÃO SUBMETIDO À AGT --}}
@if($naoSubmetido && !$estaCancelado && $tipo_documento !== 'RC')
<div class="banner banner-draft">
  <div class="banner-titulo">⚠️ DOCUMENTO NÃO SUBMETIDO À AGT</div>
  <div class="banner-detalhe">
    Este documento ainda não foi enviado à Administração Geral Tributária.
    <br>Para submeter, use o endpoint: <strong>POST /onsoft-agt/faturas/{{ data_get($invoice, 'id') }}/submeter</strong>
    <br><em>A submissão é obrigatória para documentos com validade fiscal (Decreto Executivo AGT).</em>
  </div>
</div>
@endif

{{-- EM FILA (PENDING) --}}
@if($emFila && !$estaCancelado)
<div class="banner banner-pendente">
  <div class="banner-titulo">🕐 EM FILA DE SUBMISSÃO AGT</div>
  <div class="banner-detalhe">
    Este documento está na fila de envio para a AGT. Será processado em breve pelo sistema.
    <br><strong>UUID de submissão:</strong> {{ data_get($agt, 'submission_uuid', '—') }}
    <br><em>Aguardar confirmação de aceitação pela AGT.</em>
  </div>
</div>
@endif

{{-- SUBMETIDO (aguarda resposta) --}}
@if($submetido && !$estaCancelado)
<div class="banner banner-pendente">
  <div class="banner-titulo">📤 SUBMETIDO — AGUARDA RESPOSTA DA AGT</div>
  <div class="banner-detalhe">
    Documento enviado à AGT. A aguardar confirmação de aceitação.
    <br><strong>UUID:</strong> {{ data_get($agt, 'submission_uuid', '—') }}
  </div>
</div>
@endif

{{-- REJEITADO PELA AGT --}}
@if($rejeitado && !$estaCancelado)
<div class="banner banner-rejeitado">
  <div class="banner-titulo">❌ REJEITADO PELA AGT</div>
  <div class="banner-detalhe">
    Este documento foi rejeitado pela Administração Geral Tributária.
    <br><strong>Acção recomendada:</strong> Verificar os dados do documento e resubmeter após correcção.
    <br>Use: <strong>POST /onsoft-agt/faturas/{{ data_get($invoice, 'id') }}/submeter</strong>
    <br><em>Se o erro persistir, emita uma Nota de Crédito e crie uma nova fatura corrigida.</em>
  </div>
</div>
@endif

{{-- FALHOU (erro técnico) --}}
@if($falhou && !$estaCancelado)
<div class="banner banner-falhou">
  <div class="banner-titulo">🔴 ERRO NA SUBMISSÃO AGT</div>
  <div class="banner-detalhe">
    Ocorreu um erro técnico ao submeter à AGT. O documento não foi recebido.
    <br><strong>Acção recomendada:</strong> Retentar a submissão.
    <br>Use: <strong>POST /onsoft-agt/faturas/{{ data_get($invoice, 'id') }}/submeter</strong>
    <br>Ou via Artisan: <strong>php artisan onsoft-agt:retentar-falhas</strong>
  </div>
</div>
@endif

{{-- ══ CABEÇALHO DO DOCUMENTO ══════════════════════════════════ --}}
<div class="cabecalho">
  <div class="cab-esq">
    @if($mostrar_logo && !empty(data_get($organization, 'logo_url')))
      <div class="logo"><img src="{{ data_get($organization, 'logo_url') }}" alt="Logo"></div>
    @endif
    <div class="nome-org">{{ data_get($organization, 'name', data_get($organization, 'commercial_name', '')) }}</div>
    <div class="info-org">
      NIF: {{ data_get($organization, 'nif', '—') }}<br>
      {{ data_get($organization, 'address', '') }}@if(data_get($organization, 'city')), {{ data_get($organization, 'city') }}@endif<br>
      @if(data_get($organization, 'telefone'))Tel: {{ data_get($organization, 'telefone') }}@endif
      @if(data_get($organization, 'email')) | {{ data_get($organization, 'email') }}@endif
    </div>
  </div>
  <div class="cab-dir">
    <div class="tipo-doc {{ strtolower($tipo_documento) }}">{{ $label_tipo }}</div>
    <div class="num-doc">Nº {{ data_get($invoice, 'document_no') }}</div>
    {{-- Via do documento — Original ou Cópia do documento original (AGT Anexo I, ponto 6) --}}
    <div class="via-doc {{ $e_original ? 'via-original' : 'via-copia' }}">{{ $via_label ?? 'Original' }}</div>
    <div class="data-doc">
      Data: {{ \Carbon\Carbon::parse(data_get($invoice, 'issued_at'))->format('d-m-Y') }}<br>
      Hora: {{ \Carbon\Carbon::parse(data_get($invoice, 'issued_at'))->format('H:i:s') }}
    </div>
    @if(data_get($invoice, 'fiscal_year'))
    <div class="data-doc">Ano Fiscal: {{ data_get($invoice, 'fiscal_year') }}</div>
    @endif
    {{-- Badge de estado AGT no cabeçalho --}}
    <div style="margin-top:4px;">
      <span class="agt-badge {{ $agtStatus }}">AGT: {{ strtoupper($agtStatus) }}</span>
      @if($aceite) <span class="agt-badge accepted">✓ ACEITE</span>@endif
    </div>
  </div>
</div>

{{-- ══ REFERÊNCIA NC / ND ═══════════════════════════════════════ --}}
@if(in_array($tipo_documento, ['NC','ND']) && data_get($invoice, 'sourceInvoiceId'))
<div class="ref-original">
  <strong>📎 Referência:</strong> {{ $label_tipo }} referente à fatura original ID: {{ data_get($invoice, 'sourceInvoiceId') }}
  @if(data_get($invoice, 'cancel_reason'))
    | <strong>Motivo:</strong> {{ data_get($invoice, 'cancel_reason') }}
  @endif
</div>
@endif

{{-- ══ EMITENTE E CLIENTE ════════════════════════════════════════ --}}
<div class="sec-cliente">
  <div class="bloco-emit">
    <div class="titulo-sec {{ $tipo_documento === 'NC' ? 'nc' : '' }}">Emitido por</div>
    <div class="dado-linha"><strong>{{ data_get($organization, 'name', '—') }}</strong></div>
    <div class="dado-linha"><span class="dado-label">NIF:</span> {{ data_get($organization, 'nif', '—') }}</div>
    <div class="dado-linha">{{ data_get($organization, 'address', '') }}</div>
  </div>
  <div class="bloco-cli">
    <div class="titulo-sec {{ $tipo_documento === 'NC' ? 'nc' : '' }}">Cliente</div>
    <div class="dado-linha">
      <strong>{{ $nome_cliente }}</strong>
      @if($e_consumidor_final) &nbsp;<span class="isento-badge">Consumidor Final</span>@endif
    </div>
    <div class="dado-linha"><span class="dado-label">NIF:</span> {{ $e_consumidor_final ? 'Consumidor Final' : $nif_cliente }}</div>
    @if(data_get($customer, 'email'))<div class="dado-linha"><span class="dado-label">Email:</span> {{ data_get($customer, 'email') }}</div>@endif
    @if(data_get($customer, 'telefone'))<div class="dado-linha"><span class="dado-label">Tel:</span> {{ data_get($customer, 'telefone') }}</div>@endif
  </div>
</div>

{{-- ══ ESTUDANTES ════════════════════════════════════════════════ --}}
@php $comAluno = collect($items)->filter(fn($i) => !empty($i['alunoId'])); @endphp
@if($comAluno->isNotEmpty())
<div class="alunos-lista">
  <div class="alunos-titulo">📚 Referente ao(s) Estudante(s):</div>
  @foreach($comAluno->unique('alunoId') as $item)
    @if(!empty($item['aluno_snapshot']))
    <div>• {{ data_get($item['aluno_snapshot'], 'name', 'Aluno #'.$item['alunoId']) }}
      @if(data_get($item['aluno_snapshot'], 'regNumero')) — Nº {{ data_get($item['aluno_snapshot'], 'regNumero') }}@endif
    </div>
    @endif
  @endforeach
</div>
@endif

{{-- ══ TABELA DE ITENS ═══════════════════════════════════════════ --}}
<table class="itens">
  <thead class="{{ $tipo_documento === 'NC' ? 'nc' : '' }}">
    <tr>
      <th style="width:4%">#</th>
      <th style="width:35%">Descrição</th>
      <th style="width:10%" class="num">Qtd</th>
      <th style="width:12%" class="num">Preço Unit.</th>
      <th style="width:9%"  class="num">Desc.</th>
      <th style="width:10%" class="ctr">IVA %</th>
      <th style="width:10%" class="num">IVA</th>
      <th style="width:10%" class="num">Total</th>
    </tr>
  </thead>
  <tbody>
    @foreach($items as $item)
    @php
      $isento = in_array($item['tax_type'] ?? '', ['ISENTO','ISE']);
      $total  = (float)($item['line_total'] ?? $item['total'] ?? 0);
      $iva    = (float)($item['tax_amount'] ?? 0);
      $desc   = (float)($item['discount_amount'] ?? 0);
    @endphp
    <tr>
      <td class="ctr">{{ $item['line_number'] ?? ($loop->index + 1) }}</td>
      <td>
        {{ $item['description'] ?? '' }}
        @if($isento)<br><span class="isento-badge">Isento — {{ $item['tax_reason'] ?? 'M00' }}</span>@endif
        @if(!empty($item['aluno_snapshot']))<br><small style="color:#888">Aluno: {{ data_get($item['aluno_snapshot'], 'name', '') }}</small>@endif
      </td>
      <td class="num">{{ number_format((float)($item['quantity'] ?? 0), 2, ',', '.') }}</td>
      <td class="num">{{ number_format((float)($item['unit_price'] ?? 0), 2, ',', '.') }}</td>
      <td class="num">{{ $desc > 0 ? number_format($desc, 2, ',', '.') : '—' }}</td>
      <td class="ctr">@if($isento)<span class="isento-badge">Isento</span>@else{{ number_format((float)($item['tax_percentage'] ?? 0), 0) }}%@endif</td>
      <td class="num">{{ $isento ? '0,00' : number_format($iva, 2, ',', '.') }}</td>
      <td class="num"><strong>{{ number_format($total, 2, ',', '.') }}</strong></td>
    </tr>
    @endforeach
  </tbody>
</table>

{{-- ══ TOTAIS ════════════════════════════════════════════════════ --}}
<div class="bloco-totais">
  <div class="totais-espaco"></div>
  <div class="totais-tabela">
    <table class="totais">
      <tr><td class="lbl">Base Tributável</td><td class="val">{{ number_format((float)($invoice['subtotal'] ?? 0), 2, ',', '.') }} AOA</td></tr>
      @if((float)($invoice['discount_total'] ?? 0) > 0)
      <tr><td class="lbl">Desconto</td><td class="val">{{ number_format((float)$invoice['discount_total'], 2, ',', '.') }} AOA</td></tr>
      @endif
      <tr><td class="lbl">IVA Total</td><td class="val">{{ number_format((float)($invoice['tax_total'] ?? 0), 2, ',', '.') }} AOA</td></tr>
      <tr class="gross {{ $tipo_documento === 'NC' ? 'nc' : '' }}">
        <td class="lbl" style="color:#fff">TOTAL</td>
        <td class="val" style="color:#fff; font-size:11pt">{{ number_format((float)($invoice['gross_total'] ?? 0), 2, ',', '.') }} AOA</td>
      </tr>
      @if(in_array($tipo_documento, ['FR','RC']))
      <tr><td class="lbl">Total Pago</td><td class="val" style="color:#2e7d32">{{ number_format((float)($invoice['paid_total'] ?? 0), 2, ',', '.') }} AOA</td></tr>
      @if((float)($invoice['remaining_balance'] ?? 0) > 0)
      <tr><td class="lbl" style="color:#c0392b">Em Falta</td><td class="val" style="color:#c0392b">{{ number_format((float)$invoice['remaining_balance'], 2, ',', '.') }} AOA</td></tr>
      @endif
      @if((float)($invoice['change_amount'] ?? 0) > 0)
      <tr><td class="lbl" style="color:#1565c0">Troco/Crédito</td><td class="val" style="color:#1565c0">{{ number_format((float)$invoice['change_amount'], 2, ',', '.') }} AOA</td></tr>
      @endif
      @endif
    </table>
  </div>
</div>

{{-- ══ PAGAMENTOS ════════════════════════════════════════════════ --}}
@if(!empty($payments) && in_array($tipo_documento, ['FR','RC']))
<div class="sec-pag">
  <div class="pag-titulo">💳 Meios de Pagamento</div>
  @foreach($payments as $pag)
    @foreach($pag['methods'] ?? [] as $met)
    <div class="pag-linha">
      <div class="pag-met">{{ config('onsoft-agt.meios_pagamento.'.strtolower($met['method_code'] ?? ''), $met['method_code'] ?? 'Outro') }}
        @if(!empty($met['reference'])) — Ref: {{ $met['reference'] }}@endif
      </div>
      <div class="pag-val">{{ number_format((float)($met['amount'] ?? 0), 2, ',', '.') }} AOA</div>
    </div>
    @endforeach
  @endforeach
  @if((float)($invoice['change_amount'] ?? 0) > 0)
  <div class="troco-linha">💰 Troco creditado na carteira: {{ number_format((float)$invoice['change_amount'], 2, ',', '.') }} AOA</div>
  @endif
</div>
@endif

{{-- ══ RODAPÉ AGT + QR ═══════════════════════════════════════════ --}}
<div class="rodape-agt">
  <div class="linha-cert">{{ $linha_agt }}</div>
  @if(!empty($agt['invoice_hash']))
  <div class="hash-full">Hash: {{ $agt['invoice_hash'] }}</div>
  @endif
  <div class="rodape-bottom">
    <div class="rodape-texto">
      <div style="font-size:7.5pt; color:#555; margin-top:4px;">
        Documento gerado electronicamente por sistema validado AGT<br>
        {{ config('onsoft-agt.software.nome','Onsoft AGT') }} v{{ config('onsoft-agt.software.versao','1.0.0') }}
        @if(in_array($tipo_documento, ['NC','ND','RC','RG','AR','RE']))
        <br><strong>Este documento não serve de fatura.</strong>
        @endif
        @if($estaCancelado)
        <br><strong style="color:#b71c1c;">DOCUMENTO CANCELADO — Sem validade fiscal.</strong>
        @endif
        @if(!$e_original)
        <br><strong style="color:#7a4a00;">Cópia do documento original — {{ data_get($invoice, 'document_no') }}</strong>
        @endif
      </div>
    </div>
    @if($mostrar_qr && $qr_base64)
    <div class="rodape-qr">
      <img src="data:{{ $qr_mime_type ?? 'image/png' }};base64,{{ $qr_base64 }}" width="80" height="80" alt="QR AGT">
      <span class="rodape-qr-label">Verificação AGT</span>
    </div>
    @endif
  </div>
</div>

</body>
</html>
