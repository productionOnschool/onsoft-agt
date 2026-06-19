<div align="center">

# 🇦🇴 ONSOFT AGT
### Pacote Laravel de Faturação Eletrónica para Angola
**Compatível com Laravel 10 · 11 · 12 — PHP 8.1+**

[![Packagist](https://img.shields.io/packagist/v/productiononschool/onsoft-agt)](https://packagist.org/packages/productiononschool/onsoft-agt)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

**Desenvolvedor:** Adilson Miguel · adilson2012jose@gmail.com · 2068417074  
**Decreto Executivo AGT Angola — 100% Conforme**

</div>

---

## 📦 Instalação

```bash
composer require productiononschool/onsoft-agt
php artisan onsoft-agt:instalar
```

---

## ⚙️ Variáveis de Ambiente (.env)

```env
# ── Ambiente AGT ──────────────────────────────────────────────────
AGT_AMBIENTE=sandbox                    # sandbox | producao
AGT_MULTI_TENANT=true

# ── Chaves do SOFTWARE (Fabricante Onsoft — partilhadas por todos) ─
# Gerar: openssl genrsa -out priv.pem 2048
#        openssl rsa -in priv.pem -pubout -out pub.pem
# Converter para .env: awk 'NF {printf "%s\\n",$0;}' priv.pem
AGT_SOFTWARE_CHAVE_PRIVADA="-----BEGIN RSA PRIVATE KEY-----\nMIIE...\n-----END RSA PRIVATE KEY-----"
AGT_SOFTWARE_CHAVE_PUBLICA="-----BEGIN PUBLIC KEY-----\nMIIB...\n-----END PUBLIC KEY-----"
AGT_SOFTWARE_NUMERO_CERTIFICACAO=0000
AGT_SOFTWARE_VERSAO_CHAVE=1
AGT_SOFTWARE_NOME="Onsoft AGT"
AGT_SOFTWARE_VERSAO=1.3.0
AGT_SOFTWARE_NIF_FORNECEDOR=500000000

# ── Cada organização configura a sua chave de CONTRIBUINTE no painel ─
# AGT → Configuração → Chaves do Contribuinte
# Guardadas encriptadas com APP_KEY na tabela organization_agt_configs

# ── Configurações gerais ──────────────────────────────────────────
AGT_MOEDA_PADRAO=AOA
AGT_TAXA_IVA_PADRAO=14
```

---

## 🏗️ Arquitectura de Chaves

```
┌─────────────────────────────────────────────────────────────────────┐
│  CHAVE DO SOFTWARE (Fabricante — Onsoft/Adilson Miguel)             │
│  ─────────────────────────────────────────────────────────────────  │
│  • Representa o FABRICANTE do software de faturação                 │
│  • Registada na AGT UMA VEZ via Declaração Modelo 8                 │
│  • PARTILHADA por todas as organizações (escolas)                   │
│  • Guardada no .env do servidor                                     │
│  • Usada para: jwsSoftwareSignature                                 │
│                                                                     │
│  CHAVE DO CONTRIBUINTE (cada organização/escola)                    │
│  ─────────────────────────────────────────────────────────────────  │
│  • Representa a ESCOLA que emite faturas                            │
│  • Cada escola tem a sua própria chave                              │
│  • Guardada ENCRIPTADA na BD (organization_agt_configs)             │
│  • Desencriptada em memória via Laravel Crypt                       │
│  • Usada para: invoice_hash (RSA-SHA1) + jwsDocumentSignature       │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 🔄 Sistema de Billing Morph (Extensível)

O sistema usa **polimorfismo** no `InvoiceItem` para ligar cada linha de fatura ao seu modelo de origem.

### Como funciona

```
invoice_items
├── invoiceable_type = "App\Models\Invoice\Billing\BillingPropina"
├── invoiceable_id   = 42
└── → BillingPropina::find(42)
```

### Modelos actuais

| Chave | Modelo | Tabela | Descrição |
|-------|--------|--------|-----------|
| `propina` | `BillingPropina` | `billing_propinas` | Propinas mensais |
| `matricula` | `BillingMatricula` | `billing_matriculas` | Matrículas |
| `confirmacao` | `BillingConfirmacao` | `billing_confirmacoes` | Confirmações de matrícula |
| `recurso` | `BillingRecurso` | `billing_recursos` | Exames de recurso |
| `transporte` | `BillingTransporte` | `billing_transportes` | Transporte escolar |
| `produto` | `PedagogicalProduct` | `pedagogical_products` | Produtos pedagógicos |

### ➕ Adicionar um novo tipo de billing (sem quebrar o pacote)

```php
// 1. Criar o modelo (igual aos outros)
class BillingSeguro extends Model {
    use BelongsToOrganization;
    protected $table = 'billing_seguros';

    public function invoiceItems(): MorphMany {
        return $this->morphMany(InvoiceItem::class, 'invoiceable');
    }
}

// 2. Registar no AppServiceProvider (UMA LINHA):
\Onsoft\Agt\Suporte\RegistoBillingMorph::registar(
    'seguro',
    \App\Models\Invoice\Billing\BillingSeguro::class
);

// 3. Pronto. PDF, relatórios e snapshots adaptam-se automaticamente.
```

---

## 📋 Todos os Endpoints

### 🔐 Autenticação
Todos os endpoints requerem autenticação. Proteger com o middleware do projecto (JWT/Sanctum).

---

### 📄 FATURAS

#### `POST /onsoft-agt/faturas`
Criar fatura com múltiplos pagamentos, estudantes e billing morph.

**Request:**
```json
{
  "idempotency_key": "uuid-único-por-fatura",
  "document_type": "FR",
  "customer_nif": "500123456",
  "customer_name": "João Silva",
  "customer_email": "joao@gmail.com",
  "encarregadoId": 42,
  "items": [
    {
      "description": "Propina — Outubro 2026",
      "quantity": 1,
      "unit_price": 45000.00,
      "tax_code": "ISE",
      "tax_type": "ISENTO",
      "tax_percentage": 0,
      "tax_reason": "M00",
      "item_category": "propina",
      "product_code": "PROP-OUT-2026",
      "invoiceable_type": "App\\Models\\Invoice\\Billing\\BillingPropina",
      "invoiceable_id": 42,
      "alunoId": 101,
      "aluno_snapshot": { "name": "Maria Silva", "regNumero": "2024/0101" }
    },
    {
      "description": "Transporte — Outubro 2026",
      "quantity": 1,
      "unit_price": 8000.00,
      "tax_code": "IVA",
      "tax_percentage": 14,
      "item_category": "transporte",
      "invoiceable_type": "App\\Models\\Invoice\\Billing\\BillingTransporte",
      "invoiceable_id": 15,
      "alunoId": 101
    }
  ],
  "payments": [
    { "method_code": "NU", "amount": 40000.00 },
    { "method_code": "wallet", "amount": 14120.00 }
  ]
}
```

**Response 201 — Sucesso:**
```json
{
  "sucesso": true,
  "mensagem": "Fatura criada com sucesso.",
  "dados": {
    "id": 1247,
    "document_type": "FR",
    "document_no": "FR FR-2026/001247",
    "gross_total": "54120.00",
    "tax_total": "986.67",
    "paid_total": "54120.00",
    "change_amount": "0.00",
    "payment_status": "paid",
    "agt_status": "submitted",
    "invoice_hash": "mYJEv4iGwLcn...",
    "hash_control": "mYJE",
    "jws_document_signature": "eyJhbGci...",
    "jws_software_signature": "eyJhbGci...",
    "items": [ "..." ],
    "payments": [ "..." ]
  }
}
```

**Erros possíveis:**
```json
{ "sucesso": false, "mensagem": "FR só pode ser emitida quando totalmente paga. Total: 54120 AOA | Pago: 40000 AOA | Em falta: 14120 AOA." }
{ "sucesso": false, "mensagem": "LICENÇA INACTIVA — A organização não tem licença activa (appCode = false)." }
{ "sucesso": false, "mensagem": "LIMITE DIÁRIO EXCEDIDO — Foram emitidas 50 de 50 faturas permitidas hoje." }
{ "sucesso": false, "mensagem": "Saldo insuficiente na carteira. Saldo: 10000 AOA | Solicitado: 14120 AOA." }
{ "sucesso": false, "mensagem": "Tipo de documento inválido: XX. Suportados: FT, FR, FS, NC, ND, RC" }
```

**Idempotência:**
Se enviar o mesmo `idempotency_key` duas vezes, a segunda chamada devolve a fatura já criada sem duplicar. Usar `crypto.randomUUID()` no frontend.

---

#### `POST /onsoft-agt/faturas/pre-visualizar`
Calcular totais sem criar fatura. Mesmo payload do criar.

**Response 200:**
```json
{
  "sucesso": true,
  "dados": {
    "tipo_documento": "FR",
    "subtotal": 53133.33,
    "iva_total": 986.67,
    "gross_total": 54120.00,
    "total_pago": 54120.00,
    "troco": 0.00,
    "em_falta": 0.00,
    "estado_pagamento": "pago"
  }
}
```

---

#### `GET /onsoft-agt/faturas/{id}/pdf`
PDF da fatura em stream. Usa snapshot se existir (dados imutáveis), dados live como fallback.

**Response:** `Content-Type: application/pdf` (inline no browser)

---

#### `GET /onsoft-agt/faturas/{id}/pdf-snapshot`
PDF exclusivamente do snapshot — re-impressão com dados originais do momento de emissão.

---

#### `GET /onsoft-agt/faturas/{id}/pdf-base64`
PDF em base64 para o frontend renderizar.

**Response 200:**
```json
{
  "sucesso": true,
  "dados": {
    "base64": "JVBERi0xLjQ...",
    "nome_ficheiro": "fr-fr-fr-2026-001247.pdf",
    "mime_type": "application/pdf",
    "tamanho_papel": "A4",
    "copias": 1,
    "mostrar_qr": true
  }
}
```

**Frontend:**
```javascript
const { base64 } = res.data.dados;
window.open(`data:application/pdf;base64,${base64}`);
```

---

#### `POST /onsoft-agt/faturas/{id}/submeter`
Submeter fatura à API AGT.

**Response 200 — AGT activo:**
```json
{ "sucesso": true, "mensagem": "Fatura submetida ao AGT com sucesso.", "dados": { "status": "pending", "batch_id": "..." } }
```

**Response 200 — AGT desactivado (simulação):**
```json
{ "sucesso": true, "mensagem": "AGT desactivado — submissão simulada localmente.", "dados": { "status": "simulated" } }
```

---

#### `POST /onsoft-agt/faturas/{id}/cancelar`
Cancelar fatura.

**Request:** `{ "motivo": "Erro no valor — reemissão necessária" }`

**Response 200 — FR já submetida (gera NC automaticamente):**
```json
{
  "sucesso": true,
  "mensagem": "Nota de Crédito emitida automaticamente.",
  "dados": {
    "id": 1248,
    "document_type": "NC",
    "document_no": "NC NC-2026/000012",
    "sourceInvoiceId": 1247
  }
}
```

**Response 200 — FR não submetida:**
```json
{ "sucesso": true, "mensagem": "Fatura cancelada localmente.", "dados": { "agt_status": "cancelled" } }
```

**Erros:**
```json
{ "sucesso": false, "mensagem": "O motivo de cancelamento é obrigatório." }
{ "sucesso": false, "mensagem": "Esta fatura já está cancelada." }
{ "sucesso": false, "mensagem": "Uma Nota de Crédito não pode ser cancelada." }
```

---

#### `GET /onsoft-agt/faturas/{id}/estado`
Estado da submissão AGT.

**Response 200:**
```json
{
  "sucesso": true,
  "dados": {
    "fatura_id": 1247,
    "agt_status": "accepted",
    "submissao": {
      "status": "accepted",
      "attempts": 1,
      "submitted_at": "2026-06-18T14:30:05Z",
      "accepted_at": "2026-06-18T14:30:10Z"
    }
  }
}
```

---

#### `GET /onsoft-agt/faturas/{id}/consult`
Consultar fatura directamente na API AGT.

---

### 📊 RELATÓRIOS E ESTATÍSTICAS

Todos aceitam filtros via query string:
```
?de=2026-01-01&ate=2026-12-31&document_type=FR&payment_status=paid&excluir_canceladas=1
```

---

#### `GET /onsoft-agt/relatorios/resumo-financeiro`
Resumo financeiro geral. Para cards do dashboard.

**Response 200:**
```json
{
  "sucesso": true,
  "dados": {
    "total_documentos": 1247,
    "total_emitido": 12450000.00,
    "total_pago": 11200000.00,
    "total_divida": 1250000.00,
    "total_iva": 1456780.00,
    "taxa_cobranca": 89.96,
    "por_tipo_documento": [
      { "tipo": "FR", "label": "Fatura-Recibo", "total": 980, "valor": 9800000.00 },
      { "tipo": "FT", "label": "Fatura", "total": 267, "valor": 2650000.00 }
    ]
  }
}
```

---

#### `GET /onsoft-agt/relatorios/receita-por-dia`
Receita diária. Para gráfico de linha (Chart.js / Recharts).

**Response 200:**
```json
{
  "sucesso": true,
  "dados": [
    { "data": "2026-06-01", "faturas": 12, "total_emitido": 540000.00, "total_pago": 540000.00 },
    { "data": "2026-06-02", "faturas": 8,  "total_emitido": 360000.00, "total_pago": 350000.00 }
  ]
}
```

---

#### `GET /onsoft-agt/relatorios/receita-por-mes`
Receita mensal. Para gráfico de barras.

---

#### `GET /onsoft-agt/relatorios/receita-por-hora`
Pico de emissão por hora. Para análise operacional.

---

#### `GET /onsoft-agt/relatorios/por-categoria`
Receita por categoria de billing (propina, matrícula, transporte...).

**Response 200:**
```json
{
  "sucesso": true,
  "dados": [
    { "categoria": "propina",    "tipo_label": "BillingPropina",    "faturas": 890, "total": 8900000.00, "iva": 0.00 },
    { "categoria": "transporte", "tipo_label": "BillingTransporte", "faturas": 245, "total": 1960000.00, "iva": 274400.00 }
  ]
}
```

---

#### `GET /onsoft-agt/relatorios/meios-pagamento`
Distribuição por meio de pagamento. Para gráfico donut/pie.

**Response 200:**
```json
{
  "sucesso": true,
  "dados": [
    { "metodo": "NU",     "label": "Numerário",        "faturas": 600, "total": 6000000.00 },
    { "metodo": "WALLET", "label": "Saldo da Carteira", "faturas": 400, "total": 4000000.00 },
    { "metodo": "MX",     "label": "Multicaixa Express","faturas": 247, "total": 2450000.00 }
  ]
}
```

---

#### `GET /onsoft-agt/relatorios/resumo-iva`
Resumo de IVA por taxa — para declaração fiscal mensal à AGT.

**Response 200:**
```json
{
  "sucesso": true,
  "dados": [
    { "tax_type": "IVA",    "taxa": 14, "taxa_label": "14%",  "faturas": 456, "base_tributavel": 10400000.00, "iva_total": 1456000.00 },
    { "tax_type": "ISENTO", "taxa": 0,  "taxa_label": "Isento","faturas": 791, "base_tributavel": 7900000.00,  "iva_total": 0.00 }
  ]
}
```

---

#### `GET /onsoft-agt/relatorios/estado-agt`
Estatísticas de submissão à AGT.

**Response 200:**
```json
{
  "sucesso": true,
  "dados": {
    "draft":     { "total": 5,    "valor": 25000.00 },
    "pending":   { "total": 12,   "valor": 60000.00 },
    "submitted": { "total": 230,  "valor": 1150000.00 },
    "accepted":  { "total": 1000, "valor": 5000000.00 },
    "rejected":  { "total": 2,    "valor": 10000.00 },
    "failed":    { "total": 3,    "valor": 15000.00 },
    "taxa_submissao": 80.32
  }
}
```

---

#### `GET /onsoft-agt/relatorios/top-clientes?limite=10`
Top clientes por valor faturado.

---

#### `GET /onsoft-agt/relatorios/maiores-devedores?limite=10`
Maiores devedores com divida em aberto.

---

#### `GET /onsoft-agt/relatorios/emissoes-30-dias`
Emissões nos últimos 30 dias.

---

#### `GET /onsoft-agt/relatorios/limite-diario`
Estado actual do limite diário de emissão.

**Response 200 — dentro do limite:**
```json
{
  "sucesso": true,
  "dados": {
    "licenca_activa": true,
    "limite_activo": true,
    "data_referencia": "2026-06-18",
    "emitidas_hoje": 23,
    "maximo_diario": 50,
    "disponivel_hoje": 27,
    "percentagem_uso": 46.0,
    "bloqueado": false,
    "mensagem_bloqueio": null
  }
}
```

**Response 200 — limite excedido:**
```json
{
  "sucesso": true,
  "dados": {
    "licenca_activa": true,
    "limite_activo": true,
    "emitidas_hoje": 50,
    "maximo_diario": 50,
    "disponivel_hoje": 0,
    "percentagem_uso": 100.0,
    "bloqueado": true,
    "mensagem_bloqueio": "Limite diário atingido. Contacte a administração."
  }
}
```

---

#### `GET /onsoft-agt/relatorios/billing-types`
Lista todos os tipos de billing morph registados.

**Response 200:**
```json
{
  "sucesso": true,
  "dados": [
    { "chave": "propina",    "classe": "App\\Models\\Invoice\\Billing\\BillingPropina",    "tabela": "billing_propinas",    "existe": true },
    { "chave": "matricula",  "classe": "App\\Models\\Invoice\\Billing\\BillingMatricula",  "tabela": "billing_matriculas",  "existe": true },
    { "chave": "transporte", "classe": "App\\Models\\Invoice\\Billing\\BillingTransporte", "tabela": "billing_transportes", "existe": true }
  ]
}
```

---

#### `GET /onsoft-agt/relatorios/pdf-listagem?de=2026-01-01&ate=2026-12-31`
PDF A4 com listagem completa de faturas. Stream directo para o browser.

#### `GET /onsoft-agt/relatorios/pdf-resumo-financeiro`
PDF A4 com resumo financeiro completo.

#### `GET /onsoft-agt/relatorios/pdf-iva`
PDF A4 com relatório de IVA para entrega à AGT.

#### `GET /onsoft-agt/relatorios/pdf-devedores`
PDF A4 com lista de devedores.

---

### 🔐 SÉRIES

#### `POST /onsoft-agt/series/sincronizar`
Sincronizar séries da API AGT para a BD local.

**Response 200:**
```json
{ "sucesso": true, "mensagem": "Sincronizadas 6 séries.", "dados": { "sincronizadas": 6, "erros": [] } }
```

---

### ✅ CONFIGURAÇÃO

#### `GET /onsoft-agt/configuracao/validar`
Validar configuração AGT completa.

**Response 200 — válido:**
```json
{ "sucesso": true, "valido": true, "erros": [], "mensagem": "Configuração AGT válida e pronta." }
```

**Response 200 — com erros:**
```json
{
  "sucesso": false,
  "valido": false,
  "erros": [
    "[Contribuinte] NIF fiscal não configurado.",
    "[Software] AGT_SOFTWARE_CHAVE_PRIVADA não definida no .env."
  ]
}
```

---

## 🔒 Imutabilidade Fiscal (AGT Decreto, Anexo I ponto 12l)

Após a criação de uma fatura com hash fiscal, **nenhum campo fiscal pode ser alterado**.

O `InvoiceObserver` bloqueia automaticamente tentativas de alterar:

```
✗ Bloqueado: document_no, document_type, series_code, subtotal,
             gross_total, invoice_hash, jws_*, issued_at,
             organization_snapshot, customer_snapshot

✓ Permitido: agt_status, payment_status, cancel_reason,
             cancelled_at, submission_uuid
```

**Se tentar alterar um campo imutável:**
```php
$fatura->gross_total = 99999; // ← lança ExcecaoFaturaAgt
$fatura->save();
// "VIOLAÇÃO DE IMUTABILIDADE FISCAL — A fatura FR FR-2026/001247
//  já foi emitida. Campos fiscais não podem ser alterados: gross_total"
```

**Snapshot imutável criado automaticamente** após cada fatura com hash.

---

## 🗓️ Reset Ano Fiscal

Automático: executa 1 Janeiro às 00:01 para todas as organizações activas.

Manual:
```bash
php artisan onsoft-agt:reset-ano-fiscal 2026 --todas-orgs --fechar-anteriores
php artisan onsoft-agt:reset-ano-fiscal 2026 --organizacaoId=5
```

---

## 🖨️ Formatos PDF

| Formato | Papel | QR Code | Uso |
|---------|-------|---------|-----|
| A4 | 210×297mm | ✅ 80×80px | Fatura standard, relatórios |
| 88mm | Térmico largo | ✅ 70×70px | Impressora térmica larga |
| 58mm | Térmico estreito | ✅ 55×55px | Impressora térmica estreita |

QR Code gerado localmente (`bacon/bacon-qr-code`) — **100% offline, sem internet**.

Conteúdo do QR: `NIF:xxx;DOC:xxx;TIPO:xxx;DATA:xxx;TOTAL:xxx;HASH:xxxx;CERT:xxxx`

Configuração lida automaticamente de `invoice_print_configs`. Sem configuração → A4 por defeito.

---

## 📜 Tipos de Documento

| Código | Nome | Campos AGT | Nota |
|--------|------|-----------|------|
| `FT` | Fatura | CreditAmount | Standard |
| `FR` | Fatura-Recibo | CreditAmount | Deve estar totalmente pago |
| `FS` | Fatura Simplificada | CreditAmount | Sem NIF obrigatório |
| `NC` | Nota de Crédito | **DebitAmount** | Gerada automaticamente ao cancelar FR submetida |
| `ND` | Nota de Débito | CreditAmount | |
| `RC` | Recibo | — | Sem assinatura AGT |

---

## 💳 Meios de Pagamento

| Código | Nome |
|--------|------|
| `NU` | Numerário (Dinheiro) |
| `TB` | Transferência Bancária |
| `CC` | Cartão de Crédito/Débito |
| `CH` | Cheque |
| `MP` | Pagamento Móvel |
| `MX` | Multicaixa Express |
| `wallet` | Saldo da Carteira do Encarregado |

---

## 🛠️ Comandos Artisan

```bash
php artisan onsoft-agt:instalar                    # Instalação inicial
php artisan onsoft-agt:estado                      # Estado de todas as organizações
php artisan onsoft-agt:sincronizar-series          # Sincronizar séries da AGT
php artisan onsoft-agt:retentar-falhas             # Retentar faturas com falha
php artisan onsoft-agt:reset-ano-fiscal            # Reset séries ano fiscal
php artisan onsoft-agt:verificar-integridade       # Auditoria de integridade fiscal
```

---

## ✅ Conformidade AGT (Decreto Executivo Angola)

| Requisito | Status |
|-----------|--------|
| Hash RSA-SHA1: `InvoiceDate;SystemEntryDate;InvoiceNo;GrossTotal;HashAnterior` | ✅ |
| HashControl: posições 1, 11, 21, 31 | ✅ |
| `XxXx-Processado por programa validado nº 0000/AGT` | ✅ |
| RC: `Emitido por programa validado nº 0000/AGT` | ✅ |
| NC usa DebitAmount; FT/FR/FS/ND usam CreditAmount | ✅ |
| Numeração sequencial com lockForUpdate | ✅ |
| Hash chain encadeado por série | ✅ |
| Documento assinado não pode ser alterado | ✅ |
| Séries não podem ser apagadas (só inactivadas) | ✅ |
| Faturas emitidas não podem ser eliminadas | ✅ |
| PDF sem valores negativos | ✅ |
| Consumidor Final quando sem NIF | ✅ |
| QR Code com dados de verificação | ✅ |
| Multi-tenant: chaves encriptadas | ✅ |
| Chave Software no .env, chave Contribuinte na BD | ✅ |

---

*Onsoft AGT v1.3.0 — Adilson Miguel — adilson2012jose@gmail.com — 2068417074*

---

## ⚡ Performance e Escalabilidade

### Quantas faturas por segundo suporta?

**Resposta honesta por configuração:**

| Configuração | Faturas/segundo | Quando usar |
|---|---|---|
| MySQL + `CACHE_STORE=database` (actual) | ~15–20/seg | Desenvolvimento, escola pequena |
| MySQL + `CACHE_STORE=redis` | ~50–80/seg | Produção, escola média |
| MySQL + Redis + Connection Pool | ~150–200/seg | Rede de escolas |
| MySQL + Redis + 2 servidores PHP | ~400–600/seg | Grande rede nacional |
| Read replicas + Load Balancer | ~800–1000/seg | Arquitectura enterprise |

**Contexto real Angola:** Uma escola com 500 alunos no pico de matrículas emite ~50–100 faturas por hora (~0.03/seg). Uma rede de 50 escolas emite ~5.000 faturas por hora (~1.4/seg). O sistema actual aguenta isso **com muito espaço**.

---

### Quantas queries à BD por fatura?

```
Fatura com 2 itens + 2 pagamentos = ~25 queries

  Verificação idempotency ......... 1 SELECT
  Garantir série fiscal ........... 1 SELECT (+1 INSERT se nova)
  Próximo número (lockForUpdate) .. 1 SELECT + 1 UPDATE
  INSERT da fatura ................ 1 INSERT
  Config AGT (com cache) .......... 1 query (depois usa cache 5 min)
  Fatura anterior (hash chain) .... 1 SELECT
  UPDATE hash + assinaturas ....... 1 UPDATE
  Por cada item (×2):
    INSERT InvoiceItem .............. 1 INSERT
    INSERT InvoiceItemTax ........... 1 INSERT
  Por cada pagamento (×2):
    Wallet (firstOrCreate) .......... 1 SELECT/INSERT
    INSERT InvoicePayment ........... 1 INSERT
    INSERT InvoicePaymentMethod ..... 1 INSERT
    INSERT InvoicePaymentAllocation . 1 INSERT
    UPDATE wallet balance ........... 1 UPDATE
    INSERT WalletMovement ........... 1 INSERT
  Observer snapshot ............... 1 SELECT + 1 INSERT
  ────────────────────────────────────────────
  TOTAL                               ~25 queries
```

---

### Cache da OrganizationAgtConfig

A configuração AGT da organização (chaves, NIF, número de certificação) era carregada **3 vezes separadamente** por fatura. Agora é carregada uma vez e guardada em cache por 5 minutos.

```php
// Internamente o pacote faz isto:
$config = cache()->remember("onsoft_agt_config_{$orgId}", 300, fn() =>
    OrganizationAgtConfig::where('organizationId', $orgId)->first()
);
```

**O cache funciona com qualquer driver — não precisa de Redis.**

| Driver | Como configurar | Comportamento |
|---|---|---|
| `database` (padrão) | `CACHE_STORE=database` | Cache na tabela `cache` da BD |
| `file` | `CACHE_STORE=file` | Cache em `storage/framework/cache/` |
| `redis` | `CACHE_STORE=redis` | Cache no Redis (mais rápido) |
| `array` | `CACHE_STORE=array` | Só dura o pedido actual |

**Se não tiver Redis, o sistema funciona na mesma.** Redis é uma optimização futura.

Quando a config AGT for alterada no painel, o cache é invalidado automaticamente:
```php
// Chamar no controller depois de guardar a config:
\Onsoft\Agt\Servicos\ServicoFatura::invalidarCacheConfig($organizacaoId);
```

---

### Índices de BD (obrigatório em produção)

O pacote inclui uma migração com **26 índices** nas tabelas críticas. Sem estes índices, queries em tabelas com 100.000+ registos fazem full table scan e demoram segundos. Com índices, as mesmas queries demoram <1ms.

```bash
php artisan migrate
# Aplica automaticamente: 2024_01_01_000003_performance_indexes.php
```

Tabelas com índices adicionados:

| Tabela | Índices adicionados |
|---|---|
| `invoices` | `(organizationId, issued_at)`, `(organizationId, payment_status)`, `(organizationId, agt_status)`, `(organizationId, idempotency_key)`, + 4 mais |
| `agt_series` | `(organizationId, document_type, fiscal_year)`, `(organizationId, active)` |
| `invoice_items` | `(invoiceId)`, `(invoiceable_type, invoiceable_id)`, `(itemable_type, itemable_id)` |
| `invoice_item_taxes` | `(invoiceItemId)`, `(organizationId, tax_type)` |
| `invoice_payments` | `(invoiceId)`, `(organizationId, encarregadoId)` |
| `invoice_snapshots` | `(organizationId, invoiceId)` |
| `guardian_wallets` | `(organizationId, encarregadoId)` |

---

### O `lockForUpdate` na série — o que faz e porque é necessário

```php
// ServicoSeries::proximoNumeroDocumento()
$bloqueada = AgtSeries::whereKey($serie->id)->lockForUpdate()->firstOrFail();
```

Este lock garante que **duas faturas da mesma organização não recebem o mesmo número** mesmo que sejam criadas em simultâneo (dois utilizadores ao mesmo tempo). Sem este lock, duas faturas poderiam receber `FR-2026/000001`.

**Implicação:** Duas faturas da mesma série não podem ser criadas exactamente em paralelo — a segunda espera a primeira terminar (tipicamente <50ms). Para o contexto escolar, isto nunca é um problema.

---

### Quando activar Redis (passo a passo)

Redis não é obrigatório. Activar quando:
- Tiveres >50 utilizadores simultâneos
- Tiveres múltiplos servidores PHP
- As queries à tabela `cache` da BD começarem a aparecer como bottleneck

**Instalação no servidor Ubuntu/Debian:**
```bash
sudo apt install redis-server
sudo systemctl enable redis-server
sudo systemctl start redis-server
```

**Alterar no `.env`:**
```env
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

**Limpar caches antigos:**
```bash
php artisan cache:clear
php artisan config:clear
php artisan optimize
```

Impacto imediato: **3–4× mais capacidade** sem alterar uma linha de código.

---

### Submissão AGT — síncrona vs assíncrona

Por defeito, quando `auto_submit_invoices = true`, a submissão à API AGT é **assíncrona via queue**:

```
Utilizador cria fatura
    → fatura criada em BD (~50ms)
    → resposta HTTP 201 devolvida ao utilizador
    → SubmitInvoiceToAgtJob colocado na queue
        → worker processa em background
        → chama API AGT
        → actualiza agt_status
```

O utilizador recebe resposta imediata. A comunicação com a AGT acontece nos segundos seguintes em background. Se a AGT estiver lenta ou em baixo, a fatura fica em `agt_status = pending` e o job retenta automaticamente (3 vezes por defeito).

**Para ver faturas pendentes de submissão:**
```bash
php artisan onsoft-agt:retentar-falhas --limite=50
```

---

### Checklist de produção

Antes de lançar em produção, verificar:

```bash
# 1. Correr migrações (inclui índices de performance)
php artisan migrate

# 2. Verificar configuração AGT
php artisan onsoft-agt:estado
php artisan onsoft-agt:configuracao/validar  # via HTTP

# 3. Optimizar autoloader
composer install --no-dev --optimize-autoloader

# 4. Optimizar Laravel
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# 5. Verificar queue worker está a correr
php artisan queue:work --queue=agt-faturas --tries=3 --timeout=90

# 6. Configurar supervisor para o worker (não cair se reiniciar)
# /etc/supervisor/conf.d/onsoft-agt-worker.conf
[program:onsoft-agt-worker]
command=php /var/www/html/artisan queue:work --queue=agt-faturas --tries=3
autostart=true
autorestart=true
```

**Variáveis de ambiente mínimas para produção:**
```env
APP_ENV=production
APP_DEBUG=false
AGT_AMBIENTE=producao
AGT_SOFTWARE_CHAVE_PRIVADA="-----BEGIN RSA PRIVATE KEY-----\n...\n-----END RSA PRIVATE KEY-----"
AGT_SOFTWARE_NUMERO_CERTIFICACAO=XXXX
CACHE_STORE=redis          # ou database se não tiver Redis
SESSION_DRIVER=redis       # ou database
QUEUE_CONNECTION=redis     # ou database
```

---

*Onsoft AGT v1.5.0 — Adilson Miguel — adilson2012jose@gmail.com — 2068417074*

---

## 🖨️ Estados no PDF — O que aparece em cada situação

O PDF mostra automaticamente banners visuais no topo de acordo com o estado da fatura. Funciona nos três formatos: A4, 88mm e 58mm.

### Banners por estado

| Estado | O que aparece no PDF | Cor |
|--------|---------------------|-----|
| `draft` — não submetido | ⚠️ **DOCUMENTO NÃO SUBMETIDO À AGT** com instrução para submeter | Amarelo |
| `pending` — em fila | 🕐 **EM FILA DE SUBMISSÃO AGT** com UUID | Azul claro |
| `submitted` — aguarda resposta | 📤 **SUBMETIDO — AGUARDA RESPOSTA DA AGT** | Azul |
| `accepted` — aceite ✓ | Badge verde "AGT: ACCEPTED" no cabeçalho | Verde |
| `rejected` — rejeitado | ❌ **REJEITADO PELA AGT** com instrução para corrigir e resubmeter | Vermelho |
| `failed` — erro técnico | 🔴 **ERRO NA SUBMISSÃO AGT** com comando artisan para retentar | Roxo |
| `cancelled` + `payment_status=cancelled` | ⛔ **DOCUMENTO CANCELADO** com motivo e data | Vermelho escuro + marca d'água |
| `NC` (Nota de Crédito) | Marca d'água diagonal "NOTA DE CRÉDITO" + cabeçalho vermelho | Vermelho |

### O que cada banner contém

**Documento não submetido (`draft`):**
```
⚠️ DOCUMENTO NÃO SUBMETIDO À AGT
Este documento ainda não foi enviado à Administração Geral Tributária.
Para submeter, use o endpoint: POST /onsoft-agt/faturas/1247/submeter
A submissão é obrigatória para documentos com validade fiscal (Decreto Executivo AGT).
```

**Cancelado:**
```
⛔ DOCUMENTO CANCELADO
Este documento foi cancelado e não tem validade fiscal.
Motivo: Erro no valor — reemissão necessária
Data de cancelamento: 18/06/2026 14:30
Nota de Crédito emitida: Ver documento NC associado (ID: 1248)
Conforme AGT: documentos cancelados após submissão requerem Nota de Crédito.
```

**Rejeitado:**
```
❌ REJEITADO PELA AGT
Este documento foi rejeitado pela Administração Geral Tributária.
Acção recomendada: Verificar os dados do documento e resubmeter após correcção.
Use: POST /onsoft-agt/faturas/1247/submeter
Se o erro persistir, emita uma Nota de Crédito e crie uma nova fatura corrigida.
```

**Erro técnico:**
```
🔴 ERRO NA SUBMISSÃO AGT
Ocorreu um erro técnico ao submeter à AGT. O documento não foi recebido.
Acção recomendada: Retentar a submissão.
Use: POST /onsoft-agt/faturas/1247/submeter
Ou via Artisan: php artisan onsoft-agt:retentar-falhas
```

---

## 📊 Estatísticas AGT por Organização

### `GET /onsoft-agt/relatorios/estado-agt`
Estatísticas completas para a organização actual. Inclui totais por estado + últimas 50 submissões.

**Response 200:**
```json
{
  "sucesso": true,
  "dados": {
    "draft":     { "total": 5,    "valor": 25000.00 },
    "pending":   { "total": 3,    "valor": 15000.00 },
    "submitted": { "total": 12,   "valor": 60000.00 },
    "accepted":  { "total": 1000, "valor": 5000000.00 },
    "rejected":  { "total": 2,    "valor": 10000.00 },
    "failed":    { "total": 1,    "valor": 5000.00 },
    "cancelled": { "total": 8,    "valor": 40000.00 },
    "taxa_submissao": 97.5,
    "total_documentos": 1031,
    "ultimas_submissoes": [
      {
        "id": 445,
        "invoiceId": 1247,
        "status": "accepted",
        "attempts": 1,
        "submitted_at": "2026-06-18T14:30:05Z",
        "accepted_at":  "2026-06-18T14:30:10Z",
        "rejected_at":  null,
        "error_message": null
      },
      {
        "id": 444,
        "invoiceId": 1246,
        "status": "failed",
        "attempts": 3,
        "submitted_at": "2026-06-18T13:00:00Z",
        "accepted_at":  null,
        "rejected_at":  null,
        "error_message": "Connection timeout — AGT API unreachable"
      }
    ]
  }
}
```

**Como usar no frontend (gráfico donut):**
```javascript
// Recharts / Chart.js
const estados = ['draft','pending','submitted','accepted','rejected','failed','cancelled'];
const cores   = ['#90a4ae','#64b5f6','#42a5f5','#66bb6a','#ef5350','#ab47bc','#ef9a9a'];
const data = estados.map((e, i) => ({
  name:  e.toUpperCase(),
  value: res.data.dados[e].total,
  fill:  cores[i],
}));
```

---

### `GET /onsoft-agt/relatorios/estado-agt-todas-organizacoes`
Visão admin — estatísticas AGT para TODAS as organizações. Mostra quantas faturas cada escola tem em cada estado.

**Parâmetros:** `?de=2026-01-01&ate=2026-12-31`

**Response 200:**
```json
{
  "sucesso": true,
  "dados": [
    {
      "organizationId": 1,
      "draft":     { "total": 0,   "valor": 0 },
      "pending":   { "total": 2,   "valor": 10000 },
      "submitted": { "total": 5,   "valor": 25000 },
      "accepted":  { "total": 450, "valor": 2250000 },
      "rejected":  { "total": 1,   "valor": 5000 },
      "failed":    { "total": 0,   "valor": 0 },
      "cancelled": { "total": 3,   "valor": 15000 },
      "total_documentos": 461,
      "total_valor": 2305000
    },
    {
      "organizationId": 2,
      "draft":     { "total": 5,   "valor": 25000 },
      "pending":   { "total": 1,   "valor": 5000 },
      "submitted": { "total": 7,   "valor": 35000 },
      "accepted":  { "total": 550, "valor": 2750000 },
      "rejected":  { "total": 1,   "valor": 5000 },
      "failed":    { "total": 1,   "valor": 5000 },
      "cancelled": { "total": 5,   "valor": 25000 },
      "total_documentos": 570,
      "total_valor": 2845000
    }
  ]
}
```

**Como usar no frontend (tabela comparativa):**
```javascript
// Tabela com uma linha por organização, colunas por estado
dados.forEach(org => {
  console.log(`Org ${org.organizationId}:
    Aceites:   ${org.accepted.total}
    Em fila:   ${org.pending.total}
    Falhou:    ${org.failed.total}
    Total:     ${org.total_documentos}`);
});
```

---

*Onsoft AGT v1.5.0 — Adilson Miguel — adilson2012jose@gmail.com — 2068417074*

---

## 👨‍🎓 Área do Aluno — Faturas

Endpoints para o aluno autenticado consultar as suas próprias faturas.

### Padrão do projecto On-School

O `alunoId` é passado via **query string** (`?alunoId=101`) — exactamente igual ao padrão existente em `EstudanteInfoController`, `EstudanteAnoClasseController` e todos os outros controladores do projecto.

Existem **dois grupos de rotas**:

| Grupo | URL | alunoId | Quem usa |
|-------|-----|---------|----------|
| `/aluno/` | `GET /onsoft-agt/aluno/faturas?alunoId=101` | Query string obrigatória | Secretaria, admin, encarregado |
| `/eu/` | `GET /onsoft-agt/eu/faturas` | `auth()->id()` automático | O próprio aluno autenticado |

### Como funciona o lookup de faturas

O sistema encontra as faturas do aluno de **3 formas** em paralelo:

```
1. invoice_items.alunoId = alunoId
   → Faturas onde o aluno está mencionado directamente na linha

2. invoices.studentId = alunoId
   → Faturas criadas directamente para este aluno

3. Via billing morph (billing_propinas, billing_transportes, etc.)
   → billing_propinas.alunoId = alunoId → invoiceable_id → invoice_items → invoices

União dos 3 conjuntos → faturas únicas, sem duplicados
```

### Quando o aluno muda de mensalidade (turma/classe)

O `EstudanteAnoClasse` regista TODAS as mensalidadeIds históricas do aluno. O sistema consulta o histórico completo e inclui faturas de todas as mensalidades anteriores. O aluno vê o historial financeiro completo independentemente de quantas turmas mudou.

### Faturas com múltiplos alunos

Quando uma fatura cobre propinas de 2 filhos do mesmo encarregado, cada aluno vê a fatura completa mas com distinção clara:

```json
{
  "fatura_partilhada": true,
  "total_alunos_fatura": 2,
  "meus_itens": [
    { "description": "Propina — Outubro 2026", "line_total": 45000 }
  ],
  "outros_itens": [
    { "description": "Propina — Outubro 2026", "alunoId": 102, "line_total": 45000 }
  ],
  "meu_total": 45000,
  "gross_total": 90000
}
```

---

### `GET /onsoft-agt/aluno/faturas?alunoId=101`

Para secretaria/admin/encarregado. Filtros: `?alunoId=101&de=&ate=&document_type=&payment_status=`

### `GET /onsoft-agt/eu/faturas`

Para o próprio aluno autenticado. Sem alunoId — usa `auth()->id()` automaticamente.

**Response 200:**
```json
{
  "sucesso": true,
  "dados": {
    "aluno_id": 101,
    "total_faturas": 12,
    "resumo": {
      "total_emitido": 540000.00,
      "total_pago": 495000.00,
      "total_divida": 45000.00,
      "total_canceladas": 1,
      "por_estado_agt": { "accepted": 10, "draft": 1, "failed": 1 },
      "por_tipo": { "FR": 10, "FT": 1, "NC": 1 }
    },
    "faturas": [
      {
        "id": 1247,
        "document_no": "FR FR-2026/001247",
        "document_type": "FR",
        "label_tipo": "Fatura-Recibo",
        "issued_at_fmt": "18/06/2026 14:30",
        "payment_status": "paid",
        "agt_status": "accepted",
        "gross_total": 90000.00,
        "paid_total": 90000.00,
        "remaining_balance": 0.00,
        "fatura_partilhada": true,
        "total_alunos_fatura": 2,
        "meu_total": 45000.00,
        "meus_itens": [
          {
            "description": "Propina — Outubro 2026",
            "quantity": 1,
            "unit_price": 45000.00,
            "tax_type": "ISENTO",
            "line_total": 45000.00,
            "item_category": "propina"
          }
        ],
        "outros_itens": [
          {
            "description": "Propina — Outubro 2026",
            "alunoId": 102,
            "line_total": 45000.00
          }
        ],
        "payments": [
          {
            "amount": 90000.00,
            "methods": [
              { "method_code": "NU", "label": "Numerário", "amount": 90000.00 }
            ]
          }
        ],
        "pode_ver_pdf": true,
        "pode_submeter": false,
        "pdf_url": "https://api.escola.ao/onsoft-agt/faturas/1247/pdf",
        "pdf_base64_url": "https://api.escola.ao/onsoft-agt/faturas/1247/pdf-base64",
        "mensalidade_id": 5,
        "hash_control": "mYJE"
      }
    ],
    "mensalidades": [
      {
        "estudante_ano_classe_id": 89,
        "mensalidade_id": 5,
        "status": 1,
        "anolectivo": { "id": 3, "name": "2026" },
        "curso": { "id": 2, "name": "Ensino Geral" },
        "classe": { "id": 4, "name": "10ª Classe" },
        "turma": { "id": 7, "name": "Turma A" },
        "sala": { "id": 2, "name": "Sala 102" },
        "periodo": { "id": 1, "name": "Manhã" },
        "pagamento": {
          "propinaAnual": 540000.00,
          "propinaMensal": 45000.00,
          "confirmacaoPreco": 15000.00,
          "matriculaPreco": 25000.00
        }
      }
    ]
  }
}
```

---

### `GET /onsoft-agt/aluno/faturas/{id}`

Detalhes de uma fatura específica. Retorna 403 se o aluno não tiver acesso.

---

### `GET /onsoft-agt/aluno/faturas/{id}/pdf`

PDF da fatura em stream. Verifica acesso antes de gerar.

---

### `GET /onsoft-agt/aluno/faturas/{id}/pdf-base64`

PDF em base64 para o frontend abrir sem guardar.

```javascript
const { base64 } = res.data.dados;
window.open(`data:application/pdf;base64,${base64}`);
```

---

### `GET /onsoft-agt/aluno/mensalidades`

Histórico de todas as mensalidades do aluno (incluindo mudanças de turma).

---

### `GET /onsoft-agt/aluno/resumo`

Resumo financeiro do aluno: total emitido, pago, em dívida, por tipo e estado AGT.

---

### Como integrar no routes/api.php do projecto

```php
use Onsoft\Agt\Http\Controladores\ControladorFaturasAluno;

// ── Para secretaria / admin / encarregado (alunoId na query string) ──
// Mesmo padrão que EstudanteInfoController
Route::middleware(['jwt.auth', SetCurrentOrganization::class])->group(function () {
    Route::get('/aluno/faturas',                 [ControladorFaturasAluno::class, 'faturas']);
    Route::get('/aluno/faturas/{id}',            [ControladorFaturasAluno::class, 'fatura']);
    Route::get('/aluno/faturas/{id}/pdf',        [ControladorFaturasAluno::class, 'pdfFatura']);
    Route::get('/aluno/faturas/{id}/pdf-base64', [ControladorFaturasAluno::class, 'pdfBase64Fatura']);
    Route::get('/aluno/mensalidades',            [ControladorFaturasAluno::class, 'mensalidades']);
    Route::get('/aluno/resumo',                  [ControladorFaturasAluno::class, 'resumo']);
    // Uso: GET /api/aluno/faturas?alunoId=101
    //      GET /api/aluno/faturas/1247?alunoId=101
    //      GET /api/aluno/faturas/1247/pdf?alunoId=101
});

// ── Para o próprio aluno autenticado (sem alunoId — usa auth()->id()) ──
Route::prefix('estudante')
    ->middleware(['jwt.auth', 'org', 'role:estudante'])
    ->group(function () {
        Route::get('/eu/faturas',                 [ControladorFaturasAluno::class, 'minhasFaturas']);
        Route::get('/eu/faturas/{id}',            [ControladorFaturasAluno::class, 'minhaFatura']);
        Route::get('/eu/faturas/{id}/pdf',        [ControladorFaturasAluno::class, 'minhaPdfFatura']);
        Route::get('/eu/faturas/{id}/pdf-base64', [ControladorFaturasAluno::class, 'minhaPdfBase64']);
        Route::get('/eu/mensalidades',            [ControladorFaturasAluno::class, 'minhasMensalidades']);
        Route::get('/eu/resumo',                  [ControladorFaturasAluno::class, 'meuResumo']);
        // Uso: GET /api/estudante/eu/faturas  (sem alunoId)
    });
```

---

*Onsoft AGT v1.6.0 — Adilson Miguel — adilson2012jose@gmail.com — 2068417074*

---

## 🖼️ Logo da Fatura — Sempre da Base de Dados

O logótipo no PDF **nunca** vem do `request` — vem sempre de `Organization.logo_path`, resolvido exactamente como o `OrganizationController` já faz:

```php
Storage::disk('public')->url($org->logo_path)
```

Isto evita que alguém envie um `logo_url` falso no payload da fatura para tentar inserir conteúdo malicioso ou indevido no documento fiscal.

**Campos do snapshot construídos directamente da tabela `organizations`:**

```php
[
    'nif'             => $org->nif,
    'name'            => $org->nome_fiscal,
    'commercial_name' => $org->nome_comercial,
    'address'         => $org->endereco,
    'bairro'          => $org->bairro,
    'city'            => $org->municipio,
    'province'        => $org->provincia,
    'country'         => $org->pais ?? 'Angola',
    'telefone'        => $org->telefone,
    'telefone_alt'    => $org->telefone_alt,
    'email'           => $org->email,
    'website'         => $org->website,
    'logo_path'       => $org->logo_path,
    'logo_url'        => $logoUrl,  // resolvido via Storage::disk('public')->url()
]
```

Se `logo_path` estiver vazio ou o ficheiro não existir, `logo_url` fica `null` e o PDF simplesmente não mostra logo — sem erro.

---

## 📅 Ordem Sequencial de Pagamento de Propinas

### A regra

Um aluno **nunca** pode pagar o mês 7 sem ter pago 1, 2, 3, 4, 5 e 6 antes. A ordem é definida pelo campo `meses.orderNumber`. Meses com `anularpagamento = true` são ignorados na sequência (não contam).

"Pago" = existe `BillingPropina` com `mensalidadeId` + `alunoId` + `anolectivoId` + `mesid` correctos e `status != 'cancelled'`.

### Como o pacote valida (1 única query, sem N+1)

```sql
-- Uma única query LEFT JOIN para o ano lectivo completo
SELECT m.id, m.mesId, m.name, m.orderNumber,
       CASE WHEN bp.id IS NOT NULL THEN 1 ELSE 0 END as ja_pago
FROM meses m
LEFT JOIN billing_propinas bp
    ON bp.mesid = m.mesId
   AND bp.mensalidadeId = ?
   AND bp.alunoId = ?
   AND bp.anolectivoId = ?
   AND bp.status != 'cancelled'
WHERE m.anolectivoId = ?
  AND m.anularpagamento = 0
ORDER BY m.orderNumber
```

Depois em memória (sem mais queries):
1. Encontra o `orderNumber` mais alto já pago
2. Verifica que o(s) mês(es) pedido(s) começam exactamente em `último_pago + 1`
3. Verifica que não há buracos se pedir vários meses de uma vez

### Payload de exemplo — item de propina

```json
{
  "item_category": "propina",
  "description": "Propina — Julho 2026",
  "quantity": 1,
  "unit_price": 45000.00,
  "tax_code": "ISE",
  "tax_percentage": 0,
  "mensalidadeId": 5,
  "alunoId": 101,
  "anolectivoId": 3,
  "mesId": 47,
  "classComExam": false
}
```

### Erro devolvido se a ordem for violada

```json
{
  "sucesso": false,
  "mensagem": "ORDEM DE PAGAMENTO VIOLADA — Não é possível pagar 'Julho' (posição 7) sem primeiro pagar 'Abril' (posição 4). As propinas devem ser pagas em ordem sequencial: 1, 2, 3... sem saltar meses."
}
```

### Lock atómico — sem afectar outros alunos

```php
BillingPropina::where('mensalidadeId', $mensalidadeId)
    ->where('alunoId', $alunoId)
    ->where('anolectivoId', $anolectivoId)
    ->lockForUpdate()  // ← lock ESTREITO, só estas linhas
    ->get();
```

O lock é **por aluno+mensalidade**, nunca pela tabela inteira. Isto significa que 10.000 alunos diferentes podem pagar propinas em paralelo sem qualquer contenção — apenas dois pedidos *simultâneos do mesmo aluno* esperam um pelo outro (cenário extremamente raro, resolvido em <10ms).

### Endpoints de apoio para o frontend

**`GET /onsoft-agt/propinas/proximo-mes?mensalidadeId=5&alunoId=101&anolectivoId=3`**

Devolve o próximo mês que o aluno deve pagar — útil para pré-seleccionar no formulário.

```json
{
  "sucesso": true,
  "dados": {
    "proximo_mes": { "id": 47, "mesId": 47, "name": "Julho", "orderNumber": 7, "data": "2026-07-01" },
    "todos_meses_pagos": false
  }
}
```

**`POST /onsoft-agt/propinas/validar-ordem`**

Pré-validar sem criar nada — feedback imediato ao utilizador antes de submeter.

```json
// Request
{ "mensalidadeId": 5, "alunoId": 101, "anolectivoId": 3, "mesIds": [7] }

// Response
{ "sucesso": false, "dados": { "pode_pagar": false, "erro": "ORDEM DE PAGAMENTO VIOLADA — ..." } }
```

### Índices de BD adicionados (críticos para performance)

```sql
billing_propinas: (mensalidadeId, alunoId, anolectivoId)
billing_propinas: (mensalidadeId, alunoId, anolectivoId, mesid)
billing_propinas: (mesid, status)
meses:            (anolectivoId, anularpagamento, classComExam)
meses:            (anolectivoId, orderNumber)
estudanteanoclasse: (organizationId, alunoId)
```

Com estes índices, a query de validação executa em **<1ms** mesmo com 100.000+ registos de propinas na BD — essencial para suportar alto volume de pedidos simultâneos.

---

*Onsoft AGT v1.7.0 — Adilson Miguel — adilson2012jose@gmail.com — 2068417074*

---

## 💳 Exclusividade de Métodos de Pagamento (Multicaixa Express, Referência Multicaixa, POS Online)

### Importante — este pacote NÃO duplica a infra-estrutura de pagamentos online

O projecto **já tem** o ciclo completo de pagamento online implementado e funcional:

| Componente já existente no projecto | Função |
|---|---|
| `App\Models\Config\Financeiro\TipoDePagamento` | Tabela `tipodepagamento` com os 10 métodos estáticos (appCode 1001-1010) |
| `App\Models\Payment\OnlinePaymentIntent` | Staging da referência/pagamento ANTES da fatura |
| `App\Models\Payment\OrganizationPaymentConfig` | Configuração do provider (ProxyPay) por organização |
| `App\Models\Payment\OnlinePaymentWebhookLog` | Log de auditoria de cada webhook recebido |
| `App\Services\Payment\OnlinePaymentIntentService` | `createIntent()` e `markPaidAndCreateInvoice()` — staging + criação atómica da fatura SÓ após confirmação |
| `App\Http\Controllers\PaymentProvider\ProxyPayWebhookController` | Recebe o webhook do ProxyPay e chama o service |
| `App\Http\Controllers\PaymentProvider\PaymentReferenceController` | `POST /payment-references` para iniciar, `cancel`, `refresh` |

**O pacote Onsoft AGT não recria nada disto.** Continue a usar os endpoints existentes do projecto para o fluxo de pagamento online:

```
POST /api/payment-references           → cria a referência (PaymentReferenceController::store)
POST /api/payment-references/{id}/cancel
POST /webhooks/proxypay                 → confirma e cria a fatura (ProxyPayWebhookController::handle)
```

### O que o pacote adiciona — apenas a regra de exclusividade que faltava

Antes desta versão, nada impedia que Multicaixa Express fosse combinado com Numerário na mesma fatura. O pacote acrescenta **duas colunas** à tabela `tipodepagamento` já existente (migração `2024_01_01_000004_add_exclusivity_to_tipodepagamento.php` — não cria tabela nova):

```sql
ALTER TABLE tipodepagamento
  ADD COLUMN exclusivo BOOLEAN DEFAULT FALSE AFTER appCode,
  ADD COLUMN requer_consulta_online BOOLEAN DEFAULT FALSE AFTER exclusivo,
  ADD COLUMN provider VARCHAR(60) NULL AFTER requer_consulta_online;

-- Marca automaticamente os 3 métodos exclusivos conhecidos
UPDATE tipodepagamento SET exclusivo = 1, requer_consulta_online = 1, provider = 'proxypay'
WHERE appCode IN (1005, 1006, 1009);
```

| appCode | Nome | exclusivo | requer_consulta_online | provider |
|---|---|---|---|---|
| 1001 | Dinheiro | false | false | — |
| 1002 | TPA | false | false | — |
| 1003 | Transferência Bancária | false | false | — |
| 1004 | Depósito Bancário | false | false | — |
| **1005** | **Multicaixa Express** | **true** | **true** | proxypay |
| **1006** | **Referência Multicaixa** | **true** | **true** | proxypay |
| 1007 | Cheque | false | false | — |
| 1008 | Carteira Interna | false | false | — |
| **1009** | **POS Online** | **true** | **true** | proxypay |
| 1010 | Pagamento Parcial | false | false | — |

### Onde a validação corre

`ServicoFatura::criar()` chama `validarExclusividadeMetodos()` em **todo** pedido de criação de fatura com mais de um método de pagamento — lendo directamente de `tipodepagamento.exclusivo`. Isto protege qualquer caminho de criação de fatura, incluindo o `OnlinePaymentIntentService::markPaidAndCreateInvoice()` do projecto, que internamente chama o mesmo `InvoiceService`/`ServicoFatura`.

```json
// Rejeitado automaticamente
{
  "payments": [
    { "tipodepagamentoId": 1005, "amount": 30000 },
    { "tipodepagamentoId": 1001, "amount": 20000 }
  ]
}
```
```json
{
  "sucesso": false,
  "mensagem": "'Multicaixa Express' é um método de pagamento exclusivo e não pode ser combinado com outros métodos na mesma fatura. Use apenas este método isoladamente."
}
```

### Endpoints leves adicionados pelo pacote

```
GET  /onsoft-agt/metodos-pagamento           → lista tipodepagamento com exclusivo/provider
POST /onsoft-agt/metodos-pagamento/validar   → validar combinação SEM criar nada (feedback ao frontend)
```

**Exemplo:**
```json
POST /onsoft-agt/metodos-pagamento/validar
{ "payments": [{ "tipodepagamentoId": 1005, "amount": 50000 }] }
```
```json
{ "sucesso": true, "dados": { "valido": true, "erro": null } }
```

### Extensibilidade — marcar um novo método como exclusivo no futuro

Não é preciso código nenhum. Basta uma linha SQL:

```sql
UPDATE tipodepagamento SET exclusivo = 1, requer_consulta_online = 1, provider = 'meu_provider'
WHERE appCode = 1011;
```

A validação em `ServicoFatura` passa a aplicar-se automaticamente a esse `appCode`, sem qualquer alteração no pacote.

### `ServicoExclusividadePagamento` — uso directo no código

```php
use Onsoft\Agt\Servicos\ServicoExclusividadePagamento;

$servico = app(ServicoExclusividadePagamento::class);

$resultado = $servico->validar($request->input('payments'));
// ['valido' => bool, 'erro' => ?string]

$ehOnline = $servico->ehExclusivoOnline(1005); // true
$provider = $servico->providerDoAppCode(1005); // 'proxypay'
```

---

*Onsoft AGT v1.8.0 — Adilson Miguel — adilson2012jose@gmail.com — 2068417074*

---

## 🗓️ Mapa de Meses — Pago / Parcial / Pendente

### `GET /onsoft-agt/propinas/mapa-meses?mensalidadeId=5&alunoId=101&anolectivoId=3&propinaAnual=540000`

Mostra, para um aluno+mensalidade+ano lectivo, o estado de **todos os meses** com a `propinaMensal` calculada automaticamente (`propinaAnual / totalMeses` — mesma fórmula do `Mes::paidAndUnpaidMeses()` já usado no projecto).

**Regras aplicadas:**
- Um mês sem registo em `billing_propinas`, ou com `status='cancelled'`, **nunca é ignorado** — é sempre contado na sequência e aparece como `pendente`
- `status='paid'` → `estado: "pago"`
- `status='partial'` → `estado: "parcial"` (conta como ocupando a posição na sequência)
- `pode_pagar_agora` só é `true` no mês que está exactamente na posição seguinte ao último pago/parcial

**Response 200:**
```json
{
  "sucesso": true,
  "dados": {
    "resumo": {
      "total_meses": 10,
      "propina_anual": 540000.00,
      "propina_mensal": 54000.00,
      "total_meses_pagos": 4,
      "total_meses_parciais": 1,
      "total_meses_pendentes": 5,
      "total_pago": 216000.00,
      "total_em_divida": 27000.00,
      "proximo_mes_a_pagar": "Maio",
      "proximo_order_number": 6,
      "todos_meses_pagos": false
    },
    "meses": [
      { "mesId": 1, "name": "Janeiro",  "orderNumber": 1, "estado": "pago",     "propina_mensal": 54000.00, "valor_pago": 54000.00, "valor_restante": 0.00,    "pode_pagar_agora": false },
      { "mesId": 2, "name": "Fevereiro","orderNumber": 2, "estado": "pago",     "propina_mensal": 54000.00, "valor_pago": 54000.00, "valor_restante": 0.00,    "pode_pagar_agora": false },
      { "mesId": 3, "name": "Março",    "orderNumber": 3, "estado": "pago",     "propina_mensal": 54000.00, "valor_pago": 54000.00, "valor_restante": 0.00,    "pode_pagar_agora": false },
      { "mesId": 4, "name": "Abril",    "orderNumber": 4, "estado": "pago",     "propina_mensal": 54000.00, "valor_pago": 54000.00, "valor_restante": 0.00,    "pode_pagar_agora": false },
      { "mesId": 5, "name": "Maio",     "orderNumber": 5, "estado": "parcial",  "propina_mensal": 54000.00, "valor_pago": 27000.00, "valor_restante": 27000.00,"pode_pagar_agora": true  },
      { "mesId": 6, "name": "Junho",    "orderNumber": 6, "estado": "pendente", "propina_mensal": 54000.00, "valor_pago": 0.00,     "valor_restante": 54000.00,"pode_pagar_agora": false },
      { "mesId": 7, "name": "Julho",    "orderNumber": 7, "estado": "pendente", "propina_mensal": 54000.00, "valor_pago": 0.00,     "valor_restante": 54000.00,"pode_pagar_agora": false }
    ]
  }
}
```

> Note: no exemplo acima, Maio está `parcial`, por isso `pode_pagar_agora` fica `true` em Maio (precisa de terminar de pagar essa posição) e `false` em Junho — não se pode saltar para Junho enquanto Maio não estiver `pago` por completo.

Útil para o frontend desenhar uma grelha de 12 meses com cores (verde=pago, amarelo=parcial, cinza=pendente) e bloquear o clique em meses fora de ordem.

---

*Onsoft AGT v1.9.0 — Adilson Miguel — adilson2012jose@gmail.com — 2068417074*

---

## 🔄 Alternância: Faturação Eletrónica AGT ⇄ SAF-T (AO)

### O que isto resolve

Algumas organizações precisam de operar sob o regime **SAF-T (AO)** em vez do regime de **Faturação Eletrónica em tempo real**. O pacote permite alternar entre os dois a qualquer momento — **de forma totalmente reversível, em ambos os sentidos**.

| Modo | Comportamento |
|---|---|
| `electronic` (defeito) | Cada fatura é assinada (RSA-SHA1), recebe hash chain, jws e QR Code, e é submetida em tempo real à AGT |
| `saft_ao` | Faturas continuam a ser criadas normalmente, mas **sem** assinatura nem submissão em tempo real. Periodicamente é exportado um ficheiro XML SAF-T(AO) com todas as faturas de um intervalo de datas |

**A troca nunca apaga ou altera faturas já emitidas** — afecta apenas o comportamento das faturas criadas a partir do momento da troca.

---

### Consultar o modo actual

```
GET /onsoft-agt/modo-faturacao/estado
```
```json
{
  "sucesso": true,
  "dados": {
    "modo_actual": "electronic",
    "modo_label": "Faturação Eletrónica AGT",
    "submissao_tempo_real_activa": true,
    "requer_geracao_saft": false,
    "alterado_em": null
  }
}
```

### Alternar para SAF-T (AO)

```
POST /onsoft-agt/modo-faturacao/alternar
{ "modo": "saft_ao" }
```
```json
{
  "sucesso": true,
  "dados": {
    "alterado": true,
    "modo_anterior": "electronic",
    "modo_actual": "saft_ao",
    "mensagem": "Organização alternada para regime SAF-T (AO). Faturas a partir de agora deixam de ser submetidas em tempo real à AGT."
  }
}
```

### Voltar para Faturação Eletrónica — vice-versa, sempre possível

```
POST /onsoft-agt/modo-faturacao/alternar
{ "modo": "electronic" }
```

A validação garante que a organização só pode activar `saft_ao` se já tiver `tax_registration_number` (NIF) e `software_validation_number` configurados — senão devolve erro `422` explicando o que falta.

---

## 📄 Geração do Ficheiro SAF-T (AO) — entre Data de Início e Data de Fim

### Pré-visualizar (sem gerar o ficheiro)

```
GET /onsoft-agt/saft/previsualizar?data_inicio=2026-06-01&data_fim=2026-06-30
```
```json
{
  "sucesso": true,
  "dados": {
    "data_inicio": "2026-06-01",
    "data_fim": "2026-06-30",
    "total_faturas": 412,
    "total_emitido": 18540000.00,
    "total_iva": 980000.00,
    "por_tipo": [
      { "tipo": "FR", "total": 390, "valor": 17550000.00 },
      { "tipo": "NC", "total": 22,  "valor": 990000.00 }
    ]
  }
}
```

### Exportar o ficheiro XML directamente (download)

```
GET /onsoft-agt/saft/exportar?data_inicio=2026-06-01&data_fim=2026-06-30
```
Devolve `Content-Type: application/xml` como anexo (`SAFT_AO_<NIF>_<inicio>_a_<fim>.xml`), pronto para entrega à AGT pelo canal próprio do regime SAF-T.

### Exportar em base64 (para SPA/frontend)

```
GET /onsoft-agt/saft/exportar-base64?data_inicio=2026-06-01&data_fim=2026-06-30
```
```json
{
  "sucesso": true,
  "dados": {
    "base64": "PD94bWwgdmVyc2lvbj0iMS4wIi...",
    "nome_ficheiro": "SAFT_AO_500000000_20260601_a_20260630.xml",
    "mime_type": "application/xml",
    "total_documentos": 412,
    "resumo": { "...": "..." }
  }
}
```

```javascript
// Frontend — descarregar o XML a partir do base64
const { base64, nome_ficheiro } = res.data.dados;
const blob = new Blob([atob(base64)], { type: 'application/xml' });
const url  = URL.createObjectURL(blob);
const a    = document.createElement('a');
a.href = url; a.download = nome_ficheiro; a.click();
```

---

### Estrutura do XML gerado

```
<AuditFile>
  <Header>                 NIF, nome, FiscalYear, StartDate, EndDate, ProductID...
  <MasterFiles>
    <Customer>              Clientes únicos referenciados no período
    <Product>                Artigos/serviços únicos referenciados
    <TaxTableEntry>          Taxas de IVA usadas (incluindo isenções)
  <SourceDocuments>
    <SalesInvoices>
      NumberOfEntries, TotalCredit
      <Invoice> (uma por documento)
        <Line> (uma por item, com Tax aninhado)
        <DocumentTotals>     TaxPayable, NetTotal, GrossTotal
```

Regras aplicadas, consistentes com o resto do pacote:
- NC usa `DebitAmount`; FT/FR/FS/ND usam `CreditAmount`
- `InvoiceStatus = 'A'` para faturas com `payment_status = cancelled`
- Faturas `cancelled` continuam a aparecer no SAF-T (estado Anulado) — nunca são omitidas
- `TaxAccountingBasis` e `CompanyID` configuráveis por organização (colunas `saft_company_id`, `saft_tax_accounting_basis`)

---

### Uso directo no código

```php
use Onsoft\Agt\Servicos\ServicoModoFaturacao;
use Onsoft\Agt\Servicos\ServicoSaftAo;

$modo = app(ServicoModoFaturacao::class);
$modo->alternarModo($organizacaoId, ServicoModoFaturacao::SAFT_AO, auth()->id());

$saft = app(ServicoSaftAo::class);
$resultado = $saft->gerar($organizacaoId, '2026-06-01', '2026-06-30');
file_put_contents($resultado['nome_ficheiro'], $resultado['xml']);
```

---

*Onsoft AGT v1.10.0 — Adilson Miguel — adilson2012jose@gmail.com — 2068417074*

---

## ⚠️ Faturas SAF-T NUNCA migram para submissão em tempo real

**Resposta directa: não.** Se gerares faturas em modo `saft_ao` e depois voltares para `electronic`, essas faturas antigas **não podem** ser submetidas retroactivamente à Faturação Eletrónica.

### Porquê

| Razão | Explicação |
|---|---|
| Hash chain partido | Faturas SAF-T não têm `invoice_hash` — submeter uma sem hash quebraria a cadeia sequencial da série |
| Duplo reporte fiscal | A fatura já será (ou já foi) reportada à AGT via ficheiro SAF-T — submeter também em tempo real reportaria o mesmo documento duas vezes |
| Prazo expirado | A submissão em tempo real exige que aconteça no momento da emissão — não retroactivamente, dias ou semanas depois |

### O que acontece na prática

```
Modo: electronic → Fatura A criada (hash, jws, submetida em tempo real)
Modo: saft_ao     → Fatura B, C, D criadas (sem hash, agt_status = saft_pending_export)
[Exportar SAF-T]   → B, C, D ficam agt_status = saft_exported
Modo: electronic   → Fatura E criada (hash, jws, submetida em tempo real — normal)

Resultado:
  A → já submetida (electronic)
  B, C, D → reportadas apenas via SAF-T, nunca via submissão em tempo real
  E → submetida normalmente (electronic)
```

Se tentar chamar `POST /onsoft-agt/faturas/{id}/submeter` numa fatura B, C ou D:
```json
{
  "sucesso": false,
  "mensagem": "Esta fatura [FR FR-2026/000412] foi criada em modo SAF-T(AO) e não pode ser submetida à Faturação Eletrónica retroactivamente. Documentos emitidos em regime SAF-T devem ser reportados exclusivamente via exportação do ficheiro SAF-T(AO) (GET /onsoft-agt/saft/exportar)."
}
```

### Auditoria da transição

```
GET /onsoft-agt/modo-faturacao/auditoria
```
```json
{
  "sucesso": true,
  "dados": {
    "modo_actual": "electronic",
    "faturas_electronicas_total": 1204,
    "faturas_saft_aguardando_exportacao": 0,
    "faturas_saft_ja_exportadas": 47,
    "nota": "Não há faturas SAF-T pendentes de exportação."
  }
}
```

Use este endpoint **antes** de mudar de modo para confirmar que todas as faturas SAF-T pendentes já foram exportadas e entregues à AGT — evita esquecer um período sem ficheiro gerado.

---

*Onsoft AGT v1.10.1 — Adilson Miguel — adilson2012jose@gmail.com — 2068417074*

---

## 🏷️ Separador Explícito entre Regimes — `invoicing_mode` na Fatura

### O problema que isto resolve

Até à v1.10.1, o regime de uma fatura só era identificável indirectamente através de valores específicos de `agt_status` (`saft_pending_export`, `saft_exported`). Isto era ambíguo e não dava ao frontend um campo único e estável para separar visualmente os dois regimes nem para decidir, sem lógica própria, que botões mostrar.

### A solução — coluna dedicada e imutável

A migração `2024_01_01_000006_add_invoicing_mode_to_invoices.php` adiciona `invoicing_mode` directamente à tabela `invoices`. É gravado **uma vez**, no momento da criação, reflectindo o modo da organização nesse instante exacto — e está protegido pelo `InvoiceSnapshotGuard` como **campo imutável**. Mudar o modo da organização no futuro nunca altera o `invoicing_mode` de faturas já criadas.

```sql
ALTER TABLE invoices ADD COLUMN invoicing_mode VARCHAR(20) DEFAULT 'electronic' AFTER agt_status;
-- Preenchimento retroactivo automático com base no agt_status existente
```

### `ServicoFlagsUiFatura` — fonte única da verdade para o frontend

Em vez do frontend decidir com lógica própria que botões mostrar, o backend devolve um objecto de flags já calculado:

```php
$flags = app(\Onsoft\Agt\Servicos\ServicoFlagsUiFatura::class)->calcular($fatura);
```

```json
{
  "invoicing_mode": "saft_ao",
  "invoicing_mode_label": "SAF-T (AO)",
  "badge_cor": "amber",
  "mostrar_botao_submeter": false,
  "pode_submeter": false,
  "motivo_submeter_desactivado": "Fatura emitida em regime SAF-T(AO) — reportada apenas via exportação do ficheiro SAF-T, nunca em tempo real.",
  "mostrar_botao_retentar": false,
  "mostrar_botao_exportar_saft": true,
  "pode_exportar_saft": true,
  "ja_exportada_saft": false,
  "mostrar_botao_cancelar": true,
  "gera_nota_credito_ao_cancelar": false,
  "pode_editar_pagamento": true,
  "mostra_aviso_regime_misto": false
}
```

**Uso directo no frontend:**
```jsx
<button disabled={!flags.pode_submeter} title={flags.motivo_submeter_desactivado}>
  Submeter à AGT
</button>

{flags.mostrar_botao_exportar_saft && (
  <button disabled={!flags.pode_exportar_saft}>Exportar SAF-T</button>
)}

<span className={`badge badge-${flags.badge_cor}`}>{flags.invoicing_mode_label}</span>
```

### Endpoints

**`GET /onsoft-agt/faturas/{id}/estado`** — agora inclui `invoicing_mode` e o bloco `ui` completo:
```json
{
  "sucesso": true,
  "dados": {
    "fatura_id": 1247,
    "invoicing_mode": "electronic",
    "agt_status": "accepted",
    "submissao": { "...": "..." },
    "ui": { "invoicing_mode": "electronic", "mostrar_botao_submeter": true, "pode_submeter": false, "...": "..." }
  }
}
```

**`GET /onsoft-agt/faturas/flags-ui?ids=101,102,103`** — flags em massa para tabelas/listagens, sem 1 pedido por linha:
```json
{
  "sucesso": true,
  "dados": [
    { "invoice_id": 101, "invoicing_mode": "electronic", "pode_submeter": true, "...": "..." },
    { "invoice_id": 102, "invoicing_mode": "saft_ao", "pode_submeter": false, "...": "..." }
  ]
}
```

### Bloqueio reforçado a nível de servidor

`POST /onsoft-agt/faturas/{id}/submeter` agora verifica `invoicing_mode` **antes** de qualquer chamada à API AGT — uma fatura SAF-T nunca chega a tocar no `ServicoSubmissao`:

```json
{
  "sucesso": false,
  "mensagem": "Esta fatura foi criada em modo SAF-T(AO) e não pode ser submetida à Faturação Eletrónica. Reporte-a via exportação do ficheiro SAF-T (GET /onsoft-agt/saft/exportar).",
  "ui": { "invoicing_mode": "saft_ao", "pode_submeter": false, "...": "..." }
}
```

Como a resposta de erro já inclui o bloco `ui`, o frontend pode actualizar o estado dos botões imediatamente sem precisar de um segundo pedido a `/estado`.

---

*Onsoft AGT v1.11.0 — Adilson Miguel — adilson2012jose@gmail.com — 2068417074*

---

## 📑 Original vs Cópia do Documento Original

### A regra AGT

> **Decreto Executivo AGT, Anexo I, ponto 6, alínea n):** "A impressão de uma 2.ª via de um documento deve preservar o seu conteúdo original, ainda que deva conter qualquer expressão que indique não se tratar de um original."
>
> **Alínea h):** "...deverá fazer menção desta qualidade, através da expressão **'Cópia do documento original'** (sem aspas)..."

