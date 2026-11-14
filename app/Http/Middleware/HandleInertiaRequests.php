<?php

namespace App\Http\Middleware;

use App\Models\Empresa;
use App\Support\EmpresaAtual;
use Illuminate\Http\Request;
use Inertia\Middleware;

/** Dados que toda tela recebe: usuário logado, permissões, empresa e avisos. */
class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $usuario = $request->user();

        return [
            ...parent::share($request),
            'empresa' => fn () => $this->empresa(),
            'auth' => fn () => $usuario ? [
                'usuario' => [
                    'id' => $usuario->id,
                    'nome' => $usuario->nome,
                    'login' => $usuario->login,
                    'nivel' => $usuario->nivel?->nome,
                ],
                'acesso_total' => (bool) $usuario->nivel?->fixo,
                'permissoes' => $usuario->nivel?->fixo ? [] : array_values($usuario->nivel?->permissoes ?? []),
                'inatividade_minutos' => config('erp.inatividade_minutos'),
                'agora' => now()->format('Y-m-d H:i:s'),
            ] : null,
            // cadastros esperando aprovação (número no menu)
            'fila' => fn () => $usuario?->pode('aprovar') ? \App\Models\CadastroPendente::count() : 0,
            'atualizacoes' => fn () => $usuario?->pode('aprovar') ? \App\Models\AtualizacaoCadastral::where('situacao', 'respondida')->count() : 0,
            'flash' => fn () => [
                'sucesso' => $request->session()->get('sucesso'),
                'erro' => $request->session()->get('erro'),
                'aviso' => $request->session()->get('aviso'),
                'imprimir' => $request->session()->get('imprimir'),
            ],
        ];
    }

    private function empresa(): ?array
    {
        $id = app(EmpresaAtual::class)->id();
        // tela de login: mostra a única empresa cadastrada (enquanto o sistema tiver uma só)
        $empresa = $id ? Empresa::find($id) : (Empresa::count() === 1 ? Empresa::first() : null);

        return $empresa ? [
            'nome' => $empresa->nome,
            'logo_url' => $empresa->logo ? asset('storage/'.$empresa->logo) : null,
            // Banco Inter ligado: as telas de cobrança oferecem "gerar boleto"
            'inter' => $id ? \App\Http\Controllers\CobrancaController::interLigado() : false,
        ] : null;
    }
}
