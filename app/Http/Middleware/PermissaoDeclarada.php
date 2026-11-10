<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloqueio por padrão: toda rota precisa declarar a permissão que exige
 * (middleware "permissao:...") ou dizer que é pública (middleware "publica").
 * Uma tela nova esquecida sem permissão não abre. Há também um teste automático
 * que confere todas as rotas (tests/Feature/RotasTest.php).
 */
class PermissaoDeclarada
{
    public static function declarou(array $middlewares): bool
    {
        foreach ($middlewares as $m) {
            if (is_string($m) && ($m === 'publica' || Str::startsWith($m, 'permissao:'))) {
                return true;
            }
        }

        return false;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $rota = $request->route();
        if ($rota && ! self::declarou($rota->gatherMiddleware())) {
            Log::error('Rota sem permissão declarada: '.$rota->uri());
            abort(403);
        }

        return $next($request);
    }
}
