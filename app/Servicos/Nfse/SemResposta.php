<?php

namespace App\Servicos\Nfse;

use RuntimeException;

/** o envio pode ter chegado ou não (conexão caiu): a nota fica "enviando" até conferir pela DPS */
class SemResposta extends RuntimeException {}
