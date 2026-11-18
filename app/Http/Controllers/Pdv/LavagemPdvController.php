<?php

namespace App\Http\Controllers\Pdv;

use App\Http\Controllers\Controller;
use App\Http\Controllers\EstadiaController;
use App\Models\Estadia;
use App\Models\FormaPagamento;
use App\Models\OrdemServico;
use App\Models\Pagamento;
use App\Models\Servico;
use App\Models\TipoVeiculo;
use App\Servicos\Estadias;
use App\Servicos\EtapasLavagem;
use App\Servicos\FormasPagamento;
use App\Servicos\Ordens;
use App\Servicos\Pdv\Lavagens;
use App\Servicos\Precos;
use App\Servicos\Vendas;
use App\Support\Formato;
use App\Support\Placa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** API do PDV, lavagens (etapa 8.4): andamento, nova lavagem, etapas, busca do ticket, cálculo e entrega. */
class LavagemPdvController extends Controller
{
    /** lavagem (venda) para as telas: a principal e os adicionais */
    private static function cartao(OrdemServico $p): array
    {
        $grupo = Vendas::grupo($p);
        $p = $grupo->first()->loadMissing(['tipoVeiculo:id,nome', 'cliente:id,nome', 'veiculo:id,modelo']);
        $etapas = EtapasLavagem::ativas();
        $abertas = $grupo->where('situacao', 'aberta');
        $prox = Lavagens::proximaEtapa($p->etapa ?? EtapasLavagem::PRIMEIRA);

        return [
            'id' => $p->id, 'numero' => $p->numero, 'codigo' => $p->uuid,
            'placa' => $p->placa ? Placa::formatar($p->placa) : null, 'modelo' => $p->veiculo_descricao ?: $p->veiculo?->modelo,
            'tipo' => $p->tipoVeiculo?->nome, 'cliente' => $p->cliente?->only(['id', 'nome']),
            'servicos' => $grupo->where('situacao', '<>', 'cancelada')->map(fn ($o) => $o->descricao)->values(),
            'total' => round((float) $grupo->where('situacao', '<>', 'cancelada')->sum('valor'), 2),
            'pago' => $abertas->whereNull('cliente_id')->isEmpty(),
            'etapa' => $p->etapa, 'etapa_nome' => $etapas[$p->etapa] ?? \App\Models\EtapaLavagem::where('chave', $p->etapa)->value('nome') ?? ucfirst((string) $p->etapa),
            'proxima' => $prox ? ['chave' => $prox, 'nome' => $etapas[$prox]] : null,
            'pronta' => $p->etapa === Lavagens::etapaPronta(),
            'desde' => $p->criado_em?->format('Y-m-d H:i'),
            'pdv' => $p->origem === 'pdv',
        ];
    }

    public function opcoes(): JsonResponse
    {
        FormasPagamento::garantirPadrao();
        $unidade = Ordens::unidadePadrao();
        $tipos = TipoVeiculo::where('ativo', true)->orderByDesc('ativo')->orderBy('ordem')->get(['id', 'nome']);
        $servicos = Servico::where('ativo', true)->where('papel', 'principal')->with(['adicionais' => fn ($q) => $q->where('ativo', true)])->orderBy('nome')->get();
        $preco = function ($sid) use ($tipos, $unidade) {
            $r = [];
            foreach ($tipos as $t) {
                $v = Precos::servico($sid, $t->id, $unidade);
                if ($v !== null) {
                    $r[$t->id] = (float) $v;
                }
            }

            return $r;
        };

        return response()->json([
            'tipos' => $tipos,
            'servicos' => $servicos->map(fn ($s) => ['id' => $s->id, 'nome' => $s->nome, 'preco_modo' => $s->preco_modo, 'precos' => $preco($s->id),
                'adicionais' => $s->adicionais->map(fn ($a) => ['id' => $a->id, 'nome' => $a->nome, 'precos' => $preco($a->id)])->values()])->values(),
            'formas' => FormaPagamento::where('ativa', true)->where('tipo', '<>', 'nao_informada')->orderBy('ordem')->get(['id', 'nome', 'tipo', 'parcelas_max']),
            'convenios' => EstadiaController::convenios('servico'),
            'estadia_depois' => Lavagens::regraEstadia(),
        ]);
    }

    public function andamento(): JsonResponse
    {
        $lista = OrdemServico::with(['cliente:id,nome', 'tipoVeiculo:id,nome', 'veiculo:id,modelo'])
            ->where('tipo', 'servico')->whereNull('principal_id')->whereNotNull('etapa')->where('situacao', '<>', 'cancelada')
            ->where('etapa', '<>', EtapasLavagem::ULTIMA)->orderBy('criado_em')->limit(200)->get();

        return response()->json($lista->map(fn ($p) => self::cartao($p))->values());
    }

