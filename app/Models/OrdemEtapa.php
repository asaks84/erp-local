<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Histórico da lavagem: cada mudança de etapa, com quem registrou e quando. */
class OrdemEtapa extends Modelo
{
    use PertenceAEmpresa;

    public $timestamps = false;

    protected $table = 'ordem_etapas';

    protected function casts(): array
    {
        return ['quando' => 'datetime'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}
