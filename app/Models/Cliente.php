<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Cliente extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'clientes';

    protected $attributes = ['ativo' => true, 'nao_recebe_avisos' => false, 'origem' => 'gestao'];

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
            'nao_recebe_avisos' => 'boolean',
            'regras_boleto' => 'array',
            'campos_locais' => 'array',
            'cliente_desde' => 'date:Y-m-d',
            'dia_vencimento' => 'integer',
        ];
    }

    public function veiculos(): HasMany
    {
        return $this->hasMany(Veiculo::class);
    }

    public function contratos(): HasMany
    {
        return $this->hasMany(MensalidadeContrato::class);
    }

    public function convenio(): HasOne
    {
        return $this->hasOne(Convenio::class);
    }

    public function pagadores(): HasMany
    {
        return $this->hasMany(PagadorVinculado::class);
    }
}
