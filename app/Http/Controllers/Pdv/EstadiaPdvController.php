<?php

namespace App\Http\Controllers\Pdv;

use App\Http\Controllers\ClienteController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\EstadiaController;
use App\Models\Cliente;
use App\Models\Estadia;
use App\Models\FormaPagamento;
use App\Models\Pagamento;
use App\Servicos\Estadias;
use App\Servicos\FormasPagamento;
use App\Servicos\Vendas;
use App\Support\Formato;
use App\Support\Placa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** API do PDV, estadias (etapa 8.2): pátio, entrada, busca do ticket, cálculo, saída e cancelamento. */
class EstadiaPdvController extends Controller
{
    private const WITH = ['tipo:id,nome', 'cliente:id,nome', 'convenio.cliente:id,nome', 'convenio.regras.servico', 'pacote:id,nome'];

    private static function achar(int $id): Estadia
    {
        return Estadia::with(self::WITH)->findOrFail($id);
    }

    /** o que as telas de estadia precisam: tamanhos (com diárias), formas de pagamento e convênios */
    public function opcoes(): JsonResponse
    {
        FormasPagamento::garantirPadrao();

        return response()->json([
            'tipos' => collect(EstadiaController::tiposComTabela())->filter(fn ($t) => $t['tabela'])->values(),
            'formas' => FormaPagamento::where('ativa', true)->orderBy('ordem')->get(['id', 'nome', 'tipo', 'parcelas_max']),
            'convenios' => EstadiaController::convenios('estadia'),
        ]);
    }

    public function patio(): JsonResponse
    {
        return response()->json(Estadia::with(self::WITH)->where('situacao', 'patio')->orderByDesc('entrada')->get()
            ->map(fn ($e) => EstadiaController::linha($e)));
    }

    /** sugestão para a entrada: cadastro do veículo ou a última visita avulsa daquela placa */
    public function placa(string $placa): JsonResponse
    {
        $p = Placa::normalizar($placa);
        if (! $p) {
            return response()->json(['placa' => null]);
        }
        $noPatio = Estadia::where('situacao', 'patio')->where('placa', $p)->first();
        $v = Vendas::veiculoDaPlaca($p);
        $ultima = $v ? null : Estadias::ultimaVisita($p);

        return response()->json([
            'placa' => Placa::formatar($p),
            'no_patio' => $noPatio ? ['id' => $noPatio->id, 'numero' => $noPatio->numero, 'entrada' => $noPatio->entrada->format('d/m H:i')] : null,
            'veiculo' => $v ? ['modelo' => trim($v->marca.' '.$v->modelo) ?: null, 'tipo_id' => $v->tipo_veiculo_id, 'cliente' => $v->cliente?->nome,
                'mensalista' => (bool) Estadias::contratoVigente($v->id)] : null,
            'ultima' => $ultima,
        ]);
    }

    /** "na conta de": busca de cliente (mesma busca da gestão) */
    public function clientes(Request $r): JsonResponse
    {
        $texto = mb_substr(trim((string) $r->query('q')), 0, 80);
        if (mb_strlen($texto) < 2) {
            return response()->json([]);
        }

        return response()->json(ClienteController::busca($texto)->where('clientes.ativo', true)->orderBy('nome')->limit(8)->get()
            ->map(fn (Cliente $c) => ['id' => $c->id, 'nome' => $c->nome]));
    }

    public function entrar(Request $r): JsonResponse
    {
        $d = $r->validate([
            'placa' => ['nullable', 'string', 'max:10'],
            'identificacao' => ['nullable', 'string', 'max:40'],
            'tipo_veiculo_id' => ['required', 'integer'],
            'confirmar_mensalista' => ['nullable', 'boolean'],
        ] + EstadiaController::regrasEntrada(), ['tipo_veiculo_id.required' => 'Escolha o tamanho do veículo.']);
        PdvController::pessoa($r);
        $e = Estadias::entrar($d);

        return response()->json(['estadia' => EstadiaController::linha(self::achar($e->id)), 'recibo' => self::recibo($e->fresh(), $e->ordem_entrada_id)]);
    }

