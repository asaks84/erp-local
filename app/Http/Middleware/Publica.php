<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Marca uma rota como pública (sem login). Não faz nada além de declarar. */
class Publica
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
