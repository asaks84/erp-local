<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Conta a pagar (ou já paga). Nunca é apagada: cancela com motivo; pagamento errado se desfaz. */
class Despesa extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'despesas';

    protected $attributes = ['situacao' => 'aberta'];

    public const SITUACOES = ['aberta' => 'A pagar', 'paga' => 'Paga', 'cancelada' => 'Cancelada'];

    protected function casts(): array
    {
        return [
            'competencia' => 'date', 'vencimento' => 'date', 'pago_em' => 'date',
            'valor' => 'decimal:2', 'valor_pago' => 'decimal:2', 'cancelada_em' => 'datetime',
        ];
    }

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class);
    }

    public function conta(): BelongsTo
    {
        return $this->belongsTo(PlanoConta::class, 'conta_id');
    }

    public function forma(): BelongsTo
    {
        return $this->belongsTo(FormaPagamento::class, 'forma_pagamento_id');
    }

    public function contaFinanceira(): BelongsTo
    {
        return $this->belongsTo(ContaFinanceira::class, 'conta_financeira_id');
    }

    /** a pagar, vence hoje, atrasada, paga, cancelada */
    public function estado(): string
    {
        if ($this->situacao !== 'aberta') {
            return $this->situacao;
        }
        $hoje = now()->toDateString();
        $v = $this->vencimento->toDateString();

        return $v < $hoje ? 'atrasada' : ($v === $hoje ? 'hoje' : 'aberta');
    }
}
