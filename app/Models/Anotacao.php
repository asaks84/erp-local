<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;

/** Anotação (tarefa) de um usuário no Início. Só o próprio usuário vê e mexe. */
class Anotacao extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'anotacoes';

    protected function casts(): array
    {
        return ['para' => 'date', 'feita_em' => 'datetime'];
    }

    public function linha(): array
    {
        return [
            'id' => $this->id,
            'texto' => $this->texto,
            'para' => $this->para?->format('Y-m-d'),
            'feita' => (bool) $this->feita_em,
        ];
    }

    /** pendentes (as com data primeiro, pela data) e as 10 últimas feitas */
    public static function doUsuario(int $usuarioId): array
    {
        $pendentes = self::where('usuario_id', $usuarioId)->whereNull('feita_em')
            ->orderByRaw('para IS NULL')->orderBy('para')->orderBy('id')->get();
        $feitas = self::where('usuario_id', $usuarioId)->whereNotNull('feita_em')->orderByDesc('feita_em')->limit(10)->get();

        return $pendentes->merge($feitas)->map->linha()->values()->all();
    }
}