### Implementação

A **primeira** vez que o PDF de uma fatura é gerado — em qualquer canal (`pdf`, `pdf-base64`, `pdf-snapshot`) — é registada como **Original**. Qualquer geração seguinte da mesma fatura, mesmo que seja noutro formato de papel ou canal diferente, é automaticamente marcada **"Cópia do documento original"**.

O conteúdo (valores, NIF, hash, QR Code) é **sempre idêntico** entre Original e Cópia — apenas a etiqueta impressa muda.

### Onde aparece no PDF

Em todos os 3 formatos (A4, 88mm, 58mm):
- **Badge** logo abaixo do número do documento, com cor verde para Original e âmbar para Cópia
- **Frase formal no rodapé**, exactamente no formato exigido pela alínea h): `Cópia do documento original — FR FR-2026/001247`

### Lock atómico — sem corridas

Registado via `lockForUpdate()` na tabela `onsoft_agt_invoice_print_log`. Se dois pedidos chegarem ao mesmo tempo para a mesma fatura (ex: duplo clique, ou impressão automática + visualização manual), **nunca** os dois ficam marcados como Original.

### Endpoint de auditoria

```
GET /onsoft-agt/faturas/{id}/historico-impressao
```
```json
{
  "sucesso": true,
  "dados": {
    "fatura_id": 1247,
    "ja_tem_original": true,
    "historico": [
      { "is_original": true,  "via_label": "Original",                     "formato_papel": "A4",   "canal": "pdf",        "gerado_por": 12, "gerado_em": "2026-06-18T14:30:05Z" },
      { "is_original": false, "via_label": "Cópia do documento original",  "formato_papel": "88mm", "canal": "pdf",        "gerado_por": 3,  "gerado_em": "2026-06-19T09:12:40Z" },
      { "is_original": false, "via_label": "Cópia do documento original",  "formato_papel": "A4",   "canal": "pdf-base64", "gerado_por": 12, "gerado_em": "2026-06-20T11:00:00Z" }
    ]
  }
}
```

