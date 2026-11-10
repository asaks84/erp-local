<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;

class TipoVeiculo extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'tipos_veiculo';

    protected $attributes = ['ativo' => true, 'ordem' => 0];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }
}
