<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;

/** Como classificar as compras de um estabelecimento: sempre a mesma conta, ou perguntar (marketplaces). */
class CartaoRegra extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'cartao_regras';
}
