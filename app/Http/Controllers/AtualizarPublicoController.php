<?php

namespace App\Http\Controllers;

use App\Models\AtualizacaoCadastral;
use App\Models\Empresa;
use App\Models\Veiculo;
use App\Servicos\Atualizacoes;
use App\Support\Documento;
use App\Support\EmpresaAtual;
use App\Support\Placa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Formulário público de atualização cadastral (/atualizar/<código>): o cliente confirma a placa de
 * um veículo dele para ver e corrigir os dados. O que ele envia vai para aprovação.
 */
class AtualizarPublicoController extends Controller
{
    private function atualizacao(string $token): AtualizacaoCadastral
    {
        $a = AtualizacaoCadastral::withoutGlobalScopes()->where('token', $token)->firstOrFail();
        app(EmpresaAtual::class)->definir($a->empresa_id);

        return $a;
    }

    private function chave(AtualizacaoCadastral $a): string
    {
        return 'atualizacao_ok_'.$a->id;
    }

    public function mostrar(Request $request, string $token): Response
    {
        $a = $this->atualizacao($token);
        $empresa = Empresa::find($a->empresa_id);
        $estado = match (true) {
            $a->situacao === 'respondida', $a->situacao === 'aprovada' => 'recebida',
            $a->situacao !== 'aberta', $a->expirada() => 'fechada',
            ! $request->session()->get($this->chave($a)) => 'placa',
            default => 'formulario',
        };
        $props = ['token' => $token, 'estado' => $estado, 'empresa_publica' => ['nome' => $empresa->nome, 'telefone' => $empresa->telefone]];
        if ($estado === 'formulario') {
            $c = $a->cliente;
            $props['dados'] = collect(Atualizacoes::CAMPOS)->mapWithKeys(fn ($k) => [$k => $k === 'documento' ? '' : (string) ($c->{$k} ?? '')])->all();
            // o CPF não é mostrado inteiro: o cliente digita para confirmar (pega os inventados)
            $doc = (string) Documento::limpar($c->documento);
            $props['documento_dica'] = strlen($doc) === 11 ? '***.'.substr($doc, 3, 3).'.***-'.substr($doc, -2) : null;
            $props['veiculos'] = Veiculo::where('cliente_id', $c->id)->where('ativo', true)->get()->map(fn ($v) => [
                'placa' => $v->placa ? Placa::formatar($v->placa) : $v->identificacao, 'descricao' => trim(implode(' ', array_filter([$v->marca, $v->modelo, $v->cor]))),
            ]);
        }

        return Inertia::render('Atualizacao/Publico', $props);
    }

    public function placa(Request $request, string $token): RedirectResponse
    {
        $a = $this->atualizacao($token);
        abort_unless($a->situacao === 'aberta' && ! $a->expirada(), 410);
        Atualizacoes::conferirPlaca($a, $request->input('placa'));
        $request->session()->put($this->chave($a), true);

        return redirect('/atualizar/'.$token);
    }

    public function enviar(Request $request, string $token): RedirectResponse
    {
        $a = $this->atualizacao($token);
        abort_unless($request->session()->get($this->chave($a)), 403);
        $request->merge([
            'documento' => Documento::limpar($request->input('documento')),
            'cep' => preg_replace('/\D/', '', (string) $request->input('cep')) ?: null,
            'celular' => preg_replace('/\D/', '', (string) $request->input('celular')) ?: null,
            'telefone' => preg_replace('/\D/', '', (string) $request->input('telefone')) ?: null,
            'uf' => strtoupper((string) $request->input('uf')) ?: null,
        ]);
        $d = $request->validate([
            'nome' => ['required', 'string', 'max:100'],
            'documento' => ['required', 'string', fn ($at, $v, $f) => Documento::valido($v) ? null : $f('CPF ou CNPJ inválido. Confira os números.')],
            'celular' => ['required', 'string', fn ($at, $v, $f) => Atualizacoes::problemaCelular($v) ? $f('Celular inválido: '.Atualizacoes::problemaCelular($v).'. Use DDD + 9 + número.') : null],
            'telefone' => ['nullable', 'string', 'min:10', 'max:11'],
            'email' => ['nullable', 'email', 'max:120'],
            'cep' => ['required', 'digits:8'],
            'logradouro' => ['required', 'string', 'max:100'],
            'numero' => ['required', 'string', 'max:10'],
            'complemento' => ['nullable', 'string', 'max:80'],
            'bairro' => ['required', 'string', 'max:60'],
            'cidade' => ['required', 'string', 'max:60'],
            'uf' => ['required', 'alpha', 'size:2'],
            'aceite' => ['accepted'],
        ], ['aceite.accepted' => 'É preciso autorizar o uso dos dados para enviar.']);
        unset($d['aceite']);
        foreach (['nome', 'logradouro', 'complemento', 'bairro', 'cidade'] as $c) {
            $d[$c] = isset($d[$c]) ? mb_strtoupper(trim($d[$c])) : null;
        }
        $d['email'] = isset($d['email']) ? mb_strtolower(trim($d['email'])) : null;
        Atualizacoes::responder($a, $d, $request->ip());
        $request->session()->forget($this->chave($a));

        return redirect('/atualizar/'.$token);
    }
}