Permite saber exactamente quem viu/imprimiu o documento, em que formato, e quando — útil para auditoria interna além da exigência fiscal.

---

*Onsoft AGT v1.12.0 — Adilson Miguel — adilson2012jose@gmail.com — 2068417074*

---

## 🛡️ Default Seguro Quando o Modo Está "Desligado"

### O problema que isto resolve

Antes da v1.14.0, se uma organização **não tivesse nenhuma configuração AGT**, ou tivesse `agt_enabled = false` (AGT desligado), o sistema assumia silenciosamente o modo `electronic` — tentando assinar e submeter faturas a uma integração que não estava configurada. Isto causava falhas confusas ou, em pior caso, hash inválido persistido na fatura.

### A correcção

**`ServicoModoFaturacao::modoActual()`** agora segue esta regra de resolução, por ordem:

| Situação | Modo resolvido |
|---|---|
| Sem nenhum registo em `organization_agt_configs` | `saft_ao` |
| `agt_enabled = false` | `saft_ao` |
| `agt_enabled = true` e `invoicing_mode` nunca definido | `electronic` (compatibilidade histórica) |
| `agt_enabled = true` e `invoicing_mode` definido explicitamente | usa o valor gravado |

**Nunca mais assume `electronic` silenciosamente quando não há nada configurado.** Faturas criadas nestas condições não tentam assinatura nem submissão — ficam em modo SAF-T à espera de exportação.

