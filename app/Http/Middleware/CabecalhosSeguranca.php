<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeçalhos de segurança. A CSP (scripts só do próprio site) vale na produção;
 * no computador o Vite (npm run dev) serve os arquivos de outro endereço.
 */
class CabecalhosSeguranca
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->isProduction()) {
            Vite::useCspNonce();
        }

        $resposta = $next($request);

        $resposta->headers->set('X-Content-Type-Options', 'nosniff');
        $resposta->headers->set('X-Frame-Options', 'DENY');
        $resposta->headers->set('Referrer-Policy', 'same-origin');
        $resposta->headers->set('Permissions-Policy', 'geolocation=(), microphone=()');

        if (app()->isProduction()) {
            $nonce = Vite::cspNonce();
            $resposta->headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'nonce-{$nonce}'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data: blob:",
                "font-src 'self'",
                "connect-src 'self' https://viacep.com.br",
                "frame-ancestors 'none'",
                "base-uri 'self'",
                "form-action 'self'",
            ]));
            if ($request->isSecure()) {
                $resposta->headers->set('Strict-Transport-Security', 'max-age=31536000');
            }
        }

        return $resposta;
    }
}
