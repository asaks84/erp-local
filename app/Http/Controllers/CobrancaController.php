<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\Empresa;
use App\Models\OrdemServico;
use App\Servicos\Cobrancas;
use App\Servicos\Mensalidades;
use App\Support\Documento;
use App\Support\Placa;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Cobranças: emissão (uma por cliente ou em lote), consulta, impressão e cancelamento. */
class CobrancaController extends Controller
{
    private function mes(Request $request): string
    {
        $mes = (string) $request->query('mes', '');

        return Mensalidades::competenciaValida($mes) ? $mes : now()->format('Y-m');
    }

    public function index(Request $request): Response
    {
        $mes = $this->mes($request);
        $f = $request->validate(['situacao' => ['nullable', 'in:aberta,parcial,paga,levada,cancelada'], 'q' => ['nullable', 'string', 'max:80']]);
        $base = Cobranca::where('competencia', $mes);
        $lista = (clone $base)->with('cliente:id,nome')
            ->when($f['situacao'] ?? null, fn ($q, $s) => $q->where('situacao', $s))
            ->when($f['q'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w->whereHas('cliente', fn ($c) => $c->where('nome', 'like', '%'.$t.'%'))->when(ctype_digit($t), fn ($w) => $w->orWhere('numero', (int) $t))))
            ->orderByDesc('numero')->paginate(50)->withQueryString()
            ->through(fn (Cobranca $c) => self::resumo($c) + ['cliente' => $c->cliente->only(['id', 'nome'])]);
        $validas = (clone $base)->where('situacao', '<>', 'cancelada');

        return Inertia::render('Cobrancas/Index', [
            'mes' => $mes,
            'cobrancas' => $lista,
            'filtros' => ['situacao' => $f['situacao'] ?? '', 'q' => $f['q'] ?? ''],
            'resumo' => ['emitidas' => (clone $validas)->count(), 'total' => round((float) (clone $validas)->sum('total'), 2),
                'pagas' => (clone $validas)->where('situacao', 'paga')->count()],
        ]);
    }

    public static function resumo(Cobranca $c): array
    {
        return [
            'id' => $c->id,
            'numero' => $c->numero,
            'competencia' => $c->competencia,
            'emitida_em' => $c->emitida_em->format('Y-m-d H:i'),
            'vencimento' => $c->vencimento->format('Y-m-d'),
            'total' => (float) $c->total,
            'situacao' => $c->situacao,
            'motivo_cancelamento' => $c->motivo_cancelamento,
        ];
    }

    public function gerar(Request $request): Response
    {
        $mes = $this->mes($request);

        return Inertia::render('Cobrancas/Gerar', ['mes' => $mes, 'linhas' => Cobrancas::previa($mes)]);
    }

    public function emitirLote(Request $request): RedirectResponse
    {
        $d = $request->validate(['mes' => ['required', 'date_format:Y-m'], 'clientes' => ['required', 'array', 'min:1'], 'clientes.*' => ['integer']],
            ['clientes.required' => 'Marque pelo menos um cliente.']);
        [$emitidas, $erros] = Cobrancas::emitirLote($d['mes'], $d['clientes']);
        $r = redirect('/cobrancas?mes='.$d['mes'])->with('sucesso', count($emitidas).' cobrança(s) emitida(s).');
        if ($erros) {
            $r->with('aviso', 'Ficaram de fora: '.implode(' ', array_slice($erros, 0, 5)).(count($erros) > 5 ? ' (e mais '.(count($erros) - 5).')' : ''));
        }
        if ($emitidas) {
            $r->with('imprimir', collect($emitidas)->pluck('id')->all());
        }

        return $r;
    }

    public function emitir(Request $request, Cliente $cliente): RedirectResponse
    {
        $d = $request->validate(['mes' => ['required', 'date_format:Y-m'], 'vencimento' => ['nullable', 'date_format:Y-m-d']]);
        $c = Cobrancas::emitir($cliente, $d['mes'], $d['vencimento'] ?? null);

        return redirect("/cobrancas/{$c->id}")->with('sucesso', 'Cobrança '.$c->numero.' emitida.');
    }

    public function show(Cobranca $cobranca): Response
    {
        $cobranca->load(['cliente', 'itens.ordem']);

        return Inertia::render('Cobrancas/Show', [
            'cobranca' => self::resumo($cobranca),
            'cliente' => $cobranca->cliente->only(['id', 'nome']),
            'itens' => $cobranca->itens->map(fn ($i) => [
                'grupo' => $i->grupo,
                'numero' => $i->numero,
                'tipo' => $i->tipo,
                'descricao' => $i->descricao,
                'placa' => $i->placa ? Placa::formatar($i->placa) : null,
                'data' => $i->data->format('Y-m-d'),
                'vencimento' => $i->vencimento?->format('Y-m-d'),
                'valor' => (float) $i->valor,
                // como a ordem está agora
                'agora' => match (true) {
                    ! $i->ordem => 'removida',
                    $i->ordem->situacao === 'aberta' && $i->ordem->cobranca_id != $cobranca->id => 'levada',
                    default => $i->ordem->situacao,
                },
            ]),
        ]);
    }

    public function imprimir(Request $request): Response
    {
        $ids = array_slice(array_map('intval', (array) $request->query('ids', [])), 0, 200);
        $empresa = Empresa::atual();
        $cobrancas = Cobranca::with(['cliente', 'itens.ordem:id,competencia,veiculo_descricao'])->whereIn('id', $ids)->get()->sortBy(fn ($c) => $c->cliente->nome)->values();

        return Inertia::render('Cobrancas/Imprimir', [
            'empresa' => [
                'nome' => $empresa->nome,
                'documento' => Documento::formatar($empresa->documento),
                'telefone' => $empresa->telefone,
                'logo_url' => $empresa->logo ? '/storage/'.$empresa->logo : null,
                'instrucoes' => $empresa->config('cobranca_instrucoes'),
            ],
            'cobrancas' => $cobrancas->map(fn (Cobranca $c) => self::resumo($c) + [
                'cliente' => ['id' => $c->cliente->id, 'nome' => $c->cliente->nome, 'documento' => Documento::formatar($c->cliente->documento)],
                'mes' => Carbon::parse($c->competencia.'-01')->format('m/Y'),
                'itens' => $c->itens->map(fn ($i) => [
                    'grupo' => $i->grupo, 'numero' => $i->numero, 'descricao' => $i->descricao,
                    'placa' => $i->placa ? Placa::formatar($i->placa) : null, 'data' => $i->data->format('Y-m-d'),
                    'vencimento' => $i->vencimento?->format('Y-m-d'), 'valor' => (float) $i->valor, 'tipo' => $i->tipo,
                    // a ordem guarda a cópia do veículo e o mês da mensalidade de quando foi lançada
                    'competencia' => $i->ordem?->competencia, 'veiculo' => $i->ordem?->veiculo_descricao,
                ]),
            ]),
        ]);
    }

    public function cancelar(Request $request, Cobranca $cobranca): RedirectResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:200']], ['motivo.required' => 'Informe o motivo do cancelamento.']);
        Cobrancas::cancelar($cobranca, $d['motivo']);

        return back()->with('sucesso', 'Cobrança '.$cobranca->numero.' cancelada; as contas continuam em aberto.');
    }
}
