<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Models\Unidade;
use App\Support\Documento;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Política de privacidade pública (/privacidade): link dos formulários públicos e exigência da Meta para o app do
 * WhatsApp. Identifica o controlador (razão social, CNPJ, endereço) e o canal de privacidade, que vêm de
 * Configurações › Empresa. Sem endereço próprio, usa o da primeira unidade ativa; sem contato de privacidade,
 * o e-mail e o telefone da empresa.
 */
class PrivacidadeController extends Controller
{
    public function __invoke(): Response
    {
        $e = Empresa::withoutGlobalScopes()->where('ativa', true)->orderBy('id')->first();
        $endereco = $e?->config('privacidade_endereco') ?: ($e ? Unidade::withoutGlobalScopes()->where('empresa_id', $e->id)
            ->where('ativa', true)->whereNotNull('endereco')->orderBy('id')->value('endereco') : null);

        return Inertia::render('Privacidade', ['empresa_publica' => [
            'nome' => $e?->nome ?? config('app.name'),
            'razao_social' => $e?->razao_social,
            'cnpj' => $e && Documento::valido($e->documento) ? Documento::formatar($e->documento) : null,
            'endereco' => $endereco,
            'contato_privacidade' => $e?->config('privacidade_contato'),
            'telefone' => $e?->telefone,
            'email' => $e?->email,
        ]]);
    }
}
