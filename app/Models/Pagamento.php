<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Dinheiro recebido. Dá baixa em uma ou mais ordens; nunca é apagado (estorno com motivo). */
class Pagamento extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'pagamentos';

    protected $attributes = ['situacao' => 'confirmado', 'origem' => 'gestao', 'parcelas' => 1, 'taxa' => 0];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'taxa' => 'decimal:2',
            'liquido' => 'decimal:2',
            'previsto_para' => 'date:Y-m-d',
            'recebido_em' => 'datetime',
            'estornado_em' => 'datetime',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function forma(): BelongsTo
    {
        return $this->belongsTo(FormaPagamento::class, 'forma_pagamento_id');
    }

    public function conta(): BelongsTo
    {
        return $this->belongsTo(ContaFinanceira::class, 'conta_financeira_id');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(PagamentoItem::class);
    }

    public function criador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'criado_por');
    }
}
