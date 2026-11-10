<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;

/** Onde o dinheiro fica: caixa físico, banco, maquininha. */
class ContaFinanceira extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'contas_financeiras';

    protected $attributes = ['ativa' => true, 'ordem' => 0];

    public const TIPOS = ['caixa' => 'Caixa', 'banco' => 'Banco', 'maquininha' => 'Maquininha'];

    protected function casts(): array
    {
        return ['ativa' => 'boolean'];
    }
}
