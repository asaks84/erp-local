<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Estorno ao cliente: devolução do crédito dele (Pix ou transferência feitos no app do banco). */
class Estorno extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'estornos';

    protected $attributes = ['situacao' => 'feito'];

    protected function casts(): array
    {
        return ['valor' => 'decimal:2', 'estornado_em' => 'date:Y-m-d', 'creditos' => 'array', 'desfeito_em' => 'datetime'];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function forma(): BelongsTo
    {
        return $this->belongsTo(FormaPagamento::class, 'forma_pagamento_id');
    }
}
