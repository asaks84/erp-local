<?php

namespace App\Http\Controllers;

use App\Models\Caixa;
use App\Models\CaixaMovimento;
use App\Models\ContaFinanceira;
use App\Servicos\Pdv\Caixas;
use App\Support\Atividades;
use App\Support\Formato;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Gestão › Resultado e caixa › Caixa do PDV: caixas do balcão (fechamentos) e retiradas dos sócios. */
class CaixaController extends Controller
{
    public function index(Request $request): Response
    {
        $f = $request->validate(['mes' => ['nullable', 'date_format:Y-m'], 'caixa' => ['nullable', 'integer']]);
        $mes = $f['mes'] ?? now()->format('Y-m');
        $ini = Carbon::createFromFormat('Y-m-d', $mes.'-01')->startOfDay();
        $fim = $ini->copy()->endOfMonth();
        $caixas = Caixa::with(['abertoPor:id,nome', 'fechadoPor:id,nome'])
            ->where(fn ($q) => $q->whereBetween('aberto_em', [$ini, $fim->copy()->endOfDay()])->orWhere('situacao', 'aberto'))
            ->orderByDesc('aberto_em')->get();
        $sel = isset($f['caixa']) ? Caixa::find($f['caixa']) : null;

        return Inertia::render('Resultado/Caixas', [
            'mes' => $mes,
            'caixas' => $caixas->map(fn (Caixa $c) => [
                'id' => $c->id, 'situacao' => $c->situacao, 'aberto_em' => $c->aberto_em->format('Y-m-d H:i'), 'fechado_em' => $c->fechado_em?->format('Y-m-d H:i'),
                'aberto_por' => $c->abertoPor?->nome, 'fechado_por' => $c->fechadoPor?->nome, 'troco_inicial' => (float) $c->troco_inicial,
                'total_recebido' => $c->situacao === 'fechado' ? (float) ($c->resumo['total_recebido'] ?? 0) : null,
                'esperado' => $c->dinheiro_esperado !== null ? (float) $c->dinheiro_esperado : null,
                'contado' => $c->dinheiro_contado !== null ? (float) $c->dinheiro_contado : null,
                'diferenca' => $c->diferenca !== null ? (float) $c->diferenca : null, 'observacao' => $c->observacao,
            ]),
            'detalhe' => $sel ? Caixas::resumo($sel) + ['observacao' => $sel->observacao] : null,
            'retiradas' => CaixaMovimento::with(['usuario:id,nome', 'contaFinanceira:id,nome'])->where('tipo', 'retirada')
                ->whereBetween('data', [$ini->toDateString(), $fim->toDateString()])->orderByDesc('data')->orderByDesc('id')->get()
                ->map(fn (CaixaMovimento $m) => ['id' => $m->id, 'data' => $m->data->format('Y-m-d'), 'valor' => (float) $m->valor,
                    'origem' => $m->caixa_id ? 'Caixa do PDV' : ($m->contaFinanceira?->nome ?? '—'), 'motivo' => $m->motivo, 'quem' => $m->usuario?->nome,
                    'pdv' => (bool) $m->caixa_id]),
            'contas' => ContaFinanceira::where('ativa', true)->orderBy('ordem')->orderBy('nome')->get(['id', 'nome', 'tipo']),
        ]);
    }

    public function retirada(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'valor' => ['required', 'numeric', 'min:0.01', 'max:10000000'],
            'data' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'conta_financeira_id' => ['required', 'integer'],
            'motivo' => ['nullable', 'string', 'max:200'],
        ], ['conta_financeira_id.required' => 'Escolha de onde saiu.']);
        Caixas::retiradaGestao($d);

        return back()->with('sucesso', 'Retirada do sócio registrada: '.Formato::moeda((float) $d['valor']).'.');
    }

    /** só a registrada na gestão (a do PDV faz parte de um caixa) */
    public function apagarRetirada(CaixaMovimento $movimento): RedirectResponse
    {
        if ($movimento->tipo !== 'retirada' || $movimento->caixa_id) {
            return back()->with('erro', 'A retirada feita no PDV faz parte do caixa e não pode ser apagada aqui.');
        }
        Atividades::registrar('caixa_movimento', 'retirada do sócio apagada: '.Formato::moeda((float) $movimento->valor).' de '.$movimento->data->format('d/m/Y'));
        $movimento->delete();

        return back()->with('sucesso', 'Retirada apagada.');
    }
}
