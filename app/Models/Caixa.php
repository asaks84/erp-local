<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Caixa do balcão (PDV): aberto com o troco inicial, fechado uma vez por dia pelo gerente. */
class Caixa extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'caixas';

    protected $attributes = ['situacao' => 'aberto', 'troco_inicial' => 0];

    protected function casts(): array
    {
        return [
            'aberto_em' => 'datetime', 'fechado_em' => 'datetime', 'resumo' => 'array',
            'troco_inicial' => 'decimal:2', 'dinheiro_esperado' => 'decimal:2', 'dinheiro_contado' => 'decimal:2',
            'diferenca' => 'decimal:2', 'troco_deixado' => 'decimal:2',
        ];
    }

    public function movimentos(): HasMany
    {
        return $this->hasMany(CaixaMovimento::class)->orderBy('id');
    }

    public function pagamentos(): HasMany
    {
        return $this->hasMany(Pagamento::class);
    }

    public function abertoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'aberto_por');
    }

    public function fechadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'fechado_por');
    }
}
