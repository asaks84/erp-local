<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Conta do plano de contas (árvore única de receitas e despesas). */
class PlanoConta extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'plano_contas';

    public const TIPOS = ['receita' => 'Receitas', 'despesa' => 'Despesas'];

    protected function casts(): array
    {
        return ['ativa' => 'boolean'];
    }

    public function pai(): BelongsTo
    {
        return $this->belongsTo(self::class, 'pai_id');
    }

    public function filhas(): HasMany
    {
        return $this->hasMany(self::class, 'pai_id');
    }

    public function rotulo(): string
    {
        return $this->codigo.' '.$this->nome;
    }
}
