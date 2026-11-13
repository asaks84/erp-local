<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use App\Support\Formato;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Tabela de cobrança de estadia (com vigência; unidade vazia = vale para a empresa toda). */
class Tarifa extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'tarifas';

    protected $attributes = ['tolerancia_min' => 0, 'fracao_min' => 0, 'restante' => 'faixas'];

    protected function casts(): array
    {
        return ['vigencia_inicio' => 'date:Y-m-d', 'vigencia_fim' => 'date:Y-m-d'];
    }

    public function faixas(): HasMany
    {
        return $this->hasMany(TarifaFaixa::class)->orderBy('ordem');
    }

    public function pacotes(): HasMany
    {
        return $this->hasMany(TarifaPacote::class)->orderByRaw('duracao_min IS NULL, duracao_min');
    }

    public function tipos(): BelongsToMany
    {
        return $this->belongsToMany(TipoVeiculo::class, 'tarifa_tipos', 'tarifa_id', 'tipo_veiculo_id');
    }

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    /** texto curto das regras: "1h R$ 15,00 · cada 1h R$ 5,00 · Diária (12h) R$ 10,00" */
    public function resumo(): string
    {
        $partes = [];
        foreach ($this->faixas as $f) {
            $partes[] = $f->modo === 'fixo'
                ? ($f->duracao_min ? Formato::duracao($f->duracao_min) : 'Restante').' '.Formato::moeda($f->valor)
                : 'cada '.Formato::duracao($f->bloco_min).' '.Formato::moeda($f->valor);
        }
        foreach ($this->pacotes as $p) {
            $partes[] = $p->nome.($p->duracao_min ? ' ('.Formato::duracao($p->duracao_min).')' : '').' '.Formato::moeda($p->valor);
        }

        return implode(' · ', $partes) ?: 'Sem regras';
    }

    /** formato usado pelo Tarifador */
    public function regras(): array
    {
        return [
            'tolerancia' => $this->tolerancia_min,
            'fracao' => $this->fracao_min,
            'restante' => $this->restante,
            'faixas' => $this->faixas->map(fn ($f) => [
                'duracao' => $f->duracao_min, 'modo' => $f->modo, 'bloco' => $f->bloco_min, 'valor' => (float) $f->valor,
            ])->all(),
            'pacotes' => $this->pacotes->map(fn ($p) => [
                'nome' => $p->nome, 'duracao' => $p->duracao_min, 'valor' => (float) $p->valor,
                'hora_inicio' => $p->hora_inicio, 'hora_fim' => $p->hora_fim,
            ])->all(),
        ];
    }
}
