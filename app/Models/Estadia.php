<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use App\Support\Placa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Estadia (ticket): entrada e saída de um veículo no pátio. Na saída, o valor do tarifador
 * é dividido com o convênio (se houver) e vira ordem de serviço. Nunca é apagada: cancela com motivo.
 */
class Estadia extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'estadias';

    protected $attributes = ['situacao' => 'patio', 'origem' => 'gestao', 'valor_convenio' => 0, 'desconto' => 0, 'mensalista' => false];

    public const DESTINOS = [
        'pago' => 'Pago na saída',
        'conta' => 'Na conta do cliente',
        'convenio' => 'Pago pelo convênio',
        'mensalista' => 'Mensalista',
        'gratis' => 'Sem cobrança (tolerância)',
        'pago_entrada' => 'Diária paga na entrada',
        'sem_receber' => 'Encerrada sem receber',
    ];

    protected static function booted(): void
    {
        static::creating(function (Estadia $e) {
            if ($a = request()?->attributes->get('aparelho')) {
                $e->aparelho_id = $a->id;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'entrada' => 'datetime',
            'saida' => 'datetime',
            'valor_calculado' => 'decimal:2',
            'valor_convenio' => 'decimal:2',
            'desconto' => 'decimal:2',
            'valor_cliente' => 'decimal:2',
            'valor_pacote' => 'decimal:2',
            'pago_entrada' => 'decimal:2',
            'mensalista' => 'boolean',
            'detalhes' => 'array',
            'cancelada_em' => 'datetime',
        ];
    }

    public function veiculo(): BelongsTo
    {
        return $this->belongsTo(Veiculo::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoVeiculo::class, 'tipo_veiculo_id');
    }

    public function pacote(): BelongsTo
    {
        return $this->belongsTo(TarifaPacote::class, 'pacote_id');
    }

    public function aparelho(): BelongsTo
    {
        return $this->belongsTo(Aparelho::class);
    }

    public function convenio(): BelongsTo
    {
        return $this->belongsTo(Convenio::class);
    }

    public function rotulo(): string
    {
        return $this->placa ? Placa::formatar($this->placa) : ($this->identificacao ?: 'Sem placa');
    }
}
