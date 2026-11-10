<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;

/** Etapa da lavagem configurada pela empresa. Aguardando e entregue são fixas (primeira e última). */
class EtapaLavagem extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'etapas_lavagem';

    protected function casts(): array
    {
        return ['ativa' => 'boolean', 'fixa' => 'boolean'];
    }
}
