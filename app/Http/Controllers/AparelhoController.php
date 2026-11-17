<?php

namespace App\Http\Controllers;

use App\Models\Aparelho;
use App\Models\Empresa;
use App\Models\Unidade;
use App\Servicos\Pdv\Aparelhos;
use App\Support\Atividades;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Configurações › Aparelhos (PDV): cadastrar, gerar código de pareamento, desativar; memória do PIN. */
class AparelhoController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Configuracoes/Aparelhos', [
            'aparelhos' => Aparelho::with('unidade:id,nome')->orderByDesc('ativo')->orderBy('nome')->get()->map(fn (Aparelho $a) => [
                'id' => $a->id, 'nome' => $a->nome, 'unidade' => $a->unidade?->nome, 'situacao' => $a->situacao(),
                'pareado_em' => $a->pareado_em?->format('Y-m-d H:i'), 'ultimo_acesso_em' => $a->ultimo_acesso_em?->format('Y-m-d H:i'),
                'versao_app' => $a->versao_app, 'codigo_expira_em' => $a->codigo_expira_em?->format('Y-m-d H:i'),
            ]),
            'unidades' => Unidade::orderBy('nome')->get(['id', 'nome']),
            // código acabado de gerar: aparece uma vez só
            'codigo' => $request->session()->get('pdv_codigo'),
            'pin_memoria_min' => (int) (Empresa::atual()->config('pdv_pin_memoria_min') ?? 0),
            'endereco_pdv' => url('/pdv'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate(['nome' => ['required', 'string', 'max:60'], 'unidade_id' => ['nullable', 'integer', 'exists:unidades,id']],
            ['nome.required' => 'Dê um nome ao aparelho (ex.: Celular do balcão).']);
        $a = Aparelho::create($d + ['criado_por' => $request->user()->id]);
        Atividades::registrar('pdv_aparelho', 'aparelho '.$a->nome.' cadastrado');

        return $this->comCodigo($a);
    }

    /** novo código (pareia de novo; o token antigo para de valer) */
    public function codigo(Aparelho $aparelho): RedirectResponse
    {
        return $this->comCodigo($aparelho);
    }

    private function comCodigo(Aparelho $a): RedirectResponse
    {
        $codigo = Aparelhos::novoCodigo($a);

        return redirect('/configuracoes/aparelhos')->with('pdv_codigo', ['aparelho' => $a->nome, 'codigo' => $codigo, 'minutos' => Aparelhos::VALIDADE_CODIGO_MIN]);
    }

    public function update(Request $request, Aparelho $aparelho): RedirectResponse
    {
        $d = $request->validate(['nome' => ['required', 'string', 'max:60'], 'unidade_id' => ['nullable', 'integer', 'exists:unidades,id']]);
        $aparelho->update($d);

        return back()->with('sucesso', 'Aparelho salvo.');
    }

    public function desativar(Aparelho $aparelho): RedirectResponse
    {
        Aparelhos::desativar($aparelho);

        return back()->with('sucesso', 'Aparelho desativado: ele não acessa mais o PDV. Para usar de novo, gere um código.');
    }

    public function config(Request $request): RedirectResponse
    {
        $d = $request->validate(['pin_memoria_min' => ['required', 'integer', 'in:0,1,2,5,10']]);
        $e = Empresa::atual();
        $e->configuracoes = array_merge($e->configuracoes ?? [], ['pdv_pin_memoria_min' => (int) $d['pin_memoria_min']]);
        $e->save();
        Atividades::registrar('pdv_config', 'memória do PIN: '.($d['pin_memoria_min'] ? $d['pin_memoria_min'].' min' : 'a cada ação'));

        return back()->with('sucesso', 'Configuração do PDV salva.');
    }
}
