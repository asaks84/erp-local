<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Models\Unidade;
use App\Support\Atividades;
use App\Support\EmpresaAtual;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmpresaController extends Controller
{
    private function empresa(): Empresa
    {
        return Empresa::findOrFail(app(EmpresaAtual::class)->id());
    }

    public function edit(): Response
    {
        $empresa = $this->empresa();

        return Inertia::render('Empresa/Edit', [
            'empresa' => $empresa->only(['nome', 'razao_social', 'documento', 'telefone', 'email']),
            'unidades' => Unidade::orderBy('nome')->get(['id', 'nome', 'endereco', 'ativa']),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:80'],
            'razao_social' => ['nullable', 'string', 'max:120'],
            'documento' => ['nullable', 'string', 'max:18', 'regex:/^[0-9.\/\- ]+$/'],
            'telefone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:120'],
        ]);
        $empresa = $this->empresa();
        $empresa->update($dados);
        Atividades::registrar('empresa_alterada', $empresa->nome);

        return back()->with('sucesso', 'Dados da empresa salvos.');
    }
}
