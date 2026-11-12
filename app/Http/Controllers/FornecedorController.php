<?php

namespace App\Http\Controllers;

use App\Models\Fornecedor;
use App\Servicos\PlanoContas;
use App\Support\Atividades;
use App\Support\Documento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Gestão › Contas a pagar › Fornecedores. */
class FornecedorController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Pagar/Fornecedores', [
            'fornecedores' => Fornecedor::with('contaPadrao:id,codigo,nome')->orderByDesc('ativo')->orderBy('nome')->get()->map(fn ($f) => [
                'id' => $f->id, 'nome' => $f->nome, 'documento' => $f->documento, 'telefone' => $f->telefone, 'email' => $f->email,
                'pix' => $f->pix, 'conta_padrao_id' => $f->conta_padrao_id, 'conta' => $f->contaPadrao?->codigo.' '.$f->contaPadrao?->nome,
                'observacao' => $f->observacao, 'ativo' => $f->ativo,
            ]),
            'contas' => PlanoContas::lancaveis()->map(fn ($c) => ['id' => $c['id'], 'rotulo' => $c['codigo'].' '.$c['nome']]),
        ]);
    }

    private function dados(Request $request): array
    {
        $d = $request->validate([
            'nome' => ['required', 'string', 'max:100'],
            'documento' => ['nullable', 'string', 'max:18'],
            'telefone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:120'],
            'pix' => ['nullable', 'string', 'max:120'],
            'conta_padrao_id' => ['nullable', 'integer'],
            'observacao' => ['nullable', 'string', 'max:255'],
            'ativo' => ['nullable', 'boolean'],
        ], ['nome.required' => 'Informe o nome do fornecedor.']);
        $d['nome'] = mb_strtoupper(trim($d['nome']));
        $d['documento'] = Documento::limpar($d['documento'] ?? null);
        if ($d['documento'] && ! Documento::valido($d['documento'])) {
            throw ValidationException::withMessages(['documento' => 'CPF ou CNPJ inválido.']);
        }
        $d['telefone'] = preg_replace('/\D/', '', (string) ($d['telefone'] ?? '')) ?: null;
        if (! empty($d['conta_padrao_id']) && ! PlanoContas::lancavel((int) $d['conta_padrao_id'])) {
            throw ValidationException::withMessages(['conta_padrao_id' => 'Escolha uma conta de despesa.']);
        }

        return $d;
    }

    public function store(Request $request): RedirectResponse
    {
        $f = Fornecedor::create($this->dados($request));
        Atividades::registrar('fornecedor_criado', $f->nome);

        return back()->with('sucesso', 'Fornecedor '.$f->nome.' cadastrado.')->with('fornecedor_novo', $f->id);
    }

    public function update(Request $request, Fornecedor $fornecedor): RedirectResponse
    {
        $fornecedor->update($this->dados($request));
        Atividades::registrar('fornecedor_alterado', $fornecedor->nome.($fornecedor->ativo ? '' : ' (desativado)'));

        return back()->with('sucesso', 'Fornecedor salvo.');
    }
}
