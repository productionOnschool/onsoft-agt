<?php

namespace Onsoft\Agt\Excecoes;

use Exception;

/**
 * ExcecaoOnsoftAgt
 *
 * Excepção base do pacote Onsoft AGT — todas as outras excepções do
 * pacote derivam desta, permitindo capturar qualquer erro do pacote
 * com um único catch (\Onsoft\Agt\Excecoes\ExcecaoOnsoftAgt $e).
 */
class ExcecaoOnsoftAgt extends Exception {}
