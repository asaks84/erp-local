<?php

namespace App\Http\Controllers;

use App\Models\Convenio;
use App\Models\Servico;
use App\Servicos\Convenios;
use App\Support\Atividades;
use App\Support\EmpresaAtual;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Convênios: para cada serviço marcado (e a estadia), pagamento integral, parcial (o convênio paga até um
 * valor) ou limite de tempo (estadia); e o fechamento (com a mensalidade, a cada X dias ou num dia da semana).
 */
class ConvenioController extends Controller
{
    public function index(): Response
    {
        $aFechar = Convenios::aFechar()->keyBy(fn ($x) => $x['convenio']->id);

        return Inertia::render('Convenios/Index', [
            'convenios' => Convenio::with('cliente:id,nome', 'regras.servico')->get()->sortBy([['ativo', 'desc'], fn ($a, $b) => strcmp((string) $a->cliente?->nome, (string) $b->cliente?->nome)])->values()->map(fn (Convenio $c) => [
                'id' => $c->id,
                'cliente_id' => $c->cliente_id,
                'cliente' => $c->cliente?->nome,
                'resumo' => $c->resumo(),
                'fechamento' => $c->resumoFechamento(),
                'reconfigurar' => $c->reconfigurar,
                'fechar' => isset($aFechar[$c->id]) ? ['itens' => $aFechar[$c->id]['itens'], 'total' => $aFechar[$c->id]['total']] : null,
                'ativo' => $c->ativo,
            ]),
        ]);
    }

    private function form(?Convenio $c): Response
    {
        return Inertia::render('Convenios/Form', [
            'convenio' => $c ? [
                ...$c->only(['id', 'cliente_id', 'observacoes', 'ativo', 'reconfigurar', 'fechamento', 'fechamento_dias', 'fechamento_semana']),
                'cliente' => $c->cliente?->only(['id', 'nome']),
                'regras' => $c->regras->map(fn ($r) => ['servico_id' => $r->servico_id, 'modo' => $r->modo,
                    'limite_valor' => $r->limite_valor === null ? null : (float) $r->limite_valor, 'limite_min' => $r->limite_min])->values(),
                'regras_no_erp' => $c->regras_no_erp,
            ] : null,
            'opcoes' => Convenios::opcoes(),
            'modos' => Convenios::modos(),
            'fechamentos' => Convenio::FECHAMENTOS,
            'dias_semana' => Convenio::DIAS_SEMANA,
        ]);
    }

    public function create(): Response
    {
        return $this->form(null);
    }

    public function edit(Convenio $convenio): Response
    {
        return $this->form($convenio->load('regras', 'cliente'));
    }

    public function store(Request $request): RedirectResponse
    {
        [$d, $regras] = $this->validar($request);
        $c = Convenio::create($d + ['atualizado_por' => $request->user()->id]);
        Convenios::salvarRegras($c, $regras);
        Atividades::registrar('convenio_criado', $c->cliente->nome.': '.$c->fresh()->resumo());

        return redirect('/convenios')->with('sucesso', 'Convênio cadastrado.');
    }

    public function update(Request $request, Convenio $convenio): RedirectResponse
    {
        [$d, $regras] = $this->validar($request, $convenio);
        $convenio->update($d + ['atualizado_por' => $request->user()->id]);
        Convenios::salvarRegras($convenio, $regras);
        Atividades::registrar('convenio_alterado', $convenio->cliente->nome.': '.$convenio->fresh()->resumo());

        return redirect('/convenios')->with('sucesso', 'Convênio salvo.');
    }

    /** "Gerar cobrança" do aviso de fechamento */
    public function fechar(Convenio $convenio): RedirectResponse
    {
        if (Convenios::ordensSoltas($convenio)->isEmpty()) {
            return back()->with('erro', 'Este convênio não tem contas para fechar.');
        }
        $cob = Convenios::fechar($convenio);

        return redirect("/cobrancas/{$cob->id}")->with('sucesso', 'Fechamento do convênio: cobrança '.$cob->numero.' gerada.');
    }

    private function validar(Request $request, ?Convenio $c = null): array
    {
        $empresa = app(EmpresaAtual::class)->id();
        $d = $request->validate([
            'cliente_id' => ['required', Rule::exists('clientes', 'id')->where('empresa_id', $empresa),
                Rule::unique('convenios', 'cliente_id')->where('empresa_id', $empresa)->ignore($c?->id)],
            'regras' => ['array'],
            'regras.*.servico_id' => ['nullable', 'integer'],
            'regras.*.modo' => ['required', Rule::in(array_keys(Convenios::modos()))],
            'regras.*.limite_valor' => ['nullable', 'numeric', 'gt:0', 'max:99999'],
            'regras.*.limite_min' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'fechamento' => ['required', Rule::in(array_keys(Convenio::FECHAMENTOS))],
            'fechamento_dias' => ['exclude_unless:fechamento,dias', 'required', 'integer', 'min:1', 'max:90'],
            'fechamento_semana' => ['exclude_unless:fechamento,semana', 'required', 'integer', 'between:1,7'],
            'observacoes' => ['nullable', 'string', 'max:255'],
            'ativo' => ['boolean'],
        ], [
            'cliente_id.unique' => 'Este cliente já tem convênio. Edite o existente.',
            'cliente_id.required' => 'Escolha o cliente conveniado.',
            'fechamento_dias.required' => 'Informe a cada quantos dias.',
            'fechamento_semana.required' => 'Escolha o dia da semana.',
        ]);
        $regras = array_values($d['regras'] ?? []);
        if (! $regras) {
            throw ValidationException::withMessages(['regras' => 'Marque pelo menos um serviço (ou a estadia) que o convênio paga.']);
        }
        $servicos = Servico::pluck('id')->map(fn ($x) => (int) $x)->all();
        $vistos = [];
        foreach ($regras as $i => $r) {
            $sid = isset($r['servico_id']) && $r['servico_id'] !== '' ? (int) $r['servico_id'] : null;
            if ($sid !== null && ! in_array($sid, $servicos, true)) {
                throw ValidationException::withMessages(["regras.{$i}.servico_id" => 'Serviço não encontrado.']);
            }
            if (in_array($sid ?? 0, $vistos, true)) {
                throw ValidationException::withMessages(["regras.{$i}.servico_id" => 'Serviço repetido.']);
            }
            $vistos[] = $sid ?? 0;
            if ($r['modo'] === 'tempo' && $sid !== null) {
                throw ValidationException::withMessages(["regras.{$i}.modo" => 'Limite de tempo só vale para a estadia.']);
            }
            if ($r['modo'] === 'parcial' && empty($r['limite_valor'])) {
                throw ValidationException::withMessages(["regras.{$i}.limite_valor" => 'Informe até quanto o convênio paga.']);
            }
            if ($r['modo'] === 'tempo' && empty($r['limite_min'])) {
                throw ValidationException::withMessages(["regras.{$i}.limite_min" => 'Informe até quanto tempo o convênio paga.']);
            }
            $regras[$i]['servico_id'] = $sid;
        }
        unset($d['regras']);

        return [$d + ['fechamento_dias' => null, 'fechamento_semana' => null], $regras];
    }
}
