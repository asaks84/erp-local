<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Forma de pagamento aceita, com conta de destino, taxa e prazo para o dinheiro cair. */
class FormaPagamento extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'formas_pagamento';

    protected $attributes = ['ativa' => true, 'ordem' => 0, 'taxa_pct' => 0, 'taxa_fixa' => 0, 'parcelas_max' => 1, 'prazo_dias' => 0];

    public const TIPOS = [
        'dinheiro' => 'Dinheiro',
        'pix' => 'Pix',
        'debito' => 'Cartão de débito',
        'credito' => 'Cartão de crédito',
        'boleto' => 'Boleto',
        'transferencia' => 'Transferência',
        'nao_informada' => 'Não informada',
    ];

    protected function casts(): array
    {
        return [
            'ativa' => 'boolean',
            'taxa_pct' => 'decimal:2',
            'taxa_fixa' => 'decimal:2',
            'taxas_parcelas' => 'array',
            'parcelas_max' => 'integer',
            'prazo_dias' => 'integer',
        ];
    }

    public function conta(): BelongsTo
    {
        return $this->belongsTo(ContaFinanceira::class, 'conta_financeira_id');
    }

    /** taxa em reais sobre um valor, considerando o número de parcelas no crédito */
    public function taxaSobre(float $valor, int $parcelas = 1): float
    {
        if ($valor <= 0) {
            return 0.0;
        }
        $pct = (float) $this->taxa_pct;
        if ($parcelas > 1 && isset(($this->taxas_parcelas ?? [])[(string) $parcelas])) {
            $pct = (float) $this->taxas_parcelas[(string) $parcelas];
        }

        return round($valor * $pct / 100 + (float) $this->taxa_fixa, 2);
    }

    public function previsaoPara(CarbonInterface $recebido): string
    {
        return $recebido->copy()->addDays($this->prazo_dias)->toDateString();
    }
}
