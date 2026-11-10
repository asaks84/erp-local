<?php

namespace App\Http\Controllers;

use App\Models\PagadorVinculado;
use App\Support\Atividades;
use Illuminate\Http\RedirectResponse;

class PagadorController extends Controller
{
    public function destroy(PagadorVinculado $pagador): RedirectResponse
    {
        Atividades::registrar('pagador_desvinculado', $pagador->nome.' (cliente '.$pagador->cliente_id.')');
        $pagador->delete();

        return back()->with('sucesso', 'Pagador desvinculado.');
    }
}
