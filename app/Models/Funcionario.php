<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Funcionário: registrado (recebe pelo recibo de salário do contador) ou avulso (recebe por diária). */
class Funcionario extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'funcionarios';

    public const TIPOS = ['registrado' => 'Registrado', 'avulso' => 'Avulso (diária)'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean', 'valor_diaria' => 'decimal:2'];
    }

    public function holerites(): HasMany
    {
        return $this->hasMany(Holerite::class);
    }

    public function diarias(): HasMany
    {
        return $this->hasMany(Diaria::class);
    }
}
