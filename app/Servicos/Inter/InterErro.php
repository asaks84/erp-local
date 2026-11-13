<?php

namespace App\Servicos\Inter;

use RuntimeException;

/** Erro vindo do Banco Inter (ou da configuração), com mensagem para mostrar na tela. */
class InterErro extends RuntimeException {}
