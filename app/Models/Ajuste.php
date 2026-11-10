<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Correção com motivo: desconto, crédito, débito ou estorno. Nada é editado por cima. */
class Ajuste extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'ajustes';

    public const TIPOS = ['desconto' => 'Desconto', 'credito' => 'Crédito', 'debito' => 'Débito', 'estorno' => 'Estorno'];

    protected function casts(): array
    {
        return ['valor' => 'decimal:2'];
    }

    public function criador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'criado_por');
    }
}
