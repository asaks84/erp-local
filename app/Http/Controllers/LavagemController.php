<?php

namespace App\Http\Controllers;

use App\Models\FormaPagamento;
use App\Models\OrdemServico;
use App\Models\Servico;
use App\Models\TipoVeiculo;
use App\Servicos\Baixa;
use App\Servicos\Estadias;
use App\Servicos\FormasPagamento;
use App\Servicos\Ordens;
use App\Servicos\Precos;
use App\Servicos\Vendas;
use App\Support\Formato;
use App\Support\Placa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Lavagens e serviços: quadro das etapas (aguardando, lavando, pronta, entregue), nova venda e recebimento na entrega. */
class LavagemController extends Controller
{
    /** um cartão do quadro = uma venda (a ordem principal e seus adicionais) */
    private static function cartao(OrdemServico $p, $adicionais): array
    {
        $todas = collect([$p])->merge($adicionais);
        $abertas = $todas->where('situacao', 'aberta');

        return [
            'id' => $p->id,
            'numero' => $p->numero,
            'placa' => $p->placa ? Placa::formatar($p->placa) : null,
            'veiculo' => $p->veiculo_descricao,
            'tipo' => $p->tipoVeiculo?->nome,
            'cliente' => $p->cliente?->only(['id', 'nome']),
            'convenio' => (bool) $p->convenio_id,
            'celular' => $p->celular,
            'servicos' => $todas->map(fn ($o) => $o->descricao)->values(),
            'total' => round((float) $todas->sum('valor'), 2),
            'em_aberto' => round((float) $abertas->sum('valor'), 2),
            'pago' => $abertas->isEmpty(),
            // na conta: cliente cadastrado ou convênio pagam pela cobrança; avulso paga no balcão
            'paga_no_balcao' => $abertas->isNotEmpty() && $abertas->every(fn ($o) => ! $o->cliente_id),
            'etapa' => $p->etapa,
            'data' => $p->data->format('Y-m-d'),
            'criado_em' => $p->criado_em?->format('Y-m-d H:i'),
        ];
    }

    public function index(): Response
    {
        FormasPagamento::garantirPadrao();
        // em andamento (qualquer dia) e as entregues de hoje
        $principais = OrdemServico::with(['cliente:id,nome', 'tipoVeiculo:id,nome'])
            ->where('tipo', 'servico')->whereNull('principal_id')->whereNotNull('etapa')->where('situacao', '<>', 'cancelada')
            ->where(fn ($q) => $q->where('etapa', '<>', 'entregue')->orWhere('atualizado_em', '>=', now()->startOfDay()))
            ->orderBy('criado_em')->limit(300)->get();
        $adicionais = OrdemServico::whereIn('principal_id', $principais->pluck('id'))->where('situacao', '<>', 'cancelada')->get()->groupBy('principal_id');
        $cartoes = $principais->map(fn ($p) => self::cartao($p, $adicionais->get($p->id, collect())));

        return Inertia::render('Lavagens/Index', [
            'cartoes' => $cartoes->values(),
            'etapas' => Vendas::ETAPAS,
            'formas' => FormaPagamento::where('ativa', true)->orderBy('ordem')->get(['id', 'nome', 'tipo', 'parcelas_max']),
        ]);
    }

    public function create(): Response
    {
        FormasPagamento::garantirPadrao();
        $unidade = Ordens::unidadePadrao();
        $tipos = TipoVeiculo::where('ativo', true)->orderBy('nome')->get(['id', 'nome']);
        $servicos = Servico::where('ativo', true)->with(['adicionais' => fn ($q) => $q->where('ativo', true)])->orderBy('nome')->get();
        $precos = [];
        foreach ($tipos as $t) {
            foreach ($servicos as $s) {
                $precos[$t->id][$s->id] = Precos::servico($s->id, $t->id, $unidade);
            }
        }

        return Inertia::render('Lavagens/Nova', [
            'tipos' => $tipos,
            'servicos' => $servicos->where('papel', 'principal')->values()->map(fn ($s) => [
                'id' => $s->id, 'nome' => $s->nome, 'preco_modo' => $s->preco_modo,
                'adicionais' => $s->adicionais->map(fn ($a) => ['id' => $a->id, 'nome' => $a->nome])->values(),
            ]),
            'precos' => $precos,
            'convenios' => EstadiaController::convenios('servico'),
            'formas' => FormaPagamento::where('ativa', true)->orderBy('ordem')->get(['id', 'nome', 'tipo', 'parcelas_max']),
        ]);
    }

