<?php

namespace App\Http\Controllers;

use App\Models\Convenio;
use App\Models\Estadia;
use App\Models\FormaPagamento;
use App\Models\TipoVeiculo;
use App\Servicos\Estadias;
use App\Servicos\FormasPagamento;
use App\Servicos\Vendas;
use App\Support\Formato;
use App\Support\Placa;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Estadias: pátio (quem está dentro), entrada, cálculo, saída e histórico. */
class EstadiaController extends Controller
{
    public static function linha(Estadia $e): array
    {
        return [
            'id' => $e->id,
            'numero' => $e->numero,
            'rotulo' => $e->rotulo(),
            'placa' => $e->placa,
            'tipo' => $e->tipo?->nome,
            'cliente' => $e->cliente?->only(['id', 'nome']),
            'convenio' => $e->convenio ? ['id' => $e->convenio->id, 'nome' => $e->convenio->cliente?->nome, 'resumo' => $e->convenio->resumo()] : null,
            'mensalista' => $e->mensalista,
            'celular' => $e->celular,
            'entrada' => $e->entrada->format('Y-m-d H:i'),
            'saida' => $e->saida?->format('Y-m-d H:i'),
            'tempo' => $e->minutos !== null ? Formato::duracao($e->minutos) : Formato::duracao((int) $e->entrada->diffInMinutes(now())),
            'valor_calculado' => $e->valor_calculado !== null ? (float) $e->valor_calculado : null,
            'valor_convenio' => (float) $e->valor_convenio,
            'valor_cliente' => $e->valor_cliente !== null ? (float) $e->valor_cliente : null,
            'situacao' => $e->situacao,
            'destino' => $e->destino,
            'destino_rotulo' => $e->destino ? Estadia::DESTINOS[$e->destino] : null,
            'motivo_cancelamento' => $e->motivo_cancelamento,
        ];
    }

    public static function convenios(string $uso): array
    {
        return Convenio::with('cliente:id,nome')->where('ativo', true)->where($uso === 'estadia' ? 'vale_estadia' : 'vale_servicos', true)->get()
            ->sortBy(fn ($c) => $c->cliente?->nome)->values()
            ->map(fn ($c) => ['id' => $c->id, 'nome' => $c->cliente?->nome, 'resumo' => $c->resumo()])->all();
    }

    public function index(Request $request): Response
    {
        FormasPagamento::garantirPadrao();
        $with = ['tipo:id,nome', 'cliente:id,nome', 'convenio.cliente:id,nome'];
        $patio = Estadia::with($with)->where('situacao', 'patio')->orderBy('entrada')->get();
        $hoje = Estadia::with($with)->where('situacao', '<>', 'patio')
            ->where(fn ($q) => $q->whereDate('saida', now()->toDateString())->orWhereDate('cancelada_em', now()->toDateString()))
            ->orderByDesc('atualizado_em')->limit(100)->get();

        return Inertia::render('Estadias/Index', [
            'patio' => $patio->map(fn ($e) => self::linha($e)),
            'hoje' => $hoje->map(fn ($e) => self::linha($e)),
            'resumo_hoje' => [
                'saidas' => $hoje->where('situacao', 'finalizada')->count(),
                'recebido' => round((float) $hoje->where('destino', 'pago')->sum('valor_cliente'), 2),
                'convenio' => round((float) $hoje->sum('valor_convenio'), 2),
            ],
            'tipos' => TipoVeiculo::where('ativo', true)->orderBy('nome')->get(['id', 'nome']),
            'convenios' => self::convenios('estadia'),
            'formas' => FormaPagamento::where('ativa', true)->orderBy('ordem')->get(['id', 'nome', 'tipo', 'parcelas_max']),
        ]);
    }

