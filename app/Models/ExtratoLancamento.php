<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Entrada do extrato bancário já processada (o mesmo PIX não baixa duas vezes). */
class ExtratoLancamento extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'extrato_lancamentos';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['data' => 'date:Y-m-d', 'valor' => 'decimal:2', 'total_contas' => 'decimal:2', 'processado_em' => 'datetime'];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function pagamento(): BelongsTo
    {
        return $this->belongsTo(Pagamento::class);
    }
}
