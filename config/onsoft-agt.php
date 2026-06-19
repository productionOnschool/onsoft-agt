<?php

/**
 * ============================================================
 * ONSOFT AGT — Configuração do Pacote de Faturação Eletrónica
 * ============================================================
 * Desenvolvedor: Adilson Miguel
 * Email: adilson2012jose@gmail.com
 * Telefone: 2068417074
 * ============================================================
 */

return [

    /*
    |------------------------------------------------------------------
    | Ambiente AGT
    |------------------------------------------------------------------
    | Hosts REAIS conforme documentação oficial:
    | https://quiosqueagt.minfin.gov.ao/doc-agt/faturacao-electronica/1/
    |
    | 'sandbox'  => https://sifphml.minfin.gov.ao (homologação)
    | 'producao' => https://sifp.minfin.gov.ao
    |
    | O prefixo de path (/sigt/fe/v1/{serviço}) é construído por
    | endpoint em ServicoApiAgt — não incluir aqui.
    */
    'ambiente' => env('AGT_AMBIENTE', 'sandbox'),

    'urls_base' => [
        'sandbox'  => 'https://sifphml.minfin.gov.ao',
        'producao' => 'https://sifp.minfin.gov.ao',
    ],

    /*
    |------------------------------------------------------------------
    | Versão do schema dos pedidos (campo "schemaVersion" no envelope)
    |------------------------------------------------------------------
    */
    'schema_version' => env('AGT_SCHEMA_VERSION', '1.2'),

    /*
    |------------------------------------------------------------------
    | Portal do Parceiro (registo da chave pública do SOFTWARE)
    |------------------------------------------------------------------
    | Testes:   https://portaldoparceiro.hml.minfin.gov.ao/
    | Produção: https://portaldoparceiro.minfin.gov.ao/
    | Apenas para referência/documentação — não usado em chamadas HTTP.
    */
    'portal_parceiro' => [
        'sandbox'  => 'https://portaldoparceiro.hml.minfin.gov.ao/',
        'producao' => 'https://portaldoparceiro.minfin.gov.ao/',
    ],

    /*
    |------------------------------------------------------------------
    | Modo Multi-tenant
    |------------------------------------------------------------------
    | true  => cada organização tem a sua chave de CONTRIBUINTE na BD
    | false => sistema single-tenant, tudo em .env
    |
    | NOTA: A chave do SOFTWARE é SEMPRE do fabricante (Onsoft)
    | e fica SEMPRE em .env — independentemente deste modo.
    */
    'multi_tenant' => env('AGT_MULTI_TENANT', true),

    /*
    |------------------------------------------------------------------
    | CHAVES DO SOFTWARE (Fabricante — Onsoft)
    |------------------------------------------------------------------
    | Estas chaves representam o FABRICANTE DO SOFTWARE (Onsoft/Adilson Miguel).
    | São registadas na AGT uma única vez via Declaração Modelo 8.
    | São PARTILHADAS por todas as organizações que usam este software.
    | NUNCA vão para a base de dados — ficam SEMPRE no .env do servidor.
    |
    | Para gerar o par de chaves:
    |   openssl genrsa -out software_privada.pem 2048
    |   openssl rsa -in software_privada.pem -pubout -out software_publica.pem
    |
    | Para colocar no .env numa única linha (substituir \n por \\n):
    |   awk 'NF {sub(/\r/, ""); printf "%s\\n",$0;}' software_privada.pem
    */
    'software' => [
        // Chave privada do software (fabricante) — usada para assinar jwsSoftwareSignature
        // Formato: chave PEM completa numa única linha com \n
        // Exemplo: "-----BEGIN RSA PRIVATE KEY-----\nMIIE...\n-----END RSA PRIVATE KEY-----"
        'chave_privada' => env('AGT_SOFTWARE_CHAVE_PRIVADA', ''),

        // Chave pública do software (fabricante) — entregue à AGT no Modelo 8
        'chave_publica' => env('AGT_SOFTWARE_CHAVE_PUBLICA', ''),

        // Número de certificação atribuído pela AGT ao software
        // Aparece impresso em todas as faturas: "Processado por programa validado nº 0000/AGT"
        'numero_certificacao' => env('AGT_SOFTWARE_NUMERO_CERTIFICACAO', ''),

        // Versão da chave privada (inteiro sequencial — incrementa ao mudar de chave)
        // Guardado no HashControl conforme AGT spec ponto 5.c
        'versao_chave' => env('AGT_SOFTWARE_VERSAO_CHAVE', 1),

        // Identificação do software para o bloco softwareInfo
        'nome'    => env('AGT_SOFTWARE_NOME', 'Onsoft AGT'),
        'versao'  => env('AGT_SOFTWARE_VERSAO', '1.0.0'),
        'nif_fornecedor' => env('AGT_SOFTWARE_NIF_FORNECEDOR', ''),
    ],

    /*
    |------------------------------------------------------------------
    | Credenciais do CONTRIBUINTE (single-tenant apenas)
    |------------------------------------------------------------------
    | Apenas usado quando multi_tenant = false.
    | No modo multi-tenant, cada organização tem as suas credenciais
    | de CONTRIBUINTE guardadas encriptadas na tabela organization_agt_configs.
    */
    'contribuinte' => [
        'nif'                => env('AGT_CONTRIBUINTE_NIF', ''),
        'nome'               => env('AGT_CONTRIBUINTE_NOME', ''),
        'chave_privada_path' => env('AGT_CONTRIBUINTE_CHAVE_PRIVADA_PATH', storage_path('app/agt/contribuinte_privada.pem')),
        'chave_privada_senha' => env('AGT_CONTRIBUINTE_CHAVE_PRIVADA_SENHA', ''),
    ],

    /*
    |------------------------------------------------------------------
    | Tabelas da base de dados
    |------------------------------------------------------------------
    */
    'tabelas' => [
        'series'     => 'agt_series',
        'submissoes' => 'agt_invoice_submissions',
        'logs'       => 'agt_submission_logs',
    ],

    /*
    |------------------------------------------------------------------
    | Configurações HTTP
    |------------------------------------------------------------------
    */
    'http' => [
        'timeout'         => env('AGT_HTTP_TIMEOUT', 30),
        'timeout_conexao' => env('AGT_HTTP_TIMEOUT_CONEXAO', 10),
        'verificar_ssl'   => env('AGT_VERIFICAR_SSL', true),
    ],

    /*
    |------------------------------------------------------------------
    | Polling de estado (verificação assíncrona)
    |------------------------------------------------------------------
    */
    'polling' => [
        'max_tentativas'     => env('AGT_POLL_MAX_TENTATIVAS', 10),
        'intervalo_segundos' => env('AGT_POLL_INTERVALO', 5),
    ],

    /*
    |------------------------------------------------------------------
    | Fila de trabalho (Queue)
    |------------------------------------------------------------------
    */
    'fila' => [
        'ativa'   => env('AGT_FILA_ATIVA', false),
        'conexao' => env('AGT_FILA_CONEXAO', 'default'),
        'nome'    => env('AGT_FILA_NOME', 'agt-faturas'),
    ],

    /*
    |------------------------------------------------------------------
    | Tamanho máximo do lote (máximo 30 faturas por AGT spec)
    |------------------------------------------------------------------
    */
    'tamanho_lote' => 30,

    /*
    |------------------------------------------------------------------
    | Moeda e IVA por defeito
    |------------------------------------------------------------------
    */
    'moeda_padrao'    => env('AGT_MOEDA_PADRAO', 'AOA'),
    'taxa_iva_padrao' => env('AGT_TAXA_IVA_PADRAO', 14),

    /*
    |------------------------------------------------------------------
    | PDF — Configurações de impressão por defeito
    |------------------------------------------------------------------
    | Se a tabela invoice_print_configs não tiver registo para a org,
    | estas configurações são usadas como fallback (A4 por defeito).
    */
    'pdf' => [
        'papel_padrao'     => 'A4',
        'formato_saida'    => 'pdf',
        'copias'           => 1,
        'mostrar_logo'     => true,
        'mostrar_qr_code'  => true,
        'abrir_em_memoria' => true,
    ],

    /*
    |------------------------------------------------------------------
    | NIF genérico para "Consumidor Final" (AGT spec)
    |------------------------------------------------------------------
    */
    'nif_consumidor_final' => '999999999',

    /*
    |------------------------------------------------------------------
    | Tipos de documento suportados
    |------------------------------------------------------------------
    | O enum Onsoft\Agt\Enums\TipoDocumento define os 18 tipos REAIS
    | aceites pela API AGT (FA, FT, FR, FG, GF, AC, AR, TV, RC, RG, RE,
    | ND, NC, AF, RP, RA, CS, LD) — necessário para qualquer organização
    | que precise emitir qualquer um deles.
    |
    | Este projecto concreto usa apenas um subconjunto. "tipos_activos"
    | restringe quais tipos a aplicação aceita criar/submeter — qualquer
    | tentativa de criar uma fatura com um tipo fora desta lista é
    | rejeitada por ServicoFatura::criar() antes de tocar a BD ou a AGT.
    |
    | "FP" (Factura Pró-forma) está incluído como tipo INTERNO — NÃO
    | EXISTE na documentação oficial da AGT porque pró-formas nunca são
    | documentos fiscais. Tratado inteiramente fora do ciclo de vida de
    | Invoice (ver ServicoFaturaProforma) — nunca é persistido, nunca é
    | submetido, nunca entra em série fiscal nenhuma.
    */
    'tipos_activos' => explode(',', env('AGT_TIPOS_ACTIVOS', 'FT,FR,NC,ND')),

    'tipos_documento' => [
        'FP' => 'Factura Pró-forma (documento interno — nunca enviado à AGT)',
        'FA' => 'Factura de Adiantamento',
        'FT' => 'Factura',
        'FR' => 'Factura/Recibo',
        'FG' => 'Factura Global',
        'GF' => 'Factura Genérica',
        'AC' => 'Aviso de Cobrança',
        'AR' => 'Aviso de Cobrança/Recibo',
        'TV' => 'Talão de Venda',
        'RC' => 'Recibo Emitido (numerário)',
        'RG' => 'Recibo Geral',
        'RE' => 'Estorno / Recibo de Estorno',
        'ND' => 'Nota de Débito',
        'NC' => 'Nota de Crédito',
        'AF' => 'Factura/Recibo de Autofacturação',
        'RP' => 'Prémio ou Recibo de Prémio',
        'RA' => 'Resseguro Aceite',
        'CS' => 'Imputação a Co-seguradoras',
        'LD' => 'Imputação a Co-seguradora Líder',
    ],

    /*
    |------------------------------------------------------------------
    | Meios de pagamento — apenas etiquetas para exibição (PDF, relatórios)
    |------------------------------------------------------------------
    | A FONTE DA VERDADE é a tabela `tipodepagamento` do projecto
    | (App\Models\Config\Financeiro\TipoDePagamento), incluindo o
    | `appCode` estático e as colunas `exclusivo` / `requer_consulta_online`
    | / `provider` adicionadas pela migração deste pacote.
    |
    | Este array serve apenas de fallback textual quando method_code
    | não corresponde a nenhum appCode (compatibilidade com payloads antigos).
    */
    'meios_pagamento' => [
        'wallet' => 'Saldo da Carteira',
        'NU'     => 'Numerário (Dinheiro)',
        'TB'     => 'Transferência Bancária',
        'CC'     => 'Cartão de Crédito/Débito',
        'CH'     => 'Cheque',
        'MP'     => 'Pagamento Móvel',
        'MX'     => 'Multicaixa Express',
        'CR'     => 'Crédito',
    ],

];
