<?php

namespace App\Servicos;

use App\Models\Estadia;
use App\Models\OrdemEtapa;
use App\Models\OrdemServico;
use App\Models\TipoVeiculo;
use App\Servicos\Pdv\Lavagens;
use App\Support\Atividades;
use App\Support\Formato;
use App\Support\Placa;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pátio unificado da gestão: estadias e lavagens numa lista só (agora e histórico). Entrada e saída são do PDV;
 * aqui ficam as correções do gerente (permissão patio_corrigir): corrigir placa/modelo/tamanho, cancelar e
 * encerrar o que ficou esquecido.
 */
class Patio
{
    private const WITH_ESTADIA = ['tipo:id,nome', 'cliente:id,nome', 'convenio.cliente:id,nome', 'pacote:id,nome'];

    private static function erro(string $campo, string $msg): never
    {
        throw ValidationException::withMessages([$campo => $msg]);
    }

    // ---------- leitura ----------

    /** linha de estadia (agora: valor até este momento; histórico: o que foi cobrado) */
    public static function linhaEstadia(Estadia $e): array
    {
        $valor = $e->valor_calculado !== null ? (float) $e->valor_calculado : null;
        if ($e->situacao === 'patio') {
            try {
                $valor = Estadias::calcular($e)['valor'];
            } catch (\Throwable) {
                $valor = null; // sem tabela de estadia para o tamanho
            }
        }
        $como = match ($e->situacao) {
            'patio' => $e->pago_entrada > 0 ? 'Diária paga na entrada' : ($e->mensalista ? 'Mensalista' : null),
            'cancelada' => 'Cancelada',
            default => $e->destino ? Estadia::DESTINOS[$e->destino] : null,
        };

        return [
            'chave' => 'e'.$e->id, 'tipo' => 'estadia', 'id' => $e->id, 'numero' => $e->numero,
            'placa' => $e->rotulo(), 'modelo' => $e->modelo, 'tamanho' => $e->tipo?->nome, 'tipo_id' => $e->tipo_veiculo_id,
            'cliente' => $e->cliente?->nome, 'convenio' => $e->convenio?->cliente?->nome,
            'entrada' => $e->entrada->format('Y-m-d H:i'), 'saida' => $e->saida?->format('Y-m-d H:i'),
            'tempo' => Formato::duracao($e->minutos !== null ? (int) $e->minutos : (int) $e->entrada->diffInMinutes(now())),
            'servico' => $e->pacote_id ? ($e->pacote?->nome ?? 'Diária') : 'Estadia',
            'etapa' => null, 'valor' => $valor, 'estadia' => null, 'a_receber' => 0.0,
            'situacao' => $e->situacao, 'como' => $como, 'ok' => in_array($e->destino, ['pago', 'pago_entrada'], true),
            'obs' => $e->situacao === 'cancelada' ? $e->motivo_cancelamento : $e->observacao,
            'pdv' => (bool) $e->aparelho_id, 'pode_cancelar' => $e->situacao === 'patio' && (float) $e->pago_entrada <= 0,
        ];
    }

