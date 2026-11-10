<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;

class ServicoPreco extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'servico_precos';

    protected function casts(): array
    {
        return ['vigencia_inicio' => 'date:Y-m-d', 'vigencia_fim' => 'date:Y-m-d', 'valor' => 'decimal:2'];
    }
}
