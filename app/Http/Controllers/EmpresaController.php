<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Models\Unidade;
use App\Support\Atividades;
use App\Support\EmpresaAtual;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmpresaController extends Controller
{
    private function empresa(): Empresa
    {
        return Empresa::findOrFail(app(EmpresaAtual::class)->id());
    }

    public function edit(): Response
    {
        $empresa = $this->empresa();

        return Inertia::render('Empresa/Edit', [
            'empresa' => $empresa->only(['nome', 'razao_social', 'documento', 'telefone', 'email']),
            'config' => [
                'dia_vencimento_padrao' => $empresa->config('dia_vencimento_padrao'),
                'periodos' => $empresa->config('periodos'),
                'pausa_taxa_tipo' => $empresa->config('pausa_taxa_tipo'),
                'pausa_taxa_valor' => (float) $empresa->config('pausa_taxa_valor'),
            ],
            'cobranca_instrucoes' => $empresa->config('cobranca_instrucoes'),
            'link_cadastro' => $empresa->codigo_publico ? url('/cadastro/'.$empresa->codigo_publico) : null,
            'unidades' => Unidade::orderBy('nome')->get(['id', 'nome', 'endereco', 'ativa']),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        // guarda só os números; a tela mostra com a máscara
        $request->merge([
            'documento' => \App\Support\Documento::limpar($request->input('documento')),
            'telefone' => preg_replace('/\D/', '', (string) $request->input('telefone')) ?: null,
        ]);
        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:80'],
            'razao_social' => ['nullable', 'string', 'max:120'],
            'documento' => ['nullable', 'digits:14', fn ($a, $v, $f) => \App\Support\Documento::valido($v) ? null : $f('CNPJ inválido. Confira os números.')],
            'telefone' => ['nullable', 'string', 'min:10', 'max:11'],
            'email' => ['nullable', 'email', 'max:120'],
        ], ['documento.digits' => 'O CNPJ tem 14 números.', 'telefone.min' => 'Telefone com DDD: 10 ou 11 números.']);
        $empresa = $this->empresa();
        $empresa->update($dados);
        Atividades::registrar('empresa_alterada', $empresa->nome);

        return back()->with('sucesso', 'Dados da empresa salvos.');
    }

    /** padrões de mensalidade: dia de vencimento, períodos e taxa de pausa */
    public function config(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'dia_vencimento_padrao' => ['required', 'integer', 'between:1,31'],
            'periodos' => ['required', 'array', 'min:1', 'max:10'],
            'periodos.*' => ['required', 'string', 'max:30', 'distinct'],
            'pausa_taxa_tipo' => ['required', 'in:valor,pct'],
            'pausa_taxa_valor' => ['required', 'numeric', 'min:0', 'max:99999'],
        ], ['periodos.*.distinct' => 'Há períodos repetidos.']);
        $empresa = $this->empresa();
        $d['periodos'] = array_values(array_map('trim', $d['periodos']));
        $empresa->configuracoes = array_merge($empresa->configuracoes ?? [], $d);
        $empresa->save();
        Atividades::registrar('empresa_config_alterada', 'vencimento dia '.$d['dia_vencimento_padrao'].'; períodos: '.implode(', ', $d['periodos']));

        return back()->with('sucesso', 'Padrões de mensalidade salvos.');
    }

    /** texto impresso no pé da cobrança (chave Pix, conta, recado) */
    public function cobranca(Request $request): RedirectResponse
    {
        $d = $request->validate(['cobranca_instrucoes' => ['nullable', 'string', 'max:500']]);
        $empresa = $this->empresa();
        $empresa->configuracoes = array_merge($empresa->configuracoes ?? [], ['cobranca_instrucoes' => trim((string) ($d['cobranca_instrucoes'] ?? ''))]);
        $empresa->save();
        Atividades::registrar('empresa_config_alterada', 'instruções de pagamento da cobrança');

        return back()->with('sucesso', 'Instruções da cobrança salvas.');
    }

    /** cria (ou troca) o código do link público de cadastro; trocar invalida o QR Code antigo */
    public function gerarLink(): RedirectResponse
    {
        $empresa = $this->empresa();
        do {
            $codigo = strtolower(\Illuminate\Support\Str::random(8));
        } while (Empresa::where('codigo_publico', $codigo)->exists());
        $empresa->update(['codigo_publico' => $codigo]);
        Atividades::registrar('link_cadastro_gerado', $codigo);

        return back()->with('sucesso', 'Link de cadastro gerado. Se havia um QR Code impresso com o link antigo, ele deixou de funcionar.');
    }
}
