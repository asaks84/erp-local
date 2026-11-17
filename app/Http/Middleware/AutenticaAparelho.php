<?php

namespace App\Http\Middleware;

use App\Servicos\Pdv\Aparelhos;
use App\Support\EmpresaAtual;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API do PDV: o aparelho se identifica pelo token (Authorization: Bearer). Define a empresa da vez;
 * quem fez cada ação vem do PIN, conferido em cada rota que mexe em alguma coisa.
 */
class AutenticaAparelho
{
    public function handle(Request $request, Closure $next): Response
    {
        $a = Aparelhos::doToken($request->bearerToken());
        if (! $a) {
            return response()->json(['erro' => 'Aparelho não pareado ou desativado. Pareie de novo com um código da gestão.', 'parear' => true], 401);
        }
        app(EmpresaAtual::class)->definir($a->empresa_id);
        $request->attributes->set('aparelho', $a);
        // último acesso: no máximo uma gravação por minuto
        if (! $a->ultimo_acesso_em || $a->ultimo_acesso_em->lt(now()->subMinute())) {
            $a->forceFill(['ultimo_acesso_em' => now(), 'versao_app' => $request->header('X-Versao-App') ? mb_substr($request->header('X-Versao-App'), 0, 20) : $a->versao_app])->saveQuietly();
        }

        return $next($request);
    }
}