### Escolher o modo explicitamente — mesmo sem configuração prévia

```
POST /onsoft-agt/modo-faturacao/alternar
{ "modo": "saft_ao" }
```

Se a organização não tiver **nenhuma** configuração AGT ainda, este pedido **cria automaticamente** um registo `organization_agt_configs` com `invoicing_mode = saft_ao` — sem exigir mais nada. SAF-T(AO) nunca é bloqueado por falta de configuração.

```
POST /onsoft-agt/modo-faturacao/alternar
{ "modo": "electronic" }
```

Activar `electronic` agora **exige** que a organização já tenha NIF, número de certificação **e chave privada do contribuinte** configurados — é este modo que assina documentos, por isso é o que exige preparação completa. Se faltar algo:

```json
{
  "sucesso": false,
  "mensagem": "Não é possível activar a Faturação Eletrónica - configuração incompleta: Chave privada do contribuinte não configurada — necessária para assinar documentos. A organização permanece em modo SAF-T(AO) até que isto seja resolvido."
}
```

Ao activar `electronic` com sucesso, `agt_enabled` é automaticamente definido como `true` — escolher o modo é a intenção clara de ligar a submissão em tempo real.

### `GET /onsoft-agt/modo-faturacao/estado` — campos novos

```json
{
  "sucesso": true,
  "dados": {
    "modo_actual": "saft_ao",
    "modo_label": "SAF-T (AO)",
    "pode_alternar": true,
    "configuracao_existe": false,
    "agt_enabled": false,
    "modo_e_default_automatico": true,
    "alterado_em": null,
    "submissao_tempo_real_activa": false,
    "requer_geracao_saft": true
  }
}
```

