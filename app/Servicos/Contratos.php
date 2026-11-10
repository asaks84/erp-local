<?php

namespace App\Servicos;

use App\Models\MensalidadeContrato;
use App\Models\MensalidadePausa;
use App\Models\Veiculo;
use App\Support\Atividades;
use App\Support\Formato;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Contratos de mensalidade: criar, reajustar (nova versão), agendar encerramento e pausar.
 * A geração das mensalidades (ordens de serviço) usa estes contratos na etapa 3.
 */
class Contratos
{
    private static function erro(string $campo, string $msg): never
    {
        throw ValidationException::withMessages([$campo => $msg]);
    }

    /** contrato que ainda não terminou para o veículo (vigente ou futuro) */
    public static function atual(Veiculo $veiculo): ?MensalidadeContrato
    {
        return $veiculo->contratos()->ativos()->orderByDesc('inicio')->first();
    }

    public static function criar(Veiculo $veiculo, array $d): MensalidadeContrato
    {
        if (! $veiculo->cliente_id) {
            self::erro('valor', 'Mensalidade só para veículo de cliente cadastrado.');
        }
        if (self::atual($veiculo)) {
            self::erro('valor', 'Este veículo já tem uma mensalidade ativa.');
        }
        $c = MensalidadeContrato::create([
            'unidade_id' => $d['unidade_id'],
            'cliente_id' => $veiculo->cliente_id,
            'veiculo_id' => $veiculo->id,
            'periodo' => $d['periodo'],
            'valor' => $d['valor'],
            'inicio' => $d['inicio'],
            'criado_por' => Auth::id(),
        ]);
        Atividades::registrar('mensalidade_criada', $veiculo->rotulo().': '.$c->periodo.', '.Formato::moeda($c->valor).' desde '.Carbon::parse($c->inicio)->format('d/m/Y'));

        return $c;
    }

    /** Reajuste: o contrato atual termina na véspera e uma nova versão começa na data. */
    public static function reajustar(MensalidadeContrato $c, float $valor, string $aPartir, ?string $periodo = null): MensalidadeContrato
    {
        $inicio = Carbon::parse($aPartir)->startOfDay();
        if ($inicio->lte($c->inicio)) {
            self::erro('a_partir', 'O reajuste precisa começar depois do início do contrato atual ('.$c->inicio->format('d/m/Y').').');
        }
        if ($c->fim && $inicio->gt($c->fim)) {
            self::erro('a_partir', 'O contrato termina em '.$c->fim->format('d/m/Y').'; o reajuste precisa começar antes disso.');
        }

        return DB::transaction(function () use ($c, $valor, $inicio, $periodo) {
            $fimAnterior = $c->fim;
            $c->update(['fim' => $inicio->copy()->subDay()->toDateString(), 'motivo_fim' => 'Reajuste']);
            $novo = MensalidadeContrato::create([
                'unidade_id' => $c->unidade_id,
                'cliente_id' => $c->cliente_id,
                'veiculo_id' => $c->veiculo_id,
                'periodo' => $periodo ?: $c->periodo,
                'valor' => $valor,
                'inicio' => $inicio->toDateString(),
                'fim' => $fimAnterior,
                'motivo_fim' => $fimAnterior ? $c->getOriginal('motivo_fim') : null,
                'anterior_id' => $c->id,
                'criado_por' => Auth::id(),
            ]);
            // pausas depois do reajuste passam para a nova versão
            MensalidadePausa::where('contrato_id', $c->id)->where('inicio', '>=', $inicio->toDateString())->update(['contrato_id' => $novo->id]);
            Atividades::registrar('mensalidade_reajustada', $c->veiculo->rotulo().': '.Formato::moeda($c->valor).' → '.Formato::moeda($valor).' a partir de '.$inicio->format('d/m/Y'));

            return $novo;
        });
    }

    /** Encerramento (agendado ou para hoje): a última mensalidade é a do mês do fim. */
    public static function encerrar(MensalidadeContrato $c, string $data, string $motivo): void
    {
        $fim = Carbon::parse($data)->startOfDay();
        if ($fim->lt($c->inicio)) {
            self::erro('data', 'O encerramento não pode ser antes do início do contrato ('.$c->inicio->format('d/m/Y').').');
        }
        $c->update(['fim' => $fim->toDateString(), 'motivo_fim' => $motivo]);
        Atividades::registrar('mensalidade_encerrada', $c->veiculo->rotulo().': termina em '.$fim->format('d/m/Y').' ('.$motivo.')');
    }

    public static function desfazerEncerramento(MensalidadeContrato $c): void
    {
        if (! $c->fim || $c->fim->lt(now()->startOfDay())) {
            self::erro('data', 'Só dá para desfazer um encerramento que ainda não aconteceu.');
        }
        $c->update(['fim' => null, 'motivo_fim' => null]);
        Atividades::registrar('mensalidade_encerramento_desfeito', $c->veiculo->rotulo());
    }

    /** Pausa de meses inteiros (de/até, "2026-12"), com taxa de reserva (0 = grátis). */
    public static function pausar(MensalidadeContrato $c, string $deMes, string $ateMes, string $taxaTipo, float $taxaValor, ?string $motivo): MensalidadePausa
    {
        $inicio = Carbon::parse($deMes.'-01')->startOfDay();
        $fim = Carbon::parse($ateMes.'-01')->endOfMonth()->startOfDay();
        if ($fim->lt($inicio)) {
            self::erro('ate', 'O último mês da pausa precisa ser igual ou depois do primeiro.');
        }
        if ($inicio->lt($c->inicio->copy()->startOfMonth()) || ($c->fim && $fim->gt($c->fim->copy()->endOfMonth()))) {
            self::erro('de', 'A pausa precisa ficar dentro do período do contrato.');
        }
        $sobrepoe = $c->pausas()->where('inicio', '<=', $fim->toDateString())->where('fim', '>=', $inicio->toDateString())->exists();
        if ($sobrepoe) {
            self::erro('de', 'Já existe uma pausa nesse período.');
        }
        if ($taxaTipo === 'pct' && $taxaValor > 100) {
            self::erro('taxa_valor', 'A taxa em porcentagem vai até 100%.');
        }
        $p = MensalidadePausa::create([
            'contrato_id' => $c->id,
            'inicio' => $inicio->toDateString(),
            'fim' => $fim->toDateString(),
            'taxa_tipo' => $taxaTipo,
            'taxa_valor' => $taxaValor,
            'motivo' => $motivo,
            'criado_por' => Auth::id(),
        ]);
        $taxa = $taxaValor > 0 ? ' com taxa '.($taxaTipo === 'pct' ? rtrim(rtrim(number_format($taxaValor, 2, ',', ''), '0'), ',').'%' : Formato::moeda($taxaValor)) : ' sem taxa';
        Atividades::registrar('mensalidade_pausada', $c->veiculo->rotulo().': '.$inicio->format('m/Y').' a '.$fim->format('m/Y').$taxa);

        return $p;
    }

    public static function removerPausa(MensalidadePausa $p): void
    {
        if ($p->inicio->lt(now()->startOfMonth())) {
            self::erro('pausa', 'Pausa que já começou não pode ser removida (os meses já foram tratados).');
        }
        Atividades::registrar('mensalidade_pausa_removida', $p->contrato->veiculo->rotulo().': '.$p->inicio->format('m/Y').' a '.$p->fim->format('m/Y'));
        $p->delete();
    }
}
