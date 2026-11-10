<?php

namespace App\Http\Middleware;

use App\Support\Atividades;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige uma permissão do catálogo (basta uma da lista): ->middleware('permissao:usuarios').
 * "permissao:livre" = qualquer usuário logado e ativo.
 * Acesso negado fica no registro de atividades.
 */
class Permissao
{
    public function handle(Request $request, Closure $next, string ...$chaves): Response
    {
        $usuario = $request->user();
        if (! $usuario) {
            return redirect()->guest('/login');
        }

        foreach ($chaves as $chave) {
            if ($usuario->pode($chave)) {
                return $next($request);
            }
        }

        Atividades::registrar('acesso_negado', $request->method().' /'.$request->path().' (precisa de: '.implode(' ou ', $chaves).')');
        abort(403);
    }
}