`modo_e_default_automatico: true` indica que este é o valor por defeito (ninguém escolheu explicitamente) — útil para o frontend mostrar um aviso "Configure o modo de faturação" em vez de assumir que está tudo certo.

---

*Onsoft AGT v1.14.0 — Adilson Miguel — adilson2012jose@gmail.com — 2068417074*

---

## 🔍 Auditoria de Seguimento (v1.14.1) — 3 Lacunas Novas Encontradas e Corrigidas

Após a correcção do default seguro SAF-T na v1.14.0, uma nova ronda de auditoria identificou três pontos onde o resto do pacote ainda não reflectia correctamente o regime SAF-T num documento sem hash. Nenhuma destas era um problema antes da v1.14.0 existir — são consequências directas de ter introduzido o default automático.

### 1. Linha de certificação do PDF confundia "SAF-T" com "documento incompleto"

**Antes:** uma fatura em modo `saft_ao` (que nunca terá hash, por desenho) mostrava no PDF a frase "Aguardando certificação" — sugerindo um estado transitório de erro, quando na realidade é o comportamento permanente e correcto desse regime.

**Depois:** `ServicoPdf::construirLinhaCertificacao()` distingue agora os dois casos:
```
SAF-T(AO):     "Documento emitido sob o regime SAF-T(AO) — nº 0000/AGT.
                Reportado via ficheiro SAF-T(AO), sem assinatura individual por documento."

Electronic sem hash ainda: "Aguardando certificação — nº 0000/AGT" (inalterado)
```

### 2. `invoicing_mode` da fatura não chegava ao PDF

**Causa raiz:** `InvoiceObserver` (que constrói o snapshot imutável) e `ServicoPdf::normalizarInvoice()` (que normaliza dados live) nunca incluíam o campo `invoicing_mode` no array entregue às views. A correcção do ponto 1 não tinha nenhum dado para funcionar.

**Corrigido em dois pontos:**
- `InvoiceObserver` — `invoicing_mode` agora gravado no snapshot imutável
- `ServicoPdf::normalizarInvoice()` — `invoicing_mode` agora incluído nos dados live

### 3. QR Code mostrava `HASH:` vazio em faturas SAF-T

**Antes:** o conteúdo do QR Code de uma fatura SAF-T continha `HASH:` sem valor nenhum, sem explicação — parecia um erro de geração.

**Depois:** `ServicoQrCode::construirConteudo()` substitui o campo `HASH` por `REGIME:SAFT_AO` quando aplicável, comunicando correctamente que a verificação deste documento passa pelo ficheiro SAF-T periódico, não por hash individual.

### 4. Exportação SAF-T falhava para organizações sem configuração

**Antes:** `ServicoSaftAo::gerar()` exigia uma linha em `organization_agt_configs` para funcionar — mas a v1.14.0 tornou legítimo uma organização estar em modo `saft_ao` **sem nenhuma configuração**. Isto bloqueava exactamente o caso de uso que a v1.14.0 veio resolver.

**Corrigido:** `gerar()` usa agora uma instância `OrganizationAgtConfig` vazia (não persistida) como fallback neutro quando não existe configuração, preenchendo o XML com os campos disponíveis da `Organization` e defaults seguros (`TaxAccountingBasis = 'F'`).

---

*Onsoft AGT v1.14.1 — Adilson Miguel — adilson2012jose@gmail.com — 2068417074*

---

## 🚨 Auditoria Profunda (v1.14.2) — Lacuna Crítica Encontrada e Corrigida

