<?php

namespace Onsoft\Agt\Excecoes;

use Exception;

/** Excepção base do pacote Onsoft AGT */
class ExcecaoOnsoftAgt extends Exception {}

/** Erro na comunicação com a API AGT */
class ExcecaoApiAgt extends ExcecaoOnsoftAgt {}

/** Erro de autenticação AGT */
class ExcecaoAutenticacaoAgt extends ExcecaoOnsoftAgt {}

/** Erro na assinatura criptográfica */
class ExcecaoAssinaturaAgt extends ExcecaoOnsoftAgt {}

/** Erro de validação de fatura */
class ExcecaoFaturaAgt extends ExcecaoOnsoftAgt {}

/** Erro de série fiscal */
class ExcecaoSerieAgt extends ExcecaoOnsoftAgt {}

/** Configuração AGT inválida ou incompleta */
class ExcecaoConfiguracaoAgt extends ExcecaoOnsoftAgt {}

/** Erro na geração do PDF */
class ExcecaoPdfAgt extends ExcecaoOnsoftAgt {}
