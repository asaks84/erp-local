<?php

namespace App\Servicos;

use App\Models\PlanoConta;
use App\Support\EmpresaAtual;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Resultado do mês e fluxo de caixa. Só lê; não grava nada.
 *
 * Resultado em dois olhares:
 * - "referencia" (competência): o que o mês faturou (mensalidades do mês de referência, estadias e
 *   serviços pela data) menos os descontos dados no mês, e as despesas do mês de referência.
 *   Débito (pagou a menos) e crédito (pagou a mais) não entram: são o resto ou o adiantamento de
 *   uma conta que já foi contada.
 * - "caixa": o dinheiro que entrou (pagamentos confirmados) e o que saiu (despesas pagas) no mês.
 * Nos dois, a taxa de cartão/maquininha dos recebimentos do mês entra como despesa.
 *
 * Fluxo de caixa: por dia, o que entrou e saiu (realizado) e, dos dias à frente, o que está para
 * receber (ordens em aberto pelo vencimento, cartão a cair) e para pagar (contas a pagar abertas).
 */
class Resultado
{
    private const RECEITA_POR_TIPO = ['mensalidade' => 'receita_mensalidade', 'estadia' => 'receita_estadia', 'servico' => 'receita_servico'];

    private static function empresa(): int
    {
        return app(EmpresaAtual::class)->id();
    }

    private static function limites(string $ym): array
    {
        $ini = Carbon::createFromFormat('Y-m-d', $ym.'-01')->startOfDay();

        return [$ini->toDateString(), $ini->copy()->endOfMonth()->toDateString()];
    }

    /** receitas do mês por chave de conta do plano */
    private static function receitas(string $ym, string $regime): array
    {
        [$de, $ate] = self::limites($ym);
        $e = self::empresa();
        $tot = [];
        if ($regime === 'caixa') {
            $linhas = DB::table('pagamento_itens as i')->join('pagamentos as p', 'p.id', '=', 'i.pagamento_id')->join('ordens_servico as o', 'o.id', '=', 'i.ordem_id')
                ->where('p.empresa_id', $e)->where('p.situacao', 'confirmado')->whereBetween('p.recebido_em', [$de.' 00:00:00', $ate.' 23:59:59'])
                ->groupBy('o.tipo')->selectRaw('o.tipo, SUM(i.valor) AS total')->pluck('total', 'tipo');
            foreach ($linhas as $tipo => $v) {
                $chave = self::RECEITA_POR_TIPO[$tipo] ?? 'receita_outras';
                $tot[$chave] = ($tot[$chave] ?? 0) + (float) $v;
            }
            // pagamento sem ordem (ou crédito que sobrou): o que não foi distribuído vai para "outras"
            $bruto = (float) DB::table('pagamentos')->where('empresa_id', $e)->where('situacao', 'confirmado')->whereBetween('recebido_em', [$de.' 00:00:00', $ate.' 23:59:59'])->sum('valor');
            $resto = round($bruto - array_sum($tot), 2);
            if (abs($resto) >= 0.01) {
                $tot['receita_outras'] = ($tot['receita_outras'] ?? 0) + $resto;
            }
        } else {
            $linhas = DB::table('ordens_servico')->where('empresa_id', $e)->where('situacao', '<>', 'cancelada')->whereIn('tipo', array_keys(self::RECEITA_POR_TIPO))
                ->where(fn ($w) => $w->where(fn ($m) => $m->where('tipo', 'mensalidade')->where('competencia', $ym))
                    ->orWhere(fn ($o) => $o->where('tipo', '<>', 'mensalidade')->whereBetween('data', [$de, $ate])))
                ->groupBy('tipo')->selectRaw('tipo, SUM(valor) AS total')->pluck('total', 'tipo');
            foreach ($linhas as $tipo => $v) {
                $tot[self::RECEITA_POR_TIPO[$tipo]] = (float) $v;
            }
        }

        return $tot;
    }

    /** descontos dados no mês (pelo dia do pagamento) */
    private static function descontos(string $ym): float
    {
        [$de, $ate] = self::limites($ym);

        return round((float) DB::table('ajustes')->where('empresa_id', self::empresa())->where('tipo', 'desconto')
            ->whereBetween('criado_em', [$de.' 00:00:00', $ate.' 23:59:59'])->sum('valor'), 2);
    }

    private static function taxas(string $ym): float
    {
        [$de, $ate] = self::limites($ym);

        return round((float) DB::table('pagamentos')->where('empresa_id', self::empresa())->where('situacao', 'confirmado')
            ->whereBetween('recebido_em', [$de.' 00:00:00', $ate.' 23:59:59'])->sum('taxa'), 2);
    }

