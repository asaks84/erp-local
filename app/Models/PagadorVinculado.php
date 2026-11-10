<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;

/** Quem paga por outro cliente (PIX de terceiros reconhecido no extrato). */
class PagadorVinculado extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'pagadores_vinculados';
}