    /** acha o ticket pelo QR (código), pelo número ou pela placa; só quem está no pátio */
    public function buscar(Request $r): JsonResponse
    {
        $q = trim((string) $r->query('q'));
        // QR do ticket: pode vir a URL inteira (/t/codigo) ou só o código
        if (preg_match('~/t/([a-z0-9]{16})~i', $q, $m)) {
            $q = $m[1];
        }
        $base = Estadia::with(self::WITH)->where('situacao', 'patio');
        $e = null;
        if (preg_match('/^[a-z0-9]{16}$/i', $q) && ! ctype_digit($q)) {
            $e = (clone $base)->where('codigo', mb_strtolower($q))->first();
        }
        if (! $e && ctype_digit($q) && $q !== '') {
            $e = (clone $base)->where('numero', (int) $q)->first();
        }
        if (! $e && ($p = Placa::normalizar($q))) {
            $e = (clone $base)->where('placa', $p)->first();
        }
        if (! $e) {
            // já saiu? avisa em vez de "não achei"
            $fora = Estadia::where('codigo', mb_strtolower($q))->orWhere(fn ($w) => ctype_digit($q) ? $w->where('numero', (int) $q) : $w->whereRaw('1=0'))->latest('id')->first();

            return response()->json(['erro' => $fora ? 'Este ticket já foi '.($fora->situacao === 'cancelada' ? 'cancelado.' : 'finalizado ('.$fora->saida?->format('d/m H:i').').') : 'Nenhum veículo no pátio com esse ticket ou placa.'], 404);
        }

        return response()->json(['estadia' => EstadiaController::linha($e)]);
    }

    public function calculo(Request $r, int $id): JsonResponse
    {
        $e = self::achar($id);
        try {
            $c = EstadiaController::calculoCom($e, $r);
        } catch (ValidationException $ex) {
            return response()->json(['erro' => collect($ex->errors())->flatten()->first()], 422);
        }

        return response()->json(['estadia' => EstadiaController::linha($e), 'calculo' => $c]);
    }

    public function sair(Request $r, int $id): JsonResponse
    {
        $d = $r->validate(EstadiaController::regrasSaida() + ['saida_calculo' => ['nullable', 'date']]);
        PdvController::pessoa($r);
        // a saída é a hora do cálculo mostrado na tela (o Pix pode levar uns minutos para cair)
        if ($m = PdvController::momento($d['saida_calculo'] ?? null)) {
            $d['saida'] = $m->format('Y-m-d H:i');
        }
        $e = Estadias::sair(self::achar($id), $d);

        return response()->json(['estadia' => EstadiaController::linha(self::achar($e->id)), 'recibo' => self::recibo($e, $e->ordem_id),
            'mensagem' => 'Saída de '.$e->rotulo().': '.Formato::duracao((int) $e->minutos).' · '.Estadia::DESTINOS[$e->destino].'.']);
    }

    public function cancelar(Request $r, int $id): JsonResponse
    {
        $d = $r->validate(['motivo' => ['required', 'string', 'max:200']], ['motivo.required' => 'Informe o motivo.']);
        PdvController::gerente($r, 'Cancelar');
        $e = self::achar($id);
        Estadias::cancelar($e, $d['motivo']);

        return response()->json(['ok' => true, 'mensagem' => 'Estadia de '.$e->rotulo().' cancelada.']);
    }

    /** dados para o ticket/recibo impresso: o pagamento feito agora (se houve), com troco */
    private static function recibo(Estadia $e, ?int $ordemId): array
    {
        $pg = $ordemId ? Pagamento::with('forma:id,nome')->whereHas('itens', fn ($q) => $q->where('ordem_id', $ordemId))->where('situacao', 'confirmado')->latest('id')->first() : null;

        return [
            'numero' => $e->numero,
            'codigo' => $e->codigo,
            'placa' => $e->rotulo(),
            'modelo' => $e->modelo,
            'tipo' => $e->tipo?->nome,
            'servico' => $e->pacote_id ? ($e->pacote?->nome ?? 'Diária') : 'Estadia',
            'entrada' => $e->entrada->format('d/m/Y H:i'),
            'saida' => $e->saida?->format('d/m/Y H:i'),
            'tempo' => $e->minutos !== null ? Formato::duracao((int) $e->minutos) : null,
            'valor' => $e->valor_calculado !== null ? (float) $e->valor_calculado : null,
            'convenio' => (float) $e->valor_convenio > 0 ? ['nome' => $e->convenio?->cliente?->nome, 'valor' => (float) $e->valor_convenio] : null,
            'pago_entrada' => (float) $e->pago_entrada,
            'pago' => $pg ? ['valor' => (float) $pg->valor, 'forma' => $pg->forma?->nome, 'observacao' => $pg->observacao] : null,
            'destino' => $e->destino ? Estadia::DESTINOS[$e->destino] : null,
        ];
    }
}
