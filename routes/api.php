<?php

use App\Http\Controllers\Pdv\CaixaPdvController;
use App\Http\Controllers\Pdv\EstadiaPdvController;
use App\Http\Controllers\Pdv\LavagemPdvController;
use App\Http\Controllers\Pdv\PdvController;
use App\Http\Controllers\Pdv\PixPdvController;
use Illuminate\Support\Facades\Route;

/*
 * API do PDV (docs/15-plano-pdv.md). Sem sessão nem cookie: o aparelho se identifica pelo token
 * (middleware "aparelho") e cada ação pede o PIN de quem está atendendo.
 */
Route::prefix('pdv')->middleware('publica')->group(function () {
    Route::post('/parear', [PdvController::class, 'parear'])->middleware('throttle:10,1,pdv-parear');

    Route::middleware(['aparelho', 'throttle:120,1,pdv'])->group(function () {
        Route::get('/eu', [PdvController::class, 'eu']);
        Route::post('/pin/conferir', [PdvController::class, 'conferirPin'])->middleware('throttle:30,1,pdv-pin');
        Route::post('/pin/cadastrar', [PdvController::class, 'cadastrarPin'])->middleware('throttle:20,1,pdv-cadastro');

        // estadias (8.2)
        Route::get('/estadias/opcoes', [EstadiaPdvController::class, 'opcoes']);
        Route::get('/estadias/patio', [EstadiaPdvController::class, 'patio']);
        Route::get('/estadias/placa/{placa}', [EstadiaPdvController::class, 'placa']);
        Route::get('/estadias/buscar', [EstadiaPdvController::class, 'buscar']);
        Route::get('/estadias/{id}/calculo', [EstadiaPdvController::class, 'calculo'])->whereNumber('id');
        Route::get('/clientes', [EstadiaPdvController::class, 'clientes']);
        // caixa (8.3)
        Route::get('/caixa', [CaixaPdvController::class, 'estado']);
        Route::get('/caixa/opcoes', [CaixaPdvController::class, 'opcoes']);
        Route::get('/clientes/{id}/contas', [CaixaPdvController::class, 'contas'])->whereNumber('id');
        // lavagens (8.4)
        Route::get('/lavagens/opcoes', [LavagemPdvController::class, 'opcoes']);
        Route::get('/lavagens/andamento', [LavagemPdvController::class, 'andamento']);
        Route::get('/lavagens/placa/{placa}', [LavagemPdvController::class, 'placa']);
        Route::get('/lavagens/buscar', [LavagemPdvController::class, 'buscar']);
        Route::get('/lavagens/{id}/calculo', [LavagemPdvController::class, 'calculo'])->whereNumber('id');
        // Pix com QR (8.5): a tela consulta a cada 3 segundos até pagar
        Route::get('/pix/{id}', [PixPdvController::class, 'ver'])->whereNumber('id');
        Route::middleware('throttle:60,1,pdv-acao')->group(function () {
            Route::post('/pix', [PixPdvController::class, 'criar']);
            Route::post('/pix/{id}/cancelar', [PixPdvController::class, 'cancelar'])->whereNumber('id');
            Route::post('/lavagens', [LavagemPdvController::class, 'vender']);
            Route::post('/lavagens/{id}/etapa', [LavagemPdvController::class, 'etapa'])->whereNumber('id');
            Route::post('/lavagens/{id}/entregar', [LavagemPdvController::class, 'entregar'])->whereNumber('id');
            Route::post('/caixa/resumo', [CaixaPdvController::class, 'resumo']);
            Route::post('/caixa/movimentos', [CaixaPdvController::class, 'movimentar']);
            Route::post('/caixa/fechar', [CaixaPdvController::class, 'fechar']);
            Route::post('/clientes/{id}/receber', [CaixaPdvController::class, 'receber'])->whereNumber('id');
            Route::post('/estadias', [EstadiaPdvController::class, 'entrar']);
            Route::post('/estadias/{id}/saida', [EstadiaPdvController::class, 'sair'])->whereNumber('id');
            Route::post('/estadias/{id}/cancelar', [EstadiaPdvController::class, 'cancelar'])->whereNumber('id');
        });
    });
});
