<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Regra de um convênio para a estadia (servico_id vazio) ou para um serviço. */
class ConvenioRegra extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'convenio_regras';

    public const MODOS = ['integral' => 'Pagamento integral', 'parcial' => 'Pagamento parcial', 'tempo' => 'Limite de tempo'];

    protected function casts(): array
    {
        return ['limite_valor' => 'decimal:2', 'limite_min' => 'integer'];
    }

    public function servico(): BelongsTo
    {
        return $this->belongsTo(Servico::class);
    }

    /** "Pagamento integral", "Paga até R$ 10,00", "Paga até 2h" */
    public function resumo(): string
    {
        return match ($this->modo) {
            'parcial' => 'paga até '.\App\Support\Formato::moeda($this->limite_valor),
            'tempo' => 'paga até '.\App\Support\Formato::duracao((int) $this->limite_min),
            default => 'paga tudo',
        };
    }
}
