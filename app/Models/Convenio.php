<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Convenio extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'convenios';

    protected $attributes = ['modo' => 'tudo', 'vale_estadia' => true, 'vale_servicos' => false, 'ativo' => true];

    protected function casts(): array
    {
        return ['vale_estadia' => 'boolean', 'vale_servicos' => 'boolean', 'ativo' => 'boolean', 'desconto_valor' => 'decimal:2'];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /** "Paga tudo", "Paga até 2h", "Desconto de 15%" */
    public function resumo(): string
    {
        return match ($this->modo) {
            'tempo' => 'Paga até '.\App\Support\Formato::duracao((int) $this->limite_min),
            'desconto' => 'Desconto de '.($this->desconto_tipo === 'pct'
                ? rtrim(rtrim(number_format((float) $this->desconto_valor, 2, ',', ''), '0'), ',').'%'
                : \App\Support\Formato::moeda($this->desconto_valor)),
            default => 'Paga tudo',
        };
    }
}
