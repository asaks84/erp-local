<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ordem de serviço: todo item que o cliente deve (mensalidade, lavagem/serviço, estadia,
 * débito) ou que abate da conta (crédito). Nunca é apagada: é paga ou cancelada com motivo.
 */
class OrdemServico extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'ordens_servico';

    protected $attributes = ['situacao' => 'aberta', 'origem' => 'gestao'];

    public const TIPOS = [
        'mensalidade' => 'Mensalidade',
        'servico' => 'Lavagem/serviço',
        'estadia' => 'Estadia',
        'debito' => 'Débito',
        'credito' => 'Crédito',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'date:Y-m-d',
            'vencimento' => 'date:Y-m-d',
            'valor' => 'decimal:2',
            'pago_em' => 'datetime',
            'cancelada_em' => 'datetime',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function veiculo(): BelongsTo
    {
        return $this->belongsTo(Veiculo::class);
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(MensalidadeContrato::class, 'contrato_id');
    }

    public function servico(): BelongsTo
    {
        return $this->belongsTo(Servico::class);
    }

    public function tipoVeiculo(): BelongsTo
    {
        return $this->belongsTo(TipoVeiculo::class, 'tipo_veiculo_id');
    }

    public function estadia(): BelongsTo
    {
        return $this->belongsTo(Estadia::class);
    }

    public function convenio(): BelongsTo
    {
        return $this->belongsTo(Convenio::class);
    }

    public function cobranca(): BelongsTo
    {
        return $this->belongsTo(Cobranca::class);
    }

    public function itensPagamento(): HasMany
    {
        return $this->hasMany(PagamentoItem::class, 'ordem_id');
    }

    public function scopeAbertas(Builder $q): void
    {
        $q->where('situacao', 'aberta');
    }

    /** valor com sinal: crédito abate (negativo), o resto soma */
    public function valorComSinal(): float
    {
        return ($this->tipo === 'credito' ? -1 : 1) * (float) $this->valor;
    }

    /** data usada para saber se venceu: vencimento ou, sem ele, a data da ordem */
    public function dataReferencia(): string
    {
        return ($this->vencimento ?? $this->data)->toDateString();
    }

    /** expressão SQL do valor com sinal, para somas */
    public const SQL_VALOR = "CASE WHEN tipo = 'credito' THEN -valor ELSE valor END";
}
