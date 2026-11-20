<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Builder;

class TipoVeiculo extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'tipos_veiculo';

    protected $attributes = ['ativo' => true, 'ordem' => 0];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    /** a ordem escolhida em Configurações › Tipos de veículo; vale para o PDV, cadastros e selects */
    public function scopeOrdenados(Builder $q): Builder
    {
        return $q->orderBy('ordem')->orderBy('nome');
    }
}
