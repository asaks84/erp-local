<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Empresa extends Modelo
{
    protected $table = 'empresas';

    protected $guarded = ['id'];

    protected $attributes = ['ativa' => true];

    protected function casts(): array
    {
        return ['configuracoes' => 'array', 'ativa' => 'boolean'];
    }

    public function unidades(): HasMany
    {
        return $this->hasMany(Unidade::class);
    }
}
