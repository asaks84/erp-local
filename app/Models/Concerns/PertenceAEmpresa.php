<?php

namespace App\Models\Concerns;

use App\Models\Empresa;
use App\Support\EmpresaAtual;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Toda tabela de negócio usa este trait: filtra as consultas pela empresa atual
 * e preenche empresa_id ao criar. Gravar sem empresa definida é erro.
 */
trait PertenceAEmpresa
{
    public static function bootPertenceAEmpresa(): void
    {
        static::addGlobalScope(new EmpresaScope);

        static::creating(function ($model) {
            if (! $model->empresa_id) {
                $empresa = app(EmpresaAtual::class)->id();
                if ($empresa === null) {
                    throw new LogicException('Registro sem empresa: defina a empresa atual antes de gravar.');
                }
                $model->empresa_id = $empresa;
            }
        });

        // não deixa mudar um registro de empresa
        static::updating(function ($model) {
            if ($model->isDirty('empresa_id')) {
                throw new LogicException('Não é permitido trocar a empresa de um registro.');
            }
        });
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
