<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family: DejaVu Sans Mono, Courier New, monospace; font-size:7.5pt; color:#000; background:#fff; width:80mm; max-width:80mm; }
.c   { text-align:center; }
.r   { text-align:right; }
.b   { font-weight:bold; }
.sep { border-top:1px dashed #000; margin:4px 0; }
.sep2 { border-top:2px solid #000; margin:4px 0; }
.row { display:table; width:100%; }
.l   { display:table-cell; }
.rv  { display:table-cell; text-align:right; }
.tipo-doc { font-size:11pt; font-weight:bold; text-align:center; }
.num-doc  { font-size:9pt; font-weight:bold; text-align:center; }
.via-doc  { font-size:7.5pt; font-weight:bold; text-align:center; margin:2px 0; }
.via-doc.via-original { color:#1b5e20; }
.via-doc.via-copia    { color:#7a4a00; }
.org-nome { font-size:9pt; font-weight:bold; text-align:center; }
.org-info { font-size:7pt; text-align:center; line-height:1.4; }
table.i   { width:100%; border-collapse:collapse; }
table.i th { font-size:7pt; border-bottom:1px solid #000; padding:1px 0; }
table.i td { font-size:7.5pt; padding:2px 0; border-bottom:1px dotted #ccc; }
table.i .r { text-align:right; }
.tot-linha { display:table; width:100%; font-size:8pt; padding:2px 0; }
.tot-lbl   { display:table-cell; }
.tot-val   { display:table-cell; text-align:right; }
.tot-gross { font-size:11pt; font-weight:bold; }
.qr-area   { text-align:center; margin:4px 0; }
.qr-area img { width:70px; height:70px; }
.qr-label  { font-size:6pt; color:#555; }
.rodape    { font-size:6.5pt; text-align:center; margin-top:4px; line-height:1.5; }
.cert-linha { font-size:7pt; font-weight:bold; text-align:center; margin:3px 0; word-break:break-all; }
/* banners */
.banner { border:1px solid #000; padding:4px 6px; margin:3px 0; font-size:7pt; }
.banner-titulo { font-weight:bold; margin-bottom:2px; }
</style>
</head>
<body>

@php
  $agtStatus     = data_get($agt, 'agt_status', data_get($invoice, 'agt_status', 'draft'));
  $payStatus     = data_get($invoice, 'payment_status', '');
  $estaCancelado = in_array($payStatus, ['cancelled','canceled']);
  $naoSubmetido  = in_array($agtStatus, ['draft','']);
  $emFila        = $agtStatus === 'pending';
  $rejeitado     = in_array($agtStatus, ['rejected','rejeitado']);
  $falhou        = $agtStatus === 'failed';
@endphp

{{-- BANNERS DE ESTADO --}}
@if($estaCancelado && $tipo_documento !== 'NC')
<div class="banner">
  <div class="banner-titulo">*** DOCUMENTO CANCELADO ***</div>
  @if(data_get($invoice,'cancel_reason'))Motivo: {{ data_get($invoice,'cancel_reason') }}<br>@endif
  Sem validade fiscal.
</div>
<div class="sep"></div>
@endif

@if($naoSubmetido && !$estaCancelado && $tipo_documento !== 'RC')
<div class="banner">
  <div class="banner-titulo">! NAO SUBMETIDO A AGT !</div>
  Submeter via: POST /onsoft-agt/faturas/{{ data_get($invoice,'id') }}/submeter
</div>
<div class="sep"></div>
@endif

@if($rejeitado)
<div class="banner">
  <div class="banner-titulo">*** REJEITADO PELA AGT ***</div>
  Verificar dados e resubmeter.
</div>
<div class="sep"></div>
@endif

@if($falhou)
<div class="banner">
  <div class="banner-titulo">! ERRO NA SUBMISSAO AGT !</div>
  Retentar: php artisan onsoft-agt:retentar-falhas
</div>
<div class="sep"></div>
@endif

@if($emFila)
<div class="banner">
  <div class="banner-titulo">... EM FILA AGT ...</div>
  A aguardar processamento.
</div>
<div class="sep"></div>
@endif

{{-- CABEÇALHO --}}
<div class="org-nome">{{ data_get($organization, 'name', '') }}</div>
<div class="org-info">
  NIF: {{ data_get($organization, 'nif', '—') }}<br>
  {{ data_get($organization, 'address', '') }}<br>
  @if(data_get($organization,'telefone'))Tel: {{ data_get($organization,'telefone') }}@endif
</div>
<div class="sep2"></div>
<div class="tipo-doc">{{ strtoupper($label_tipo) }}</div>
<div class="num-doc">Nº {{ data_get($invoice,'document_no') }}</div>
<div class="via-doc {{ $e_original ? 'via-original' : 'via-copia' }}">{{ $via_label ?? 'Original' }}</div>
<div class="org-info">
  {{ \Carbon\Carbon::parse(data_get($invoice,'issued_at'))->format('d-m-Y H:i:s') }}<br>
  AGT: {{ strtoupper($agtStatus) }}
</div>
<div class="sep"></div>

<div class="row"><div class="l">Cliente:</div><div class="rv">{{ \Illuminate\Support\Str::limit($nome_cliente, 20) }}</div></div>
@if(!$e_consumidor_final)<div class="row"><div class="l">NIF:</div><div class="rv">{{ $nif_cliente }}</div></div>@endif

@php $comAluno = collect($items)->filter(fn($i) => !empty($i['alunoId']))->unique('alunoId'); @endphp
@if($comAluno->isNotEmpty())
<div class="sep"></div>
<div class="b">Estudante(s):</div>
@foreach($comAluno as $item)
  @if(!empty($item['aluno_snapshot']))
  <div>• {{ \Illuminate\Support\Str::limit(data_get($item['aluno_snapshot'],'name',''), 22) }}</div>
  @endif
@endforeach
@endif

<div class="sep"></div>
<table class="i">
  <thead>
    <tr>
      <th style="width:40%" class="l">Descrição</th>
      <th style="width:12%" class="r">Qtd</th>
      <th style="width:18%" class="r">Preço</th>
      <th style="width:10%" class="r">IVA</th>
      <th style="width:20%" class="r">Total</th>
    </tr>
  </thead>
  <tbody>
    @foreach($items as $item)
    @php
      $isento = in_array($item['tax_type'] ?? '', ['ISENTO','ISE']);
      $total  = (float)($item['line_total'] ?? $item['total'] ?? 0);
    @endphp
    <tr>
      <td>{{ \Illuminate\Support\Str::limit($item['description'] ?? '', 20) }}@if($isento)<br><small>(Isento)</small>@endif</td>
      <td class="r">{{ number_format((float)($item['quantity'] ?? 0), 1, ',', '') }}</td>
      <td class="r">{{ number_format((float)($item['unit_price'] ?? 0), 2, ',', '') }}</td>
      <td class="r">{{ $isento ? 'I' : number_format((float)($item['tax_percentage'] ?? 0), 0).'%' }}</td>
      <td class="r">{{ number_format($total, 2, ',', '') }}</td>
    </tr>
    @endforeach
  </tbody>
</table>
<div class="sep"></div>
<div class="tot-linha"><div class="tot-lbl">Base Tributável</div><div class="tot-val">{{ number_format((float)($invoice['subtotal'] ?? 0), 2, ',', '.') }} AOA</div></div>
<div class="tot-linha"><div class="tot-lbl">IVA Total</div><div class="tot-val">{{ number_format((float)($invoice['tax_total'] ?? 0), 2, ',', '.') }} AOA</div></div>
<div class="sep2"></div>
<div class="tot-linha tot-gross"><div class="tot-lbl">TOTAL</div><div class="tot-val">{{ number_format((float)($invoice['gross_total'] ?? 0), 2, ',', '.') }} AOA</div></div>

@if(in_array($tipo_documento, ['FR','RC']) && !empty($payments))
<div class="sep"></div>
@foreach($payments as $pag)@foreach($pag['methods'] ?? [] as $met)
<div class="tot-linha">
  <div class="tot-lbl">{{ \Illuminate\Support\Str::limit(config('onsoft-agt.meios_pagamento.'.strtolower($met['method_code'] ?? ''), $met['method_code'] ?? 'Outro'), 18) }}</div>
  <div class="tot-val">{{ number_format((float)($met['amount'] ?? 0), 2, ',', '.') }} AOA</div>
</div>
@endforeach@endforeach
@if((float)($invoice['change_amount'] ?? 0) > 0)
<div class="tot-linha b"><div class="tot-lbl">Troco/Crédito</div><div class="tot-val">{{ number_format((float)$invoice['change_amount'], 2, ',', '.') }} AOA</div></div>
@endif
@endif

<div class="sep2"></div>

@if($mostrar_qr && $qr_base64)
<div class="qr-area">
  <img src="data:{{ $qr_mime_type ?? 'image/png' }};base64,{{ $qr_base64 }}" width="70" height="70" alt="QR AGT">
  <div class="qr-label">Verificação AGT</div>
</div>
@endif

<div class="cert-linha">{{ $linha_agt }}</div>
@if(!empty($agt['invoice_hash']))
<div class="rodape" style="font-size:5.5pt; word-break:break-all;">Hash: {{ $agt['invoice_hash'] }}</div>
@endif
<div class="sep"></div>
<div class="rodape">
  {{ \Carbon\Carbon::parse(data_get($invoice,'issued_at'))->format('d-m-Y H:i:s') }}<br>
  {{ config('onsoft-agt.software.nome','Onsoft AGT') }}
  @if($estaCancelado)<br><b>CANCELADO</b>@endif
  @if(in_array($tipo_documento,['NC','ND','RC','RG','AR','RE']))<br><b>Nao serve de fatura.</b>@endif
  @if(!$e_original)<br><b>Copia do documento original - {{ data_get($invoice,'document_no') }}</b>@endif
</div>
</body>
</html>
