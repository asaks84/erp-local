<?php

namespace App\Http\Controllers\Pdv;

use App\Http\Controllers\Controller;
use App\Servicos\Pdv\SemInternet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** API do PDV, modo sem internet (8.7): a cópia que o celular guarda e a subida da fila. */
class SemInternetController extends Controller
{
    public function pacote(Request $r): JsonResponse
    {
        // junto vai o mesmo /eu (dados do aparelho, cabeçalho do ticket, impressão): o app abre sem internet
        return response()->json(SemInternet::pacote(PdvController::aparelho($r)) + ['eu' => app(PdvController::class)->eu($r)->getData(true)]);
    }

    public function sincronizar(Request $r): JsonResponse
    {
        $d = $r->validate([
            'acoes' => ['required', 'array', 'max:100'],
            'acoes.*.uuid' => ['required', 'uuid'],
            'acoes.*.tipo' => ['required', 'string', 'max:30'],
            'acoes.*.feita_em' => ['required', 'string', 'max:40'],
            'acoes.*.usuario_id' => ['nullable', 'integer'],     // só informativo: quem fez sai do PIN trancado (prova)
            'acoes.*.prova' => ['nullable', 'string', 'max:1000'],
            'acoes.*.dados' => ['nullable', 'array'],
        ]);

        return response()->json(['resultados' => SemInternet::sincronizar(PdvController::aparelho($r), $d['acoes'])]);
    }
}
