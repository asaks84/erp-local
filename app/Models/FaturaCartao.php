<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Fatura de um mês, importada do arquivo do banco. Vira uma conta a pagar (pelo total); as compras são classificadas. */
class FaturaCartao extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'faturas_cartao';

    protected function casts(): array
    {
        return ['inicio' => 'date', 'fechamento' => 'date', 'vencimento' => 'date', 'total' => 'decimal:2', 'saldo_arquivo' => 'decimal:2'];
    }

    public function cartao(): BelongsTo
    {
        return $this->belongsTo(Cartao::class);
    }

    public function itens(): HasMany
    {
        return $this->hasMany(FaturaItem::class, 'fatura_id');
    }

    public function despesa(): BelongsTo
    {
        return $this->belongsTo(Despesa::class);
    }
}
