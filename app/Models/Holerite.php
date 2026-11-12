<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Recibo de salário de um mês, importado do PDF do contador. O líquido vira uma conta a pagar. */
class Holerite extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'holerites';

    protected function casts(): array
    {
        return [
            'competencia' => 'date', 'itens' => 'array',
            'salario_base' => 'decimal:2', 'vencimentos' => 'decimal:2', 'descontos' => 'decimal:2', 'liquido' => 'decimal:2', 'fgts' => 'decimal:2',
        ];
    }

    public function funcionario(): BelongsTo
    {
        return $this->belongsTo(Funcionario::class);
    }

    public function despesa(): BelongsTo
    {
        return $this->belongsTo(Despesa::class);
    }
}
