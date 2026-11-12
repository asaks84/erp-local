<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Cartão de crédito: da empresa (a fatura é paga pela empresa) ou pessoal do sócio (só entram as compras marcadas). */
class Cartao extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'cartoes';

    public const TIPOS = ['empresa' => 'Da empresa', 'pessoal' => 'Pessoal (do sócio)'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function faturas(): HasMany
    {
        return $this->hasMany(FaturaCartao::class);
    }

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class);
    }
}