    /**
     * despesas do mês por conta do plano (id). A conta a pagar de uma fatura de cartão não entra:
     * entram as compras dela, cada uma na sua conta (sem conta: "Cartão de crédito (a classificar)").
     * Devolve também o que, nas faturas, não é da empresa.
     *
     * @return array{0: array<int,float>, 1: float}
     */
    private static function despesas(string $ym, string $regime): array
    {
        [$de, $ate] = self::limites($ym);
        $q = DB::table('despesas')->where('empresa_id', self::empresa())->whereNull('fatura_id');
        if ($regime === 'caixa') {
            $q->where('situacao', 'paga')->whereBetween('pago_em', [$de, $ate])->selectRaw('conta_id, SUM(COALESCE(valor_pago, valor)) AS total');
        } else {
            $q->where('situacao', '<>', 'cancelada')->where('competencia', $de)->selectRaw("conta_id, SUM(CASE WHEN situacao = 'paga' THEN COALESCE(valor_pago, valor) ELSE valor END) AS total");
        }
        $porConta = $q->groupBy('conta_id')->pluck('total', 'conta_id')->map(fn ($v) => (float) $v)->all();

        $semConta = PlanoContas::porChave('despesa_cartao')?->id;
        $itens = DB::table('fatura_itens as i')->join('faturas_cartao as f', 'f.id', '=', 'i.fatura_id')->join('despesas as d', 'd.id', '=', 'f.despesa_id')
            ->join('cartoes as c', 'c.id', '=', 'f.cartao_id')
            ->where('f.empresa_id', self::empresa())
            // no cartão pessoal do sócio, o que não é da empresa não é assunto da empresa
            ->where(fn ($w) => $w->where('i.da_empresa', true)->orWhere('c.tipo', 'empresa'))
            ->when($regime === 'caixa', fn ($w) => $w->where('d.situacao', 'paga')->whereBetween('d.pago_em', [$de, $ate]),
                fn ($w) => $w->where('d.situacao', '<>', 'cancelada')->where('d.competencia', $de))
            ->groupBy('i.conta_id', 'i.da_empresa')->selectRaw('i.conta_id, i.da_empresa, SUM(i.valor) AS total')->get();
        $fora = 0.0;
        foreach ($itens as $i) {
            if (! $i->da_empresa) {
                $fora += (float) $i->total;

                continue;
            }
            $id = $i->conta_id ?? $semConta;
            $porConta[$id] = ($porConta[$id] ?? 0) + (float) $i->total;
        }

        return [$porConta, round($fora, 2)];
    }

    /**
     * Demonstrativo do mês: árvore do plano com os valores somados para cima.
     *
     * @return array{mes:string,regime:string,receitas:array,despesas:array,total_receitas:float,total_despesas:float,resultado:float,margem:?float,a_pagar:float}
     */
    public static function mes(string $ym, string $regime = 'referencia'): array
    {
        PlanoContas::garantirPadrao();
        $porChave = self::receitas($ym, $regime);
        [$porConta, $fora] = self::despesas($ym, $regime);
        $contas = PlanoConta::where('ativa', true)->orWhereIn('id', array_keys($porConta))->orderBy('ordem')->get();
        $chaves = $contas->whereNotNull('chave')->pluck('id', 'chave');
        $valores = $porConta;
        foreach ($porChave as $chave => $v) {
            if ($id = $chaves[$chave] ?? null) {
                $valores[$id] = ($valores[$id] ?? 0) + $v;
            }
        }

        $receitas = self::arvore($contas, 'receita', $valores);
        $desconto = self::descontos($ym);
        if ($desconto > 0 && $regime === 'referencia') {
            $receitas[] = ['id' => 'descontos', 'codigo' => '', 'nome' => '(−) Descontos dados', 'nivel' => 1, 'valor' => -$desconto, 'tem_filhas' => false];
        }
        $todas = self::arvore($contas, 'despesa', $valores);
        // investimentos (equipamentos) ficam abaixo do resultado da operação
        $inv = $chaves['despesa_investimentos'] ?? null;
        $despesas = [];
        $investimentos = [];
        $dentro = false;
        foreach ($todas as $l) {
            if ($l['nivel'] <= 1) {
                $dentro = $l['id'] === $inv;
            }
            if ($dentro) {
                $investimentos[] = $l;
            } else {
                $despesas[] = $l;
            }
        }
        $taxas = self::taxas($ym);
        if ($taxas > 0) {
            $despesas[] = ['id' => 'taxas', 'codigo' => '', 'nome' => 'Taxas de cartão e maquininha', 'nivel' => 1, 'valor' => $taxas, 'tem_filhas' => false];
        }
        $totRec = round(array_sum(array_column(array_filter($receitas, fn ($l) => $l['nivel'] === 1), 'valor')), 2);
        $totDesp = round(array_sum(array_column(array_filter($despesas, fn ($l) => $l['nivel'] === 1), 'valor')), 2);
        [$de] = self::limites($ym);

        return [
            'mes' => $ym, 'regime' => $regime,
            'receitas' => array_values(array_filter($receitas, fn ($l) => $l['nivel'] > 0)),
            'despesas' => array_values(array_filter($despesas, fn ($l) => $l['nivel'] > 0)),
            'total_receitas' => $totRec, 'total_despesas' => $totDesp,
            'resultado' => round($totRec - $totDesp, 2),
            'margem' => $totRec > 0 ? round(($totRec - $totDesp) / $totRec * 100, 1) : null,
            'investimentos' => $investimentos,
            'total_investimentos' => $totInv = round(array_sum(array_column(array_filter($investimentos, fn ($l) => $l['nivel'] === 1), 'valor')), 2),
            'fora_empresa' => $fora,
            'resultado_final' => round($totRec - $totDesp - $totInv - $fora, 2),
            // no olhar de referência, quanto das despesas do mês ainda não foi pago
            'a_pagar' => $regime === 'referencia' ? round((float) DB::table('despesas')->where('empresa_id', self::empresa())->where('situacao', 'aberta')->where('competencia', $de)->sum('valor'), 2) : 0.0,
        ];
    }

