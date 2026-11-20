<?php

namespace App\Http\Controllers;

use App\Models\TipoVeiculo;
use App\Models\Veiculo;
use App\Support\Atividades;
use App\Support\EmpresaAtual;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TipoVeiculoController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Configuracoes/Tipos', [
            'tipos' => TipoVeiculo::orderByDesc('ativo')->ordenados()->get()->map(fn ($t) => [
                ...$t->only(['id', 'nome', 'detalhe', 'ordem', 'ativo']),
                'veiculos' => Veiculo::where('tipo_veiculo_id', $t->id)->where('ativo', true)->count(),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // novo tipo entra no fim da lista; depois é só subir com as setas
        $t = TipoVeiculo::create(['ordem' => (int) TipoVeiculo::max('ordem') + 1] + $this->validar($request));
        Atividades::registrar('tipo_veiculo_criado', $t->nome);

        return back()->with('sucesso', 'Tipo de veículo criado. Lembre de colocar o preço dele nos serviços e na tabela de estadia.');
    }

    public function update(Request $request, TipoVeiculo $tipo): RedirectResponse
    {
        $tipo->update($this->validar($request, $tipo));
        Atividades::registrar('tipo_veiculo_alterado', $tipo->nome);

        return back()->with('sucesso', 'Tipo de veículo salvo.');
    }

    /** nova ordem de exibição: ids na ordem da tela (setas subir/descer em Configurações › Tipos) */
    public function ordenar(Request $request): RedirectResponse
    {
        $d = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']]);
        DB::transaction(function () use ($d) {
            $tipos = TipoVeiculo::whereIn('id', $d['ids'])->get()->keyBy('id');
            foreach (array_values(array_unique($d['ids'])) as $i => $id) {
                $tipos[$id]?->update(['ordem' => $i + 1]);
            }
        });
        Atividades::registrar('tipo_veiculo_ordem', 'Ordem dos tipos de veículo');

        return back()->with('sucesso', 'Ordem salva. Vale para o PDV, os cadastros e as listas de tamanho.');
    }

    public function destroy(TipoVeiculo $tipo): RedirectResponse
    {
        $usado = Veiculo::where('tipo_veiculo_id', $tipo->id)->exists()
            || DB::table('servico_precos')->where('tipo_veiculo_id', $tipo->id)->exists()
            || DB::table('tarifa_tipos')->where('tipo_veiculo_id', $tipo->id)->exists();
        if ($usado) {
            return back()->with('erro', 'Este tipo já foi usado (veículos, preços ou tabelas). Desative em vez de excluir.');
        }
        Atividades::registrar('tipo_veiculo_excluido', $tipo->nome);
        $tipo->delete();

        return back()->with('sucesso', 'Tipo de veículo excluído.');
    }

    private function validar(Request $request, ?TipoVeiculo $tipo = null): array
    {
        return $request->validate([
            'nome' => ['required', 'string', 'max:30', Rule::unique('tipos_veiculo')->where('empresa_id', app(EmpresaAtual::class)->id())->ignore($tipo?->id)],
            'detalhe' => ['nullable', 'string', 'max:80'],
            'ativo' => ['boolean'],
        ]);
    }
}
