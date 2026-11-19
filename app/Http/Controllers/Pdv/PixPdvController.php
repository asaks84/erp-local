<?php

namespace App\Http\Controllers\Pdv;

use App\Http\Controllers\Controller;
use App\Models\PixCobranca;
use App\Servicos\Pdv\PixPdv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** API do PDV, Pix imediato (etapa 8.5): gera o QR, consulta até pagar e cancela quando o operador desiste. */
class PixPdvController extends Controller
{
    public function criar(Request $r): JsonResponse
    {
        $d = $r->validate(['valor' => ['required', 'numeric', 'min:0.01', 'max:100000'], 'descricao' => ['nullable', 'string', 'max:140']]);

        return response()->json(PixPdv::linha(PixPdv::criar((float) $d['valor'], $d['descricao'] ?? null)));
    }

    public function ver(int $id): JsonResponse
    {
        return response()->json(PixPdv::linha(PixPdv::atualizar(PixCobranca::findOrFail($id))));
    }

    public function cancelar(int $id): JsonResponse
    {
        return response()->json(PixPdv::linha(PixPdv::cancelar(PixCobranca::findOrFail($id))));
    }
}