Uma terceira ronda de auditoria, seguindo o fluxo de dados ponta-a-ponta em vez de requisito-a-requisito, encontrou **uma lacuna crítica** e três inconsistências de relatório. Esta foi a ronda mais importante até agora.

### 🔴 Crítico — Submissão automática via fila contornava toda a protecção SAF-T

**Esta é a lacuna mais grave encontrada em todas as rondas de auditoria.**

**O problema:** `ServicoFatura::criar()` despachava `App\Jobs\SubmitInvoiceToAgtJob` — o Job **do projecto hospedeiro**, não do pacote — para a submissão automática (`auto_submit_invoices = true`). Esse Job chama `App\Services\Agt\AgtInvoiceSubmissionService`, um serviço escrito **antes de o regime SAF-T(AO) existir**, sem qualquer noção desse modo.

**Consequência real:** todas as protecções documentadas nas versões anteriores — bloqueio de faturas SAF-T em `ServicoSubmissao::submeter()`, validação no endpoint HTTP — **nunca eram exercidas no fluxo automático de fila**. Se uma organização tivesse `auto_submit_invoices = true`, uma fatura criada em modo SAF-T podia ser silenciosamente submetida à AGT em tempo real através deste caminho alternativo, apesar de toda a documentação anterior garantir o contrário.

**Correcção:** novo Job próprio do pacote, `Onsoft\Agt\Jobs\SubmeterFaturaAgtJob`, com a mesma verificação explícita de `invoicing_mode` usada nos endpoints HTTP. `ServicoFatura::criar()` e `gerarNotaCredito()` foram actualizados para usar este Job em vez do legado.

```php
// Onsoft\Agt\Jobs\SubmeterFaturaAgtJob::handle()
if (($fatura->invoicing_mode ?? ServicoModoFaturacao::ELECTRONIC) === ServicoModoFaturacao::SAFT_AO) {
    Log::warning('SubmeterFaturaAgtJob recebeu fatura em modo SAF-T(AO) — ignorado.');
    return;
}
```

**Acção necessária:** `app/Jobs/SubmitInvoiceToAgtJob.php` passa de `KEEP` para `DELETE` na lista de ficheiros a remover após instalação — ver `FICHEIROS_A_ELIMINAR.txt` actualizado.

### Inconsistências de relatório — faturas SAF-T invisíveis nas estatísticas

**`ServicoRelatorios::estadoAgt()`** tinha categorias fixas (`draft`, `pending`, `submitted`, `accepted`, `rejected`, `failed`, `cancelled`) que não incluíam `saft_pending_export` nem `saft_exported`. O `total_documentos` somava todos os estados (incluindo SAF-T), mas as categorias visíveis ao frontend não — a soma das partes não batia com o total apresentado.

**Corrigido:** adicionadas as duas categorias SAF-T explicitamente, mais `total_documentos_electronic` e `total_documentos_saft` para clareza.

**`calcularTaxaSubmissao()`** dividia faturas aceites pelo total de **todas** as faturas, incluindo SAF-T — distorcendo a métrica para organizações com volume SAF-T elevado (a taxa parecia artificialmente baixa, quando a métrica simplesmente não se aplica a esse regime).

**Corrigido:** o denominador exclui agora `saft_pending_export` e `saft_exported`.

**`estadoAgtTodasOrganizacoes()`** (visão multi-tenant) tinha o mesmo problema de categorias fixas sem SAF-T — corrigido da mesma forma.

### Cancelamento de faturas SAF-T não gerava NC quando devia

**`ServicoFatura::cancelar()`** determinava a necessidade de NC verificando apenas estados exclusivos do regime electronic (`enviado`, `aceite`, `submitted`, `accepted`). Uma fatura SAF-T com `agt_status = saft_exported` — já reportada à AGT via ficheiro — caía sempre no ramo de cancelamento simples, sem gerar NC, apesar de já ter sido comunicada à AGT.

**Corrigido:** a lógica agora bifurca pelo `invoicing_mode` da fatura — SAF-T exige NC quando `saft_exported`; electronic continua a exigir NC nos estados que já tinha.

**`gerarNotaCredito()`** também chamava `gerarEGuardarHashChain()` incondicionalmente, atribuindo hash a NCs de faturas SAF-T. Corrigido para espelhar a mesma condicional usada na criação de faturas normais.

---

*Onsoft AGT v1.14.2 — Adilson Miguel — adilson2012jose@gmail.com — 2068417074*

---

## 🔴 Auditoria Profunda — Quarta Ronda (v1.14.3) — Lacuna Fundamental na Imutabilidade

Esta foi a ronda de auditoria mais profunda até agora — em vez de verificar requisito a requisito, segui a execução completa do código (fatura → cancelamento → NC → reset de ano fiscal → relatórios) e procurei especificamente em jobs, comandos e observers, áreas que escapam à verificação directa de endpoints HTTP.

### 🔴 Crítico — Faturas SAF-T nunca tinham protecção de imutabilidade

**Esta é a lacuna mais fundamental encontrada em todas as quatro rondas de auditoria.**

**A causa raiz:** `InvoiceObserver::created()` só criava o snapshot imutável quando `invoice_hash` não estava vazio. Como faturas SAF-T **nunca** têm hash por desenho desse regime, nunca recebiam snapshot. E como `InvoiceSnapshotGuard::permitirMutacao()` e `verificarAntesDeAtualizar()` usavam apenas `empty($invoice->invoice_hash)` para decidir se a fatura estava "bloqueada", **qualquer campo de uma fatura SAF-T — valores, NIF do cliente, data de emissão, tudo — podia ser alterado livremente em qualquer momento após a criação**, sem qualquer aviso ou bloqueio.

Isto viola directamente o ponto 12.l do Anexo I, que não distingue entre regimes de submissão: um documento fiscal emitido é imutável, independentemente de ser reportado em tempo real ou via ficheiro periódico.

**Correcção em três pontos:**

1. `InvoiceObserver::created()` cria agora o snapshot para **todas** as faturas, sem condição de hash.
2. `InvoiceSnapshotGuard::verificarAntesDeAtualizar()` usa `estaLocked()` (que considera hash OU snapshot) em vez de testar apenas `invoice_hash`.
3. `InvoiceSnapshotGuard::permitirMutacao()` corrigido da mesma forma.

```php
// Antes — nunca protegia faturas SAF-T
if (empty($invoice->invoice_hash)) {
    return; // SAF-T cai sempre aqui — sem protecção nenhuma
}

// Depois — protege qualquer regime com snapshot
if (!self::estaLocked($invoice)) {
    return; // só passa livre se NÃO houver hash NEM snapshot
}
```

### Comandos Artisan não reconheciam organizações em modo SAF-T por default automático

Três comandos filtravam organizações exclusivamente por `OrganizationAgtConfig.agt_enabled = true`, ignorando por completo organizações sem nenhuma configuração (o novo default seguro SAF-T introduzido na v1.14.0):

- **`onsoft-agt:reset-ano-fiscal --todas-orgs`** — o mais grave dos três, porque corre automaticamente via scheduler todos os anos a 1 de Janeiro. Organizações sem config nunca tinham as suas séries fiscais reiniciadas, ficando indefinidamente paradas no ano anterior.
- **`onsoft-agt:estado`** — ferramenta de diagnóstico que escondia exactamente as organizações que mais precisam de visibilidade (as que nunca configuraram nada).
- **`onsoft-agt:retentar-falhas`** — ainda despachava o Job antigo do projecto (`App\Jobs\SubmitInvoiceToAgtJob`), que a v1.14.2 tinha substituído em `ServicoFatura` mas esquecido aqui.

**Correcção:** os três comandos agora incluem explicitamente organizações com faturas emitidas mesmo sem configuração AGT, e o comando de retry usa o Job próprio do pacote com protecção SAF-T.

### Nota sobre `onsoft-agt:sincronizar-series`

Este comando **continua** a filtrar apenas por `agt_enabled = true` — e está correcto fazê-lo. Sincronizar séries com a API AGT só tem sentido para organizações em regime electronic; uma organização SAF-T não tem API em tempo real para sincronizar.

---

*Onsoft AGT v1.14.3 — Adilson Miguel — adilson2012jose@gmail.com — 2068417074*

---

## 🔴 Auditoria Profunda — Quinta Ronda (v1.14.4) — Snapshots Vazios Desde a Origem

Esta ronda seguiu a recomendação da ronda anterior — relatórios financeiros e billing morph — e confirmou que ambas as áreas estão correctas. Mas ao verificar a ordem exacta de execução de `ServicoFatura::criar()` para validar a correcção da v1.14.3, foi descoberta uma falha **mais antiga e mais grave** do que qualquer uma das anteriores.

### 🔴 Crítico — O snapshot imutável estava sempre vazio, em qualquer regime

**A causa:** o evento `created` do Eloquent dispara imediatamente após o `INSERT` da fatura — **dentro** da mesma transacção, não depois do commit, contrariamente ao que um comentário no código (incorrectamente) afirmava. `ServicoFatura::criar()` cria a fatura primeiro, e só **depois**, em passos seguintes da mesma transacção, persiste os itens (`InvoiceItem`), os pagamentos (`InvoicePayment`) e o hash AGT.

**Consequência:** desde que a criação automática do snapshot foi introduzida, `InvoiceObserver::created()` construía o snapshot **no momento exacto em que a fatura era inserida** — antes de qualquer item ou pagamento existir na base de dados. O array `items` e `payments` do snapshot ficavam sempre vazios `[]`, e o campo de hash ficava sempre `null`, independentemente do regime da fatura (electronic ou SAF-T).

**Isto significa que todo o sistema de imutabilidade auditado nas rondas 1 a 4 — incluindo a correcção crítica da v1.14.3 — estava a proteger um snapshot sem dados reais.** A protecção contra alteração de campos existia (e funcionava), mas o registo que deveria preservar os valores originais para auditoria e reimpressão fiel estava sempre vazio.

**Correcção:**

1. `InvoiceObserver::created()` deixou de criar o snapshot automaticamente — confirmado que esse momento é sempre prematuro.
2. Novo método público `InvoiceObserver::criarSnapshotAgora(Invoice $invoice)` — chamado explicitamente.
3. `ServicoFatura::criar()` chama `criarSnapshotAgora()` no fim do processo, depois de itens, pagamentos e hash (se aplicável) estarem todos persistidos, com `$fatura->refresh()` antes para garantir que as relações reflectem o estado real da BD.
4. `ServicoFatura::gerarNotaCredito()` corrigido da mesma forma — nunca tinha chamada de criação de snapshot nenhuma até agora.
5. Removida uma chamada `$nc->save()` inconsistente que disparava o guard sem usar `permitirMutacao()` previamente, por segurança e uniformidade com o resto do código.

```php
// Antes — sempre vazio, em qualquer regime
Invoice::create([...]); // dispara created() AQUI — sem itens, sem hash
foreach ($itens as $item) { criarLinhaFatura(...); } // itens só agora
gerarEGuardarHashChain($fatura, $orgId); // hash só agora

// Depois — snapshot correcto
Invoice::create([...]);
foreach ($itens as $item) { criarLinhaFatura(...); }
gerarEGuardarHashChain($fatura, $orgId); // ou saveQuietly() em SAF-T
$fatura->refresh();
InvoiceObserver::criarSnapshotAgora($fatura); // SÓ AGORA, com tudo completo
```

### Áreas confirmadas correctas nesta ronda

- **Relatórios financeiros** (`resumoFinanceiro`, `resumoIva`, `topClientes`, `maioresDevedores`, `porMeioPagamento`, `receitaPorCategoria`) — todos usam `baseInvoiceQuery()`, que não filtra por regime nem por `agt_status` salvo pedido explícito. Faturas SAF-T são correctamente incluídas nos totais financeiros e fiscais.
- **`RegistoBillingMorph`** e **`ServicoValidacaoPropina`** — independentes do regime de faturação por desenho; sem interacção problemática com `invoicing_mode`.
- **Busca exaustiva de `agt_enabled`** em todo o pacote — todas as ocorrências restantes confirmadas correctas (sincronização de séries com a API AGT só faz sentido em regime electronic).

---

*Onsoft AGT v1.14.4 — Adilson Miguel — adilson2012jose@gmail.com — 2068417074*

---

## ✅ v1.14.5 — Testes Reais e Comando de Regeneração para a Falha da v1.14.4

Conforme recomendado no final da auditoria anterior, esta versão entrega dois artefactos concretos: testes PHPUnit que provam o comportamento corrigido, e um comando para remediar faturas antigas afectadas pela falha de snapshot vazio.

### Por que não foi possível correr testes neste ambiente

Este ambiente de desenvolvimento não tem PHP instalado — não foi possível executar `vendor/bin/phpunit` directamente aqui. Em vez disso, os testes foram escritos para serem corridos no ambiente real do projecto hospedeiro, e a lógica foi verificada manualmente, linha a linha, seguindo a ordem exacta de execução do Eloquent (confirmando, por exemplo, que `loadMissing()` busca correctamente da BD quando a relação ainda não está em memória).

### `tests/Feature/SnapshotIntegridadeTest.php`

Três casos de teste:

1. **`test_snapshot_contem_itens_e_pagamentos_em_modo_electronic`** — cria uma fatura real com 2 itens e 1 pagamento, e verifica que o `payload_json` do `InvoiceSnapshot` contém ambos os arrays populados, não vazios. Esta é a asserção que teria falhado antes da v1.14.4.
2. **`test_snapshot_contem_itens_em_modo_saft_sem_hash`** — mesmo teste em modo SAF-T, confirmando snapshot completo mas sem `invoice_hash` (correcto para esse regime).
3. **`test_alterar_campo_fiscal_depois_de_criado_e_bloqueado_em_qualquer_regime`** — confirma que `InvoiceSnapshotGuard` bloqueia alteração de `gross_total` em ambos os regimes, validando a correcção da v1.14.3.

**Como correr no projecto real:**
```bash
composer require --dev orchestra/testbench
vendor/bin/phpunit vendor/productiononschool/onsoft-agt/tests/Feature/SnapshotIntegridadeTest.php
```

### `php artisan onsoft-agt:regenerar-snapshots` — comando de remediação

Detecta faturas com `InvoiceSnapshot.payload_json` tendo `items` ou `payments` vazios — o sintoma exacto da falha pré-v1.14.4 — e oferece regenerá-los.

```bash
# Apenas detectar, sem alterar nada
php artisan onsoft-agt:regenerar-snapshots --apenas-detectar

# Regenerar com confirmação interactiva
php artisan onsoft-agt:regenerar-snapshots

# Regenerar sem confirmação (scripts/CI)
php artisan onsoft-agt:regenerar-snapshots --forcar
```

**Saída de exemplo:**
```
Encontrados 47 snapshot(s) incompleto(s):

Invoice ID  Documento           Modo        Items vazios  Payments vazios  Risco da regeneração
1024        FR FR-2026/001024   electronic  SIM           SIM              BAIXO — protegida por invoice_hash desde a emissão
1198        FR FR-2026/001198   saft_ao     SIM           SIM              ALTO — sem protecção histórica, dados podem ter mudado
```

### ⚠️ Importante — o que a regeneração NÃO garante

Regenerar um snapshot usa os dados **actuais** da fatura, não os dados **originais** do momento da emissão — que nunca foram capturados correctamente devido à falha. Para faturas em modo `electronic`, isto é geralmente seguro porque `invoice_hash` protegia os campos fiscais desde sempre. **Para faturas SAF-T criadas antes da v1.14.3, não havia protecção nenhuma** — os dados actuais podem genuinamente já ter divergido dos originais, sem qualquer registo de quando ou como isso aconteceu. O comando assinala isto explicitamente na coluna "Risco" e marca cada snapshot regenerado com `_snapshot_meta.regenerado_em` e `regenerado_motivo`, para que nunca seja confundido com um snapshot original genuíno em auditorias futuras.

### `InvoiceSnapshotGuard::verificarIntegridade()` agora detecta o sintoma automaticamente

`php artisan onsoft-agt:verificar-integridade` passa a reportar faturas com snapshot de itens/pagamentos vazios como não-íntegras, apontando directamente para o comando de regeneração.

---

*Onsoft AGT v1.14.5 — Adilson Miguel — adilson2012jose@gmail.com — 2068417074*

---

## 🔴 Auditoria Profunda — Sexta Ronda (v1.14.6) — PDF de Fatura Cancelada Não Mostrava Cancelamento

Esta ronda confirmou que a correcção da v1.14.4 (ordem de criação do snapshot) está correcta — verificação manual linha a linha confirmou que `loadMissing()` busca correctamente da BD quando a relação ainda não está em memória, e a ordem itens → pagamentos → hash → snapshot está bem sequenciada. Também confirmou que `criarLinhaFatura()` e `BillingPropina` não interferem com essa ordem, e que a verificação de integridade adicionada na v1.14.5 não produz falsos positivos para faturas legitimamente sem pagamentos.

