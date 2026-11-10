<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Agrupa as ordens em aberto de um cliente num momento. Emitida, não muda (itens copiados). */
class Cobranca extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'cobrancas';

    protected $attributes = ['situacao' => 'aberta'];

    public const SITUACOES = ['aberta' => 'Em aberto', 'parcial' => 'Parcialmente paga', 'paga' => 'Paga', 'levada' => 'Levada para a próxima', 'cancelada' => 'Cancelada'];

    protected function casts(): array
    {
        return ['emitida_em' => 'datetime', 'vencimento' => 'date:Y-m-d', 'total' => 'decimal:2', 'cancelada_em' => 'datetime'];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function itens(): HasMany
    {
        return $this->hasMany(CobrancaItem::class)->orderByRaw("grupo = 'atual'")->orderBy('data')->orderBy('numero');
    }
}
