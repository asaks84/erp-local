<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** NFS-e emitida pelo Sistema Nacional, uma por recebimento (pagamento). */
class Nfse extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'nfses';

    protected $attributes = ['situacao' => 'enviando'];

    protected $hidden = ['xml_dps', 'xml_nfse'];

    public const SITUACOES = ['enviando' => 'Enviando', 'emitida' => 'Emitida', 'rejeitada' => 'Rejeitada', 'cancelada' => 'Cancelada'];

    protected function casts(): array
    {
        return ['valor' => 'decimal:2', 'valor_iss' => 'decimal:2', 'iss_retido' => 'boolean', 'tomador_identificado' => 'boolean', 'ret_irrf' => 'boolean', 'ret_cp' => 'boolean', 'valor_ret_federal' => 'decimal:2', 'emitida_em' => 'datetime', 'cancelada_em' => 'datetime'];
    }

    public function pagamento(): BelongsTo
    {
        return $this->belongsTo(Pagamento::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function criador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'criado_por');
    }
}
