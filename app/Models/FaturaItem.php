<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma compra (ou estorno) da fatura. Pertence à empresa pela fatura. */
class FaturaItem extends Model
{
    protected $table = 'fatura_itens';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'date', 'valor' => 'decimal:2', 'da_empresa' => 'boolean'];
    }

    public function fatura(): BelongsTo
    {
        return $this->belongsTo(FaturaCartao::class, 'fatura_id');
    }

    public function conta(): BelongsTo
    {
        return $this->belongsTo(PlanoConta::class, 'conta_id');
    }
}
