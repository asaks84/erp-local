<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;

class Unidade extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'unidades';

    protected $attributes = ['ativa' => true];

    protected function casts(): array
    {
        return ['ativa' => 'boolean'];
    }
}
