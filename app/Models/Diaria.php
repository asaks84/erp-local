<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Dia trabalhado por um avulso. Fica "a pagar" até entrar num pagamento (conta a pagar). */
class Diaria extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'diarias';

    protected function casts(): array
    {
        return ['data' => 'date', 'valor' => 'decimal:2'];
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
