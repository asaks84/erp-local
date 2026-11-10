<?php

namespace App\Http\Controllers;

use App\Models\LogAtividade;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AtividadeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $filtros = $request->validate([
            'usuario' => ['nullable', 'integer'],
            'busca' => ['nullable', 'string', 'max:100'],
        ]);

        $atividades = LogAtividade::query()
            ->when($filtros['usuario'] ?? null, fn ($q, $id) => $q->where('usuario_id', $id))
            ->when($filtros['busca'] ?? null, function ($q, $busca) {
                $q->where(fn ($q) => $q->where('acao', 'like', "%{$busca}%")->orWhere('detalhe', 'like', "%{$busca}%"));
            })
            ->orderByDesc('criado_em')->orderByDesc('id')
            ->simplePaginate(50)
            ->withQueryString()
            ->through(fn (LogAtividade $a) => [
                'id' => $a->id,
                'criado_em' => $a->criado_em?->format('Y-m-d H:i:s'),
                'usuario' => $a->usuario_nome,
                'acao' => $a->acao,
                'detalhe' => $a->detalhe,
                'ip' => $a->ip,
            ]);

        return Inertia::render('Atividades/Index', [
            'atividades' => $atividades,
            'filtros' => (object) array_filter($filtros),
            'usuarios' => Usuario::orderBy('nome')->get(['id', 'nome']),
        ]);
    }
}
