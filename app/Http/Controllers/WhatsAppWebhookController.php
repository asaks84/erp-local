<?php

namespace App\Http\Controllers;

use App\Models\Integracao;
use App\Servicos\WhatsApp\Regua;
use App\Support\EmpresaAtual;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Webhook da Meta (WhatsApp): confirmação do endereço (GET) e status de entrega dos avisos (POST).
 * A integração é achada pelo ID do número; com a chave secreta do app guardada, a assinatura é conferida.
 */
class WhatsAppWebhookController extends Controller
{
    private function integracoes()
    {
        return Integracao::withoutGlobalScopes()->where('tipo', 'whatsapp')->get();
    }

    public function verificar(Request $request): Response
    {
        $token = (string) $request->query('hub_verify_token');
        $ok = $token !== '' && $request->query('hub_mode') === 'subscribe'
            && $this->integracoes()->contains(fn ($i) => hash_equals((string) $i->credencial('verify_token'), $token));

        return $ok ? response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain') : response('', 403);
    }

    public function receber(Request $request, EmpresaAtual $empresaAtual): Response
    {
        $integracoes = $this->integracoes();
        foreach ((array) $request->input('entry', []) as $entrada) {
            foreach ((array) ($entrada['changes'] ?? []) as $mudanca) {
                $v = $mudanca['value'] ?? [];
                $i = $integracoes->first(fn ($x) => $x->credencial('phone_number_id') === (string) ($v['metadata']['phone_number_id'] ?? ''));
                if (! $i) {
                    continue;
                }
                $segredo = $i->credencial('app_secret');
                if ($segredo && ! hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $segredo), (string) $request->header('X-Hub-Signature-256'))) {
                    Log::warning('whatsapp webhook: assinatura não confere');

                    return response('', 403);
                }
                $empresaAtual->como($i->empresa_id, function () use ($v) {
                    foreach ((array) ($v['statuses'] ?? []) as $s) {
                        if (! empty($s['id']) && ! empty($s['status'])) {
                            Regua::atualizarStatus((string) $s['id'], (string) $s['status'], $s['errors'][0]['title'] ?? null);
                        }
                    }
                });
            }
        }

        return response('', 200);
    }
}
