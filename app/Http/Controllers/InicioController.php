<?php

namespace App\Http\Controllers;

use App\Models\Nivel;
use App\Models\Unidade;
use App\Models\Usuario;
use Inertia\Inertia;
use Inertia\Response;

class InicioController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Inicio', [
            'resumo' => [
                'unidades' => Unidade::where('ativa', true)->count(),
                'usuarios' => Usuario::where('ativo', true)->count(),
                'niveis' => Nivel::count(),
            ],
        ]);
    }
}
