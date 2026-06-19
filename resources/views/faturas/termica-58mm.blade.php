<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family: DejaVu Sans Mono, Courier New, monospace; font-size:6.5pt; color:#000; background:#fff; width:52mm; max-width:52mm; }
.c  { text-align:center; }
.r  { text-align:right; }
.b  { font-weight:bold; }
.sep  { border-top:1px dashed #000; margin:3px 0; }
.sep2 { border-top:2px solid #000; margin:3px 0; }
.row  { display:table; width:100%; }
.l    { display:table-cell; }
.rv   { display:table-cell; text-align:right; }
table.i  { width:100%; border-collapse:collapse; font-size:6pt; }
table.i td { padding:1px 0; border-bottom:1px dotted #aaa; }
table.i .r { text-align:right; }
.qr-area  { text-align:center; margin:3px 0; }
.qr-area img { width:55px; height:55px; }
.cert     { font-size:6.5pt; font-weight:bold; text-align:center; word-break:break-all; }
.banner   { border:1px solid #000; padding:2px 3px; margin:2px 0; font-size:6pt; text-align:center; font-weight:bold; }
</style>
</head>
<body>

@php
  $agtStatus     = data_get($agt, 'agt_status', data_get($invoice, 'agt_status', 'draft'));
  $payStatus     = data_get($invoice, 'payment_status', '');
  $estaCancelado = in_array($payStatus, ['cancelled','canceled']);
  $naoSubmetido  = in_array($agtStatus, ['draft','']);
  $rejeitado     = in_array($agtStatus, ['rejected','rejeitado']);
  $falhou        = $agtStatus === 'failed';
@endphp

@if($estaCancelado && $tipo_documento !== 'NC')
<div class="banner">*** CANCELADO ***</div>
@endif
@if($naoSubmetido && !$estaCancelado && $tipo_documento !== 'RC')
<div class="banner">! NAO SUBMETIDO A AGT !</div>
@endif
@if($rejeitado)<div class="banner">*** REJEITADO AGT ***</div>@endif
@if($falhou)<div class="banner">! ERRO SUBMISSAO AGT !</div>@endif

<div class="c b" style="font-size:8pt">{{ data_get($organization,'name','') }}</div>
<div class="c" style="font-size:6pt">NIF: {{ data_get($organization,'nif','—') }}</div>
<div class="sep2"></div>
<div class="c b" style="font-size:9pt">{{ strtoupper($label_tipo) }}</div>
<div class="c">Nº {{ data_get($invoice,'document_no') }}</div>
<div class="c" style="font-size:6.5pt; font-weight:bold; color:{{ $e_original ? '#1b5e20' : '#7a4a00' }}">{{ $via_label ?? 'Original' }}</div>
<div class="c" style="font-size:6pt">
  {{ \Carbon\Carbon::parse(data_get($invoice,'issued_at'))->format('d-m-Y H:i') }}<br>
  AGT: {{ strtoupper($agtStatus) }}
</div>
<div class="sep"></div>

<div class="row"><div class="l">Cliente:</div><div class="rv">{{ \Illuminate\Support\Str::limit($nome_cliente,16) }}</div></div>
@if(!$e_consumidor_final)<div class="row"><div class="l">NIF:</div><div class="rv">{{ $nif_cliente }}</div></div>@endif

@php $comAluno = collect($items)->filter(fn($i) => !empty($i['alunoId']))->unique('alunoId'); @endphp
@if($comAluno->isNotEmpty())
<div class="sep"></div>
@foreach($comAluno as $item)
@if(!empty($item['aluno_snapshot']))<div>Aluno: {{ \Illuminate\Support\Str::limit(data_get($item['aluno_snapshot'],'name',''),16) }}</div>@endif
@endforeach
@endif

<div class="sep"></div>
<table class="i">
@foreach($items as $item)
@php $total = (float)($item['line_total'] ?? $item['total'] ?? 0); @endphp
<tr><td colspan="2">{{ \Illuminate\Support\Str::limit($item['description'] ?? '',22) }}</td></tr>
<tr>
  <td>{{ number_format((float)($item['quantity'] ?? 0),1) }}x{{ number_format((float)($item['unit_price'] ?? 0),2) }}</td>
  <td class="r"><b>{{ number_format($total,2) }}</b></td>
</tr>
@endforeach
</table>
<div class="sep2"></div>
<div class="row b" style="font-size:9pt">
  <div class="l">TOTAL</div>
  <div class="rv">{{ number_format((float)($invoice['gross_total'] ?? 0),2,',','.') }} AOA</div>
</div>

@if(in_array($tipo_documento,['FR','RC']) && !empty($payments))
<div class="sep"></div>
@foreach($payments as $pag)@foreach($pag['methods'] ?? [] as $met)
<div class="row">
  <div class="l">{{ \Illuminate\Support\Str::limit(config('onsoft-agt.meios_pagamento.'.strtolower($met['method_code'] ?? ''),$met['method_code'] ?? 'Outro'),14) }}</div>
  <div class="rv">{{ number_format((float)($met['amount'] ?? 0),2) }}</div>
</div>
@endforeach@endforeach
@if((float)($invoice['change_amount'] ?? 0) > 0)
<div class="row b"><div class="l">Troco</div><div class="rv">{{ number_format((float)$invoice['change_amount'],2) }}</div></div>
@endif
@endif

<div class="sep2"></div>
@if($mostrar_qr && $qr_base64)
<div class="qr-area">
  <img src="data:{{ $qr_mime_type ?? 'image/png' }};base64,{{ $qr_base64 }}" width="55" height="55" alt="QR">
  <div style="font-size:5.5pt">Verificação AGT</div>
</div>
@endif
<div class="cert">{{ $linha_agt }}</div>
<div class="sep"></div>
<div class="c" style="font-size:5.5pt">
  {{ \Carbon\Carbon::parse(data_get($invoice,'issued_at'))->format('d-m-Y H:i:s') }}<br>
  {{ config('onsoft-agt.software.nome','Onsoft AGT') }}
  @if($estaCancelado)<br><b>CANCELADO</b>@endif
  @if(in_array($tipo_documento,['NC','ND','RC','RG','AR','RE']))<br><b>Nao serve de fatura.</b>@endif
  @if(!$e_original)<br><b>Copia do original</b>@endif
</div>
</body>
</html>