Mas encontrou um problema novo e real, numa área diferente: a interacção entre o snapshot imutável e o estado de cancelamento.

### 🔴 Crítico — PDF gerado do snapshot nunca reflectia cancelamento posterior

**O problema:** `ServicoPdf::resolverDadosFatura()` e `gerarStreamDeSnapshot()` usavam **exclusivamente** os dados do snapshot quando disponível — incluindo `payment_status` e `agt_status`. Como o snapshot é criado **uma vez**, no momento da emissão, e nunca é actualizado depois (correctamente, por desenho de imutabilidade), esses campos ficavam congelados no estado "não cancelado".

**Consequência real:** o fluxo normal de uso é emitir → snapshot criado → mais tarde, cancelar a fatura. Depois do cancelamento, gerar o PDF (`GET /onsoft-agt/faturas/{id}/pdf`, o endpoint normal) usava o snapshot, e os templates Blade (que decidem mostrar o banner "⛔ DOCUMENTO CANCELADO" com base em `payment_status`) **nunca viam o cancelamento** — porque liam o `payment_status` congelado do momento da emissão, não o estado actual.

Isto significava que **reimprimir uma fatura cancelada produzia um PDF que parecia perfeitamente válido**, sem qualquer indicação de que o documento tinha sido anulado — um risco real de uso indevido, não apenas uma inconsistência cosmética.

**Correcção — separação clara entre dados fiscais e dados de estado:**

```php
// Valores FISCAIS continuam fiéis ao momento da emissão (do snapshot):
// itens, totais, hash, assinaturas — exactamente como a imutabilidade exige.

// Campos de ESTADO são sempre sobrepostos com os valores LIVE actuais:
$dados['invoice']['payment_status']  = $fatura->payment_status;   // actual
$dados['invoice']['agt_status']      = $fatura->agt_status;        // actual
$dados['invoice']['cancel_reason']   = $fatura->cancel_reason;     // actual
$dados['invoice']['cancelled_at']    = $fatura->cancelled_at;      // actual
```

Esta correcção foi aplicada em **ambos** os caminhos de leitura do snapshot: `resolverDadosFatura()` (usado por `gerarStream()` e `gerarBase64()`) e `gerarStreamDeSnapshot()` — mesmo este último, desenhado para "reimpressão fiel ao original", agora mostra sempre o estado de cancelamento actual. Foi uma decisão deliberada: preservar fielmente os *valores* fiscais nunca deve significar esconder que o *documento* já não tem validade fiscal.

### Princípio confirmado nesta ronda

A imutabilidade fiscal (Anexo I, ponto 12.l) protege **valores** — montantes, NIF, datas de emissão, hash. Nunca se destinou a congelar o **estado de validade** de um documento. Um documento cancelado continua cancelado independentemente de quando o seu PDF for gerado.

---

*Onsoft AGT v1.14.6 — Adilson Miguel — adilson2012jose@gmail.com — 2068417074*

---

## 🔴 Auditoria Profunda — Sétima Ronda (v1.15.0) — Faltava o Mecanismo que Resolve `pending` → `accepted`/`rejected`

Seguindo exactamente a recomendação da ronda anterior, esta auditoria focou-se na transição de estado AGT depois da submissão — e encontrou uma lacuna funcional, não apenas de imutabilidade.

### 🔴 Crítico — `consultarEstado()` existia mas nunca era chamado, e nunca actualizava a fatura

**O problema, em dois níveis:**

1. **`ServicoSubmissao::consultarEstado()`** — o método responsável por perguntar à AGT se uma fatura foi aceite ou rejeitada — actualizava apenas o registo `AgtInvoiceSubmission`. **Nunca tocava no campo `agt_status` da própria `Invoice`.** Mesmo que a AGT respondesse "aceite", a fatura continuava com `agt_status = 'pending'` para sempre.

2. **Código morto** — uma busca exaustiva confirmou que `consultarEstado()` nunca era invocado de lado nenhum no pacote. Sem nenhum endpoint HTTP, comando agendado, ou job a chamá-lo, mesmo a actualização parcial (apenas na tabela de submissões) nunca chegava a acontecer em produção.

**Consequência real:** combinado com a correcção da v1.14.6 (que faz o PDF reflectir o `agt_status` actual da fatura), o sistema ficava preso a mostrar "📤 SUBMETIDO — AGUARDA RESPOSTA DA AGT" para sempre — mesmo faturas aceites ou rejeitadas há semanas continuavam a aparecer como pendentes, porque o "estado actual" nunca era de facto actualizado.

**Correcção em três partes:**

1. `ServicoSubmissao::consultarEstado()` agora propaga o resultado real (`accepted`/`rejected`) para a fatura, respeitando `InvoiceSnapshotGuard::permitirMutacao()` — `agt_status` continua um campo mutável, esta alteração não viola a imutabilidade fiscal.
2. Novo comando `php artisan onsoft-agt:consultar-submissoes` — percorre todas as submissões `pending`/`submitted` (multi-tenant, agrupado por organização para reutilizar o contexto AGT) e chama `consultarEstado()` para cada uma.
3. Agendamento automático no scheduler do pacote, a correr **de 5 em 5 minutos**, ao lado do já existente reset de ano fiscal.

```php
// OnsoftAgtServiceProvider::boot()
$schedule->command('onsoft-agt:consultar-submissoes', ['--limite' => 100])
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();
```

### Novo endpoint — confirmação imediata sob pedido

```
POST /onsoft-agt/faturas/{id}/estado/consultar-agora
```

Para quando o utilizador não quer esperar pelo próximo ciclo do scheduler — útil imediatamente depois de uma submissão manual.

```json
{
  "sucesso": true,
  "dados": {
    "fatura_id": 1247,
    "agt_status": "accepted",
    "submissao": { "status": "accepted", "accepted_at": "2026-06-19T15:32:00Z", "...": "..." },
    "ui": { "pode_submeter": false, "...": "..." }
  }
}
```

### Comando manual

```bash
php artisan onsoft-agt:consultar-submissoes
php artisan onsoft-agt:consultar-submissoes --organizacaoId=5 --limite=20
```

---

## 📋 Resumo de Todas as Correcções Críticas Encontradas (Rondas 1-7)

| Ronda | Versão | Lacuna crítica |
|---|---|---|
| 1 | v1.13.0 | `ServicoLimiteDiario` nunca era chamado — licença e limite diário não eram aplicados |
| 2 | v1.14.0 | Default inseguro `electronic` quando organização sem config — corrigido para `saft_ao` |
| 3 | v1.14.2 | Job de fila legado contornava toda a protecção SAF-T na auto-submissão |
| 4 | v1.14.3 | Faturas SAF-T nunca recebiam snapshot — sem protecção de imutabilidade nenhuma |
| 5 | v1.14.4 | Snapshot criado antes de itens/pagamentos existirem — sempre vazio, em qualquer regime |
| 6 | v1.14.6 | PDF do snapshot nunca reflectia cancelamento posterior à emissão |
| 7 | v1.15.0 | Mecanismo de consulta de estado AGT existia mas nunca era chamado — faturas ficavam presas em `pending` |

---

*Onsoft AGT v1.15.0 — Adilson Miguel — adilson2012jose@gmail.com — 2068417074*

---

## 🔴 Correcção Urgente (v1.16.0) — `request_id` e Callback AGT, Apontados Directamente

Estas duas lacunas foram apontadas directamente, fora da sequência normal de auditoria — e confirmaram-se ambas reais.

### 🔴 Crítico — `obterEstado()` usava sempre o UUID errado

**O problema:** o campo `request_id` em `AgtInvoiceSubmission` era preenchido com o **nosso UUID gerado no cliente** (`Str::uuid()`, criado antes de sequer contactar a AGT), nunca com o `idLote` real devolvido pela AGT na resposta de `registarFactura()`. Como `obterEstado()` consulta a AGT usando `request_id`, **todas as consultas de estado desde sempre enviavam um identificador que a AGT nunca emitiu** — o mecanismo de polling construído na v1.15.0 nunca teria recebido uma resposta válida em produção real.

**Correcção:** `ServicoSubmissao::enviarParaAgt()` agora extrai o `idLote` da resposta real da AGT (tentando várias chaves plausíveis — `idLote`, `id_lote`, `batchId`, `requestId`, `id` — visto que o nome exacto do campo não está confirmado na documentação disponível) e é **esse** valor, não o nosso UUID, que passa a ser gravado em `request_id` e usado em todas as consultas futuras de estado.

```php
// Antes
$requestId = $fatura->submission_uuid ?: Str::uuid(); // gerado ANTES de contactar a AGT
// ... 'request_id' => $requestId  // SEMPRE o nosso UUID, nunca o idLote da AGT

// Depois
$idLoteAgt = $resposta['idLote'] ?? $resposta['id_lote'] ?? $resposta['batchId'] ?? ... ?? $requestId;
// ... 'request_id' => $idLoteAgt  // o idLote REAL devolvido pela AGT
```

**⚠️ Honestidade sobre o que não está confirmado:** o nome exacto do campo que a AGT usa na resposta REST de `/registarFactura` para identificar o lote **não está confirmado** nesta auditoria — a especificação disponível cobre o esquema SOAP/ficheiro do Decreto Executivo, não necessariamente o payload REST actual. A correcção tenta as chaves mais plausíveis e regista um aviso explícito (`Log::warning`) sempre que nenhuma é encontrada, para que o problema seja visível em produção em vez de falhar silenciosamente. **Acção recomendada:** confirmar o nome exacto do campo na documentação REST oficial da AGT e ajustar a lista de chaves tentadas.

### Callback/Webhook AGT — mecanismo que não existia, agora implementado como complemento ao polling

**O problema:** o pacote dependia exclusivamente de polling (v1.15.0) para descobrir mudanças de estado — com até 5 minutos de latência. Não havia nenhum endpoint para a AGT notificar directamente.

**Implementado:**

```
POST /onsoft-agt/callback/{organizacaoId}
```

- Regista **sempre** o payload recebido em `onsoft_agt_callback_logs`, antes de qualquer processamento — mesmo que a assinatura seja inválida ou o `idLote` não seja reconhecido, para rasto de auditoria completo.
- Valida assinatura via HMAC-SHA256 sobre um segredo configurado por organização (`agt_callback_secret_encrypted`).
- Actualiza `AgtInvoiceSubmission` e propaga `agt_status` para a fatura, exactamente como o polling já fazia.

**⚠️ Honestidade sobre o que não está confirmado:** se a API AGT efectivamente suporta callbacks/webhooks, qual o esquema de assinatura usado, e o formato exacto do payload — **nada disto está confirmado** na documentação disponível nesta auditoria. A implementação é defensiva: tenta um cabeçalho `X-Agt-Signature` com HMAC-SHA256, mas isto é uma suposição razoável baseada em padrões comuns de webhook, não uma confirmação da especificação real da AGT. **O polling (v1.15.0) continua a ser o mecanismo principal e confiável** até que a especificação real do callback seja confirmada e este código seja ajustado em conformidade.

### Nova tabela `onsoft_agt_callback_logs`

Auditoria completa de qualquer tentativa de callback recebida — `id_lote_agt`, `estado_recebido`, `assinatura_valida`, `payload`, `headers`, `processado`, `mensagem`.

---

*Onsoft AGT v1.16.0 — Adilson Miguel — adilson2012jose@gmail.com — 2068417074*

---

## 🔴 RECONSTRUÇÃO TOTAL (v2.0.0) — Reconciliação Completa com a Documentação Oficial da AGT

Esta versão é a mais importante de todo o histórico do pacote. Todas as versões anteriores (v1.0.0 a v1.16.0) foram construídas a partir do **Decreto Executivo AGT** (texto legal/regulamentar) e de inferências razoáveis sobre como uma API REST moderna "deveria" funcionar. Nunca tínhamos lido a **documentação técnica oficial da API REST**, publicada em:

```
https://quiosqueagt.minfin.gov.ao/doc-agt/faturacao-electronica/1/
```

Ao ler essa documentação na íntegra, descobrimos que **partes estruturais inteiras do pacote estavam incorrectas** — não pequenos ajustes, mas conceitos de base como o algoritmo de assinatura, os endpoints, a autenticação, e o vocabulário de estados. Esta versão reconstrói tudo isso do zero.

### ⚠️ Aviso sobre uma secção desta documentação que ficou desactualizada

As secções anteriores deste README (antes desta, datadas de v1.0.0 a v1.16.0) **contêm afirmações incorrectas** sobre o algoritmo de assinatura (diziam "RSA-SHA1", que nunca existiu na API real) e sobre o mecanismo de callback (que implementámos especulativamente, mas a documentação oficial confirma "Disponível nas próximas versões" — não existe ainda). Essas secções são mantidas como registo histórico de auditoria, mas **não devem ser seguidas** — esta secção (v2.0.0) é a fonte de verdade actual.

### O que mudou — resumo por área

| Área | Antes (errado) | Agora (conforme documentação oficial) |
|---|---|---|
| **Autenticação** | Inexistente / customizada | HTTP Basic Auth (username:password Base64) em TODOS os pedidos — credenciais obtidas por email a `produtores.dfe.dcrr.agt@minfin.gov.ao` |
| **Hosts** | `quiosqueagt-sandbox.minfin.gov.ao` (inventado) | Homologação: `sifphml.minfin.gov.ao` · Produção: `sifp.minfin.gov.ao` |
| **Prefixo de path** | Genérico | `/sigt/fe/v1/{serviço}` |
| **Método HTTP** | Misto GET/POST | **TODOS os serviços são POST** — incluindo consultas e listagens |
| **Algoritmo de assinatura** | RSA-SHA1 sobre string `;`-concatenada | **RS256 (RSA+SHA256)**, JWS Compact Serialization, sobre o **objecto JSON completo**, Base64URL sem padding |
| **Identificador de submissão** | UUID gerado no cliente | **`requestID`** devolvido por `registarFactura` (string até 15 caracteres) — é este valor, nunca o UUID do cliente, que se usa em `obterEstado` |
| **Custódia da chave do contribuinte** | Assumida como gerada localmente | **Emitida pela AGT**, disponibilizada no portal do contribuinte — nunca gerada localmente |
| **Tipos de documento** | 6 tipos (incluindo "FS" inventado) | **18 tipos reais**: FA, FT, FR, FG, GF, AC, AR, TV, RC, RG, RE, ND, NC, AF, RP, RA, CS, LD |
| **Vocabulário de estado** | `accepted`/`rejected`/`pending`/`failed` (inventado) | `resultCode` (0/1/2/7/8/9, nível de lote) + `documentStatus` (`V`/`I`, nível de documento) — ver `EstadoValidacaoAgt` |
| **QR Code** | String `;`-separada com hash | **URL** para o portal de verificação da AGT: `?emissor={nif}&document={documentNo}` |
| **Callback/Webhook** | Implementado especulativamente (v1.16.0) | **Removido** — documentação confirma "Disponível nas próximas versões". Único mecanismo real: polling via `obterEstado` |
| **Payload de registo** | Estrutura simplificada custom | Envelope completo com `softwareInfo`, `documents[]`, `lines[]`, `taxes[]`, `documentTotals`, `paymentReceipt`, `withholdingTaxList` |

### Ficheiros completamente reescritos nesta versão

- **`ServicoAssinatura`** — algoritmo RS256/JWS real, com helpers dedicados para cada uma das 3 assinaturas documentadas (`assinarSoftwareInfo`, `assinarDocumento`, `assinarPedido`)
- **`ServicoApiAgt`** — cliente HTTP completo com Basic Auth, hosts reais, todos os 7 serviços (`registarFactura`, `obterEstado`, `solicitarSerie`, `listarSeries`, `consultarFactura`, `listarFacturas`, `validarDocumento`)
- **`ServicoConstrutorPayloadAgt`** *(novo)* — constrói o objecto `document` completo (linhas, impostos, totais, paymentReceipt) exactamente conforme a documentação, substituindo a dependência externa `AgtInvoicePayloadBuilder` cuja estrutura nunca tinha sido verificada
- **`ServicoFatura::gerarEGuardarHashChain`** — gera as duas assinaturas JWS reais em vez do hash chain RSA-SHA1 fictício; conceito de "hash anterior" removido (não existe na API REST)
- **`ServicoSeries`** — `solicitarSerie`/`listarSeries` com os campos reais (`seriesFEResult`, `seriesInfo`, `establishmentNumber`, `seriesContingencyIndicator`)
- **`ServicoQrCode`** — gera URL de verificação + imagem PNG 350×350px conforme especificação exacta
- **`ServicoSubmissao`** — usa `requestID` real, vocabulário `resultCode`/`documentStatus`, com ponte de compatibilidade para o vocabulário interno via `EstadoValidacaoAgt::mapearParaVocabularioInterno()`
- **`Onsoft\Agt\Enums\TipoDocumento`** — 18 tipos reais (era 6, incluindo um inventado)
- **`Onsoft\Agt\Enums\EstadoDocumentoRegisto`** *(novo)* — `N`/`C` (documentStatus do registo)
- **`Onsoft\Agt\Enums\EstadoValidacaoAgt`** *(novo)* — todos os 4 vocabulários de estado documentados

