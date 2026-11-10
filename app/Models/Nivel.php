<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Nivel extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'niveis';

    protected $attributes = ['fixo' => false, 'permissoes' => '[]'];

    protected function casts(): array
    {
        return ['permissoes' => 'array', 'fixo' => 'boolean'];
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(Usuario::class);
    }

    /** Todas as chaves do catálogo (config/permissoes.php). */
    public static function catalogoChaves(): array
    {
        return collect(config('permissoes'))->flatMap(fn ($itens) => array_keys($itens))->values()->all();
    }

    public function permite(string $chave): bool
    {
        if ($this->fixo) {
            return true;
        }

        return in_array($chave, $this->permissoes ?? [], true);
    }
}
