<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use App\Support\Placa;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Veiculo extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'veiculos';

    protected $guarded = ['id', 'empresa_id', 'placa_ativa'];

    protected $attributes = ['ativo' => true];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    /** placa sempre normalizada (maiúsculas, sem traço/espaço); vazia = sem placa */
    protected function placa(): Attribute
    {
        return Attribute::make(set: fn ($v) => Placa::normalizar($v));
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoVeiculo::class, 'tipo_veiculo_id');
    }

    public function contratos(): HasMany
    {
        return $this->hasMany(MensalidadeContrato::class);
    }

    /** "ABC1D23" ou a identificação alternativa (sem placa) */
    public function rotulo(): string
    {
        return $this->placa ? Placa::formatar($this->placa) : ($this->identificacao ?: 'Sem placa #'.$this->id);
    }
}
