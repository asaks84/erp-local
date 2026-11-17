<?php

namespace App\Http\Controllers;

use App\Models\Nivel;
use App\Models\Unidade;
use App\Models\Usuario;
use App\Support\Atividades;
use App\Support\EmpresaAtual;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UsuarioController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Usuarios/Index', [
            'usuarios' => Usuario::with(['nivel:id,nome', 'unidade:id,nome'])
                ->orderByDesc('ativo')->orderBy('nome')->get()
                ->map(fn (Usuario $u) => [
                    'id' => $u->id,
                    'nome' => $u->nome,
                    'login' => $u->login,
                    'nivel' => $u->nivel?->nome,
                    'unidade' => $u->unidade?->nome,
                    'ativo' => $u->ativo,
                    'ultimo_acesso_em' => $u->ultimo_acesso_em?->format('Y-m-d H:i:s'),
                    'pin' => (bool) $u->pin_hash,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Usuarios/Form', ['usuario' => null] + $this->opcoes());
    }

    public function edit(Usuario $usuario): Response
    {
        return Inertia::render('Usuarios/Form', [
            'usuario' => $usuario->only(['id', 'nome', 'login', 'nivel_id', 'unidade_id', 'operador_caixa', 'ativo']) + ['pin' => (bool) $usuario->pin_hash],
        ] + $this->opcoes());
    }

    public function store(Request $request): RedirectResponse
    {
        $dados = $this->validar($request);
        $usuario = new Usuario(collect($dados)->except(['senha', 'pin'])->all());
        $usuario->definirSenha($dados['senha']);
        $usuario->save();
        Atividades::registrar('usuario_criado', $usuario->login.' ('.$usuario->nivel->nome.')');
        if ($erro = $this->gravarPin($usuario, $dados['pin'] ?? null)) {
            return redirect("/usuarios/{$usuario->id}")->withErrors(['pin' => $erro])->with('erro', 'Usuário criado, mas o PIN não foi aceito: escolha outro.');
        }

        return redirect('/usuarios')->with('sucesso', 'Usuário criado.');
    }

    public function update(Request $request, Usuario $usuario): RedirectResponse
    {
        $dados = $this->validar($request, $usuario);
        $eu = $usuario->is($request->user());

        // ninguém muda o próprio nível nem se desativa (evita ficar trancado para fora)
        if ($eu) {
            $dados['nivel_id'] = $usuario->nivel_id;
            $dados['ativo'] = true;
        }

        // sempre sobra ao menos um usuário ativo com acesso total
        $continuaTotal = $dados['ativo'] && Nivel::find($dados['nivel_id'])?->fixo;
        if ($usuario->ativo && $usuario->nivel->fixo && ! $continuaTotal) {
            $outros = Usuario::where('ativo', true)->where('id', '<>', $usuario->id)
                ->whereHas('nivel', fn ($q) => $q->where('fixo', true))->exists();
            if (! $outros) {
                return back()->withErrors(['nivel_id' => 'Este é o único usuário ativo com acesso total. Dê acesso total a outro usuário antes.']);
            }
        }

        $antes = $usuario->nivel->nome;
        $usuario->fill(collect($dados)->except(['senha', 'pin'])->all());
        if (! empty($dados['senha'])) {
            $usuario->definirSenha($dados['senha']);
        }
        $usuario->save();

        $detalhe = $usuario->login;
        if ($antes !== $usuario->fresh('nivel')->nivel->nome) {
            $detalhe .= ': nível '.$antes.' → '.$usuario->nivel->nome;
        }
        if (! empty($dados['senha'])) {
            $detalhe .= ' (senha trocada)';
        }
        Atividades::registrar('usuario_alterado', $detalhe);
        if ($erro = $this->gravarPin($usuario, $dados['pin'] ?? null)) {
            return back()->withErrors(['pin' => $erro]);
        }

        return redirect('/usuarios')->with('sucesso', 'Usuário salvo.');
    }

    private function validar(Request $request, ?Usuario $usuario = null): array
    {
        $empresa = app(EmpresaAtual::class)->id();
        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:80'],
            'login' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._@-]+$/', Rule::unique('usuarios', 'login')->ignore($usuario?->id)],
            'senha' => [$usuario ? 'nullable' : 'required', 'string', 'min:6', 'max:200', 'confirmed'],
            'nivel_id' => ['required', 'integer', Rule::exists('niveis', 'id')->where('empresa_id', $empresa)],
            'unidade_id' => ['nullable', 'integer', Rule::exists('unidades', 'id')->where('empresa_id', $empresa)->where('ativa', true)],
            'operador_caixa' => ['boolean'],
            'ativo' => ['boolean'],
            'pin' => ['nullable', 'string', 'confirmed'],
        ], [
            'pin.confirmed' => 'Os dois PINs não são iguais.',
            'login.regex' => 'Use só letras, números, ponto, traço, @ ou sublinhado (sem espaços).',
        ]);
        $dados['login'] = trim($dados['login']);
        $dados['operador_caixa'] = (bool) ($dados['operador_caixa'] ?? false);
        $dados['ativo'] = (bool) ($dados['ativo'] ?? true);

        return $dados;
    }

    /** PIN do PDV digitado no cadastro (a pessoa escolhe na hora, junto). Devolve a mensagem de erro, se houver. */
    private function gravarPin(Usuario $usuario, ?string $pin): ?string
    {
        if ($pin === null || trim($pin) === '') {
            return null;
        }
        try {
            \App\Servicos\Pdv\Pins::definir($usuario, trim($pin));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return collect($e->errors())->flatten()->first();
        }

        return null;
    }

    private function opcoes(): array
    {
        return [
            'niveis' => Nivel::orderByDesc('fixo')->orderBy('nome')->get(['id', 'nome']),
            'unidades' => Unidade::where('ativa', true)->orderBy('nome')->get(['id', 'nome']),
        ];
    }

    /** apaga o PIN do PDV (a pessoa cadastra outro no próprio PDV, com login e senha) */
    public function apagarPin(Usuario $usuario): RedirectResponse
    {
        \App\Servicos\Pdv\Pins::apagar($usuario);

        return back()->with('sucesso', 'PIN de '.$usuario->nome.' apagado. Ele cadastra outro no PDV.');
    }
}
