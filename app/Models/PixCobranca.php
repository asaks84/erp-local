<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pix imediato do PDV (cobrança "cob" do Inter): o QR mostrado no balcão. */
class PixCobranca extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'pix_cobrancas';

    protected function casts(): array
    {
        return ['valor' => 'decimal:2', 'valor_pago' => 'decimal:2', 'expira_em' => 'datetime', 'pago_em' => 'datetime', 'consultado_em' => 'datetime'];
    }

    public function pagamento(): BelongsTo
    {
        return $this->belongsTo(Pagamento::class);
    }

    public function aparelho(): BelongsTo
    {
        return $this->belongsTo(Aparelho::class);
    }
}
