<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Boleto com Pix emitido no Inter para uma cobrança. */
class Boleto extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'boletos';

    protected $attributes = ['situacao' => 'em_processamento'];

    public const SITUACOES = [
        'em_processamento' => 'Em emissão',
        'a_receber' => 'A receber',
        'atrasado' => 'Vencido',
        'recebido' => 'Pago',
        'cancelado' => 'Cancelado',
        'expirado' => 'Baixado',
        'falha' => 'Falha na emissão',
    ];

    /** situações em que o boleto ainda pode ser pago */
    public const ATIVAS = ['em_processamento', 'a_receber', 'atrasado'];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2', 'valor_recebido' => 'decimal:2', 'vencimento' => 'date:Y-m-d', 'recebido_em' => 'date:Y-m-d',
            'regras' => 'array', 'cancelado_em' => 'datetime', 'consultado_em' => 'datetime',
        ];
    }

    public function cobranca(): BelongsTo
    {
        return $this->belongsTo(Cobranca::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function ativo(): bool
    {
        return in_array($this->situacao, self::ATIVAS, true);
    }
}
