<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MensalidadePausa extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'mensalidade_pausas';

    protected function casts(): array
    {
        return ['inicio' => 'date:Y-m-d', 'fim' => 'date:Y-m-d', 'taxa_valor' => 'decimal:2'];
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(MensalidadeContrato::class, 'contrato_id');
    }

    /** valor cobrado por mês pausado, a partir do valor da mensalidade */
    public function taxaSobre(float $mensalidade): float
    {
        $taxa = $this->taxa_tipo === 'pct'
            ? $mensalidade * (float) $this->taxa_valor / 100
            : (float) $this->taxa_valor;

        return round(max(0, $taxa), 2);
    }
}
