<?php

namespace App\Servicos;

use App\Models\Cobranca;
use App\Models\Convenio;
use App\Models\ConvenioRegra;
use App\Models\Empresa;
use App\Models\OrdemServico;
use App\Models\Servico;
use App\Support\Atividades;
use App\Support\Formato;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Quem paga quanto com convênio (docs/05-regras-de-negocio.md, Convênio). Cada serviço marcado no
 * convênio (e a estadia) tem uma regra:
 * - integral: o convênio paga tudo;
 * - parcial: o convênio paga até um valor; o que passar fica com quem usou;
 * - tempo (só estadia): o convênio paga a estadia até o tempo combinado; o excedente fica com quem usou.
 * Serviço não marcado: quem usou paga tudo. A parte de quem usou é paga na hora.
 */
class Convenios
{
    /**
     * @param  string  $uso  estadia ou servico
     * @param  callable|null  $valorAte  estadia no modo tempo: fn(int $minutos): float (valor do tarifador até esse tempo)
     * @param  int|null  $servicoId  serviço (no uso "servico")
     * @return array{convenio: float, desconto: float, cliente: float, regra: string}
     */
    public static function dividir(?Convenio $c, string $uso, float $valor, int $minutos = 0, ?callable $valorAte = null, ?int $servicoId = null): array
    {
        $valor = round($valor, 2);
        $nada = ['convenio' => 0.0, 'desconto' => 0.0, 'cliente' => $valor, 'regra' => ''];
        if (! $c || ! $c->ativo || $valor <= 0) {
            return $nada;
        }
        $c->loadMissing('regras', 'cliente');
        $r = $c->regra($uso === 'estadia' ? null : $servicoId);
        $nome = $c->cliente?->nome ?? 'Convênio';
        if (! $r) {
            return ['regra' => "{$nome}: o convênio não cobre ".($uso === 'estadia' ? 'estadia' : 'este serviço')] + $nada;
        }
        $pago = match ($r->modo) {
            'integral' => $valor,
            'parcial' => min($valor, round((float) $r->limite_valor, 2)),
            'tempo' => $uso !== 'estadia' ? $valor
                : ($minutos <= (int) $r->limite_min || ! $valorAte ? $valor : min($valor, round((float) $valorAte((int) $r->limite_min), 2))),
            default => 0.0,
        };
        $pago = round(max(0, $pago), 2);
        $regra = match ($r->modo) {
            'integral' => "{$nome} paga tudo",
            'parcial' => "{$nome} paga até ".Formato::moeda($r->limite_valor),
            default => "{$nome} paga até ".Formato::duracao((int) $r->limite_min),
        };

        return ['convenio' => $pago, 'desconto' => 0.0, 'cliente' => round($valor - $pago, 2),
            'regra' => $regra.($pago < $valor ? '; o excedente fica com quem usou' : '')];
    }

    /**
     * Regras de um convênio que não foi editado no ERP, a partir do modo que vem do sistema atual (durante
     * o paralelo). "Paga tudo" vira integral na estadia e/ou em todos os serviços; "até um tempo" vira limite
     * de tempo na estadia; "desconto" (modo que saiu) fica para reconfigurar.
     */
    public static function refazerDoLegado(Convenio $c): void
    {
        if ($c->regras_no_erp) {
            return;
        }
        DB::transaction(function () use ($c) {
            $c->regras()->delete();
            $regras = [];
            if ($c->modo === 'tudo') {
                if ($c->vale_estadia) {
                    $regras[] = ['servico_id' => null, 'modo' => 'integral'];
                }
                if ($c->vale_servicos) {
                    foreach (Servico::where('ativo', true)->pluck('id') as $s) {
                        $regras[] = ['servico_id' => $s, 'modo' => 'integral'];
                    }
                }
            } elseif ($c->modo === 'tempo' && $c->vale_estadia && $c->limite_min) {
                $regras[] = ['servico_id' => null, 'modo' => 'tempo', 'limite_min' => (int) $c->limite_min];
            }
            foreach ($regras as $r) {
                $c->regras()->create($r);
            }
            $c->forceFill(['reconfigurar' => ! in_array($c->modo, ['tudo', 'tempo'], true)])->saveQuietly();
        });
        $c->unsetRelation('regras');
    }

    /** ordens do convênio ainda sem cobrança */
    public static function ordensSoltas(Convenio $c): Collection
    {
        return OrdemServico::abertas()->where('cliente_id', $c->cliente_id)->where('convenio_id', $c->id)->whereNull('cobranca_id')->get();
    }

