<?php

namespace App\Http\Controllers;

use App\Models\Convenio;
use App\Models\Estadia;
use App\Models\TipoVeiculo;
use App\Servicos\Estadias;
use App\Servicos\Ordens;
use App\Servicos\Precos;
use App\Servicos\Vendas;
use App\Support\Formato;
use App\Support\Placa;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Estadias (gravação): entrada, cálculo, saída e cancelamento, comuns à gestão e ao PDV. As telas da gestão
 * ficam no Pátio (PatioController); estas rotas seguem como contingência até o modo sem internet (8.7).
 */
class EstadiaController extends Controller
{
    public static function linha(Estadia $e): array
    {
        return [
            'id' => $e->id,
            'numero' => $e->numero,
            'codigo' => $e->codigo,
            'rotulo' => $e->rotulo(),
            'placa' => $e->placa,
            'modelo' => $e->modelo,
            'diaria' => $e->pacote_id ? ['nome' => $e->pacote?->nome, 'valor' => (float) $e->valor_pacote] : null,
            'pago_entrada' => (float) $e->pago_entrada,
            'retirada' => $e->retirada_nome ? $e->retirada_nome.' ('.$e->retirada_documento.')' : null,
            'pdv' => $e->aparelho_id ? true : false,
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
        return Convenio::with('cliente:id,nome', 'regras.servico')->where('ativo', true)
            ->whereHas('regras', fn ($q) => $uso === 'estadia' ? $q->whereNull('servico_id') : $q->whereNotNull('servico_id'))->get()
            ->sortBy(fn ($c) => $c->cliente?->nome)->values()
            ->map(fn ($c) => ['id' => $c->id, 'nome' => $c->cliente?->nome, 'resumo' => $c->resumo()])->all();
    }

    /** tipos de veículo com o resumo da tabela de estadia que vale hoje (vazio = sem tabela) */
    public static function tiposComTabela(): array
    {
        $unidade = Ordens::unidadePadrao();

        return TipoVeiculo::where('ativo', true)->orderBy('nome')->get(['id', 'nome'])->map(function ($t) use ($unidade) {
            $tarifa = Precos::tarifa($t->id, $unidade);

            return ['id' => $t->id, 'nome' => $t->nome, 'tabela' => $tarifa ? $tarifa->nome.' · '.$tarifa->resumo() : null,
                'diarias' => $tarifa ? Estadias::diarias($t->id)->map(fn ($p) => ['id' => $p->id, 'nome' => $p->nome, 'valor' => (float) $p->valor])->values()->all() : []];
        })->all();
    }

    /** o que o sistema sabe da placa (cadastro, mensalista) antes da entrada */
    public function placa(Request $request): JsonResponse
    {
        $v = Vendas::veiculoDaPlaca($request->query('placa'));
        if (! $v) {
            return response()->json(['veiculo' => null]);
        }

        return response()->json(['veiculo' => [
            'id' => $v->id, 'rotulo' => $v->rotulo(), 'descricao' => trim($v->marca.' '.$v->modelo.' '.$v->cor), 'modelo' => trim($v->marca.' '.$v->modelo) ?: null, 'tipo_id' => $v->tipo_veiculo_id, 'tipo' => $v->tipo?->nome,
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
        ] + self::regrasEntrada());
        $e = Estadias::entrar($d);

        return back()->with('sucesso', 'Entrada de '.$e->rotulo().' às '.$e->entrada->format('H:i').' (ticket '.$e->numero.').');
    }

    /** campos da entrada que a gestão e o PDV têm em comum (modelo, diária marcada e pagamento dela) */
    public static function regrasEntrada(): array
    {
        return [
            'modelo' => ['nullable', 'string', 'max:40'],
            'pacote_id' => ['nullable', 'integer'],
            'pagar_agora' => ['nullable', 'boolean'],
            'forma_pagamento_id' => ['nullable', 'integer'],
            'parcelas' => ['nullable', 'integer', 'min:1', 'max:24'],
            'valor_recebido' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ];
    }

    /** campos da saída comuns à gestão e ao PDV */
    public static function regrasSaida(): array
    {
        return [
            'destino' => ['required', 'in:pago,conta,mensalista'],
            'conta_cliente_id' => ['nullable', 'integer'],
            'convenio_id' => ['nullable', 'integer'],
            'forma_pagamento_id' => ['nullable', 'integer'],
            'parcelas' => ['nullable', 'integer', 'min:1', 'max:24'],
            'valor_recebido' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'perdido' => ['nullable', 'boolean'],
            'retirada_nome' => ['nullable', 'string', 'max:80'],
            'retirada_documento' => ['nullable', 'string', 'max:30'],
        ];
    }

    /** cálculo com o convênio escolhido na tela (sem gravar): convenio_id na query; vazio = sem convênio */
    public static function calculoCom(Estadia $estadia, Request $request, ?CarbonInterface $saida = null): array
    {
        if ($request->has('convenio_id')) {
            $id = (int) $request->query('convenio_id') ?: null;
            if ($id && ! Convenio::where('ativo', true)->whereHas('regras', fn ($q) => $q->whereNull('servico_id'))->whereKey($id)->exists()) {
                throw ValidationException::withMessages(['convenio_id' => 'Convênio não encontrado, inativo ou sem estadias.']);
            }
            $estadia->convenio_id = $id; // só para o cálculo
        }

        return Estadias::calcular($estadia, $saida ?? now());
    }

    public function calcular(Request $request, Estadia $estadia): JsonResponse
    {
        $saida = $request->query('saida');
        try {
            $c = self::calculoCom($estadia->load('tipo'), $request, $saida ? Carbon::createFromFormat('Y-m-d H:i', $saida) : now());
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
        ] + self::regrasSaida());
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
}
