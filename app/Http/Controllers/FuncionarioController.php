<?php

namespace App\Http\Controllers;

use App\Models\ContaFinanceira;
use App\Models\Diaria;
use App\Models\FormaPagamento;
use App\Models\Funcionario;
use App\Models\Holerite;
use App\Servicos\Folha;
use App\Servicos\FormasPagamento;
use App\Servicos\LeitorDocumento;
use App\Support\Atividades;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Gestão › Funcionários: cadastro, recibos de salário (importados do PDF do contador) e diárias dos avulsos. */
class FuncionarioController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Funcionarios/Index', [
            'funcionarios' => Funcionario::orderByDesc('ativo')->orderBy('nome')->get()->map(fn ($f) => [
                'id' => $f->id, 'nome' => $f->nome, 'tipo' => $f->tipo, 'funcao' => $f->funcao, 'matricula' => $f->matricula,
                'telefone' => $f->telefone, 'pix' => $f->pix, 'valor_diaria' => $f->valor_diaria !== null ? (float) $f->valor_diaria : null,
                'observacao' => $f->observacao, 'ativo' => $f->ativo,
            ]),
            'tipos' => Funcionario::TIPOS,
        ]);
    }

    private function dados(Request $request, ?Funcionario $f = null): array
    {
        $d = $request->validate([
            'nome' => ['required', 'string', 'max:100'],
            'tipo' => ['required', Rule::in(array_keys(Funcionario::TIPOS))],
            'funcao' => ['nullable', 'string', 'max:60'],
            'matricula' => ['nullable', 'string', 'max:20'],
            'telefone' => ['nullable', 'string', 'max:20'],
            'pix' => ['nullable', 'string', 'max:120'],
            'valor_diaria' => ['nullable', 'numeric', 'gt:0', 'max:99999'],
            'observacao' => ['nullable', 'string', 'max:255'],
            'ativo' => ['nullable', 'boolean'],
        ], ['nome.required' => 'Informe o nome.']);
        $d['nome'] = mb_strtoupper(trim($d['nome']));
        $d['matricula'] = ltrim(trim((string) ($d['matricula'] ?? '')), '0') ?: null;
        $d['telefone'] = preg_replace('/\D/', '', (string) ($d['telefone'] ?? '')) ?: null;
        if ($d['matricula'] && Funcionario::where('matricula', $d['matricula'])->when($f, fn ($w) => $w->where('id', '<>', $f->id))->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['matricula' => 'Já há um funcionário com esta matrícula.']);
        }

        return $d;
    }

    public function store(Request $request): RedirectResponse
    {
        $f = Funcionario::create($this->dados($request));
        Atividades::registrar('funcionario_criado', $f->nome.' ('.Funcionario::TIPOS[$f->tipo].')');

        return back()->with('sucesso', $f->nome.' cadastrado.');
    }

    public function update(Request $request, Funcionario $funcionario): RedirectResponse
    {
        $funcionario->update($this->dados($request, $funcionario));
        Atividades::registrar('funcionario_alterado', $funcionario->nome.($funcionario->ativo ? '' : ' (desativado)'));

        return back()->with('sucesso', 'Funcionário salvo.');
    }

    // ------------------------------------------------------------ recibos de salário

    public function recibos(Request $request): Response
    {
        $mes = $request->validate(['mes' => ['nullable', 'date_format:Y-m']])['mes'] ?? null;
        $meses = Holerite::selectRaw('distinct competencia')->orderByDesc('competencia')->limit(24)->pluck('competencia')->map(fn ($c) => substr((string) $c, 0, 7))->values();
        $mes ??= $meses->first();
        $lista = $mes ? Holerite::with(['funcionario:id,nome,funcao', 'despesa:id,situacao,vencimento,pago_em'])->where('competencia', $mes.'-01')->get()
            ->sortBy('funcionario.nome')->values()->map(fn ($h) => [
                'id' => $h->id, 'funcionario' => $h->funcionario->nome, 'funcao' => $h->funcionario->funcao,
                'vencimentos' => (float) $h->vencimentos, 'descontos' => (float) $h->descontos, 'liquido' => (float) $h->liquido,
                'fgts' => $h->fgts !== null ? (float) $h->fgts : null, 'itens' => $h->itens ?? [],
                'despesa_id' => $h->despesa_id, 'situacao' => $h->despesa?->situacao, 'estado' => $h->despesa?->estado(),
                'vencimento' => $h->despesa?->vencimento?->format('Y-m-d'), 'pago_em' => $h->despesa?->pago_em?->format('Y-m-d'),
            ]) : collect();

        return Inertia::render('Funcionarios/Recibos', [
            'mes' => $mes, 'meses' => $meses, 'recibos' => $lista,
            'funcionarios' => Funcionario::where('tipo', 'registrado')->orderBy('nome')->get(['id', 'nome', 'matricula']),
        ]);
    }

    /** lê o PDF (ou o texto do OCR) e devolve os recibos para conferir; não grava nada */
    public function lerRecibos(Request $request): JsonResponse
    {
        $request->validate([
            'arquivo' => ['required_without:texto', 'nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp'],
            'texto' => ['required_without:arquivo', 'nullable', 'string', 'max:200000'],
        ]);
        $texto = $request->input('texto');
        if (! $texto) {
            $f = $request->file('arquivo');
            $texto = strtolower($f->getClientOriginalExtension()) === 'pdf' ? LeitorDocumento::textoPdf($f->getRealPath()) : '';
            if (trim($texto) === '') {
                return response()->json(['precisa_ocr' => true]);
            }
        }
        $recibos = collect(LeitorDocumento::holerites($texto))->map(function ($r) {
            $f = Funcionario::where('matricula', $r['matricula'])->first()
                ?? Funcionario::where('tipo', 'registrado')->where('nome', $r['nome'])->first();

            return $r + [
                'funcionario_id' => $f?->id,
                'vencimento' => Folha::vencimentoSalario($r['competencia']),
                'ja_importado' => $f ? (bool) Folha::holeriteAtivo($f->id, $r['competencia']) : false,
            ];
        });

        return response()->json(['recibos' => $recibos->values()]);
    }

    public function importarRecibos(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'arquivo' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp'],
            'itens' => ['required', 'array', 'min:1'],
            'itens.*.matricula' => ['required', 'string', 'max:20'],
            'itens.*.nome' => ['required', 'string', 'max:100'],
            'itens.*.funcao' => ['nullable', 'string', 'max:60'],
            'itens.*.competencia' => ['required', 'date_format:Y-m'],
            'itens.*.salario_base' => ['nullable', 'numeric'],
            'itens.*.vencimentos' => ['required', 'numeric', 'min:0'],
            'itens.*.descontos' => ['required', 'numeric', 'min:0'],
            'itens.*.liquido' => ['required', 'numeric', 'gt:0'],
            'itens.*.fgts' => ['nullable', 'numeric'],
            'itens.*.itens' => ['nullable', 'array'],
            'itens.*.vencimento' => ['required', 'date_format:Y-m-d'],
            'itens.*.funcionario_id' => ['nullable', 'integer'],
        ], ['itens.required' => 'Nenhum recibo para importar.', 'itens.*.vencimento.required' => 'Informe a data de pagamento.']);
        $n = Folha::importarHolerites($d['itens'], $request->file('arquivo'));

        return redirect('/funcionarios/recibos?mes='.$d['itens'][0]['competencia'])
            ->with('sucesso', $n.' recibo'.($n > 1 ? 's importados' : ' importado').': '.($n > 1 ? 'as contas estão' : 'a conta está').' em Contas a pagar.');
    }

    // ------------------------------------------------------------ diárias

    public function diarias(): Response
    {
        FormasPagamento::garantirPadrao();
        $abertas = Folha::diariasAPagar()->with(['funcionario:id,nome', 'despesa:id,situacao'])->orderBy('data')->get();
        $pagas = Diaria::with(['funcionario:id,nome', 'despesa:id,situacao,pago_em,vencimento'])
            ->whereHas('despesa', fn ($d) => $d->where('situacao', '<>', 'cancelada'))
            ->where('data', '>=', now()->subDays(60)->toDateString())->orderByDesc('data')->limit(200)->get();
        $linha = fn ($d) => ['id' => $d->id, 'funcionario_id' => $d->funcionario_id, 'funcionario' => $d->funcionario->nome, 'data' => $d->data->format('Y-m-d'),
            'valor' => (float) $d->valor, 'observacao' => $d->observacao, 'situacao' => $d->despesa?->situacao];

        return Inertia::render('Funcionarios/Diarias', [
            'avulsos' => Funcionario::where('tipo', 'avulso')->where('ativo', true)->orderBy('nome')->get(['id', 'nome', 'pix', 'valor_diaria']),
            'abertas' => $abertas->map($linha),
            'pagas' => $pagas->map($linha),
            'formas' => FormaPagamento::where('ativa', true)->where('tipo', '<>', 'nao_informada')->orderBy('ordem')->get(['id', 'nome', 'conta_financeira_id']),
            'contas_financeiras' => ContaFinanceira::where('ativa', true)->orderBy('ordem')->get(['id', 'nome']),
        ]);
    }

    public function lancarDiarias(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'funcionario_id' => ['required', 'integer'],
            'datas' => ['required', 'array', 'min:1', 'max:31'],
            'datas.*' => ['date_format:Y-m-d', 'before_or_equal:today'],
            'valor' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'observacao' => ['nullable', 'string', 'max:120'],
        ], ['funcionario_id.required' => 'Escolha o funcionário.', 'datas.required' => 'Escolha o dia trabalhado.', 'valor.required' => 'Informe o valor da diária.',
            'datas.*.before_or_equal' => 'Não dá para lançar diária de dia que ainda não chegou.']);
        $f = Funcionario::findOrFail($d['funcionario_id']);
        $n = Folha::lancarDiarias($f, $d['datas'], (float) $d['valor'], $d['observacao'] ?? null);

        return back()->with($n ? 'sucesso' : 'erro', $n ? $n.' diária'.($n > 1 ? 's lançadas' : ' lançada').' para '.$f->nome.'.' : 'Esses dias já estavam lançados.');
    }

    public function removerDiaria(Diaria $diaria): RedirectResponse
    {
        Folha::removerDiaria($diaria);

        return back()->with('sucesso', 'Diária removida.');
    }

    public function pagarDiarias(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'funcionario_id' => ['required', 'integer'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'pago' => ['nullable', 'boolean'],
            'vencimento' => ['nullable', 'date_format:Y-m-d'],
            'pago_em' => ['required_if:pago,true', 'nullable', 'date_format:Y-m-d'],
            'valor_pago' => ['nullable', 'numeric', 'gt:0'],
            'forma_pagamento_id' => ['required_if:pago,true', 'nullable', 'integer'],
            'conta_financeira_id' => ['nullable', 'integer'],
        ], ['forma_pagamento_id.required_if' => 'Escolha a forma de pagamento.', 'pago_em.required_if' => 'Informe a data do pagamento.']);
        $f = Funcionario::findOrFail($d['funcionario_id']);
        $despesa = Folha::pagarDiarias($f, $d['ids'], $d);

        return back()->with('sucesso', $despesa->situacao === 'paga' ? 'Pagamento registrado: está em Contas a pagar, como pago.' : 'Conta criada em Contas a pagar.');
    }
}
