<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Fornecedor: quem a empresa paga (energia, aluguel, contador, produtos, prestadores). */
class Fornecedor extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'fornecedores';

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function contaPadrao(): BelongsTo
    {
        return $this->belongsTo(PlanoConta::class, 'conta_padrao_id');
    }
}