    /** dia do próximo fechamento (null = nada a fechar) */
    public static function proximoFechamento(Convenio $c, ?Collection $soltas = null): ?\Carbon\CarbonInterface
    {
        $soltas ??= self::ordensSoltas($c);
        if ($soltas->isEmpty()) {
            return null;
        }
        $primeira = Carbon::parse($soltas->min(fn ($o) => $o->data->toDateString()))->startOfDay();
        // a partir do último fechamento; sem fechamento ainda, conta a partir da ordem mais antiga
        $base = $c->ultimo_fechamento?->copy()->startOfDay() ?? $primeira->copy()->subDay();
        if ($c->fechamento === 'dias') {
            return ($c->ultimo_fechamento?->copy()->startOfDay() ?? $primeira)->addDays(max(1, (int) $c->fechamento_dias));
        }
        if ($c->fechamento === 'semana') {
            $d = $base->copy()->addDay();
            while ($d->dayOfWeekIso !== (int) ($c->fechamento_semana ?: 1)) {
                $d->addDay();
            }

            return $d;
        }
        // junto com a mensalidade: no dia de vencimento do cliente
        $dia = (int) ($c->cliente?->dia_vencimento ?: Empresa::atual()->config('dia_vencimento_padrao') ?: 5);
        $d = Mensalidades::vencimentoNoMes($dia, $base->format('Y-m'));
        if ($d->lte($base)) {
            $d = Mensalidades::vencimentoNoMes($dia, $base->copy()->startOfMonth()->addMonth()->format('Y-m'));
        }

        return $d;
    }

    /** convênios com fechamento hoje (ou atrasado): para o aviso no Início e no PDV */
    public static function aFechar(): Collection
    {
        $hoje = today();

        return Convenio::with('cliente')->where('ativo', true)->get()->map(function (Convenio $c) use ($hoje) {
            $soltas = self::ordensSoltas($c);
            $dia = self::proximoFechamento($c, $soltas);

            return $dia && $dia->lte($hoje) ? ['convenio' => $c, 'dia' => $dia, 'itens' => $soltas->count(), 'total' => round($soltas->sum(fn ($o) => (float) $o->valor), 2)] : null;
        })->filter()->values();
    }

    /** gera a cobrança do conveniado com as contas em aberto dele e marca o fechamento */
    public static function fechar(Convenio $c): Cobranca
    {
        $c->loadMissing('cliente');
        // junto com a mensalidade: a cobrança do mês, com tudo o que o cliente deve; nos outros, só as contas do convênio
        if ($c->fechamento === 'mensalidade') {
            $cob = Cobrancas::emitir($c->cliente, today()->format('Y-m'), null, true);
        } else {
            $cob = Cobrancas::emitir($c->cliente, today()->format('Y-m'), today()->addDays(7)->toDateString(), true, self::ordensSoltas($c)->pluck('id')->all());
        }
        $c->forceFill(['ultimo_fechamento' => today()])->save();
        Atividades::registrar('convenio_fechado', 'fechamento do convênio '.$c->cliente->nome.': cobrança '.$cob->numero.' de '.Formato::moeda($cob->total));

        return $cob;
    }

    /** grava as regras editadas no ERP: [['servico_id' => null|int, 'modo', 'limite_valor', 'limite_min'], ...] */
    public static function salvarRegras(Convenio $c, array $regras): void
    {
        DB::transaction(function () use ($c, $regras) {
            $c->regras()->delete();
            foreach ($regras as $r) {
                $c->regras()->create([
                    'servico_id' => $r['servico_id'] ?: null,
                    'modo' => $r['modo'],
                    'limite_valor' => $r['modo'] === 'parcial' ? round((float) $r['limite_valor'], 2) : null,
                    'limite_min' => $r['modo'] === 'tempo' ? (int) $r['limite_min'] : null,
                ]);
            }
            $c->forceFill(['regras_no_erp' => true, 'reconfigurar' => false])->save();
        });
        $c->unsetRelation('regras');
    }

    /** serviços que podem entrar num convênio (estadia + serviços ativos; mensalidade não entra) */
    public static function opcoes(): array
    {
        return [['servico_id' => null, 'nome' => 'Estadia', 'estadia' => true],
            ...Servico::where('ativo', true)->orderByRaw("papel = 'adicional'")->orderBy('nome')->get()
                ->map(fn ($s) => ['servico_id' => $s->id, 'nome' => $s->nome, 'estadia' => false, 'adicional' => $s->papel === 'adicional'])->all()];
    }

    /** para usar na regra (ConvenioRegra::MODOS) */
    public static function modos(): array
    {
        return ConvenioRegra::MODOS;
    }
}
