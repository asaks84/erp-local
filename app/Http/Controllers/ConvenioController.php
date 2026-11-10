<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Convenio;
use App\Support\Atividades;
use App\Support\EmpresaAtual;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Regra de cada conveniado: paga tudo, paga até um tempo ou desconto para quem usa. */
class ConvenioController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Convenios/Index', [
            'convenios' => Convenio::with('cliente:id,nome')->get()->sortBy('cliente.nome')->values()->map(fn (Convenio $c) => [
                'id' => $c->id,
                'cliente_id' => $c->cliente_id,
                'cliente' => $c->cliente?->nome,
                'resumo' => $c->resumo(),
                'vale' => implode(' e ', array_filter([$c->vale_estadia ? 'estadias' : null, $c->vale_servicos ? 'lavagens e serviços' : null])),
                'ativo' => $c->ativo,
            ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Convenios/Form', ['convenio' => null]);
    }

    public function edit(Convenio $convenio): Response
    {
        return Inertia::render('Convenios/Form', [
            'convenio' => [
                ...$convenio->only(['id', 'cliente_id', 'modo', 'limite_min', 'desconto_tipo', 'vale_estadia', 'vale_servicos', 'observacoes', 'ativo']),
                'desconto_valor' => $convenio->desconto_valor === null ? null : (float) $convenio->desconto_valor,
                'cliente' => $convenio->cliente?->only(['id', 'nome']),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $c = Convenio::create($this->validar($request) + ['atualizado_por' => $request->user()->id]);
        Atividades::registrar('convenio_criado', $c->cliente->nome.': '.$c->resumo());

        return redirect('/convenios')->with('sucesso', 'Convênio cadastrado.');
    }

    public function update(Request $request, Convenio $convenio): RedirectResponse
    {
        $convenio->update($this->validar($request, $convenio) + ['atualizado_por' => $request->user()->id]);
        Atividades::registrar('convenio_alterado', $convenio->cliente->nome.': '.$convenio->resumo());

        return redirect('/convenios')->with('sucesso', 'Convênio salvo.');
    }

    private function validar(Request $request, ?Convenio $c = null): array
    {
        $empresa = app(EmpresaAtual::class)->id();
        $d = $request->validate([
            'cliente_id' => ['required', Rule::exists('clientes', 'id')->where('empresa_id', $empresa),
                Rule::unique('convenios', 'cliente_id')->where('empresa_id', $empresa)->ignore($c?->id)],
            'modo' => ['required', 'in:tudo,tempo,desconto'],
            'limite_min' => ['exclude_unless:modo,tempo', 'required', 'integer', 'min:1', 'max:100000'],
            'desconto_tipo' => ['exclude_unless:modo,desconto', 'required', 'in:pct,valor'],
            'desconto_valor' => ['exclude_unless:modo,desconto', 'required', 'numeric', 'gt:0', 'max:99999'],
            'vale_estadia' => ['boolean'],
            'vale_servicos' => ['boolean'],
            'observacoes' => ['nullable', 'string', 'max:255'],
            'ativo' => ['boolean'],
        ], [
            'cliente_id.unique' => 'Este cliente já tem convênio. Edite o existente.',
            'cliente_id.required' => 'Escolha o cliente conveniado.',
        ]);
        if (($d['desconto_tipo'] ?? null) === 'pct' && $d['desconto_valor'] > 100) {
            throw \Illuminate\Validation\ValidationException::withMessages(['desconto_valor' => 'Desconto em porcentagem vai até 100%.']);
        }
        if (empty($d['vale_estadia']) && empty($d['vale_servicos'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['vale_estadia' => 'Marque se vale para estadias, para lavagens e serviços, ou para os dois.']);
        }

        return $d + ['limite_min' => null, 'desconto_tipo' => null, 'desconto_valor' => null];
    }
}