    /** linha de lavagem: a venda inteira (principal + adicionais), etapa e a estadia depois de pronta (R$ 0 sem cobrança) */
    public static function linhaLavagem(OrdemServico $p, Collection $grupo, array $etapas, ?string $fim = null): array
    {
        $vivas = $grupo->where('situacao', '<>', 'cancelada');
        $servicos = $vivas->where('tipo', '<>', 'estadia');
        $cancelada = $vivas->isEmpty();
        $entregue = ! $cancelada && $p->etapa === EtapasLavagem::ULTIMA;
        // estadia: entregue = o que foi lançado; em andamento = o cálculo de agora (só se a configuração cobra)
        $estadia = $entregue || $cancelada
            ? round((float) $vivas->where('tipo', 'estadia')->sum('valor'), 2)
            : (float) (Lavagens::estadiaDepois($p)['valor'] ?? 0);
        $abertas = $vivas->where('situacao', 'aberta');
        $aReceber = round((float) $abertas->whereNull('cliente_id')->sum('valor'), 2);
        $como = match (true) {
            $cancelada => 'Cancelada',
            $abertas->isEmpty() => 'Pago',
            $aReceber > 0 => 'A receber',
            default => 'Na conta',
        };
        $fim = $entregue ? $fim : null;

        return [
            'chave' => 'l'.$p->id, 'tipo' => 'lavagem', 'id' => $p->id, 'numero' => $p->numero,
            'placa' => $p->placa ? Placa::formatar($p->placa) : 'Sem placa', 'modelo' => $p->veiculo_descricao, 'tamanho' => $p->tipoVeiculo?->nome, 'tipo_id' => $p->tipo_veiculo_id,
            'cliente' => $p->cliente?->nome, 'convenio' => null,
            'entrada' => ($p->criado_em ?? $p->data)->format('Y-m-d H:i'), 'saida' => $fim ? Carbon::parse($fim)->format('Y-m-d H:i') : null,
            'tempo' => Formato::duracao((int) ($p->criado_em ?? $p->data)->diffInMinutes($fim ? Carbon::parse($fim) : now())),
            'servico' => $servicos->pluck('descricao')->implode(' + '),
            // etapa desativada depois da venda: mostra a primeira
            'etapa' => $cancelada ? null : ($etapas[$p->etapa] ?? reset($etapas)),
            'valor' => round((float) $servicos->sum('valor'), 2), 'estadia' => $estadia, 'a_receber' => $aReceber,
            'situacao' => $cancelada ? 'cancelada' : ($entregue ? 'finalizada' : 'patio'), 'como' => $como, 'ok' => $como === 'Pago',
            'obs' => $cancelada ? $grupo->first()?->motivo_cancelamento : null,
            'pdv' => $p->origem === 'pdv', 'pode_cancelar' => ! $cancelada && ! $entregue && $grupo->where('situacao', 'paga')->isEmpty(),
        ];
    }

    private static function lavagens()
    {
        return OrdemServico::with(['cliente:id,nome', 'tipoVeiculo:id,nome'])->where('tipo', 'servico')->whereNull('principal_id')->whereNotNull('etapa');
    }

    /** junta as ordens de cada venda e devolve as linhas */
    private static function linhasLavagem(Collection $principais): Collection
    {
        $grupos = OrdemServico::whereIn('principal_id', $principais->pluck('id'))->get()->groupBy('principal_id');
        $etapas = EtapasLavagem::ativas();
        // quando cada uma foi entregue (última etapa no histórico de etapas)
        $fins = OrdemEtapa::whereIn('ordem_id', $principais->pluck('id'))->where('etapa', EtapasLavagem::ULTIMA)
            ->groupBy('ordem_id')->selectRaw('ordem_id, MAX(quando) AS fim')->pluck('fim', 'ordem_id');

        return $principais->map(fn ($p) => self::linhaLavagem($p, collect([$p])->merge($grupos->get($p->id, collect())), $etapas, $fins[$p->id] ?? null));
    }

    /** quem está no pátio agora: estadias e lavagens em andamento, mais antigas primeiro */
    public static function agora(): Collection
    {
        $estadias = Estadia::with(self::WITH_ESTADIA)->where('situacao', 'patio')->get()->map(fn ($e) => self::linhaEstadia($e));
        $lav = self::linhasLavagem(self::lavagens()->where('situacao', '<>', 'cancelada')->where('etapa', '<>', EtapasLavagem::ULTIMA)->limit(300)->get());

        return $estadias->concat($lav)->sortBy('entrada')->values();
    }

    /** histórico por data de entrada; filtros: tipo, situacao (patio, finalizada, cancelada), q */
    public static function historico(string $de, string $ate, string $tipo, string $situacao, string $q): Collection
    {
        $linhas = collect();
        if ($tipo !== 'lavagem') {
            $linhas = $linhas->concat(Estadia::with(self::WITH_ESTADIA)->whereBetween('entrada', [$de.' 00:00:00', $ate.' 23:59:59'])
                ->when($situacao !== 'todas', fn ($w) => $w->where('situacao', $situacao))
                ->when($q !== '', fn ($w) => $w->where(fn ($w) => $w->where('placa', 'like', '%'.Placa::normalizar($q).'%')
                    ->orWhere('identificacao', 'like', '%'.$q.'%')->orWhere('modelo', 'like', '%'.$q.'%')
                    ->when(ctype_digit($q), fn ($w) => $w->orWhere('numero', (int) $q))))
                ->orderByDesc('entrada')->limit(2000)->get()->map(fn ($e) => self::linhaEstadia($e)));
        }
        if ($tipo !== 'estadia') {
            $lav = self::lavagens()->whereBetween('criado_em', [$de.' 00:00:00', $ate.' 23:59:59'])
                ->when($q !== '', fn ($w) => $w->where(fn ($w) => $w->where('placa', 'like', '%'.Placa::normalizar($q).'%')
                    ->orWhere('veiculo_descricao', 'like', '%'.$q.'%')->when(ctype_digit($q), fn ($w) => $w->orWhere('numero', (int) $q))))
                ->orderByDesc('criado_em')->limit(2000)->get();
            $linhas = $linhas->concat(self::linhasLavagem($lav)->when($situacao !== 'todas', fn ($c) => $c->where('situacao', $situacao)));
        }

        return $linhas->sortByDesc('entrada')->values();
    }