    /** placa digitada na venda: veículo cadastrado ou o último celular usado por esse avulso */
    public function placa(Request $request): JsonResponse
    {
        $v = Vendas::veiculoDaPlaca($request->query('placa'));
        if ($v) {
            return response()->json(['veiculo' => [
                'id' => $v->id, 'rotulo' => $v->rotulo(), 'descricao' => trim($v->marca.' '.$v->modelo.' '.$v->cor), 'tipo_id' => $v->tipo_veiculo_id,
                'tipo' => $v->tipo?->nome, 'cliente_id' => $v->cliente_id, 'cliente' => $v->cliente?->nome, 'mensalista' => (bool) Estadias::contratoVigente($v->id),
            ], 'avulso' => null]);
        }
        $p = Placa::normalizar($request->query('placa'));
        $ultima = $p ? OrdemServico::where('placa', $p)->whereNull('cliente_id')->orderByDesc('id')->first(['tipo_veiculo_id', 'celular', 'data']) : null;

        return response()->json(['veiculo' => null, 'avulso' => $ultima ? [
            'tipo_id' => $ultima->tipo_veiculo_id, 'celular' => $ultima->celular, 'ultima' => $ultima->data->format('Y-m-d'),
            'vezes' => OrdemServico::where('placa', $p)->whereNull('principal_id')->where('tipo', 'servico')->count(),
        ] : null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'placa' => ['required', 'string', 'max:10'],
            'tipo_veiculo_id' => ['nullable', 'integer'],
            'celular' => ['nullable', 'string', 'max:20'],
            'servico_id' => ['required', 'integer'],
            'adicionais' => ['nullable', 'array'],
            'adicionais.*' => ['integer'],
            'valor' => ['nullable', 'numeric', 'gt:0', 'max:99999'],
            'convenio_id' => ['nullable', 'integer'],
            'destino' => ['required', 'in:na_hora,entrega,conta'],
            'forma_pagamento_id' => ['nullable', 'integer'],
            'parcelas' => ['nullable', 'integer', 'min:1', 'max:24'],
        ], ['servico_id.required' => 'Escolha o serviço.', 'placa.required' => 'Informe a placa.']);
        [, $msg] = Vendas::vender($d);

        return redirect('/lavagens')->with('sucesso', $msg.'.');
    }

    public function etapa(Request $request, OrdemServico $ordem): RedirectResponse
    {
        $d = $request->validate(['etapa' => ['required', 'in:aguardando,lavando,pronta,entregue']]);
        Vendas::etapa($ordem, $d['etapa']);

        return back();
    }

    /** recebe no balcão o que está em aberto da venda (avulso) */
    public function receber(Request $request, OrdemServico $ordem): RedirectResponse
    {
        $d = $request->validate(['forma_pagamento_id' => ['required', 'integer'], 'parcelas' => ['nullable', 'integer', 'min:1', 'max:24']],
            ['forma_pagamento_id.required' => 'Escolha a forma de pagamento.']);
        $abertas = Vendas::grupo($ordem)->where('situacao', 'aberta')->whereNull('cliente_id');
        if ($abertas->isEmpty()) {
            return back()->with('erro', 'Nada a receber no balcão nesta venda.');
        }
        $p = Baixa::receberNaHora($abertas->all(), (int) $d['forma_pagamento_id'], (int) ($d['parcelas'] ?? 1));

        return back()->with('sucesso', 'Recebido '.Formato::moeda($p->valor).'.');
    }
}
