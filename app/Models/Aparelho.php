<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Aparelho do PDV (o celular do balcão), pareado por código de uso único; fala com a API pelo token. */
class Aparelho extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'aparelhos';

    protected $hidden = ['token_hash', 'codigo_hash'];

    protected $attributes = ['ativo' => true, 'pin_erros' => 0];

    protected function casts(): array
    {
        return ['ativo' => 'boolean', 'pin_erros' => 'integer', 'codigo_expira_em' => 'datetime', 'pareado_em' => 'datetime',
            'ultimo_acesso_em' => 'datetime', 'pin_travado_ate' => 'datetime'];
    }

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    public function situacao(): string
    {
        return match (true) {
            ! $this->ativo => 'desativado',
            (bool) $this->token_hash => 'pareado',
            $this->codigo_hash && $this->codigo_expira_em?->isFuture() => 'aguardando',
            default => 'sem_codigo',
        };
    }
}
