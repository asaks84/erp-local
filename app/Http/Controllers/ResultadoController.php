<?php

namespace App\Http\Controllers;

use App\Servicos\Resultado;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Gestão › Resultado e caixa: resultado do mês (por referência ou pelo dinheiro) e fluxo de caixa por dia. */
class ResultadoController extends Controller
{
    public function mes(Request $request): Response
    {
        $f = $request->validate(['mes' => ['nullable', 'date_format:Y-m'], 'regime' => ['nullable', 'in:referencia,caixa']]);
        $mes = $f['mes'] ?? now()->format('Y-m');
        $regime = $f['regime'] ?? 'referencia';

        return Inertia::render('Resultado/Mes', [
            'resultado' => Resultado::mes($mes, $regime),
            'historico' => Resultado::historico($mes, 6, $regime),
            'atual' => $mes === now()->format('Y-m'),
        ]);
    }

    public function fluxo(Request $request): Response
    {
        $f = $request->validate(['de' => ['nullable', 'date_format:Y-m-d'], 'ate' => ['nullable', 'date_format:Y-m-d']]);
        $de = $f['de'] ?? now()->startOfMonth()->toDateString();
        $ate = $f['ate'] ?? now()->addDays(30)->toDateString();
        if ($ate < $de) {
            [$de, $ate] = [$ate, $de];
        }
        if (Carbon::parse($de)->diffInDays(Carbon::parse($ate)) > 186) {
            $ate = Carbon::parse($de)->addDays(186)->toDateString();
        }

        return Inertia::render('Resultado/Fluxo', ['filtros' => ['de' => $de, 'ate' => $ate], 'hoje' => now()->toDateString()] + Resultado::fluxo($de, $ate));
    }
}