    /** totais do que o filtro pega */
    public static function totais(Collection $linhas): array
    {
        $e = $linhas->where('tipo', 'estadia');
        $l = $linhas->where('tipo', 'lavagem');
        $vivas = fn ($c) => $c->where('situacao', '<>', 'cancelada');

        return [
            'estadias' => $e->count(), 'lavagens' => $l->count(),
            'patio' => $linhas->where('situacao', 'patio')->count(), 'canceladas' => $linhas->where('situacao', 'cancelada')->count(),
            'estadias_valor' => round((float) $e->where('situacao', 'finalizada')->sum('valor'), 2),
            'lavagens_valor' => round((float) $vivas($l)->sum('valor'), 2),
            'estadia_lavagem' => round((float) $vivas($l)->sum('estadia'), 2),
        ];
    }

    // ---------- correções do gerente ----------

    /** corrige placa, modelo e tamanho de quem ainda está no pátio */
    public static function corrigirEstadia(Estadia $e, array $d): void
    {
        if ($e->situacao !== 'patio') {
            self::erro('placa', 'Só dá para corrigir quem ainda está no pátio.');
        }
        $antes = $e->rotulo().' · '.($e->tipo?->nome ?? '');
        $novo = [];
        if (array_key_exists('placa', $d) && $e->placa !== null) {
            $p = Placa::normalizar((string) $d['placa']) ?? self::erro('placa', 'Placa inválida.');
            if ($p !== $e->placa && Estadia::where('situacao', 'patio')->where('placa', $p)->whereKeyNot($e->id)->exists()) {
                self::erro('placa', 'Já existe outro veículo com essa placa no pátio.');
            }
            $v = Vendas::veiculoDaPlaca($p);
            $novo += ['placa' => $p, 'veiculo_id' => $v?->id, 'cliente_id' => $v?->cliente_id, 'mensalista' => (bool) ($v && Estadias::contratoVigente($v->id))];
        }
        if (array_key_exists('modelo', $d)) {
            $novo['modelo'] = trim((string) $d['modelo']) ?: null;
        }
        if (! empty($d['tipo_veiculo_id']) && (int) $d['tipo_veiculo_id'] !== (int) $e->tipo_veiculo_id) {
            TipoVeiculo::where('ativo', true)->find($d['tipo_veiculo_id']) ?? self::erro('tipo_veiculo_id', 'Tamanho inválido.');
            if ($e->pacote_id) {
                self::erro('tipo_veiculo_id', 'Esta estadia tem diária marcada para o tamanho atual: cancele e lance de novo no PDV.');
            }
            $novo['tipo_veiculo_id'] = (int) $d['tipo_veiculo_id'];
        }
        $e->update($novo);
        $e->refresh()->load('tipo');
        Atividades::registrar('patio_corrigido', 'estadia ticket '.$e->numero.': '.$antes.' → '.$e->rotulo().' · '.($e->tipo?->nome ?? ''));
    }

