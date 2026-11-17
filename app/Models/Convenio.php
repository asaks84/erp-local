<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use App\Support\Formato;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Convênio: um cliente que paga pelo uso de quem ele indica, com uma regra por serviço (estadia e cada
 * serviço marcado) e o fechamento programado. Ver Servicos\Convenios.
 */
class Convenio extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'convenios';

    protected $attributes = ['modo' => 'tudo', 'vale_estadia' => true, 'vale_servicos' => false, 'ativo' => true, 'fechamento' => 'mensalidade'];

    public const FECHAMENTOS = ['mensalidade' => 'Junto com a mensalidade', 'dias' => 'A cada X dias', 'semana' => 'Toda semana'];

    public const DIAS_SEMANA = [1 => 'segunda', 2 => 'terça', 3 => 'quarta', 4 => 'quinta', 5 => 'sexta', 6 => 'sábado', 7 => 'domingo'];

    protected static function booted(): void
    {
        // criado pelo modo antigo (importação do sistema atual): já nasce com as regras equivalentes;
        // pela tela do ERP, as regras escolhidas substituem estas logo em seguida
        static::created(fn (Convenio $c) => \App\Servicos\Convenios::refazerDoLegado($c));
    }

    protected function casts(): array
    {
        return ['vale_estadia' => 'boolean', 'vale_servicos' => 'boolean', 'ativo' => 'boolean', 'desconto_valor' => 'decimal:2',
            'regras_no_erp' => 'boolean', 'reconfigurar' => 'boolean', 'fechamento_dias' => 'integer', 'fechamento_semana' => 'integer', 'ultimo_fechamento' => 'date'];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function regras(): HasMany
    {
        return $this->hasMany(ConvenioRegra::class);
    }

    /** regra da estadia ($servicoId null) ou de um serviço */
    public function regra(?int $servicoId): ?ConvenioRegra
    {
        return $this->regras->first(fn (ConvenioRegra $r) => $servicoId === null ? $r->servico_id === null : (int) $r->servico_id === $servicoId);
    }

    public function valeEstadia(): bool
    {
        return (bool) $this->regra(null);
    }

    public function valeServicos(): bool
    {
        return $this->regras->contains(fn ($r) => $r->servico_id !== null);
    }

    /** "Estadia: paga até 2h · Lavagem simples: paga tudo" */
    public function resumo(): string
    {
        if ($this->reconfigurar) {
            return 'Reconfigurar (o modo "desconto" saiu)';
        }
        $this->loadMissing('regras.servico');
        if ($this->regras->isEmpty()) {
            return 'Sem serviços marcados';
        }

        return $this->regras->sortBy(fn ($r) => $r->servico_id === null ? '' : ($r->servico?->nome ?? 'z'))
            ->map(fn ($r) => ($r->servico_id === null ? 'Estadia' : ($r->servico?->nome ?? 'Serviço')).': '.$r->resumo())->implode(' · ');
    }

    public function resumoFechamento(): string
    {
        return match ($this->fechamento) {
            'dias' => 'A cada '.((int) $this->fechamento_dias).' dia(s)',
            'semana' => 'Toda '.(self::DIAS_SEMANA[(int) $this->fechamento_semana] ?? 'semana'),
            default => 'Junto com a mensalidade',
        };
    }
}
