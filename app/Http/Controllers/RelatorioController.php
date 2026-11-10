<?php

namespace App\Http\Controllers;

use App\Models\OrdemServico;
use App\Models\Pagamento;
use App\Support\Csv;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Recebimentos por período: por forma de pagamento e pelo que foi pago (mensalidades, serviços...). */
class RelatorioController extends Controller
{
    /** soma do que foi baixado no período, por tipo de ordem (crédito usado entra negativo) */
    public static function porCategoria(string $de, string $ate): array
    {
        $linhas = DB::table('pagamento_itens as i')
            ->join('pagamentos as p', 'p.id', '=', 'i.pagamento_id')
            ->join('ordens_servico as o', 'o.id', '=', 'i.ordem_id')
            ->where('p.empresa_id', app(\App\Support\EmpresaAtual::class)->id())
            ->where('p.situacao', 'confirmado')
            ->whereBetween('p.recebido_em', [$de.' 00:00:00', $ate.' 23:59:59'])
            ->groupBy('o.tipo')->selectRaw('o.tipo, SUM(i.valor) AS total, COUNT(*) AS qtd')->get();

        return $linhas->map(fn ($l) => ['tipo' => $l->tipo, 'rotulo' => OrdemServico::TIPOS[$l->tipo] ?? $l->tipo, 'total' => round((float) $l->total, 2), 'qtd' => (int) $l->qtd])->values()->all();
    }

    public function recebimentos(Request $request): Response|StreamedResponse
    {
        $f = $request->validate(['de' => ['nullable', 'date_format:Y-m-d'], 'ate' => ['nullable', 'date_format:Y-m-d'], 'forma' => ['nullable', 'integer']]);
        $de = $f['de'] ?? now()->startOfMonth()->toDateString();
        $ate = $f['ate'] ?? now()->toDateString();
        if ($ate < $de) {
            [$de, $ate] = [$ate, $de];
        }
        $pagamentos = Pagamento::with(['cliente:id,nome', 'forma:id,nome', 'itens.ordem:id,numero,tipo,descricao,placa'])
            ->whereBetween('recebido_em', [$de.' 00:00:00', $ate.' 23:59:59'])
            ->when($f['forma'] ?? null, fn ($q, $id) => $q->where('forma_pagamento_id', $id))
            ->orderByDesc('recebido_em')->orderByDesc('id')->get();
        $validos = $pagamentos->where('situacao', 'confirmado');

        if ($request->boolean('csv')) {
            return Csv::baixar("recebimentos-{$de}-a-{$ate}.csv",
                ['Data', 'Cliente', 'Forma', 'Valor', 'Taxa', 'Líquido', 'Previsto para', 'Origem', 'Ordens', 'Situação'],
                $pagamentos->map(fn ($p) => [$p->recebido_em->format('d/m/Y H:i'), $p->cliente?->nome, $p->forma?->nome, Csv::numero($p->valor), Csv::numero($p->taxa),
                    Csv::numero($p->liquido), $p->previsto_para?->format('d/m/Y'), $p->origem, $p->itens->map(fn ($i) => '#'.$i->ordem->numero)->implode(' '), $p->situacao]));
        }

        return Inertia::render('Relatorios/Recebimentos', [
            'filtros' => ['de' => $de, 'ate' => $ate, 'forma' => $f['forma'] ?? ''],
            'resumo' => [
                'total' => round((float) $validos->sum('valor'), 2),
                'taxa' => round((float) $validos->sum('taxa'), 2),
                'liquido' => round((float) $validos->sum('liquido'), 2),
                'qtd' => $validos->count(),
            ],
            'por_forma' => $validos->groupBy(fn ($p) => $p->forma?->nome ?? 'Não informada')->map(fn ($g, $nome) => ['nome' => $nome, 'qtd' => $g->count(), 'total' => round((float) $g->sum('valor'), 2)])->sortByDesc('total')->values(),
            'por_categoria' => self::porCategoria($de, $ate),
            'formas' => \App\Models\FormaPagamento::orderBy('ordem')->get(['id', 'nome']),
            'pagamentos' => $pagamentos->take(500)->map(fn (Pagamento $p) => [
                'id' => $p->id,
                'recebido_em' => $p->recebido_em->format('Y-m-d H:i'),
                'cliente' => $p->cliente?->only(['id', 'nome']),
                'forma' => $p->forma?->nome,
                'valor' => (float) $p->valor,
                'taxa' => (float) $p->taxa,
                'liquido' => (float) $p->liquido,
                'previsto_para' => $p->previsto_para?->format('Y-m-d'),
                'origem' => $p->origem,
                'situacao' => $p->situacao,
                'motivo_estorno' => $p->motivo_estorno,
                'itens' => $p->itens->map(fn ($i) => ['numero' => $i->ordem->numero, 'descricao' => $i->ordem->descricao, 'valor' => (float) $i->valor])->values(),
            ])->values(),
            'limitado' => $pagamentos->count() > 500,
            'periodo' => Carbon::parse($de)->format('d/m/Y').' a '.Carbon::parse($ate)->format('d/m/Y'),
        ]);
    }
}
