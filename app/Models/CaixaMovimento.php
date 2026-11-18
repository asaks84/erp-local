<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Dinheiro que entra ou sai da gaveta sem ser recebimento: suprimento, sangria, pagamento de despesa, retirada do sócio. */
class CaixaMovimento extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'caixa_movimentos';

    public const TIPOS = ['suprimento' => 'Suprimento', 'sangria' => 'Sangria', 'pagamento' => 'Pagamento', 'retirada' => 'Retirada do sócio'];

    public const DESTINOS = ['cofre' => 'Cofre', 'banco' => 'Depósito no banco'];

    protected function casts(): array
    {
        return ['data' => 'date', 'valor' => 'decimal:2'];
    }

    /** entra na gaveta (+1) ou sai (−1) */
    public function sinal(): int
    {
        return $this->tipo === 'suprimento' ? 1 : -1;
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }

    public function contaFinanceira(): BelongsTo
    {
        return $this->belongsTo(ContaFinanceira::class);
    }

    public function planoConta(): BelongsTo
    {
        return $this->belongsTo(PlanoConta::class);
    }

    public function rotulo(): string
    {
        return match ($this->tipo) {
            'sangria' => 'Sangria · '.(self::DESTINOS[$this->destino] ?? 'cofre').($this->contaFinanceira ? ' ('.$this->contaFinanceira->nome.')' : ''),
            'pagamento' => 'Pagamento · '.($this->descricao ?: $this->planoConta?->nome),
            default => self::TIPOS[$this->tipo] ?? $this->tipo,
        };
    }
}