    /** o que o sistema sabe da placa (cadastro, mensalista) antes da entrada */
    public function placa(Request $request): JsonResponse
    {
        $v = Vendas::veiculoDaPlaca($request->query('placa'));
        if (! $v) {
            return response()->json(['veiculo' => null]);
        }

        return response()->json(['veiculo' => [
            'id' => $v->id, 'rotulo' => $v->rotulo(), 'descricao' => trim($v->marca.' '.$v->modelo.' '.$v->cor), 'tipo_id' => $v->tipo_veiculo_id, 'tipo' => $v->tipo?->nome,
            'cliente_id' => $v->cliente_id, 'cliente' => $v->cliente?->nome, 'mensalista' => (bool) Estadias::contratoVigente($v->id),
        ]]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'placa' => ['nullable', 'string', 'max:10'],
            'identificacao' => ['nullable', 'string', 'max:40'],
            'tipo_veiculo_id' => ['nullable', 'integer'],
            'entrada' => ['nullable', 'date_format:Y-m-d H:i'],
            'convenio_id' => ['nullable', 'integer'],
            'celular' => ['nullable', 'string', 'max:20'],
        ]);
        $e = Estadias::entrar($d);

        return back()->with('sucesso', 'Entrada de '.$e->rotulo().' às '.$e->entrada->format('H:i').' (ticket '.$e->numero.').');
    }

    public function calcular(Request $request, Estadia $estadia): JsonResponse
    {
        $saida = $request->query('saida');
        try {
            $c = Estadias::calcular($estadia->load('tipo', 'convenio.cliente'), $saida ? Carbon::createFromFormat('Y-m-d H:i', $saida) : now());
        } catch (ValidationException $e) {
            return response()->json(['erro' => collect($e->errors())->flatten()->first()], 422);
        } catch (\Throwable $e) {
            return response()->json(['erro' => 'Data de saída inválida.'], 422);
        }

        return response()->json($c);
    }

    public function saida(Request $request, Estadia $estadia): RedirectResponse
    {
        $d = $request->validate([
            'saida' => ['nullable', 'date_format:Y-m-d H:i'],
            'destino' => ['required', 'in:pago,conta,mensalista'],
            'forma_pagamento_id' => ['nullable', 'integer'],
            'parcelas' => ['nullable', 'integer', 'min:1', 'max:24'],
        ]);
        $e = Estadias::sair($estadia, $d);
        $msg = 'Saída de '.$e->rotulo().': '.Formato::duracao((int) $e->minutos).', '.Formato::moeda($e->valor_calculado);
        if ((float) $e->valor_convenio > 0) {
            $msg .= ' (convênio '.Formato::moeda($e->valor_convenio).')';
        }

        return back()->with('sucesso', $msg.' · '.Estadia::DESTINOS[$e->destino].'.');
    }

    public function cancelar(Request $request, Estadia $estadia): RedirectResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:200']], ['motivo.required' => 'Informe o motivo.']);
        Estadias::cancelar($estadia, $d['motivo']);

        return back()->with('sucesso', 'Estadia de '.$estadia->rotulo().' cancelada.');
    }

    public function historico(Request $request): Response
    {
        $f = $request->validate(['q' => ['nullable', 'string', 'max:40'], 'de' => ['nullable', 'date_format:Y-m-d'], 'ate' => ['nullable', 'date_format:Y-m-d']]);
        $de = $f['de'] ?? now()->subDays(30)->toDateString();
        $ate = $f['ate'] ?? now()->toDateString();
        $q = trim((string) ($f['q'] ?? ''));
        $lista = Estadia::with(['tipo:id,nome', 'cliente:id,nome', 'convenio.cliente:id,nome'])
            ->whereBetween('entrada', [$de.' 00:00:00', $ate.' 23:59:59'])
            ->when($q !== '', fn ($w) => $w->where(fn ($w) => $w->where('placa', 'like', '%'.Placa::normalizar($q).'%')
                ->orWhere('identificacao', 'like', '%'.$q.'%')->when(ctype_digit($q), fn ($w) => $w->orWhere('numero', (int) $q))))
            ->orderByDesc('entrada')->paginate(50)->withQueryString()->through(fn ($e) => self::linha($e));

        return Inertia::render('Estadias/Historico', ['estadias' => $lista, 'filtros' => ['q' => $q, 'de' => $de, 'ate' => $ate]]);
    }
}
