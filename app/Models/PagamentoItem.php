<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Quanto de um pagamento foi para uma ordem (crédito usado entra negativo). */
class PagamentoItem extends Model
{
    protected $table = 'pagamento_itens';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['valor' => 'decimal:2'];
    }

    public function pagamento(): BelongsTo
    {
        return $this->belongsTo(Pagamento::class);
    }

    public function ordem(): BelongsTo
    {
        return $this->belongsTo(OrdemServico::class, 'ordem_id');
    }
}
