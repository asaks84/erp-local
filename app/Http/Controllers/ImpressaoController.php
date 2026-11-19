<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Models\Unidade;
use App\Servicos\Pdv\ImpressaoModelos;
use App\Support\Atividades;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Configurações › Impressão do PDV (8.8): editor visual dos papéis (ticket, recibos, fechamento) e o logo. */
class ImpressaoController extends Controller
{
    public function index(): Response
    {
        $e = Empresa::atual();
        $u = Unidade::orderBy('id')->first();

        return Inertia::render('Configuracoes/Impressao', [
            'config' => ImpressaoModelos::config(),
            'empresa' => ['nome' => $e->nome, 'documento' => $e->documento, 'telefone' => $u?->telefone ?: $e->telefone, 'endereco' => $u?->endereco],
        ]);
    }

    public function salvar(Request $request): RedirectResponse
    {
        $d = $request->validate(['modelos' => ['required', 'array']]);
        ImpressaoModelos::salvar($d['modelos']);
        Atividades::registrar('pdv_config', 'modelos de impressão alterados: '.implode(', ', array_keys($d['modelos'])));

        return back()->with('sucesso', 'Impressão salva. O PDV usa o novo modelo na próxima vez que voltar ao Início.');
    }

    public function logo(Request $request): RedirectResponse
    {
        $request->validate(['logo' => ['required', 'file', 'image', 'max:2048']], ['logo.image' => 'Envie uma imagem (PNG ou JPG).', 'logo.max' => 'Imagem de até 2 MB.']);
        ImpressaoModelos::salvarLogo($request->file('logo'));
        Atividades::registrar('pdv_config', 'logo da impressão trocado');

        return back()->with('sucesso', 'Logo salvo, já em preto e branco para a impressora.');
    }

    public function tirarLogo(): RedirectResponse
    {
        ImpressaoModelos::tirarLogo();

        return back()->with('sucesso', 'Logo tirado da impressão.');
    }
}
