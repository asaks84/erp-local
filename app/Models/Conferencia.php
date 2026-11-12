<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Resultado de uma importação + comparação com o sistema atual (paralelo da etapa 5). */
class Conferencia extends Model
{
    public $timestamps = false;

    protected $table = 'conferencias';

    protected $guarded = ['id'];

    public const SITUACOES = ['ok' => 'Igual', 'diferente' => 'Com diferença', 'erro' => 'Erro na importação'];

    protected function casts(): array
    {
        return ['executada_em' => 'datetime', 'linhas' => 'array'];
    }
}
