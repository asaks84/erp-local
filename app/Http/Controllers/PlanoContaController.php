<?php

namespace App\Http\Controllers;

use App\Models\Despesa;
use App\Models\DespesaRecorrente;
use App\Models\Fornecedor;
use App\Models\PlanoConta;
use App\Servicos\PlanoContas;
use App\Support\Atividades;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Configurações › Plano de contas: árvore de receitas e despesas. Conta usada não se apaga: desativa. */
class PlanoContaController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Configuracoes/PlanoContas', ['contas' => PlanoContas::arvore()]);
    }

    private static function usada(PlanoConta $c): bool
    {
        return $c->chave || $c->filhas()->exists() || Despesa::where('conta_id', $c->id)->exists()
            || DespesaRecorrente::where('conta_id', $c->id)->exists() || Fornecedor::where('conta_padrao_id', $c->id)->exists();
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate(['pai_id' => ['required', 'integer'], 'nome' => ['required', 'string', 'max:60']], ['nome.required' => 'Dê um nome para a conta.']);
        $pai = PlanoConta::findOrFail($d['pai_id']);
        if (Despesa::where('conta_id', $pai->id)->exists() || DespesaRecorrente::where('conta_id', $pai->id)->exists()) {
            return back()->with('erro', 'A conta '.$pai->rotulo().' já tem lançamentos: crie a nova conta ao lado dela, não dentro.');
        }
        $max = (int) PlanoConta::where('pai_id', $pai->id)->max('ordem');
        $c = PlanoConta::create(['pai_id' => $pai->id, 'tipo' => $pai->tipo, 'nome' => trim($d['nome']), 'codigo' => '', 'ordem' => $max + 1]);
        PlanoContas::renumerar();
        Atividades::registrar('plano_conta_criada', $c->fresh()->rotulo());

        return back()->with('sucesso', 'Conta '.$c->fresh()->rotulo().' criada.');
    }

    public function update(Request $request, PlanoConta $conta): RedirectResponse
    {
        $d = $request->validate(['nome' => ['required', 'string', 'max:60'], 'ativa' => ['nullable', 'boolean']], ['nome.required' => 'Dê um nome para a conta.']);
        $ativa = $conta->chave || ! $conta->pai_id ? true : $request->boolean('ativa', $conta->ativa);
        $conta->update(['nome' => trim($d['nome']), 'ativa' => $ativa]);
        Atividades::registrar('plano_conta_alterada', $conta->rotulo().($ativa ? '' : ' (desativada)'));

        return back();
    }

    public function mover(Request $request, PlanoConta $conta): RedirectResponse
    {
        $d = $request->validate(['direcao' => ['required', 'in:subir,descer']]);
        abort_unless($conta->pai_id, 422);
        $irmas = PlanoConta::where('pai_id', $conta->pai_id)->orderBy('ordem')->orderBy('id')->pluck('id')->all();
        $i = array_search($conta->id, $irmas, true);
        $j = $d['direcao'] === 'subir' ? $i - 1 : $i + 1;
        if ($j >= 0 && $j < count($irmas)) {
            [$irmas[$i], $irmas[$j]] = [$irmas[$j], $irmas[$i]];
            foreach ($irmas as $o => $id) {
                PlanoConta::whereKey($id)->update(['ordem' => $o]);
            }
            PlanoContas::renumerar();
        }

        return back();
    }

    public function destroy(PlanoConta $conta): RedirectResponse
    {
        if (self::usada($conta)) {
            return back()->with('erro', 'Conta do sistema, com subcontas ou já usada não é apagada: desative.');
        }
        $rotulo = $conta->rotulo();
        $conta->delete();
        PlanoContas::renumerar();
        Atividades::registrar('plano_conta_apagada', $rotulo);

        return back()->with('sucesso', 'Conta apagada.');
    }
}