    /** sugestão para a venda: veículo cadastrado ou o último avulso com essa placa (tamanho e celular) */
    public function placa(string $placa): JsonResponse
    {
        $p = Placa::normalizar($placa);
        if (! $p) {
            return response()->json(['placa' => null]);
        }
        $v = Vendas::veiculoDaPlaca($p);
        $ultima = $v ? null : OrdemServico::where('placa', $p)->whereNull('cliente_id')->orderByDesc('id')->first(['tipo_veiculo_id', 'celular', 'data', 'veiculo_descricao']);
        $patio = Estadia::where('situacao', 'patio')->where('placa', $p)->first();
        $andamento = OrdemServico::where('placa', $p)->where('tipo', 'servico')->whereNull('principal_id')->whereNotNull('etapa')
            ->where('etapa', '<>', EtapasLavagem::ULTIMA)->where('situacao', '<>', 'cancelada')->first();

        return response()->json([
            'placa' => Placa::formatar($p),
            'em_andamento' => $andamento ? ['numero' => $andamento->numero] : null,
            'estadia' => $patio ? ['id' => $patio->id, 'numero' => $patio->numero, 'entrada' => $patio->entrada->format('d/m H:i'), 'pago_entrada' => (float) $patio->pago_entrada] : null,
            'veiculo' => $v ? ['modelo' => trim($v->marca.' '.$v->modelo) ?: null, 'tipo_id' => $v->tipo_veiculo_id, 'cliente' => $v->cliente?->nome,
                'cliente_id' => $v->cliente_id, 'mensalista' => (bool) Estadias::contratoVigente($v->id)] : null,
            'ultima' => $ultima ? ['modelo' => $ultima->veiculo_descricao, 'tipo_id' => $ultima->tipo_veiculo_id, 'celular' => $ultima->celular, 'quando' => $ultima->data->format('d/m/Y')] : null,
        ]);
    }

    public function vender(Request $r): JsonResponse
    {
        $d = $r->validate([
            'placa' => ['required', 'string', 'max:10'],
            'modelo' => ['nullable', 'string', 'max:60'],
            'tipo_veiculo_id' => ['required', 'integer'],
            'celular' => ['nullable', 'string', 'max:20'],
            'servico_id' => ['required', 'integer'],
            'adicionais' => ['nullable', 'array'], 'adicionais.*' => ['integer'],
            'valor' => ['nullable', 'numeric', 'gt:0', 'max:99999'],
            'pagar_agora' => ['nullable', 'boolean'],
            'forma_pagamento_id' => ['nullable', 'integer'],
            'parcelas' => ['nullable', 'integer', 'min:1', 'max:24'],
            'valor_recebido' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'estadia_id' => ['nullable', 'integer'],
            'estadia_acao' => ['nullable', 'in:cancelar,manter'],
        ], ['servico_id.required' => 'Escolha o serviço.', 'placa.required' => 'Informe a placa.', 'tipo_veiculo_id.required' => 'Escolha o tamanho do veículo.']);
        // carro que está em estadia: cancelar a estadia pede o PIN de um gerente (regra do cancelamento)
        $estadia = ! empty($d['estadia_id']) ? Estadia::where('situacao', 'patio')->find($d['estadia_id']) : null;
        if ($estadia && ($d['estadia_acao'] ?? null) === null) {
            throw ValidationException::withMessages(['estadia_acao' => 'O carro está no pátio: escolha o que fazer com a estadia.']);
        }
        $estadia && $d['estadia_acao'] === 'cancelar' ? PdvController::gerente($r, 'Cancelar a estadia') : PdvController::pessoa($r);
        if ($estadia && $d['estadia_acao'] === 'cancelar' && (float) $estadia->pago_entrada > 0) {
            throw ValidationException::withMessages(['estadia_acao' => 'A diária dessa estadia foi paga na entrada: mantenha a estadia (ou estorne o pagamento pela gestão).']);
        }
        $agora = ! empty($d['pagar_agora']);
        $forma = $agora ? FormaPagamento::find($d['forma_pagamento_id'] ?? 0) : null;
        [$ordens, $msg, $principal] = DB::transaction(function () use ($d, $agora, $estadia) {
            [$ordens, $msg] = Vendas::vender($d + ['destino' => $agora ? 'na_hora' : 'entrega']);
            if ($estadia && $d['estadia_acao'] === 'cancelar') {
                Estadias::cancelar($estadia, 'Virou lavagem Nº '.$ordens[0]->numero);
                $msg .= ' · estadia Nº '.$estadia->numero.' cancelada';
            }

            return [$ordens, $msg, $ordens[0]];
        });
        $troco = 0.0;
        $pag = null;
        if ($agora) {
            $pag = Pagamento::whereHas('itens', fn ($q) => $q->where('ordem_id', $principal->id))->latest('id')->first();
            $recebido = isset($d['valor_recebido']) ? round((float) $d['valor_recebido'], 2) : null;
            if ($pag && $forma?->tipo === 'dinheiro' && $recebido !== null) {
                if ($recebido + 0.001 < (float) $pag->valor) {
                    throw ValidationException::withMessages(['valor_recebido' => 'O valor recebido é menor que o total ('.Formato::moeda((float) $pag->valor).').']);
                }
                $troco = round($recebido - (float) $pag->valor, 2);
                if ($troco > 0) {
                    $pag->update(['observacao' => 'Recebido '.Formato::moeda($recebido).' · troco '.Formato::moeda($troco)]);
                }
            }
        }

        return response()->json(['lavagem' => self::cartao($principal->fresh()), 'mensagem' => $msg.'.', 'troco' => $troco,
            'recibo' => self::recibo($principal->fresh(), $pag?->fresh())]);
    }

