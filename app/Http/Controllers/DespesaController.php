<?php

namespace App\Http\Controllers;

use App\Models\ContaFinanceira;
use App\Models\Despesa;
use App\Models\FormaPagamento;
use App\Models\Fornecedor;
use App\Servicos\Despesas;
use App\Servicos\FormasPagamento;
use App\Servicos\LeitorDocumento;
use App\Servicos\PlanoContas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Gestão › Contas a pagar: lista, lançar, alterar, pagar, desfazer, cancelar e comprovante. */
class DespesaController extends Controller
{
    public const ARQUIVO = ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png,webp'];

    public static function linha(Despesa $x): array
    {
        return [
            'id' => $x->id,
            'descricao' => $x->descricao,
            'fornecedor' => $x->fornecedor?->only(['id', 'nome']),
            'conta' => $x->conta ? $x->conta->codigo.' '.$x->conta->nome : null,
            'competencia' => $x->competencia->format('Y-m'),
            'vencimento' => $x->vencimento->format('Y-m-d'),
            'valor' => (float) $x->valor,
            'parcela' => $x->parcela ? $x->parcela.'/'.$x->parcelas : null,
            'recorrente' => (bool) $x->recorrente_id,
            'situacao' => $x->situacao,
            'estado' => $x->estado(),
            'pago_em' => $x->pago_em?->format('Y-m-d'),
            'valor_pago' => $x->valor_pago !== null ? (float) $x->valor_pago : null,
            'forma' => $x->forma?->nome,
            'conta_financeira' => $x->contaFinanceira?->nome,
            'comprovante' => (bool) $x->comprovante,
            'observacao' => $x->observacao,
            'motivo_cancelamento' => $x->motivo_cancelamento,
        ];
    }

    private function opcoes(): array
    {
        FormasPagamento::garantirPadrao();

        return [
            'contas' => PlanoContas::lancaveis()->map(fn ($c) => ['id' => $c['id'], 'rotulo' => $c['codigo'].' '.$c['nome']]),
            'fornecedores' => Fornecedor::where('ativo', true)->orderBy('nome')->get(['id', 'nome', 'conta_padrao_id']),
            'formas' => FormaPagamento::where('ativa', true)->where('tipo', '<>', 'nao_informada')->orderBy('ordem')->get(['id', 'nome', 'conta_financeira_id']),
            'contas_financeiras' => ContaFinanceira::where('ativa', true)->orderBy('ordem')->get(['id', 'nome']),
        ];
    }

