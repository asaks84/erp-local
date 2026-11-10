<?php

namespace App\Http\Controllers;

use App\Models\CadastroPendente;
use App\Models\Empresa;
use App\Models\TipoVeiculo;
use App\Support\Documento;
use App\Support\EmpresaAtual;
use App\Support\Placa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Formulário público de cadastro de mensalista (QR Code no balcão): /cadastro/<código da empresa>.
 * O cadastro entra na fila de espera; tipo, período e valor são definidos na aprovação.
 */
class CadastroPublicoController extends Controller
{
    private function empresa(string $codigo): Empresa
    {
        $empresa = Empresa::where('codigo_publico', $codigo)->where('ativa', true)->firstOrFail();
        app(EmpresaAtual::class)->definir($empresa->id);

        return $empresa;
    }

    /** /cadastro sem código: leva ao da única empresa (enquanto houver uma só) */
    public function inicio(): RedirectResponse
    {
        $empresas = Empresa::where('ativa', true)->whereNotNull('codigo_publico')->limit(2)->get();
        abort_unless($empresas->count() === 1, 404);

        return redirect('/cadastro/'.$empresas->first()->codigo_publico);
    }

    public function formulario(string $codigo): Response
    {
        $empresa = $this->empresa($codigo);

        return Inertia::render('Cadastro/Publico', [
            'codigo' => $codigo,
            'empresa_publica' => ['nome' => $empresa->nome],
            'tipos' => TipoVeiculo::where('ativo', true)->orderBy('ordem')->orderBy('nome')->get(['id', 'nome', 'detalhe']),
        ]);
    }

    public function enviar(Request $request, string $codigo): RedirectResponse
    {
        $empresa = $this->empresa($codigo);
        // campo escondido: robôs preenchem, pessoas não veem
        if (filled($request->input('site'))) {
            return redirect("/cadastro/{$codigo}/enviado");
        }
        $request->merge([
            'documento' => Documento::limpar($request->input('documento')),
            'placa' => Placa::normalizar($request->input('placa')),
            'cep' => preg_replace('/\D/', '', (string) $request->input('cep')) ?: null,
            'celular' => preg_replace('/\D/', '', (string) $request->input('celular')) ?: null,
            'telefone' => preg_replace('/\D/', '', (string) $request->input('telefone')) ?: null,
            'uf' => strtoupper((string) $request->input('uf')) ?: null,
        ]);
        $d = $request->validate([
            'nome' => ['required', 'string', 'max:100'],
            'documento' => ['required', 'string', fn ($a, $v, $f) => Documento::valido($v) ? null : $f('CPF ou CNPJ inválido. Confira os números.')],
            'celular' => ['required', 'string', 'min:10', 'max:11'],
            'telefone' => ['nullable', 'string', 'min:10', 'max:11'],
            'email' => ['nullable', 'email', 'max:120'],
            'cep' => ['required', 'digits:8'],
            'logradouro' => ['required', 'string', 'max:100'],
            'numero' => ['required', 'string', 'max:10'],
            'complemento' => ['nullable', 'string', 'max:80'],
            'bairro' => ['required', 'string', 'max:60'],
            'cidade' => ['required', 'string', 'max:60'],
            'uf' => ['required', 'alpha', 'size:2'],
            'placa' => ['required', 'string', 'min:5', 'max:10'],
            'marca' => ['nullable', 'string', 'max:30'],
            'modelo' => ['required', 'string', 'max:40'],
            'cor' => ['nullable', 'string', 'max:20'],
            'tipo_veiculo_id' => ['nullable', 'integer', 'exists:tipos_veiculo,id,empresa_id,'.$empresa->id],
            'aceite' => ['accepted'],
        ], ['aceite.accepted' => 'É preciso autorizar o uso dos dados para enviar o cadastro.']);

        unset($d['aceite']);
        foreach (['nome', 'logradouro', 'complemento', 'bairro', 'cidade', 'marca', 'modelo', 'cor'] as $c) {
            $d[$c] = isset($d[$c]) ? mb_strtoupper(trim($d[$c])) : null;
        }
        CadastroPendente::create($d + ['recebido_em' => now(), 'consentimento_em' => now(), 'ip' => $request->ip()]);

        return redirect("/cadastro/{$codigo}/enviado");
    }

    public function enviado(string $codigo): Response
    {
        $empresa = $this->empresa($codigo);

        return Inertia::render('Cadastro/Enviado', ['empresa_publica' => ['nome' => $empresa->nome]]);
    }
}