    public function etapa(Request $r, int $id): JsonResponse
    {
        PdvController::pessoa($r);
        $o = OrdemServico::findOrFail($id);
        $prox = Lavagens::proximaEtapa($o->etapa ?? EtapasLavagem::PRIMEIRA)
            ?? throw ValidationException::withMessages(['etapa' => 'Já está na última etapa antes da entrega: entregue pelo botão Entregar.']);
        Vendas::etapa($o, $prox);

        return response()->json(['lavagem' => self::cartao($o->fresh())]);
    }

    /** acha a lavagem pelo QR (código), pelo número ou pela placa; só as em andamento */
    public function buscar(Request $r): JsonResponse
    {
        $q = trim((string) $r->query('q'));
        if (preg_match('~/t/([0-9a-f-]{36})~i', $q, $m)) {
            $q = $m[1];
        }
        $base = OrdemServico::where('tipo', 'servico')->whereNull('principal_id')->whereNotNull('etapa')->where('situacao', '<>', 'cancelada')
            ->where('etapa', '<>', EtapasLavagem::ULTIMA);
        $o = null;
        if (preg_match('/^[0-9a-f-]{36}$/i', $q)) {
            $o = (clone $base)->where('uuid', strtolower($q))->first();
        }
        if (! $o && ctype_digit($q) && $q !== '') {
            $o = (clone $base)->where('numero', (int) $q)->first();
        }
        if (! $o && ($p = Placa::normalizar($q))) {
            $o = (clone $base)->where('placa', $p)->orderByDesc('id')->first();
        }
        if (! $o) {
            return response()->json(['erro' => 'Nenhuma lavagem em andamento com esse ticket ou placa.'], 404);
        }

        return response()->json(['lavagem' => self::cartao($o)]);
    }

    public function calculo(Request $r, int $id): JsonResponse
    {
        $o = OrdemServico::findOrFail($id);
        try {
            $c = Lavagens::calculo($o, (int) $r->query('convenio_id') ?: null);
        } catch (ValidationException $ex) {
            return response()->json(['erro' => collect($ex->errors())->flatten()->first()], 422);
        }

        return response()->json(['lavagem' => self::cartao($o), 'calculo' => $c]);
    }

    public function entregar(Request $r, int $id): JsonResponse
    {
        $d = $r->validate([
            'convenio_id' => ['nullable', 'integer'],
            'destino' => ['required', 'in:pago,conta'],
            'conta_cliente_id' => ['nullable', 'integer'],
            'forma_pagamento_id' => ['nullable', 'integer'],
            'parcelas' => ['nullable', 'integer', 'min:1', 'max:24'],
            'valor_recebido' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);
        PdvController::pessoa($r);
        [$principal, $pag, $troco, $msg] = Lavagens::entregar(OrdemServico::findOrFail($id), $d);

        return response()->json(['mensagem' => $msg.'.', 'troco' => $troco, 'recibo' => self::recibo($principal, $pag)]);
    }

    /** ticket / recibo da lavagem */
    private static function recibo(OrdemServico $p, ?Pagamento $pag): array
    {
        $grupo = Vendas::grupo($p)->where('situacao', '<>', 'cancelada')->load('cliente:id,nome');
        $p = $grupo->first()->loadMissing(['tipoVeiculo:id,nome', 'veiculo:id,modelo']);
        $pag?->loadMissing('forma:id,nome');

        return [
            'numero' => $p->numero, 'codigo' => $p->uuid, 'placa' => $p->placa ? Placa::formatar($p->placa) : '',
            'modelo' => $p->veiculo_descricao ?: $p->veiculo?->modelo, 'tipo' => $p->tipoVeiculo?->nome,
            'servico' => $grupo->where('tipo', 'servico')->pluck('descricao')->map(fn ($s) => preg_replace('/ · .*$/', '', $s))->unique()->implode(' + '),
            'entrada' => $p->criado_em?->format('d/m/Y H:i'), 'saida' => $p->etapa === EtapasLavagem::ULTIMA ? now()->format('d/m/Y H:i') : null,
            'itens' => $grupo->map(fn ($o) => ['descricao' => $o->descricao, 'valor' => (float) $o->valor, 'situacao' => $o->situacao,
                'conta' => $o->situacao === 'aberta' && $o->cliente_id ? $o->cliente?->nome : null])->values(),
            'pago' => $pag ? ['valor' => (float) $pag->valor, 'forma' => $pag->forma?->nome, 'observacao' => $pag->observacao] : null,
        ];
    }
}