    public function index(Request $request): Response
    {
        Despesas::gerarRecorrentes();
        $f = $request->validate([
            'estado' => ['nullable', 'in:abertas,atrasadas,hoje,pagas,canceladas,todas'],
            'q' => ['nullable', 'string', 'max:80'],
            'fornecedor_id' => ['nullable', 'integer'],
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $estado = $f['estado'] ?? 'abertas';
        $hoje = now()->toDateString();
        $q = trim((string) ($f['q'] ?? ''));
        $lista = Despesa::with(['fornecedor:id,nome', 'conta:id,codigo,nome', 'forma:id,nome', 'contaFinanceira:id,nome'])
            ->when($estado === 'abertas', fn ($w) => $w->where('situacao', 'aberta'))
            ->when($estado === 'atrasadas', fn ($w) => $w->where('situacao', 'aberta')->where('vencimento', '<', $hoje))
            ->when($estado === 'hoje', fn ($w) => $w->where('situacao', 'aberta')->where('vencimento', $hoje))
            ->when($estado === 'pagas', fn ($w) => $w->where('situacao', 'paga'))
            ->when($estado === 'canceladas', fn ($w) => $w->where('situacao', 'cancelada'))
            ->when($q !== '', fn ($w) => $w->where(fn ($w) => $w->where('descricao', 'like', "%{$q}%")->orWhereHas('fornecedor', fn ($o) => $o->where('nome', 'like', "%{$q}%"))))
            ->when($f['fornecedor_id'] ?? null, fn ($w, $id) => $w->where('fornecedor_id', $id))
            ->when($f['de'] ?? null, fn ($w, $d) => $w->where($estado === 'pagas' ? 'pago_em' : 'vencimento', '>=', $d))
            ->when($f['ate'] ?? null, fn ($w, $d) => $w->where($estado === 'pagas' ? 'pago_em' : 'vencimento', '<=', $d))
            ->when($estado === 'pagas', fn ($w) => $w->orderByDesc('pago_em'), fn ($w) => $w->orderBy('vencimento'))
            ->orderBy('id')->paginate(50)->withQueryString()->through(fn ($x) => self::linha($x));

        return Inertia::render('Pagar/Index', [
            'despesas' => $lista,
            'filtros' => ['estado' => $estado, 'q' => $q, 'fornecedor_id' => $f['fornecedor_id'] ?? '', 'de' => $f['de'] ?? '', 'ate' => $f['ate'] ?? ''],
            'resumo' => Despesas::resumo(),
        ] + $this->opcoes());
    }

    private function dados(Request $request, bool $nova): array
    {
        return $request->validate([
            'descricao' => ['required', 'string', 'max:120'],
            'fornecedor_id' => ['nullable', 'integer'],
            'conta_id' => ['required', 'integer'],
            'competencia' => ['required', 'date_format:Y-m'],
            'vencimento' => ['required', 'date_format:Y-m-d'],
            'valor' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'parcelas' => $nova ? ['nullable', 'integer', 'min:1', 'max:60'] : ['prohibited'],
            'observacao' => ['nullable', 'string', 'max:255'],
            'pago' => $nova ? ['nullable', 'boolean'] : ['prohibited'],
            'pago_em' => ['nullable', 'date_format:Y-m-d'],
            'valor_pago' => ['nullable', 'numeric', 'gt:0', 'max:9999999'],
            'forma_pagamento_id' => ['nullable', 'integer'],
            'conta_financeira_id' => ['nullable', 'integer'],
            'comprovante' => self::ARQUIVO,
        ], ['descricao.required' => 'Descreva a conta.', 'conta_id.required' => 'Escolha a conta do plano de contas.', 'valor.required' => 'Informe o valor.',
            'vencimento.required' => 'Informe o vencimento.', 'comprovante.mimes' => 'Comprovante em PDF ou imagem (JPG, PNG).', 'comprovante.max' => 'Comprovante de até 5 MB.']);
    }

    public function create(): Response
    {
        return Inertia::render('Pagar/Form', ['despesa' => null] + $this->opcoes());
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $this->dados($request, true);
        $criadas = Despesas::lancar($d, $request->file('comprovante'));
        $msg = count($criadas) > 1 ? count($criadas).' parcelas lançadas' : (! empty($d['pago']) ? 'Conta lançada como paga' : 'Conta lançada');

        return redirect('/pagar')->with('sucesso', $msg.'.');
    }

    /**
     * Lê boleto ou guia: PDF com texto (aqui), texto do OCR (feito no navegador) ou código colado.
     * Devolve sugestões para preencher o formulário; não grava nada. PDF sem texto ou foto: precisa_ocr.
     */
    public function ler(Request $request): JsonResponse
    {
        $request->validate([
            'arquivo' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp'],
            'texto' => ['nullable', 'string', 'max:200000'],
        ]);
        $texto = (string) $request->input('texto');
        if ($texto === '' && $f = $request->file('arquivo')) {
            $texto = strtolower($f->getClientOriginalExtension()) === 'pdf' ? LeitorDocumento::textoPdf($f->getRealPath()) : '';
            if (trim($texto) === '') {
                return response()->json(['precisa_ocr' => true]);
            }
        }
        if (trim($texto) === '') {
            return response()->json(['message' => 'Envie o PDF, a foto ou cole o código de barras.'], 422);
        }
        $r = LeitorDocumento::interpretar($texto);
        $fornecedor = $r['documentos'] ? Fornecedor::where('ativo', true)->whereIn('documento', $r['documentos'])->first() : null;
        $conta = $r['conta_chave'] ? PlanoContas::porChave($r['conta_chave']) : null;
        $r['conta_id'] = $conta && PlanoContas::lancavel($conta->id) ? $conta->id : ($fornecedor?->conta_padrao_id);
        $r['fornecedor_id'] = $fornecedor?->id;
        $r['fornecedor'] = $fornecedor?->nome;
        $r['descricao'] ??= $fornecedor?->nome;
        unset($r['documentos']);

        return response()->json($r);
    }

    public function edit(Despesa $despesa): Response
    {
        return Inertia::render('Pagar/Form', ['despesa' => self::linha($despesa->load('fornecedor', 'conta')) + [
            'fornecedor_id' => $despesa->fornecedor_id, 'conta_id' => $despesa->conta_id]] + $this->opcoes());
    }

    public function update(Request $request, Despesa $despesa): RedirectResponse
    {
        Despesas::alterar($despesa, $this->dados($request, false), $request->file('comprovante'));

        return redirect('/pagar')->with('sucesso', 'Conta alterada.');
    }

    public function pagar(Request $request, Despesa $despesa): RedirectResponse
    {
        $d = $request->validate([
            'pago_em' => ['required', 'date_format:Y-m-d'],
            'valor_pago' => ['nullable', 'numeric', 'gt:0', 'max:9999999'],
            'forma_pagamento_id' => ['required', 'integer'],
            'conta_financeira_id' => ['nullable', 'integer'],
            'comprovante' => self::ARQUIVO,
        ], ['forma_pagamento_id.required' => 'Escolha a forma de pagamento.', 'pago_em.required' => 'Informe a data do pagamento.']);
        Despesas::pagar($despesa, $d, $request->file('comprovante'));

        return back()->with('sucesso', 'Pagamento registrado.');
    }

    public function desfazer(Request $request, Despesa $despesa): RedirectResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:200']], ['motivo.required' => 'Informe o motivo.']);
        Despesas::desfazerPagamento($despesa, $d['motivo']);

        return back()->with('sucesso', 'Pagamento desfeito: a conta voltou para a pagar.');
    }

    public function cancelar(Request $request, Despesa $despesa): RedirectResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:200']], ['motivo.required' => 'Informe o motivo.']);
        Despesas::cancelar($despesa, $d['motivo']);

        return back()->with('sucesso', 'Conta cancelada.');
    }

    public function comprovante(Despesa $despesa): StreamedResponse
    {
        abort_unless($despesa->comprovante && \Storage::disk('local')->exists($despesa->comprovante), 404);

        return \Storage::disk('local')->response($despesa->comprovante, $despesa->comprovante_nome ?? basename($despesa->comprovante));
    }
}
