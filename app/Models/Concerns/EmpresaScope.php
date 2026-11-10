<?php

namespace App\Models\Concerns;

use App\Support\EmpresaAtual;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filtro global por empresa. Sem empresa definida, a consulta não devolve nada
 * (falha fechada): é melhor uma tela vazia do que dados de outra empresa.
 */
class EmpresaScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $empresa = app(EmpresaAtual::class)->id();
        if ($empresa === null) {
            $builder->whereRaw('1 = 0');

            return;
        }
        $builder->where($model->qualifyColumn('empresa_id'), $empresa);
    }
}
