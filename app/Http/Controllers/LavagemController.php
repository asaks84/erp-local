<?php

namespace App\Http\Controllers;

use App\Models\OrdemServico;
use App\Servicos\Baixa;
use App\Servicos\Estadias;
use App\Servicos\Vendas;
use App\Support\Formato;
use App\Support\Placa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Lavagens (gravação): venda, etapa e recebimento no balcão. As telas da gestão ficam no Pátio; a venda é do PDV. */
class LavagemController extends Controller
{
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
            'conta_cliente_id' => ['nullable', 'integer'],
            'forma_pagamento_id' => ['nullable', 'integer'],
            'parcelas' => ['nullable', 'integer', 'min:1', 'max:24'],
        ], ['servico_id.required' => 'Escolha o serviço.', 'placa.required' => 'Informe a placa.']);
        [, $msg] = Vendas::vender($d);

        return redirect('/patio')->with('sucesso', $msg.'.');
    }

    public function etapa(Request $request, OrdemServico $ordem): RedirectResponse
    {
        $d = $request->validate(['etapa' => ['required', 'string', 'max:30']]);
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
