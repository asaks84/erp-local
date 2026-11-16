<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Aviso de cobrança pelo WhatsApp (um por cobrança e por passo da régua). */
class Aviso extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'avisos';

    protected $attributes = ['situacao' => 'pendente'];

    public const SITUACOES = ['pendente' => 'Para enviar', 'enviado' => 'Enviado', 'entregue' => 'Entregue', 'lido' => 'Lido', 'falha' => 'Falhou', 'cancelado' => 'Cancelado'];

    protected function casts(): array
    {
        return ['previsto_para' => 'date:Y-m-d', 'vencimento' => 'date:Y-m-d', 'enviado_em' => 'datetime', 'dias' => 'integer'];
    }

    public function cobranca(): BelongsTo
    {
        return $this->belongsTo(Cobranca::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }
}
