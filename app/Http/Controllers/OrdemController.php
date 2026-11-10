<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\OrdemServico;
use App\Models\Servico;
use App\Models\Veiculo;
use App\Servicos\Ordens;
use App\Servicos\Precos;
use App\Support\Placa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Lista de ordens de serviço, lançamento na conta do cliente, alteração e cancelamento. */
class OrdemController extends Controller
{
    public function index(Request $request): Response
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'tipo' => ['nullable', Rule::in(array_keys(OrdemServico::TIPOS))],
            'situacao' => ['nullable', 'in:aberta,paga,cancelada'],
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $q = trim((string) ($f['q'] ?? ''));
        $ordens = OrdemServico::with('cliente:id,nome')
            ->when($q !== '', function ($w) use ($q) {
                $placa = Placa::normalizar($q);
                $w->where(function ($w) use ($q, $placa) {
                    if (ctype_digit($q)) {
                        $w->orWhere('numero', (int) $q);
                    }
                    if ($placa && strlen($placa) >= 3) {
                        $w->orWhere('placa', 'like', '%'.$placa.'%');
                    }
                    $w->orWhereHas('cliente', fn ($c) => $c->where('nome', 'like', '%'.$q.'%'));
                });
            })
            ->when($f['tipo'] ?? null, fn ($w, $t) => $w->where('tipo', $t))
            ->when($f['situacao'] ?? null, fn ($w, $s) => $w->where('situacao', $s))
            ->when($f['de'] ?? null, fn ($w, $d) => $w->where('data', '>=', $d))
            ->when($f['ate'] ?? null, fn ($w, $d) => $w->where('data', '<=', $d))
            ->orderByDesc('data')->orderByDesc('numero')
            ->paginate(50)->withQueryString()
            ->through(fn (OrdemServico $o) => self::linha($o) + ['cliente' => $o->cliente?->only(['id', 'nome'])]);

