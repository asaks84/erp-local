<?php

namespace App\Http\Controllers;

use App\Models\Anotacao;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Anotações do Início: cada usuário anota, marca como feita e apaga as próprias. */
class AnotacaoController extends Controller
{
    private function dados(Request $request): array
    {
        return $request->validate([
            'texto' => ['required', 'string', 'max:500'],
            'para' => ['nullable', 'date_format:Y-m-d'],
        ], ['texto.required' => 'Escreva a anotação.']);
    }

    private function minha(Anotacao $anotacao): Anotacao
    {
        abort_unless($anotacao->usuario_id === Auth::id(), 404);

        return $anotacao;
    }

    public function store(Request $request): RedirectResponse
    {
        Anotacao::create($this->dados($request) + ['usuario_id' => Auth::id()]);

        return back();
    }

    public function update(Request $request, Anotacao $anotacao): RedirectResponse
    {
        $this->minha($anotacao)->update($this->dados($request));

        return back();
    }

    public function alternar(Anotacao $anotacao): RedirectResponse
    {
        $a = $this->minha($anotacao);
        $a->update(['feita_em' => $a->feita_em ? null : now()]);

        return back();
    }

    public function destroy(Anotacao $anotacao): RedirectResponse
    {
        $this->minha($anotacao)->delete();

        return back();
    }
}
