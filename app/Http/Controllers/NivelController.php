<?php

namespace App\Http\Controllers;

use App\Models\Nivel;
use App\Support\Atividades;
use App\Support\EmpresaAtual;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class NivelController extends Controller
{
    public function index(): Response
    {
        $total = count(Nivel::catalogoChaves());

        return Inertia::render('Niveis/Index', [
            'niveis' => Nivel::withCount('usuarios')->orderByDesc('fixo')->orderBy('nome')->get()
                ->map(fn (Nivel $n) => [
                    'id' => $n->id,
                    'nome' => $n->nome,
                    'fixo' => $n->fixo,
                    'usuarios' => $n->usuarios_count,
                    'total_permissoes' => count(array_intersect($n->permissoes ?? [], Nivel::catalogoChaves())),
                    'total_catalogo' => $total,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Niveis/Form', ['nivel' => null, 'catalogo' => $this->catalogo()]);
    }

    public function edit(Nivel $nivel): Response
    {
        return Inertia::render('Niveis/Form', [
            'nivel' => [
                'id' => $nivel->id,
                'nome' => $nivel->nome,
                'fixo' => $nivel->fixo,
                'permissoes' => array_values($nivel->permissoes ?? []),
                'usuarios' => $nivel->usuarios()->count(),
            ],
            'catalogo' => $this->catalogo(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $dados = $this->validar($request);
        $nivel = Nivel::create(['nome' => $dados['nome'], 'permissoes' => $dados['permissoes'], 'fixo' => false]);
        Atividades::registrar('nivel_criado', $nivel->nome.': '.$this->lista($nivel));

        return redirect('/niveis')->with('sucesso', 'Nível criado.');
    }

    public function update(Request $request, Nivel $nivel): RedirectResponse
    {
        $dados = $this->validar($request, $nivel);
        $nivel->nome = $dados['nome'];
        if (! $nivel->fixo) {
            $nivel->permissoes = $dados['permissoes'];
        }
        $nivel->save();
        Atividades::registrar('nivel_alterado', $nivel->nome.': '.$this->lista($nivel));

        return redirect('/niveis')->with('sucesso', 'Nível salvo.');
    }

    public function destroy(Nivel $nivel): RedirectResponse
    {
        if ($nivel->fixo) {
            return back()->with('erro', 'O nível de acesso total não pode ser excluído.');
        }
        if ($nivel->usuarios()->exists()) {
            return back()->with('erro', 'Há usuários neste nível. Mude o nível deles antes de excluir.');
        }
        $nome = $nivel->nome;
        $nivel->delete();
        Atividades::registrar('nivel_excluido', $nome);

        return redirect('/niveis')->with('sucesso', 'Nível excluído.');
    }

    private function validar(Request $request, ?Nivel $nivel = null): array
    {
        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:40', Rule::unique('niveis')
                ->where('empresa_id', app(EmpresaAtual::class)->id())
                ->ignore($nivel?->id)],
            'permissoes' => ['array'],
            'permissoes.*' => ['string', Rule::in(Nivel::catalogoChaves())],
        ]);
        $dados['permissoes'] = array_values(array_unique($dados['permissoes'] ?? []));

        return $dados;
    }

    private function lista(Nivel $nivel): string
    {
        return $nivel->fixo ? 'acesso total' : (implode(', ', $nivel->permissoes ?? []) ?: 'só o dia a dia');
    }

    private function catalogo(): array
    {
        return collect(config('permissoes'))->map(fn ($itens, $grupo) => [
            'grupo' => $grupo,
            'itens' => collect($itens)->map(fn ($p, $chave) => [
                'chave' => $chave,
                'rotulo' => $p[0],
                'descricao' => $p[1],
            ])->values(),
        ])->values()->all();
    }
}
