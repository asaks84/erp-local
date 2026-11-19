<?php

namespace App\Http\Controllers;

use App\Models\Nivel;
use App\Models\Unidade;
use App\Models\Usuario;
use App\Servicos\Pdv\Pins;
use App\Support\Atividades;
use App\Support\EmpresaAtual;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
                    'acessa_gestao' => $u->acessa_gestao,
                    'codigo_ate' => $u->pin_codigo_ate?->isFuture() ? $u->pin_codigo_ate->format('d/m H:i') : null,
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
            'usuario' => $usuario->only(['id', 'nome', 'login', 'nivel_id', 'unidade_id', 'operador_caixa', 'ativo', 'acessa_gestao']) + ['pin' => (bool) $usuario->pin_hash,
                'tem_senha' => (bool) $usuario->senha, 'codigo_ate' => $usuario->pin_codigo_ate?->isFuture() ? $usuario->pin_codigo_ate->format('d/m/Y H:i') : null],
        ] + $this->opcoes());
    }

    public function store(Request $request): RedirectResponse
    {
        $dados = $this->validar($request);
        $usuario = new Usuario(collect($dados)->except(['senha', 'pin'])->all());
        if ($dados['acessa_gestao']) {
            $usuario->definirSenha($dados['senha']);
        }
        $usuario->save();
        Atividades::registrar('usuario_criado', ($usuario->login ?? $usuario->nome.', só PDV').' ('.$usuario->nivel->nome.')');
        if ($erro = $this->gravarPin($usuario, $dados['pin'] ?? null)) {
            return redirect("/usuarios/{$usuario->id}")->withErrors(['pin' => $erro])->with('erro', 'Usuário criado, mas o PIN não foi aceito: escolha outro.');
        }
        // só do PDV e sem PIN: já sai com o código de cadastro para ele escolher o PIN no balcão
        if (! $usuario->acessa_gestao && ! $usuario->pin_hash) {
            $codigo = Pins::gerarCodigo($usuario);

            return redirect('/usuarios')->with('sucesso', 'Usuário criado.')
                ->with('codigo_pin', ['nome' => $usuario->nome, 'codigo' => $codigo, 'ate' => $usuario->pin_codigo_ate->format('d/m H:i')]);
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
            if (! $dados['acessa_gestao']) {
                return back()->withErrors(['acessa_gestao' => 'Você não pode tirar o seu próprio acesso à gestão.']);
            }
        }
        // ligar o acesso à gestão de quem era só do PDV: precisa de login e senha
        if ($dados['acessa_gestao'] && ! $usuario->senha && empty($dados['senha'])) {
            return back()->withErrors(['senha' => 'Para acessar a gestão, defina uma senha.']);
        }

        // sempre sobra ao menos um usuário ativo com acesso total (e que entra na gestão)
        $continuaTotal = $dados['ativo'] && $dados['acessa_gestao'] && Nivel::find($dados['nivel_id'])?->fixo;
        if ($usuario->ativo && $usuario->acessa_gestao && $usuario->nivel->fixo && ! $continuaTotal) {
            $outros = Usuario::where('ativo', true)->where('acessa_gestao', true)->where('id', '<>', $usuario->id)
                ->whereHas('nivel', fn ($q) => $q->where('fixo', true))->exists();
            if (! $outros) {
                return back()->withErrors(['nivel_id' => 'Este é o único usuário ativo com acesso total. Dê acesso total a outro usuário antes.']);
            }
        }

        $antes = $usuario->nivel->nome;
        $acessavaGestao = $usuario->acessa_gestao;
        $usuario->fill(collect($dados)->except(['senha', 'pin'])->all());
        if (! $dados['acessa_gestao']) {
            // só do PDV: sem senha (não sobra senha antiga que alguém conheça)
            $usuario->forceFill(['senha' => null, 'remember_token' => null]);
        } elseif (! empty($dados['senha'])) {
            $usuario->definirSenha($dados['senha']);
        }
        $usuario->save();

        $detalhe = $usuario->login ?? $usuario->nome;
        if ($acessavaGestao !== $usuario->acessa_gestao) {
            $detalhe .= $usuario->acessa_gestao ? ': passou a acessar a gestão' : ': agora só PDV (sem acesso à gestão)';
        }
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
        // só do PDV (acessa_gestao desligado): sem login e sem senha, só o PIN
        $gestao = $request->boolean('acessa_gestao', true);
        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:80'],
            'acessa_gestao' => ['boolean'],
            'login' => $gestao ? ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._@-]+$/', Rule::unique('usuarios', 'login')->ignore($usuario?->id)] : ['nullable'],
            'senha' => $gestao ? [$usuario ? 'nullable' : 'required', 'string', 'min:6', 'max:200', 'confirmed'] : ['nullable'],
            'nivel_id' => ['required', 'integer', Rule::exists('niveis', 'id')->where('empresa_id', $empresa)],
            'unidade_id' => ['nullable', 'integer', Rule::exists('unidades', 'id')->where('empresa_id', $empresa)->where('ativa', true)],
            'operador_caixa' => ['boolean'],
            'ativo' => ['boolean'],
            'pin' => ['nullable', 'string', 'confirmed'],
        ], [
            'pin.confirmed' => 'Os dois PINs não são iguais.',
            'login.regex' => 'Use só letras, números, ponto, traço, @ ou sublinhado (sem espaços).',
        ]);
        $dados['acessa_gestao'] = $gestao;
        $dados['login'] = $gestao ? trim($dados['login']) : null;
        if (! $gestao) {
            unset($dados['senha']);
        }
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
            Pins::definir($usuario, trim($pin));
        } catch (ValidationException $e) {
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

    /** gera o código de cadastro do PIN (6 dígitos, 24 h): a pessoa escolhe o próprio PIN no PDV, sem login e senha */
    public function codigoPin(Usuario $usuario): RedirectResponse
    {
        if (! $usuario->ativo) {
            return back()->with('erro', 'Usuário inativo.');
        }
        $codigo = Pins::gerarCodigo($usuario);

        return back()->with('codigo_pin', ['nome' => $usuario->nome, 'codigo' => $codigo, 'ate' => $usuario->pin_codigo_ate->format('d/m H:i')]);
    }

    /** apaga o PIN do PDV (a pessoa cadastra outro no próprio PDV, com login e senha ou com um código de cadastro) */
    public function apagarPin(Usuario $usuario): RedirectResponse
    {
        Pins::apagar($usuario);

        return back()->with('sucesso', 'PIN de '.$usuario->nome.' apagado. Cadastra outro no PDV'.($usuario->senha ? '.' : ' com um código de cadastro.'));
    }
}
