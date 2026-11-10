<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogAtividade extends Modelo
{
    use PertenceAEmpresa;

    public const UPDATED_AT = null;

    protected $table = 'log_atividades';

    protected function casts(): array
    {
        return ['criado_em' => 'datetime'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}
