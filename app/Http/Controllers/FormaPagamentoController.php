<?php

namespace App\Http\Controllers;

use App\Models\ContaFinanceira;
use App\Models\FormaPagamento;
use App\Servicos\FormasPagamento;
use App\Support\Atividades;
use App\Support\EmpresaAtual;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Formas de pagamento aceitas e contas onde o dinheiro entra (Configurações). Nada é apagado: desativa. */
class FormaPagamentoController extends Controller
{
    public function index(): Response
    {
        FormasPagamento::garantirPadrao();

        return Inertia::render('Configuracoes/Pagamentos', [
            'contas' => ContaFinanceira::orderBy('ordem')->orderBy('nome')->get(['id', 'nome', 'tipo', 'ativa']),
            'formas' => FormaPagamento::with('conta:id,nome')->where('tipo', '<>', 'nao_informada')->orderBy('ordem')->orderBy('nome')->get()
                ->map(fn ($f) => [
                    ...$f->only(['id', 'nome', 'tipo', 'conta_financeira_id', 'parcelas_max', 'prazo_dias', 'ativa']),
                    'conta' => $f->conta?->nome,
                    'taxa_pct' => (float) $f->taxa_pct,
                    'taxa_fixa' => (float) $f->taxa_fixa,
                    'taxas_parcelas' => (object) ($f->taxas_parcelas ?? []),
                ]),
            'tipos_forma' => collect(FormaPagamento::TIPOS)->except('nao_informada'),
            'tipos_conta' => ContaFinanceira::TIPOS,
        ]);
    }

    private function validarForma(Request $request, ?FormaPagamento $f = null): array
    {
        $empresa = app(EmpresaAtual::class)->id();
        $d = $request->validate([
            'nome' => ['required', 'string', 'max:40', Rule::unique('formas_pagamento')->where('empresa_id', $empresa)->ignore($f?->id)],
            'tipo' => ['required', Rule::in(array_keys(collect(FormaPagamento::TIPOS)->except('nao_informada')->all()))],
            'conta_financeira_id' => ['nullable', Rule::exists('contas_financeiras', 'id')->where('empresa_id', $empresa)],
            'taxa_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'taxa_fixa' => ['required', 'numeric', 'min:0', 'max:9999'],
            'parcelas_max' => ['required', 'integer', 'min:1', 'max:24'],
            'taxas_parcelas' => ['nullable', 'array'],
            'taxas_parcelas.*' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'prazo_dias' => ['required', 'integer', 'min:0', 'max:365'],
            'ativa' => ['boolean'],
        ], ['nome.unique' => 'Já existe uma forma com esse nome.']);
        // taxa por número de parcelas: só de 2 até o máximo, e só as preenchidas
        $d['taxas_parcelas'] = collect($d['taxas_parcelas'] ?? [])
            ->filter(fn ($v, $k) => $v !== null && $v !== '' && (int) $k >= 2 && (int) $k <= $d['parcelas_max'])
            ->mapWithKeys(fn ($v, $k) => [(string) (int) $k => round((float) $v, 2)])->all() ?: null;

        return $d;
    }

    public function storeForma(Request $request): RedirectResponse
    {
        $f = FormaPagamento::create($this->validarForma($request) + ['ordem' => (int) FormaPagamento::max('ordem') + 1]);
        Atividades::registrar('forma_pagamento_criada', $f->nome);

        return back()->with('sucesso', 'Forma de pagamento cadastrada.');
    }

    public function updateForma(Request $request, FormaPagamento $forma): RedirectResponse
    {
        abort_if($forma->tipo === 'nao_informada', 404);
        $forma->update($this->validarForma($request, $forma));
        Atividades::registrar('forma_pagamento_alterada', $forma->nome.($forma->ativa ? '' : ' (inativa)'));

        return back()->with('sucesso', 'Forma de pagamento salva.');
    }

    private function validarConta(Request $request, ?ContaFinanceira $c = null): array
    {
        return $request->validate([
            'nome' => ['required', 'string', 'max:60', Rule::unique('contas_financeiras')->where('empresa_id', app(EmpresaAtual::class)->id())->ignore($c?->id)],
            'tipo' => ['required', Rule::in(array_keys(ContaFinanceira::TIPOS))],
            'ativa' => ['boolean'],
        ], ['nome.unique' => 'Já existe uma conta com esse nome.']);
    }

    public function storeConta(Request $request): RedirectResponse
    {
        $c = ContaFinanceira::create($this->validarConta($request) + ['ordem' => (int) ContaFinanceira::max('ordem') + 1]);
        Atividades::registrar('conta_financeira_criada', $c->nome);

        return back()->with('sucesso', 'Conta cadastrada.');
    }

    public function updateConta(Request $request, ContaFinanceira $conta): RedirectResponse
    {
        $conta->update($this->validarConta($request, $conta));
        Atividades::registrar('conta_financeira_alterada', $conta->nome);

        return back()->with('sucesso', 'Conta salva.');
    }
}
