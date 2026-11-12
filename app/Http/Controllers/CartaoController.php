<?php

namespace App\Http\Controllers;

use App\Models\Cartao;
use App\Models\CartaoRegra;
use App\Models\FaturaCartao;
use App\Models\Fornecedor;
use App\Servicos\Cartoes;
use App\Servicos\PlanoContas;
use App\Support\Atividades;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Gestão › Contas a pagar › Cartões: cartões, importação da fatura e classificação das compras. */
class CartaoController extends Controller
{
    public function index(): Response
    {
        $faturas = FaturaCartao::with(['cartao:id,nome,tipo', 'despesa:id,situacao,vencimento,pago_em,valor,valor_pago,forma_pagamento_id', 'despesa.forma:id,tipo'])
            ->withCount(['itens', 'itens as sem_conta_count' => fn ($q) => $q->whereNull('conta_id')->where('da_empresa', true)])
            ->orderByDesc('fechamento')->limit(60)->get();

        return Inertia::render('Pagar/Cartoes', [
            'cartoes' => Cartao::orderByDesc('ativo')->orderBy('nome')->get()->map(fn ($c) => $c->only(['id', 'nome', 'tipo', 'dia_vencimento', 'fornecedor_id', 'ativo'])),
            'tipos' => Cartao::TIPOS,
            'fornecedores' => Fornecedor::where('ativo', true)->orderBy('nome')->get(['id', 'nome']),
            'faturas' => $faturas->map(fn ($f) => [
                'id' => $f->id, 'cartao' => $f->cartao->nome, 'tipo' => $f->cartao->tipo,
                'fechamento' => $f->fechamento->format('Y-m-d'), 'vencimento' => $f->vencimento->format('Y-m-d'),
                'total' => (float) ($f->despesa?->valor ?? $f->total), 'itens' => $f->itens_count, 'sem_conta' => $f->sem_conta_count,
                'estado' => $f->despesa?->estado(), 'pelo_socio' => $f->despesa?->forma?->tipo === 'socio',
            ]),
        ]);
    }

    private function dados(Request $request): array
    {
        $d = $request->validate([
            'nome' => ['required', 'string', 'max:60'],
            'tipo' => ['required', Rule::in(array_keys(Cartao::TIPOS))],
            'dia_vencimento' => ['nullable', 'integer', 'between:1,31'],
            'fornecedor_id' => ['nullable', 'integer'],
            'ativo' => ['nullable', 'boolean'],
        ], ['nome.required' => 'Dê um nome ao cartão (ex.: Nubank da empresa).']);
        if (! empty($d['fornecedor_id']) && ! Fornecedor::find($d['fornecedor_id'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['fornecedor_id' => 'Fornecedor não encontrado.']);
        }

        return $d;
    }

    public function store(Request $request): RedirectResponse
    {
        $c = Cartao::create($this->dados($request));
        Atividades::registrar('cartao_criado', $c->nome);

        return back()->with('sucesso', 'Cartão '.$c->nome.' cadastrado.');
    }

    public function update(Request $request, Cartao $cartao): RedirectResponse
    {
        $cartao->update($this->dados($request));
        Atividades::registrar('cartao_alterado', $cartao->nome);

        return back()->with('sucesso', 'Cartão salvo.');
    }

    public function importar(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'cartao_id' => ['required', 'integer'],
            'arquivo' => ['required', 'file', 'max:2048', 'extensions:ofx,csv,txt'],
            'vencimento' => ['nullable', 'date_format:Y-m-d'],
        ], ['cartao_id.required' => 'Escolha o cartão.', 'arquivo.required' => 'Escolha o arquivo da fatura (OFX ou CSV).', 'arquivo.extensions' => 'Use o arquivo OFX ou CSV exportado pelo banco.']);
        $f = Cartoes::importar(Cartao::findOrFail($d['cartao_id']), $request->file('arquivo'), $d['vencimento'] ?? null);

        return redirect('/pagar/faturas/'.$f->id)->with('sucesso', 'Fatura importada: classifique as compras.');
    }

    public function fatura(FaturaCartao $fatura): Response
    {
        $fatura->load(['cartao', 'despesa.forma', 'itens' => fn ($q) => $q->orderBy('data')->orderBy('id')]);
        $regras = CartaoRegra::whereIn('estabelecimento', $fatura->itens->pluck('estabelecimento')->unique())->pluck('modo', 'estabelecimento');

        return Inertia::render('Pagar/Fatura', [
            'fatura' => [
                'id' => $fatura->id, 'cartao' => $fatura->cartao->nome, 'tipo' => $fatura->cartao->tipo,
                'inicio' => $fatura->inicio?->format('Y-m-d'), 'fechamento' => $fatura->fechamento->format('Y-m-d'), 'vencimento' => $fatura->vencimento->format('Y-m-d'),
                'total' => (float) $fatura->total, 'saldo_arquivo' => $fatura->saldo_arquivo !== null ? (float) $fatura->saldo_arquivo : null,
                'despesa' => $fatura->despesa ? ['id' => $fatura->despesa->id, 'estado' => $fatura->despesa->estado(), 'valor' => (float) $fatura->despesa->valor,
                    'pelo_socio' => $fatura->despesa->forma?->tipo === 'socio'] : null,
                'arquivo' => (bool) $fatura->arquivo,
            ],
            'itens' => $fatura->itens->map(fn ($i) => [
                'id' => $i->id, 'data' => $i->data->format('Y-m-d'), 'original' => $i->original, 'parcela' => $i->parcela ? $i->parcela.'/'.$i->parcelas : null,
                'valor' => (float) $i->valor, 'conta_id' => $i->conta_id ?? '', 'descricao' => $i->descricao ?? '', 'da_empresa' => $i->da_empresa,
                'estabelecimento' => $i->estabelecimento,
                'perguntar' => ($regras[$i->estabelecimento] ?? null) === 'perguntar' || Cartoes::marketplace($i->estabelecimento),
            ]),
            'contas' => PlanoContas::lancaveis()->reject(fn ($c) => $c['id'] === PlanoContas::porChave('despesa_cartao')->id)
                ->map(fn ($c) => ['id' => $c['id'], 'rotulo' => $c['codigo'].' '.$c['nome']])->values(),
        ]);
    }

    public function classificar(Request $request, FaturaCartao $fatura): RedirectResponse
    {
        $d = $request->validate([
            'itens' => ['required', 'array'],
            'itens.*.conta_id' => ['nullable', 'integer'],
            'itens.*.descricao' => ['nullable', 'string', 'max:120'],
            'itens.*.da_empresa' => ['required', 'boolean'],
        ]);
        Cartoes::classificar($fatura, $d['itens']);

        return back()->with('sucesso', 'Classificação salva.');
    }

    public function desfazer(Request $request, FaturaCartao $fatura): RedirectResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:150']], ['motivo.required' => 'Informe o motivo.']);
        Cartoes::desfazer($fatura, $d['motivo']);

        return redirect('/pagar/cartoes')->with('sucesso', 'Importação desfeita: a conta da fatura foi cancelada.');
    }

    public function arquivo(FaturaCartao $fatura): StreamedResponse
    {
        abort_unless($fatura->arquivo && \Storage::disk('local')->exists($fatura->arquivo), 404);

        return \Storage::disk('local')->download($fatura->arquivo, $fatura->arquivo_nome ?? basename($fatura->arquivo));
    }
}
