<?php

namespace App\Http\Controllers;

use App\Models\Unidade;
use App\Models\Usuario;
use App\Support\Atividades;
use App\Support\EmpresaAtual;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UnidadeController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Empresa/UnidadeForm', ['unidade' => null]);
    }

    public function edit(Unidade $unidade): Response
    {
        return Inertia::render('Empresa/UnidadeForm', [
            'unidade' => $unidade->only(['id', 'nome', 'endereco', 'telefone', 'ativa']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $unidade = Unidade::create($this->validar($request));
        Atividades::registrar('unidade_criada', $unidade->nome);

        return redirect('/empresa')->with('sucesso', 'Unidade criada.');
    }

    public function update(Request $request, Unidade $unidade): RedirectResponse
    {
        $dados = $this->validar($request, $unidade);

        // não deixa desativar a última unidade ativa nem a unidade de usuários ativos
        if ($unidade->ativa && ! $dados['ativa']) {
            if (Unidade::where('ativa', true)->count() <= 1) {
                return back()->withErrors(['ativa' => 'É preciso ter ao menos uma unidade ativa.']);
            }
            if (Usuario::where('unidade_id', $unidade->id)->where('ativo', true)->exists()) {
                return back()->withErrors(['ativa' => 'Há usuários ativos presos a esta unidade. Mude a unidade deles antes.']);
            }
        }

        $unidade->update($dados);
        Atividades::registrar('unidade_alterada', $unidade->nome);

        return redirect('/empresa')->with('sucesso', 'Unidade salva.');
    }

    private function validar(Request $request, ?Unidade $unidade = null): array
    {
        $request->merge(['telefone' => preg_replace('/\D/', '', (string) $request->input('telefone')) ?: null]);

        return $request->validate([
            'nome' => ['required', 'string', 'max:60', Rule::unique('unidades')
                ->where('empresa_id', app(EmpresaAtual::class)->id())
                ->ignore($unidade?->id)],
            'endereco' => ['nullable', 'string', 'max:160'],
            'telefone' => ['nullable', 'string', 'min:10', 'max:11'],
            'ativa' => ['required', 'boolean'],
        ]);
    }
}
