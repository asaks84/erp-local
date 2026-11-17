<?php

use App\Http\Controllers\Pdv\PdvController;
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
    });
});
