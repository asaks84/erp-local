<?php

use App\Http\Controllers\AtividadeController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\EmpresaController;
use App\Http\Controllers\InicioController;
use App\Http\Controllers\NivelController;
use App\Http\Controllers\UnidadeController;
use App\Http\Controllers\UsuarioController;
use Illuminate\Support\Facades\Route;

// Toda rota declara a permissão que exige ("permissao:<chave>", ou "permissao:livre" para
// qualquer usuário logado) ou que é pública ("publica"). Rota sem isso é bloqueada.

Route::middleware(['publica', 'guest'])->group(function () {
    Route::get('/login', [LoginController::class, 'formulario'])->name('login');
    Route::post('/login', [LoginController::class, 'entrar'])->middleware('throttle:login');
});

Route::middleware('auth')->group(function () {
    Route::middleware('permissao:livre')->group(function () {
        Route::get('/', InicioController::class);
        Route::post('/sair', [LoginController::class, 'sair']);
        Route::get('/sessao/ping', [LoginController::class, 'ping']);
    });

    Route::middleware('permissao:configuracoes')->group(function () {
        Route::get('/empresa', [EmpresaController::class, 'edit']);
        Route::put('/empresa', [EmpresaController::class, 'update']);

        Route::get('/unidades/nova', [UnidadeController::class, 'create']);
        Route::post('/unidades', [UnidadeController::class, 'store']);
        Route::get('/unidades/{unidade}', [UnidadeController::class, 'edit']);
        Route::put('/unidades/{unidade}', [UnidadeController::class, 'update']);

        Route::get('/niveis', [NivelController::class, 'index']);
        Route::get('/niveis/novo', [NivelController::class, 'create']);
        Route::post('/niveis', [NivelController::class, 'store']);
        Route::get('/niveis/{nivel}', [NivelController::class, 'edit']);
        Route::put('/niveis/{nivel}', [NivelController::class, 'update']);
        Route::delete('/niveis/{nivel}', [NivelController::class, 'destroy']);
    });

    Route::middleware('permissao:usuarios')->group(function () {
        Route::get('/usuarios', [UsuarioController::class, 'index']);
        Route::get('/usuarios/novo', [UsuarioController::class, 'create']);
        Route::post('/usuarios', [UsuarioController::class, 'store']);
        Route::get('/usuarios/{usuario}', [UsuarioController::class, 'edit']);
        Route::put('/usuarios/{usuario}', [UsuarioController::class, 'update']);

        Route::get('/atividades', AtividadeController::class);
    });
});
