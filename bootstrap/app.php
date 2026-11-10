<?php

use App\Http\Middleware\CabecalhosSeguranca;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\Permissao;
use App\Http\Middleware\PermissaoDeclarada;
use App\Http\Middleware\Publica;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            PermissaoDeclarada::class,
            CabecalhosSeguranca::class,
        ]);

        $middleware->alias([
            'permissao' => Permissao::class,
            'publica' => Publica::class,
        ]);

        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // tela aberta por muito tempo (código de segurança vencido): volta com aviso, sem erro feio
        $exceptions->render(function (TokenMismatchException $e, Request $request) {
            if ($request->is('login')) {
                return redirect('/login?expirou=1');
            }

            return back()->with('erro', 'A tela ficou aberta por muito tempo. Confira os dados e tente de novo.');
        });

        // erros nas telas do sistema aparecem dentro do layout, em português
        $exceptions->respond(function (Response $resposta, Throwable $e, Request $request) {
            $status = $resposta->getStatusCode();
            $mostrar = [403, 404, 503];
            if (! config('app.debug')) {
                $mostrar[] = 500;
            }
            if (in_array($status, $mostrar, true) && ! $request->expectsJson()) {
                return Inertia::render('Erro', ['status' => $status])
                    ->toResponse($request)
                    ->setStatusCode($status);
            }

            return $resposta;
        });
    })->create();