        return Inertia::render('Ordens/Index', ['ordens' => $ordens, 'filtros' => array_merge(['q' => '', 'tipo' => '', 'situacao' => '', 'de' => '', 'ate' => ''], array_filter($f))]);
    }

    /** dados de uma ordem para as telas */
    public static function linha(OrdemServico $o): array
    {
        return [
            'id' => $o->id,
            'numero' => $o->numero,
            'tipo' => $o->tipo,
            'descricao' => $o->descricao,
            'competencia' => $o->competencia,
            'placa' => $o->placa ? Placa::formatar($o->placa) : null,
            'data' => $o->data->format('Y-m-d'),
            'vencimento' => $o->vencimento?->format('Y-m-d'),
            'valor' => (float) $o->valor,
            'situacao' => $o->situacao,
            'pago_em' => $o->pago_em?->format('Y-m-d H:i'),
            'motivo_cancelamento' => $o->motivo_cancelamento,
            'vencida' => $o->situacao === 'aberta' && $o->tipo !== 'credito' && $o->dataReferencia() < now()->toDateString(),
            'cobranca_id' => $o->cobranca_id,
        ];
    }

    public function create(Cliente $cliente): Response
    {
        $veiculos = $cliente->veiculos()->where('ativo', true)->with('tipo:id,nome')->get();
        $servicos = Servico::where('ativo', true)->with(['adicionais' => fn ($q) => $q->where('ativo', true)])->orderBy('nome')->get();
        $unidade = Ordens::unidadePadrao();
        // preço de cada serviço para o tipo de cada veículo do cliente (hoje)
        $precos = [];
        foreach ($veiculos->pluck('tipo_veiculo_id')->unique() as $tipo) {
            foreach ($servicos as $s) {
                $precos[$tipo][$s->id] = Precos::servico($s->id, $tipo, $unidade);
            }
        }

        return Inertia::render('Ordens/Lancar', [
            'cliente' => $cliente->only(['id', 'nome']),
            'veiculos' => $veiculos->map(fn (Veiculo $v) => ['id' => $v->id, 'rotulo' => $v->rotulo(), 'tipo_id' => $v->tipo_veiculo_id, 'tipo' => $v->tipo?->nome]),
            'servicos' => $servicos->where('papel', 'principal')->values()->map(fn (Servico $s) => [
                'id' => $s->id, 'nome' => $s->nome, 'preco_modo' => $s->preco_modo,
                'adicionais' => $s->adicionais->map(fn ($a) => ['id' => $a->id, 'nome' => $a->nome])->values(),
            ]),
            'precos' => $precos,
            'tipo' => in_array(request('tipo'), ['servico', 'estadia', 'debito', 'credito']) ? request('tipo') : 'servico',
        ]);
    }

    public function store(Request $request, Cliente $cliente): RedirectResponse
    {
        $tipo = $request->input('tipo');
        if (in_array($tipo, ['debito', 'credito']) && ! $request->user()->pode('contas_editar')) {
            abort(403);
        }
        $d = $request->validate([
            'tipo' => ['required', 'in:servico,estadia,debito,credito'],
            'data' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'veiculo_id' => ['exclude_unless:tipo,servico', 'required', 'integer'],
            'servico_id' => ['exclude_unless:tipo,servico', 'required', 'integer'],
            'adicionais' => ['exclude_unless:tipo,servico', 'array'],
            'adicionais.*' => ['integer'],
            'valor' => [$tipo === 'servico' ? 'nullable' : 'required', 'numeric', 'gt:0', 'max:99999'],
            'descricao' => ['nullable', 'string', 'max:80'],
            'motivo' => [in_array($tipo, ['debito', 'credito']) ? 'required' : 'nullable', 'string', 'max:200'],
        ], ['data.before_or_equal' => 'A data não pode ser no futuro.']);

        if ($d['tipo'] === 'servico') {
            $veiculo = Veiculo::where('cliente_id', $cliente->id)->findOrFail($d['veiculo_id']);
            $servico = Servico::where('papel', 'principal')->findOrFail($d['servico_id']);
            $adicionais = Servico::whereIn('id', $d['adicionais'] ?? [])->get()->all();
            $ordens = Ordens::lancarServico($veiculo, $servico, $adicionais, $d['data'], isset($d['valor']) ? (float) $d['valor'] : null);
            $msg = count($ordens) > 1 ? count($ordens).' serviços lançados.' : 'Serviço lançado.';
        } else {
            $padrao = ['estadia' => 'Estadia avulsa', 'debito' => 'Débito', 'credito' => 'Crédito'][$d['tipo']];
            Ordens::lancarNaConta($cliente, $d['tipo'], (float) $d['valor'], trim((string) ($d['descricao'] ?? '')) ?: $padrao, $d['data'], $d['motivo'] ?? null);
            $msg = ['estadia' => 'Estadia lançada', 'debito' => 'Débito lançado', 'credito' => 'Crédito lançado'][$d['tipo']].' na conta.';
        }

        return redirect("/clientes/{$cliente->id}")->with('sucesso', $msg);
    }

    public function update(Request $request, OrdemServico $ordem): RedirectResponse
    {
        $d = $request->validate([
            'valor' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'vencimento' => ['nullable', 'date_format:Y-m-d'],
            'descricao' => ['required', 'string', 'max:80'],
        ]);
        Ordens::alterar($ordem, (float) $d['valor'], $d['vencimento'] ?? null, $d['descricao']);

        return back()->with('sucesso', 'Ordem #'.$ordem->numero.' alterada.');
    }

    public function cancelar(Request $request, OrdemServico $ordem): RedirectResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:200']], ['motivo.required' => 'Informe o motivo do cancelamento.']);
        Ordens::cancelar($ordem, $d['motivo']);

        return back()->with('sucesso', 'Ordem #'.$ordem->numero.' cancelada.');
    }
}
