<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cópia de uma ordem na emissão da cobrança. Pertence à empresa pela cobrança. */
class CobrancaItem extends Model
{
    protected $table = 'cobranca_itens';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'date:Y-m-d', 'vencimento' => 'date:Y-m-d', 'valor' => 'decimal:2'];
    }

    public function cobranca(): BelongsTo
    {
        return $this->belongsTo(Cobranca::class);
    }

    public function ordem(): BelongsTo
    {
        return $this->belongsTo(OrdemServico::class, 'ordem_id');
    }
}
