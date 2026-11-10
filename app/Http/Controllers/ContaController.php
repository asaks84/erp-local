<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\FormaPagamento;
use App\Models\OrdemServico;
use App\Models\Pagamento;
use App\Models\Servico;
use App\Servicos\Baixa;
use App\Servicos\FormasPagamento;
use App\Support\Csv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Contas em aberto (e inadimplência), receber contas do cliente e estorno de pagamento. */
class ContaController extends Controller
{
    /**
     * Uma linha por cliente com contas em aberto: total (créditos abatem), quanto já venceu,
     * vencimento mais antigo e o resumo dos itens. Mesma regra do Início e do relatório do sistema atual.
     */
    public static function abertasPorCliente(?string $busca = null): \Illuminate\Support\Collection
    {
        $hoje = now()->toDateString();
        $sinal = OrdemServico::SQL_VALOR;
        $q = DB::table('ordens_servico as o')->join('clientes as c', 'c.id', '=', 'o.cliente_id')
            ->where('o.empresa_id', app(\App\Support\EmpresaAtual::class)->id())
            ->where('o.situacao', 'aberta')
            ->when($busca, fn ($q) => $q->where(fn ($w) => $w->where('c.nome', 'like', '%'.$busca.'%')->orWhere('o.placa', 'like', '%'.\App\Support\Placa::normalizar($busca).'%')->when(ctype_digit($busca), fn ($w) => $w->orWhere('c.id', (int) $busca))))
            ->groupBy('c.id', 'c.nome', 'c.celular', 'c.telefone')
            ->havingRaw("SUM({$sinal}) > 0")
            ->selectRaw("c.id, c.nome, COALESCE(c.celular, c.telefone) AS contato,
                SUM({$sinal}) AS total,
                SUM(o.tipo <> 'credito') AS qtd,
                SUM(o.tipo = 'mensalidade') AS n_mensalidade,
                SUM(o.tipo = 'servico') AS n_servico,
                SUM(o.tipo = 'estadia') AS n_estadia,
                SUM(o.tipo = 'debito') AS n_debito,
                SUM(o.tipo = 'credito') AS n_credito,
                SUM(CASE WHEN o.tipo <> 'credito' AND COALESCE(o.vencimento, o.data) < ? THEN o.valor ELSE 0 END) AS vencido,
                MIN(CASE WHEN o.tipo <> 'credito' THEN COALESCE(o.vencimento, o.data) END) AS mais_antigo,
                GROUP_CONCAT(DISTINCT o.placa ORDER BY o.placa SEPARATOR ' · ') AS placas", [$hoje]);

        return $q->get()->map(function ($l) use ($hoje) {
            $total = round((float) $l->total, 2);
            $vencido = round(min((float) $l->vencido, $total), 2);   // crédito também abate do vencido
            $p = [];
            foreach ([['n_mensalidade', 'mensalidade', 'mensalidades'], ['n_servico', 'lavagem/serviço', 'lavagens/serviços'], ['n_estadia', 'estadia', 'estadias'], ['n_debito', 'débito', 'débitos']] as [$k, $um, $varios]) {
                if ($l->$k > 0) {
                    $p[] = $l->$k.' '.($l->$k == 1 ? $um : $varios);
                }
            }
            if ($l->n_credito > 0) {
                $p[] = 'crédito abatido';
            }

            return [
                'id' => $l->id,
                'nome' => $l->nome,
                'contato' => $l->contato,
                'total' => $total,
                'vencido' => $vencido,
                'itens' => implode(' · ', $p),
                'placas' => collect(explode(' · ', (string) $l->placas))->filter()->map(fn ($p) => \App\Support\Placa::formatar($p))->implode(' · '),
                'mais_antigo' => $l->mais_antigo,
                'dias' => $vencido > 0 && $l->mais_antigo ? (int) \Carbon\Carbon::parse($l->mais_antigo)->diffInDays($hoje) : 0,
            ];
        });
    }

    public function abertas(Request $request): Response|StreamedResponse
    {
        $f = $request->validate(['q' => ['nullable', 'string', 'max:80'], 'ver' => ['nullable', 'in:todas,vencidas']]);
        $ver = $f['ver'] ?? 'todas';
        $linhas = self::abertasPorCliente($f['q'] ?? null);
        $resumo = ['clientes' => $linhas->count(), 'total' => round($linhas->sum('total'), 2), 'vencido' => round($linhas->sum('vencido'), 2), 'inadimplentes' => $linhas->where('vencido', '>', 0)->count()];
        if ($ver === 'vencidas') {
            $linhas = $linhas->where('vencido', '>', 0)->sortByDesc('vencido');
        } else {
            $linhas = $linhas->sortBy([['mais_antigo', 'asc'], ['nome', 'asc']]);
        }

        if ($request->boolean('csv')) {
            return Csv::baixar('contas-em-aberto-'.now()->format('Y-m-d').'.csv',
                ['Código', 'Cliente', 'Contato', 'Placas', 'Itens', 'Total', 'Vencido', 'Mais antigo', 'Dias em atraso'],
                $linhas->map(fn ($l) => [$l['id'], $l['nome'], $l['contato'], $l['placas'], $l['itens'], Csv::numero($l['total']), Csv::numero($l['vencido']),
                    $l['mais_antigo'] ? \Carbon\Carbon::parse($l['mais_antigo'])->format('d/m/Y') : '', $l['dias']]));
        }

        return Inertia::render('Contas/Abertas', ['linhas' => $linhas->values(), 'resumo' => $resumo, 'filtros' => ['q' => $f['q'] ?? '', 'ver' => $ver]]);
    }

    public function receber(Cliente $cliente): Response
    {
        FormasPagamento::garantirPadrao();

        return Inertia::render('Contas/Receber', [
            'cliente' => $cliente->only(['id', 'nome']),
            'ordens' => Baixa::abertas($cliente)->map(fn (OrdemServico $o) => [
                'id' => $o->id,
                'numero' => $o->numero,
                'tipo' => $o->tipo,
                'descricao' => $o->descricao,
                'competencia' => $o->competencia,
                'placa' => $o->placa ? \App\Support\Placa::formatar($o->placa) : null,
                'data' => $o->data->format('Y-m-d'),
                'vencimento' => $o->vencimento?->format('Y-m-d'),
                'valor' => (float) $o->valor,
                'vencida' => $o->tipo !== 'credito' && $o->dataReferencia() < now()->toDateString(),
            ]),
            'formas' => FormaPagamento::where('ativa', true)->orderBy('ordem')->orderBy('nome')->get(['id', 'nome', 'tipo', 'parcelas_max']),
            'servicos' => Servico::where('ativo', true)->where('papel', 'principal')->orderBy('nome')->get(['id', 'nome']),
            'veiculos' => $cliente->veiculos()->where('ativo', true)->get()->map(fn ($v) => ['id' => $v->id, 'rotulo' => $v->rotulo()]),
        ]);
    }

    public function confirmarRecebimento(Request $request, Cliente $cliente): RedirectResponse
    {
        $d = $request->validate([
            'ordens' => ['required', 'array', 'min:1'],
            'ordens.*' => ['integer'],
            'valor' => ['required', 'numeric', 'min:0', 'max:999999'],
            'forma_pagamento_id' => ['required', 'integer'],
            'parcelas' => ['nullable', 'integer', 'min:1', 'max:24'],
            'recebido_em' => ['required', 'date_format:Y-m-d'],
            'sobra' => ['nullable', 'in:credito,servico'],
            'falta' => ['nullable', 'in:debito,desconto'],
            'servico_id' => ['nullable', 'string', 'max:10'], // id do serviço ou "estadia"
            'veiculo_id' => ['nullable', 'integer'],
            'motivo' => ['nullable', 'string', 'max:200'],
            'observacao' => ['nullable', 'string', 'max:255'],
        ], ['ordens.required' => 'Marque as contas que foram pagas.']);
        [, $msg] = Baixa::receber($cliente, $d + ['origem' => 'gestao']);

        return redirect("/clientes/{$cliente->id}")->with('sucesso', 'Pagamento registrado: '.$msg.'.');
    }

    public function estornar(Request $request, Pagamento $pagamento): RedirectResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:200']]);
        Baixa::estornar($pagamento, $d['motivo']);

        return back()->with('sucesso', 'Pagamento estornado; as contas voltaram a ficar em aberto.');
    }
}