    /** linhas da árvore de um tipo, com o valor de cada conta somado às de cima; contas zeradas saem */
    private static function arvore($contas, string $tipo, array $valores): array
    {
        $filhas = $contas->where('tipo', $tipo)->groupBy(fn ($c) => $c->pai_id ?? 0);
        $saida = [];
        $somar = function ($pai, int $nivel) use (&$somar, &$saida, $filhas, $valores): float {
            $total = 0.0;
            foreach ($filhas[$pai] ?? [] as $c) {
                $pos = count($saida);
                $saida[] = null; // reserva o lugar (a conta vem antes das filhas)
                $sub = $somar($c->id, $nivel + 1);
                $v = round(($valores[$c->id] ?? 0) + $sub, 2);
                if (abs($v) < 0.005) {
                    array_splice($saida, $pos); // conta zerada e sem filhas com valor: sai

                    continue;
                }
                $saida[$pos] = ['id' => $c->id, 'codigo' => $c->codigo, 'nome' => $c->nome, 'nivel' => $nivel, 'valor' => $v, 'tem_filhas' => isset($filhas[$c->id])];
                $total += $v;
            }

            return $total;
        };
        $somar(0, 0);

        return array_values(array_filter($saida));
    }

    /** receitas, despesas e resultado dos últimos $n meses (até $ym) */
    public static function historico(string $ym, int $n = 6, string $regime = 'referencia'): array
    {
        $fim = Carbon::createFromFormat('Y-m-d', $ym.'-01');
        $lista = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            $m = $fim->copy()->subMonthsNoOverflow($i)->format('Y-m');
            $r = self::mes($m, $regime);
            $lista[] = ['mes' => $m, 'receitas' => $r['total_receitas'], 'despesas' => $r['total_despesas'], 'resultado' => $r['resultado']];
        }

