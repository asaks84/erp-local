<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Concerns\EmpresaScope;
use App\Models\Usuario;
use App\Support\Atividades;
use App\Support\Senha;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function formulario(Request $request): Response
    {
        $aviso = $request->boolean('expirou')
            ? 'A tela ficou aberta por muito tempo ou o sistema fechou por falta de uso. Entre de novo.'
            : null;

        return Inertia::render('Auth/Login', ['aviso' => $aviso]);
    }

    public function entrar(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'login' => ['required', 'string', 'max:40'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        $usuario = Usuario::withoutGlobalScope(EmpresaScope::class)
            ->where('login', trim($dados['login']))
            ->first();

        // mensagem igual para login inexistente, senha errada e usuário inativo
        if (! $usuario || ! $usuario->ativo || ! Senha::confere($dados['password'], $usuario->senha)) {
            if (! $usuario) {
                // gasta o mesmo tempo de uma senha conferida, para não revelar se o login existe
                password_verify($dados['password'], '$2y$12$DLhHz7JKSYmQ.k3auFKrNeUXdet5s2BHF98s7Olm.OKd0eWRbDszi');
            } else {
                Atividades::registrar('login_falhou', 'Senha incorreta ou usuário inativo', $usuario);
            }
            throw ValidationException::withMessages(['login' => 'Login ou senha incorretos.']);
        }

        if (Senha::precisaRegravar($usuario->senha)) {
            $usuario->definirSenha($dados['password']);
        }
        $usuario->ultimo_acesso_em = now();
        $usuario->timestamps = false;
        $usuario->save();
        $usuario->timestamps = true;

        Auth::login($usuario);
        $request->session()->regenerate();
        Atividades::registrar('login', null, $usuario);

        return redirect()->intended('/');
    }

    public function sair(Request $request): RedirectResponse
    {
        $motivo = $request->input('motivo') === 'inatividade' ? 'Fechado por falta de uso' : null;
        Atividades::registrar('logout', $motivo);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect($motivo ? '/login?expirou=1' : '/login');
    }

    /** Mantém a sessão viva enquanto a pessoa usa a tela sem trocar de página. */
    public function ping(): JsonResponse
    {
        return response()->json(['ok' => true]);
    }
}
