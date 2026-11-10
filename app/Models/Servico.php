<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Lavagem ou serviço vendido (principal) e seus adicionais. */
class Servico extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'servicos';

    protected $attributes = ['ativo' => true, 'papel' => 'principal', 'preco_modo' => 'fixo'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean', 'aliquota_iss' => 'decimal:2'];
    }

    public function precos(): HasMany
    {
        return $this->hasMany(ServicoPreco::class);
    }

    /** adicionais que podem acompanhar este serviço principal */
    public function adicionais(): BelongsToMany
    {
        return $this->belongsToMany(Servico::class, 'servico_adicionais', 'servico_id', 'adicional_id');
    }
}