        return $lista;
    }

    /**
     * Fluxo de caixa por dia entre $de e $ate. Até hoje: realizado (dinheiro que caiu e que saiu).
     * Depois de hoje: previsto (a receber pelo vencimento, cartão a cair, contas a pagar abertas).
     * Atrasados (vencidos e não pagos) aparecem à parte, sem entrar nos dias.
     */
    public static function fluxo(string $de, string $ate): array
    {
        $e = self::empresa();
        $hoje = now()->toDateString();
        $dias = [];
        $add = function (string $dia, string $lado, string $rotulo, float $v, bool $previsto) use (&$dias) {
            $dias[$dia] ??= ['data' => $dia, 'entradas' => 0.0, 'saidas' => 0.0, 'itens' => []];
            $dias[$dia][$lado] += $v;
            $k = $lado.'|'.$rotulo.'|'.(int) $previsto;
            if (isset($dias[$dia]['itens'][$k])) {
                $dias[$dia]['itens'][$k]['valor'] += $v;
                $dias[$dia]['itens'][$k]['qtd']++;
            } else {
                $dias[$dia]['itens'][$k] = ['lado' => $lado, 'rotulo' => $rotulo, 'valor' => $v, 'qtd' => 1, 'previsto' => $previsto];
            }
        };

        // entradas realizadas: o líquido, no dia em que cai na conta (cartão cai depois)
        $pags = DB::table('pagamentos as p')->leftJoin('formas_pagamento as f', 'f.id', '=', 'p.forma_pagamento_id')
            ->where('p.empresa_id', $e)->where('p.situacao', 'confirmado')
            ->whereRaw('COALESCE(p.previsto_para, DATE(p.recebido_em)) BETWEEN ? AND ?', [$de, $ate])
            ->selectRaw('COALESCE(p.previsto_para, DATE(p.recebido_em)) AS dia, COALESCE(f.nome, ?) AS forma, p.liquido', ['Não informada'])->get();
        foreach ($pags as $p) {
            $add((string) $p->dia, 'entradas', 'Recebido · '.$p->forma, (float) $p->liquido, $p->dia > $hoje);
        }

        // saídas realizadas
        // pagas pelo sócio (cartão pessoal) não saíram do caixa da empresa
        $pagas = DB::table('despesas as d')->leftJoin('formas_pagamento as fp', 'fp.id', '=', 'd.forma_pagamento_id')
            ->where('d.empresa_id', $e)->where('d.situacao', 'paga')->whereBetween('d.pago_em', [$de, $ate])
            ->where(fn ($w) => $w->whereNull('fp.tipo')->orWhere('fp.tipo', '<>', 'socio'))
            ->get(['d.pago_em', 'd.descricao', 'd.valor', 'd.valor_pago']);
        foreach ($pagas as $d) {
            $add((string) $d->pago_em, 'saidas', $d->descricao, (float) ($d->valor_pago ?? $d->valor), false);
        }

        // previsto: a receber (ordens em aberto com vencimento de hoje em diante) e a pagar
        $inicioPrev = max($de, $hoje);
        if ($ate >= $hoje) {
            $receber = DB::table('ordens_servico')->where('empresa_id', $e)->where('situacao', 'aberta')->where('tipo', '<>', 'credito')
                ->whereBetween('vencimento', [$inicioPrev, $ate])->groupBy('vencimento', 'tipo')->selectRaw('vencimento, tipo, SUM(valor) AS total, COUNT(*) AS qtd')->get();
            foreach ($receber as $r) {
                $add((string) $r->vencimento, 'entradas', 'A receber · '.(\App\Models\OrdemServico::TIPOS[$r->tipo] ?? $r->tipo), (float) $r->total, true);
            }
            $pagar = DB::table('despesas')->where('empresa_id', $e)->where('situacao', 'aberta')->whereBetween('vencimento', [$inicioPrev, $ate])->get(['vencimento', 'descricao', 'valor']);
            foreach ($pagar as $d) {
                $add((string) $d->vencimento, 'saidas', $d->descricao, (float) $d->valor, true);
            }
        }
        ksort($dias);
        $acumulado = 0.0;
        foreach ($dias as &$d) {
            $d['entradas'] = round($d['entradas'], 2);
            $d['saidas'] = round($d['saidas'], 2);
            $d['saldo'] = round($d['entradas'] - $d['saidas'], 2);
            $acumulado = round($acumulado + $d['saldo'], 2);
            $d['acumulado'] = $acumulado;
            $d['previsto'] = $d['data'] > $hoje;
            $d['itens'] = array_values(array_map(fn ($i) => ['valor' => round($i['valor'], 2)] + $i, $d['itens']));
        }
        unset($d);

        $realizados = array_filter($dias, fn ($d) => ! $d['previsto']);
        $previstos = array_filter($dias, fn ($d) => $d['previsto']);

        return [
            'dias' => array_values($dias),
            'realizado' => ['entradas' => round(array_sum(array_column($realizados, 'entradas')), 2), 'saidas' => round(array_sum(array_column($realizados, 'saidas')), 2)],
            'previsto' => ['entradas' => round(array_sum(array_column($previstos, 'entradas')), 2), 'saidas' => round(array_sum(array_column($previstos, 'saidas')), 2)],
            'atrasado' => [
                'receber' => round((float) DB::table('ordens_servico')->where('empresa_id', $e)->where('situacao', 'aberta')->where('tipo', '<>', 'credito')->where('vencimento', '<', $hoje)->sum('valor'), 2),
                'pagar' => round((float) DB::table('despesas')->where('empresa_id', $e)->where('situacao', 'aberta')->where('vencimento', '<', $hoje)->sum('valor'), 2),
            ],
        ];
    }
}
