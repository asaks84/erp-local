<?php

namespace App\Http\Controllers\Pdv;

use App\Http\Controllers\CobrancaController;
use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\ContaFinanceira;
use App\Models\FormaPagamento;
use App\Servicos\Baixa;
use App\Servicos\FormasPagamento;
use App\Servicos\Pdv\Caixas;
use App\Support\Formato;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** API do PDV, caixa (etapa 8.3): situação, receber conta de cliente, suprimento, retirada e fechamento. */
class CaixaPdvController extends Controller
{
    /** o mínimo para a tela do caixa (sem valores: os totais só com o PIN do gerente) */
    public function estado(): JsonResponse
    {
        $c = Caixas::atual();

        return response()->json(['id' => $c->id, 'aberto_em' => $c->aberto_em->format('d/m H:i'), 'aberto_por' => $c->abertoPor?->nome]);
    }

    public function opcoes(): JsonResponse
    {
        FormasPagamento::garantirPadrao();

        return response()->json([
            'categorias' => Caixas::categorias(),
            'bancos' => ContaFinanceira::where('ativa', true)->where('tipo', 'banco')->orderBy('ordem')->orderBy('nome')->get(['id', 'nome']),
            'formas' => FormaPagamento::where('ativa', true)->where('tipo', '<>', 'nao_informada')->orderByDesc('ativa')->orderBy('ordem')->get(['id', 'nome', 'tipo', 'parcelas_max']),
        ]);
    }

    public function resumo(Request $r): JsonResponse
    {
        PdvController::gerente($r, 'Ver o caixa');

        return response()->json(Caixas::resumo(Caixas::atual()));
    }

    public function movimentar(Request $r): JsonResponse
    {
        $d = $r->validate([
            'tipo' => ['required', 'in:suprimento,sangria,pagamento,retirada'],
            'valor' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'motivo' => ['nullable', 'string', 'max:200'],
            'destino' => ['nullable', 'in:cofre,banco'],
            'conta_financeira_id' => ['nullable', 'integer'],
            'plano_conta_id' => ['nullable', 'integer'],
            'descricao' => ['nullable', 'string', 'max:120'],
        ]);
        PdvController::gerente($r, $d['tipo'] === 'suprimento' ? 'Suprimento' : 'Retirada');
        $m = Caixas::movimentar($d);

        return response()->json(['ok' => true, 'mensagem' => $m->rotulo().': '.Formato::moeda((float) $m->valor).'.']);
    }

    public function fechar(Request $r): JsonResponse
    {
        $d = $r->validate([
            'dinheiro_contado' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'troco_deixado' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'observacao' => ['nullable', 'string', 'max:255'],
        ], ['dinheiro_contado.required' => 'Informe o dinheiro contado.', 'troco_deixado.required' => 'Informe o troco que fica.']);
        PdvController::gerente($r, 'Fechar o caixa');
        $c = Caixas::fechar((float) $d['dinheiro_contado'], (float) $d['troco_deixado'], $d['observacao'] ?? null);

        return response()->json(['resumo' => $c->resumo]);
    }

    /** contas em aberto do cliente (receber no balcão) */
    public function contas(int $id): JsonResponse
    {
        $c = Cliente::findOrFail($id);

        return response()->json(['cliente' => ['id' => $c->id, 'nome' => $c->nome], 'contas' => Caixas::contasDoCliente($c->id)]);
    }

    /**
     * Recebe no balcão as contas marcadas, pelo valor exato (créditos do cliente abatem). Em dinheiro, confere
     * o valor recebido e anota o troco. Boleto de cobrança quitada é cancelado no Inter.
     */
    public function receber(Request $r, int $id): JsonResponse
    {
        $d = $r->validate([
            'ordens' => ['required', 'array', 'min:1'], 'ordens.*' => ['integer'],
            'forma_pagamento_id' => ['required', 'integer'],
            'parcelas' => ['nullable', 'integer', 'min:1', 'max:24'],
            'valor_recebido' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ], ['ordens.required' => 'Marque as contas que o cliente está pagando.']);
        PdvController::pessoa($r);
        $cliente = Cliente::findOrFail($id);
        $contas = collect(Caixas::contasDoCliente($cliente->id));
        $marcadas = $contas->whereIn('id', $d['ordens']);
        if ($marcadas->where('credito', false)->count() < count(array_unique($d['ordens']))) {
            throw ValidationException::withMessages(['ordens' => 'Alguma conta já foi paga ou cancelada. Abra de novo.']);
        }
        $total = round($marcadas->sum('valor') + $contas->where('credito', true)->sum('valor'), 2);
        if ($total <= 0) {
            throw ValidationException::withMessages(['ordens' => 'O crédito do cliente já cobre essas contas: dê baixa pela gestão.']);
        }
        $forma = FormaPagamento::find($d['forma_pagamento_id']);
        $recebido = isset($d['valor_recebido']) ? round((float) $d['valor_recebido'], 2) : null;
        if ($forma?->tipo === 'dinheiro' && $recebido !== null && $recebido + 0.001 < $total) {
            throw ValidationException::withMessages(['valor_recebido' => 'O valor recebido é menor que o total ('.Formato::moeda($total).').']);
        }
        $troco = $forma?->tipo === 'dinheiro' && $recebido !== null && $recebido > $total ? round($recebido - $total, 2) : 0.0;
        [$p, $msg] = Baixa::receber($cliente, ['ordens' => $d['ordens'], 'valor' => $total, 'forma_pagamento_id' => $d['forma_pagamento_id'],
            'parcelas' => $d['parcelas'] ?? 1, 'origem' => 'pdv',
            'observacao' => $troco > 0 ? 'Recebido '.Formato::moeda($recebido).' · troco '.Formato::moeda($troco) : null]);
        CobrancaController::cancelarBoletosResolvidos();

        return response()->json([
            'mensagem' => 'Recebido de '.$cliente->nome.': '.Formato::moeda($total).' · '.$msg.'.',
            'troco' => $troco,
            'recibo' => [
                'numero' => $p->id, 'cliente' => $cliente->nome, 'data' => $p->recebido_em->format('d/m/Y H:i'),
                'itens' => $marcadas->merge($contas->where('credito', true))->map(fn ($o) => ['descricao' => trim($o['descricao'].($o['placa'] ? ' · '.$o['placa'] : '')), 'valor' => $o['valor']])->values(),
                'total' => $total, 'forma' => $forma?->nome, 'observacao' => $p->observacao,
            ],
        ]);
    }
}
