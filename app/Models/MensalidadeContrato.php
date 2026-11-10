<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Contrato de mensalidade de um veículo. Reajuste = nova versão (a anterior ganha fim na véspera).
 * Encerramento agendado = data de fim no futuro. Pausas ficam em mensalidade_pausas.
 */
class MensalidadeContrato extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'mensalidades_contratos';

    protected function casts(): array
    {
        return ['inicio' => 'date:Y-m-d', 'fim' => 'date:Y-m-d', 'valor' => 'decimal:2'];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function veiculo(): BelongsTo
    {
        return $this->belongsTo(Veiculo::class);
    }

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    public function pausas(): HasMany
    {
        return $this->hasMany(MensalidadePausa::class, 'contrato_id');
    }

    /** contratos valendo na data (sem fim ou fim depois dela) */
    public function scopeVigentes(Builder $q, ?CarbonInterface $data = null): void
    {
        $dia = ($data ?? now())->toDateString();
        $q->where('inicio', '<=', $dia)->where(fn ($q) => $q->whereNull('fim')->orWhere('fim', '>=', $dia));
    }

    /** contratos que ainda não terminaram (vigentes hoje ou que começam no futuro) */
    public function scopeAtivos(Builder $q): void
    {
        $q->where(fn ($q) => $q->whereNull('fim')->orWhere('fim', '>=', now()->toDateString()));
    }

    public function pausaEm(CarbonInterface $data): ?MensalidadePausa
    {
        $dia = $data->toDateString();

        return $this->pausas->first(fn ($p) => $p->inicio->toDateString() <= $dia && $p->fim->toDateString() >= $dia);
    }
}
