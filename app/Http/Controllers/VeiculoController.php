<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\TipoVeiculo;
use App\Models\Unidade;
use App\Models\Veiculo;
use App\Servicos\Contratos;
use App\Support\Atividades;
use App\Support\EmpresaAtual;
use App\Support\Placa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class VeiculoController extends Controller
{
    private function opcoes(): array
    {
        return [
            'tipos' => TipoVeiculo::where('ativo', true)->orderBy('ordem')->orderBy('nome')->get(['id', 'nome', 'detalhe']),
            'unidades' => Unidade::where('ativa', true)->orderBy('nome')->get(['id', 'nome']),
            'periodos' => Empresa::atual()->config('periodos'),
        ];
    }

    public function create(Cliente $cliente): Response
    {
        return Inertia::render('Veiculos/Form', ['cliente' => $cliente->only(['id', 'nome']), 'veiculo' => null] + $this->opcoes());
    }

    public function edit(Veiculo $veiculo): Response
    {
        return Inertia::render('Veiculos/Form', [
            'cliente' => $veiculo->cliente?->only(['id', 'nome']),
            'veiculo' => $veiculo->only(['id', 'placa', 'identificacao', 'marca', 'modelo', 'cor', 'tipo_veiculo_id', 'ativo']),
        ] + $this->opcoes());
    }

    public function store(Request $request, Cliente $cliente): RedirectResponse
    {
        $dados = $this->validar($request);
        $mensal = $request->validate([
            'mensalista' => ['boolean'],
            'periodo' => ['exclude_unless:mensalista,true', 'required', 'string', 'max:30'],
            'valor' => ['exclude_unless:mensalista,true', 'required', 'numeric', 'gt:0', 'max:99999'],
            'inicio' => ['exclude_unless:mensalista,true', 'required', 'date'],
            'unidade_id' => ['exclude_unless:mensalista,true', 'required', Rule::exists('unidades', 'id')->where('empresa_id', app(EmpresaAtual::class)->id())->where('ativa', true)],
        ]);

        DB::transaction(function () use ($dados, $mensal, $cliente, $request) {
            $veiculo = Veiculo::create($dados + ['cliente_id' => $cliente->id, 'criado_por' => $request->user()->id]);
            Atividades::registrar('veiculo_criado', $veiculo->rotulo().', cliente '.$cliente->id);
            if (! empty($mensal['mensalista'])) {
                Contratos::criar($veiculo, $mensal);
            }
        });

        return redirect("/clientes/{$cliente->id}")->with('sucesso', 'Veículo cadastrado.');
    }

    public function update(Request $request, Veiculo $veiculo): RedirectResponse
    {
        $dados = $this->validar($request, $veiculo);
        $anterior = $veiculo->placa;
        $veiculo->fill($dados);
        if ($veiculo->isDirty('placa')) {
            DB::table('veiculo_placas')->insert([
                'empresa_id' => $veiculo->empresa_id, 'veiculo_id' => $veiculo->id, 'placa_anterior' => $anterior,
                'placa_nova' => $veiculo->placa, 'usuario_id' => $request->user()->id, 'criado_em' => now(),
            ]);
        }
        $veiculo->save();
        Atividades::registrar('veiculo_alterado', $veiculo->rotulo().($anterior !== $veiculo->placa ? ' (placa anterior: '.($anterior ?: 'sem placa').')' : ''));

        return redirect($veiculo->cliente_id ? "/clientes/{$veiculo->cliente_id}" : '/clientes')->with('sucesso', 'Veículo salvo.');
    }

    /** remover = desativar (encerra a mensalidade hoje); histórico fica */
    public function desativar(Veiculo $veiculo): RedirectResponse
    {
        DB::transaction(function () use ($veiculo) {
            if ($c = Contratos::atual($veiculo)) {
                Contratos::encerrar($c, max(now()->toDateString(), $c->inicio->toDateString()), 'Veículo removido');
            }
            $veiculo->update(['ativo' => false]);
        });
        Atividades::registrar('veiculo_removido', $veiculo->rotulo().', cliente '.$veiculo->cliente_id);

        return back()->with('sucesso', 'Veículo removido. A mensalidade dele foi encerrada hoje.');
    }

    public function reativar(Veiculo $veiculo): RedirectResponse
    {
        $placa = $veiculo->placa;
        if ($placa && Veiculo::where('placa', $placa)->where('ativo', true)->where('id', '<>', $veiculo->id)->exists()) {
            return back()->with('erro', 'A placa '.Placa::formatar($placa).' está em outro veículo ativo.');
        }
        $veiculo->update(['ativo' => true]);
        Atividades::registrar('veiculo_reativado', $veiculo->rotulo());

        return back()->with('sucesso', 'Veículo reativado.');
    }

    private function validar(Request $request, ?Veiculo $veiculo = null): array
    {
        $request->merge(['placa' => Placa::normalizar($request->input('placa'))]);
        $empresa = app(EmpresaAtual::class)->id();
        $dados = $request->validate([
            'placa' => ['nullable', 'string', 'min:5', 'max:10', Rule::unique('veiculos', 'placa_ativa')->where('empresa_id', $empresa)->ignore($veiculo?->id)],
            'identificacao' => ['required_without:placa', 'nullable', 'string', 'max:60'],
            'tipo_veiculo_id' => ['required', Rule::exists('tipos_veiculo', 'id')->where('empresa_id', $empresa)],
            'marca' => ['nullable', 'string', 'max:30'],
            'modelo' => ['nullable', 'string', 'max:40'],
            'cor' => ['nullable', 'string', 'max:20'],
        ], [
            'placa.unique' => 'Esta placa já está em outro veículo ativo.',
            'identificacao.required_without' => 'Sem placa, informe outra identificação (número do quadro, de série ou um apelido).',
        ]);
        foreach (['marca', 'modelo', 'cor', 'identificacao'] as $c) {
            $dados[$c] = isset($dados[$c]) ? mb_strtoupper(trim($dados[$c])) : null;
        }

        return $dados;
    }
}
