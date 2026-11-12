<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Conta fixa: gera uma conta a pagar por mês (aluguel, energia, contador...). */
class DespesaRecorrente extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'despesas_recorrentes';

    protected function casts(): array
    {
        return ['ativa' => 'boolean', 'inicio' => 'date', 'fim' => 'date', 'valor' => 'decimal:2'];
    }

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class);
    }

    public function conta(): BelongsTo
    {
        return $this->belongsTo(PlanoConta::class, 'conta_id');
    }
}
