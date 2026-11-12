<?php

namespace App\Http\Controllers;

use App\Models\DespesaRecorrente;
use App\Models\Fornecedor;
use App\Servicos\Despesas;
use App\Servicos\PlanoContas;
use App\Support\Atividades;
use App\Support\Formato;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Gestão › Contas a pagar › Contas fixas (geram uma conta a pagar por mês). */
class RecorrenteController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Pagar/Recorrentes', [
            'recorrentes' => DespesaRecorrente::with(['fornecedor:id,nome', 'conta:id,codigo,nome'])->orderByDesc('ativa')->orderBy('dia_vencimento')->get()->map(fn ($r) => [
                'id' => $r->id, 'descricao' => $r->descricao, 'fornecedor_id' => $r->fornecedor_id, 'fornecedor' => $r->fornecedor?->nome,
                'conta_id' => $r->conta_id, 'conta' => $r->conta?->codigo.' '.$r->conta?->nome, 'valor' => (float) $r->valor,
                'dia_vencimento' => $r->dia_vencimento, 'inicio' => $r->inicio->format('Y-m'), 'fim' => $r->fim?->format('Y-m'), 'ativa' => $r->ativa,
            ]),
            'contas' => PlanoContas::lancaveis()->map(fn ($c) => ['id' => $c['id'], 'rotulo' => $c['codigo'].' '.$c['nome']]),
            'fornecedores' => Fornecedor::where('ativo', true)->orderBy('nome')->get(['id', 'nome', 'conta_padrao_id']),
        ]);
    }

    private function dados(Request $request): array
    {
        $d = $request->validate([
            'descricao' => ['required', 'string', 'max:120'],
            'fornecedor_id' => ['nullable', 'integer'],
            'conta_id' => ['required', 'integer'],
            'valor' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'dia_vencimento' => ['required', 'integer', 'min:1', 'max:31'],
            'inicio' => ['required', 'date_format:Y-m'],
            'fim' => ['nullable', 'date_format:Y-m', 'after_or_equal:inicio'],
            'ativa' => ['nullable', 'boolean'],
        ], ['descricao.required' => 'Descreva a conta fixa.', 'conta_id.required' => 'Escolha a conta do plano de contas.', 'fim.after_or_equal' => 'O fim precisa ser depois do início.']);
        if (! PlanoContas::lancavel((int) $d['conta_id'])) {
            throw ValidationException::withMessages(['conta_id' => 'Escolha uma conta de despesa.']);
        }
        if (! empty($d['fornecedor_id']) && ! Fornecedor::find($d['fornecedor_id'])) {
            throw ValidationException::withMessages(['fornecedor_id' => 'Fornecedor não encontrado.']);
        }
        $d['inicio'] = Carbon::createFromFormat('Y-m', $d['inicio'])->startOfMonth();
        $d['fim'] = ! empty($d['fim']) ? Carbon::createFromFormat('Y-m', $d['fim'])->startOfMonth() : null;

        return $d;
    }

    public function store(Request $request): RedirectResponse
    {
        $r = DespesaRecorrente::create($this->dados($request));
        $n = Despesas::gerarRecorrentes();
        Atividades::registrar('despesa_fixa_criada', $r->descricao.' · '.Formato::moeda($r->valor).' todo dia '.$r->dia_vencimento);

        return back()->with('sucesso', 'Conta fixa criada'.($n ? " ({$n} conta(s) a pagar geradas)" : '').'.');
    }

    /** muda a fixa daqui para frente; contas já geradas não mudam (altere-as em Contas a pagar) */
    public function update(Request $request, DespesaRecorrente $recorrente): RedirectResponse
    {
        $recorrente->update($this->dados($request));
        Atividades::registrar('despesa_fixa_alterada', $recorrente->descricao.($recorrente->ativa ? '' : ' (desativada)'));

        return back()->with('sucesso', 'Conta fixa salva. As contas já geradas não mudam.');
    }
}