    /**
     * carro que saiu sem baixa: encerra na hora informada sem receber (o valor calculado fica registrado para conferência)
     * ou na conta de um cliente (como uma saída normal com destino conta).
     */
    public static function encerrarEstadia(Estadia $e, array $d): Estadia
    {
        if ($e->situacao !== 'patio') {
            self::erro('saida', 'Esta estadia já foi finalizada ou cancelada.');
        }
        $saida = Carbon::createFromFormat('Y-m-d H:i', $d['saida']);
        if ($saida->gt(now())) {
            self::erro('saida', 'A saída não pode ser no futuro.');
        }
        $motivo = mb_substr(trim((string) $d['motivo']), 0, 200);
        if (($d['como'] ?? 'sem_receber') === 'conta') {
            $e = Estadias::sair($e, ['saida' => $saida->format('Y-m-d H:i'), 'destino' => 'conta', 'conta_cliente_id' => $d['conta_cliente_id'] ?? null]);
            $e->update(['observacao' => 'Encerrada pela gestão: '.$motivo]);

            return $e;
        }
        $c = Estadias::calcular($e, $saida);

        return DB::transaction(function () use ($e, $c, $saida, $motivo) {
            $e = Estadia::whereKey($e->id)->lockForUpdate()->first();
            $e->update(['saida' => $saida, 'minutos' => $c['minutos'], 'tarifa_id' => $c['tarifa_id'], 'valor_calculado' => $c['valor'],
                'valor_convenio' => 0, 'desconto' => 0, 'valor_cliente' => 0, 'situacao' => 'finalizada', 'destino' => 'sem_receber',
                'detalhes' => $c['detalhes'], 'finalizado_por' => Auth::id(), 'observacao' => $motivo]);
            Atividades::registrar('estadia_encerrada', 'ticket '.$e->numero.' · '.$e->rotulo().' · '.$c['tempo'].' · '.Formato::moeda($c['valor']).' sem receber ('.$motivo.')');

            return $e->fresh();
        });
    }

    /** corrige placa e modelo de uma lavagem em andamento (o tamanho fica: o preço já foi feito com ele) */
    public static function corrigirLavagem(OrdemServico $o, array $d): void
    {
        $grupo = Vendas::grupo($o);
        $p = $grupo->first();
        if ($p->etapa === EtapasLavagem::ULTIMA || $grupo->every(fn ($x) => $x->situacao === 'cancelada')) {
            self::erro('placa', 'Só dá para corrigir lavagem em andamento.');
        }
        $novo = [];
        if (array_key_exists('placa', $d)) {
            $novo['placa'] = Placa::normalizar((string) $d['placa']) ?? self::erro('placa', 'Placa inválida.');
        }
        DB::transaction(function () use ($grupo, $novo, $d, $p) {
            foreach ($grupo as $x) {
                $x->update($novo);
            }
            if (array_key_exists('modelo', $d)) {
                $p->update(['veiculo_descricao' => trim((string) $d['modelo']) ?: null]);
            }
        });
        Atividades::registrar('patio_corrigido', 'lavagem #'.$p->numero.' corrigida: '.Placa::formatar($novo['placa'] ?? $p->placa));
    }

    /** cancela a venda de lavagem inteira (nada pago); pagamento feito precisa ser estornado antes em Recebimentos */
    public static function cancelarLavagem(OrdemServico $o, string $motivo): void
    {
        $grupo = Vendas::grupo($o);
        if ($grupo->where('situacao', 'paga')->isNotEmpty()) {
            self::erro('motivo', 'Esta lavagem já tem pagamento: estorne em Recebimentos antes de cancelar.');
        }
        if ($grupo->first()->etapa === EtapasLavagem::ULTIMA) {
            self::erro('motivo', 'Lavagem já entregue: cancele a conta em Ordens de serviço.');
        }
        DB::transaction(function () use ($grupo, $motivo) {
            foreach ($grupo->where('situacao', 'aberta') as $x) {
                Ordens::cancelar($x, $motivo);
            }
        });
    }

    /** lavagem esquecida (o carro já foi embora): marca entregue; o que está em aberto continua a receber */
    public static function entregarLavagem(OrdemServico $o, string $motivo): void
    {
        $grupo = Vendas::grupo($o);
        $p = $grupo->first();
        if ($p->etapa === EtapasLavagem::ULTIMA) {
            self::erro('motivo', 'Esta lavagem já foi entregue.');
        }
        Vendas::etapa($p, EtapasLavagem::ULTIMA);
        Atividades::registrar('lavagem_encerrada', 'lavagem #'.$p->numero.' '.Placa::formatar($p->placa).' marcada como entregue pela gestão ('.mb_substr($motivo, 0, 150).')');
    }
}