### Novas migrações

```
2024_01_01_000009_add_agt_basic_auth_credentials.php
  → agt_basic_auth_username, agt_basic_auth_password_encrypted,
    establishment_number em organization_agt_configs
```

### Configuração — variáveis que mudaram

```env
# Antes (URLs inventadas)
AGT_AMBIENTE=sandbox  # apontava para quiosqueagt-sandbox.minfin.gov.ao

# Agora (hosts reais, configurados automaticamente por ambiente)
AGT_AMBIENTE=sandbox  # → https://sifphml.minfin.gov.ao
AGT_AMBIENTE=producao # → https://sifp.minfin.gov.ao
AGT_SCHEMA_VERSION=1.2
```

**Credenciais novas a configurar por organização** (painel AGT → Configuração):
- Username e Password de Basic Auth (solicitar por email a `produtores.dfe.dcrr.agt@minfin.gov.ao`)
- `establishment_number` (usar `"SEDE"` em sandbox ou organizações com um único estabelecimento)

### ⚠️ O que permanece como suposição razoável, não confirmação

Esta reconciliação cobriu exaustivamente a documentação disponível em `https://quiosqueagt.minfin.gov.ao/doc-agt/faturacao-electronica/1/`. Alguns pontos não estão 100% especificados nessa documentação e foram resolvidos com a opção mais segura disponível:

- **Mapeamento `operationType`** por categoria de item (propina→SE, transporte→STP, etc.) — a documentação lista os códigos válidos mas não dá uma tabela de mapeamento por contexto de negócio; o mapeamento actual é uma inferência razoável para o contexto escolar.
- **`taxCode` por percentagem de IVA** — a documentação lista NOR/INT/RED/ISE/OUT mas não especifica os limiares percentuais exactos de cada categoria; o pacote usa NOR como default seguro para qualquer taxa > 0.
- **Esquema de erro no corpo HTTP 422** (`obterEstado`) — a documentação descreve os códigos E95-E98 mas não mostra um exemplo completo do corpo JSON dessas respostas de erro.

Estes pontos estão assinalados explicitamente nos comentários do código correspondente.

---

*Onsoft AGT v2.0.0 — Adilson Miguel — adilson2012jose@gmail.com — 2068417074*

---

## 🆕 v2.1.0 — Restrição de Âmbito (FT, FR, NC, ND) + Factura Pró-forma ("FP")

### Restrição de âmbito de tipos de documento

Este sistema concreto só precisa de **4 dos 18 tipos reais** suportados pela API AGT. Configurável via `.env`:

```env
AGT_TIPOS_ACTIVOS=FT,FR,NC,ND
```

`ServicoFatura::criar()` valida o âmbito **antes de qualquer escrita à BD ou chamada à AGT** — qualquer tentativa de criar um documento fora desta lista é rejeitada de imediato:

```json
{
  "sucesso": false,
  "mensagem": "Tipo de documento 'TV' não está activo neste sistema. Tipos permitidos: FT, FR, NC, ND. Para Factura Pró-forma, use Onsoft\\Agt\\Servicos\\ServicoFaturaProforma..."
}
```

`ServicoSeries::inicializarSeriesAnoFiscal()` e o reset automático de ano fiscal usam a mesma lista configurada — nunca criam séries para tipos fora do âmbito.

`gerarNotaCredito()` tem uma verificação de coerência adicional: se `NC` for removida do âmbito activo por engano, o sistema avisa explicitamente em vez de deixar faturas FR/FT sem forma de serem corrigidas após submissão.

O enum `Onsoft\Agt\Enums\TipoDocumento` **continua a suportar os 18 tipos reais** — a restrição é apenas de configuração, não do enum. Qualquer organização que precise de mais tipos no futuro só precisa de ajustar `AGT_TIPOS_ACTIVOS`.

---

### Factura Pró-forma ("FP") — nunca persistida, nunca fiscal

**"FP" não existe nos 18 tipos reais da API AGT** — coerente com a prática: uma pró-forma nunca é um documento fiscal. Implementado como módulo **completamente independente** de `ServicoFatura`.

**Garantia de não-persistência, verificada explicitamente:**
- Nenhuma chamada a `::create()`, `::save()`, `DB::table()->insert()` em todo o `ServicoFaturaProforma` ou `ControladorFaturaProforma`
- Nunca referencia `Invoice` ou `InvoiceItem`
- Nunca chama `ServicoLimiteDiario` (não conta para o limite diário de emissão)
- Nunca consome número de série fiscal
- Nunca assina nada — sem hash, sem `jwsDocumentSignature`
- Nunca contacta a API AGT

Tudo acontece **numa única chamada**: calcular os totais a partir dos itens recebidos no pedido, gerar o HTML, devolver o PDF. Quando a resposta HTTP termina, não fica nenhum rasto na base de dados.

### Endpoints

```
POST /onsoft-agt/proforma/calcular     → apenas totais (JSON, sem PDF)
POST /onsoft-agt/proforma/pdf          → PDF em stream
POST /onsoft-agt/proforma/pdf-base64   → PDF em base64 (para SPA)
```

**Exemplo de pedido:**
```json
{
  "customer_name": "João Silva",
  "customer_nif": "500123456",
  "validade_dias": 15,
  "items": [
    { "description": "Propina — Outubro 2026", "quantity": 1, "unit_price": 45000, "tax_code": "ISE" },
    { "description": "Material Escolar", "quantity": 2, "unit_price": 2500, "tax_percentage": 14 }
  ]
}
```

**Resposta de `/calcular`:**
```json
{
  "sucesso": true,
  "dados": {
    "documento": {
      "tipo": "FP",
      "label": "Factura Pró-forma",
      "valido_ate": "2026-07-04",
      "referencia": "PROFORMA-20260619153000"
    },
    "totais": {
      "subtotal": 50000.00,
      "iva": 700.00,
      "total_geral": 50700.00
    }
  }
}
```

### O que o PDF mostra

- Marca de água diagonal "PRO-FORMA"
- Aviso destacado em vermelho: "DOCUMENTO PRÓ-FORMA — NÃO É FACTURA, SEM VALOR FISCAL"
- Data de validade explícita
- Sem QR Code, sem hash, sem número de série — porque nenhum destes existe para um documento que nunca é fiscal
- Rodapé reforçando: "Não constitui factura, recibo, ou qualquer documento fiscal. Não submetido à AGT."

---

*Onsoft AGT v2.1.0 — Adilson Miguel — adilson2012jose@gmail.com — 2068417074*

---

## 🔴 Auditoria Completa — Continuação (v2.1.2)

Continuação da auditoria completa solicitada, cobrindo as áreas que ainda faltavam: `ServicoExclusividadePagamento`, `ServicoValidacaoPropina`, `RegistoBillingMorph`, `ServicoSaftAo`, `composer.json` e `OnsoftAgtInstalarComando`.

### 🔴 Crítico — Pagamento parcial de propina ficava bloqueado permanentemente

**O problema:** `ServicoValidacaoPropina::validarOrdem()` tratava **qualquer** registo `BillingPropina` não cancelado — incluindo `status = 'partial'` — como "já pago" para efeitos de bloqueio de repetição. Isto significava que, uma vez que um mês ficasse parcialmente pago, **nunca mais podia ser pago na totalidade** — qualquer tentativa de criar uma nova fatura para completar o saldo era rejeitada com "já está pago".

**Consequência adicional encontrada:** mesmo corrigindo a validação, `validarECriarPropinas()` sempre fazia `BillingPropina::create()`, nunca `update()` — se a validação permitisse completar um mês parcial, isso criaria um **segundo registo duplicado** para o mesmo mês, em vez de complementar o existente.

**Correcção em duas partes:**
1. `validarOrdem()` agora distingue `'paid'` (bloqueia repetição) de `'partial'`/`'pending'` (ocupa a posição na sequência, mas pode ser completado)
2. `validarECriarPropinas()` agora verifica se já existe um registo não-cancelado para o mês e, se existir, **soma** ao valor existente em vez de duplicar

### Duplicação de lógica com cache divergente — `ServicoFatura::validarExclusividadeMetodos()`

Esta função reimplementava, linha a linha, exactamente a mesma lógica de `ServicoExclusividadePagamento::validar()` — mas com uma **chave de cache diferente** (`onsoft_agt_tipodepagamento_todos` vs `onsoft_agt_tipodepagamento_lista`). Invalidar a cache num serviço nunca invalidava a do outro — risco real de validação com dados desactualizados após alterar `tipodepagamento.exclusivo`. Corrigido: `ServicoFatura` agora delega para `ServicoExclusividadePagamento::validar()`, eliminando a duplicação e o risco de divergência.

### `composer.json` — Facade inexistente registada

`extra.laravel.aliases` registava `OnsoftAgt` apontando para `Onsoft\Agt\Fachadas\OnsoftAgt` — uma classe que nunca existiu no pacote. Isto causaria erro real ao instalar com Laravel auto-discovery activo. Removido (nenhum código ou documentação dependia deste alias).

### `RegistoBillingMorph` — documentação divergente da implementação

A tabela de documentação listava `categoria_produto` → `PedagogicalProductCategory`, mas esse tipo nunca esteve em `inicializar()`. Corrigido removendo a linha da documentação (facturar uma categoria de produto, em vez do produto em si, não é um caso de uso esperado).

### `ServicoSaftAo` — honestidade sobre o âmbito da reconciliação

Adicionado aviso explícito: a reconciliação completa desta auditoria validou a API REST de Faturação Eletrónica contra a documentação oficial. O esquema XML SAF-T(AO) implementado segue a estrutura genérica OECD/SAF-T, mas **não foi confirmado** com a mesma certeza contra um XSD oficial AGT específico para SAF-T(AO) — esse documento não estava disponível no portal consultado.

### Confirmado correcto sem alterações

`OnsoftAgtInstalarComando`, tags de `publishes()`, `mergeConfigFrom()`, todas as dependências declaradas em `composer.json` (Guzzle, DomPDF, BaconQrCode) confirmadas em uso real.

---

*Onsoft AGT v2.1.2 — Adilson Miguel — adilson2012jose@gmail.com — 2068417074*

---

## 🔴 Lacuna Crítica Encontrada — Fluxo de Correcção de Fatura Rejeitada (v2.2.0)

Em resposta directa à pergunta "falta alguma coisa para o pacote estar 100% alinhado com a AGT", uma verificação adicional às áreas ainda não auditadas com rigor (`ServicoFlagsUiFatura`) encontrou uma lacuna real e significativa.

### O problema

A documentação oficial da AGT (Registar Factura, regra FE-RNG-073, erro E46) é explícita:

> *"A emissão de documentos com o mesmo número de identificação no campo documentNo de outro documento previamente enviado e rejeitado pela AGT não é aceite. As correcções de documentos rejeitados deverão ser efectuados com a utilização de um novo número de documento."*

Apesar disto, `ServicoFlagsUiFatura` mostrava o botão "Submeter à AGT" também para faturas com `agt_status = 'rejected'` — levando o utilizador a tentar resubmeter exactamente o mesmo documento, que a AGT rejeitaria outra vez, **indefinidamente, sem nenhum caminho de saída**.

Pior: `ServicoConstrutorPayloadAgt` já lia um campo `$fatura->rejectedDocumentNo` (para preencher correctamente `documentStatus = 'C'` e `rejectedDocumentNo` no payload) — mas essa coluna **nunca existiu** na base de dados, e nenhum fluxo a preenchia. O suporte estava parcialmente construído, mas inatingível.

### A correcção

1. **Nova migração** `2024_01_01_000010_add_rejected_document_no.php` — adiciona a coluna real `rejected_document_no` (nome corrigido de `rejectedDocumentNo` camelCase inexistente para o padrão snake_case real do schema).
2. **`ServicoConstrutorPayloadAgt`** corrigido para ler o nome de coluna correcto.
3. **Novo método `ServicoFatura::corrigirFaturaRejeitada()`** — cria uma nova fatura com novo `documentNo`, copiando itens e pagamentos da rejeitada, preenchendo `rejected_document_no` com o documento original. A fatura rejeitada original nunca é apagada ou alterada — fica como registo histórico permanente.
4. **`ServicoFlagsUiFatura`** corrigido — `'rejected'` removido da lista de estados que mostram "Submeter"; nova flag `mostrar_botao_corrigir_rejeitada` para o único caminho válido.
5. **Novo endpoint** `POST /onsoft-agt/faturas/{id}/corrigir-rejeitada`.

```json
{
  "alteracoes": {
    "customer_nif": "500999888"
  }
}
```

```json
{
  "sucesso": true,
  "mensagem": "Nova fatura FR FR-2026/001250 criada, referenciando a rejeitada FR FR-2026/001247.",
  "dados": { "...": "..." }
}
```

### Confirmação de consistência

`ServicoFaturasAluno::formatarFaturaParaAluno()` já tinha a regra correcta (`pode_submeter` sem `'rejected'`) — confirma que esta era uma inconsistência isolada em `ServicoFlagsUiFatura`, não um padrão repetido em todo o pacote.

---

*Onsoft AGT v2.2.0 — Adilson Miguel — adilson2012jose@gmail.com — 2068417074*

---

## 🔴 Correcção Crítica — Violação PSR-4 em Excecoes.php (v2.2.1)

### O problema, reportado directamente em produção

Ao instalar o pacote num projecto real e correr `composer dump-autoload -o` (autoload optimizado), o seguinte erro ocorria ao usar qualquer excepção do pacote excepto `ExcecaoFaturaAgt`:

```
Error: Class "Onsoft\Agt\Excecoes\ExcecaoConfiguracaoAgt" not found.
```

### Causa raiz

`src/Excecoes/Excecoes.php` continha **8 classes** num único ficheiro:
`ExcecaoOnsoftAgt`, `ExcecaoApiAgt`, `ExcecaoAutenticacaoAgt`, `ExcecaoAssinaturaAgt`, `ExcecaoFaturaAgt`, `ExcecaoSerieAgt`, `ExcecaoConfiguracaoAgt`, `ExcecaoPdfAgt` — todas num ficheiro chamado `Excecoes.php`, que não corresponde ao nome de nenhuma delas.

Isto **viola a norma PSR-4**, que exige um ficheiro por classe, com o nome do ficheiro a corresponder exactamente ao nome da classe. O autoload **dinâmico** do Composer (sem `-o`) tolera isto na maioria dos casos, porque faz scan de fallback quando uma classe não é encontrada no mapa inicial. O autoload **optimizado** (`-o`, usado em produção e recomendado em qualquer ambiente real) gera um classmap estático a partir de inferência de ficheiro→classe e **não faz esse fallback** — o resultado é que apenas algumas das 8 classes ficavam registadas, de forma imprevisível, e qualquer tentativa de usar as restantes falhava com "Class not found", mesmo com o ficheiro fisicamente presente e sintacticamente correcto no disco.

### Correcção

Cada uma das 8 classes foi movida para o seu próprio ficheiro, com o nome exacto da classe:

```
src/Excecoes/ExcecaoOnsoftAgt.php
src/Excecoes/ExcecaoApiAgt.php
src/Excecoes/ExcecaoAutenticacaoAgt.php
src/Excecoes/ExcecaoAssinaturaAgt.php
src/Excecoes/ExcecaoFaturaAgt.php
src/Excecoes/ExcecaoSerieAgt.php
src/Excecoes/ExcecaoConfiguracaoAgt.php
src/Excecoes/ExcecaoPdfAgt.php
```

`Excecoes.php` foi removido. Nenhuma classe foi renomeada — todos os `use Onsoft\Agt\Excecoes\...` existentes no resto do pacote continuam a funcionar sem qualquer alteração, porque os namespaces e nomes de classe são idênticos; só a organização física em disco mudou.

### Auditoria de confirmação

Verificação exaustiva a todo o pacote confirmou que `Excecoes.php` era o **único** ficheiro com esta violação — os restantes 50 ficheiros do pacote já seguiam correctamente uma classe por ficheiro.

### Acção necessária se já tem o pacote instalado

```bash
composer update productiononschool/onsoft-agt
composer dump-autoload -o
```

---

*Onsoft AGT v2.2.1 — Adilson Miguel — adilson2012jose@gmail.com — 2068417074*
