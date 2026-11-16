<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Models\Integracao;
use App\Servicos\WhatsApp\Modelos;
use App\Servicos\WhatsApp\Regua;
use App\Servicos\WhatsApp\WhatsAppCliente;
use App\Servicos\WhatsApp\WhatsAppErro;
use App\Support\Atividades;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configurações › WhatsApp: credenciais da API oficial (criptografadas), teste de conexão, modelos de
 * mensagem na Meta e a régua de cobrança (dias, horário e envio automático).
 */
class WhatsAppController extends Controller
{
    private function integracao(): Integracao
    {
        return Integracao::whatsapp() ?? new Integracao(['tipo' => 'whatsapp', 'ambiente' => 'producao', 'ativo' => false]);
    }

    public function index(): Response
    {
        $i = $this->integracao();
        $modelos = null;
        $erroModelos = null;
        if ($i->exists && $i->ativo) {
            try {
                $na = (new WhatsAppCliente($i))->modelos();
                $modelos = collect(Modelos::TEXTOS)->map(fn ($texto, $nome) => ['nome' => $nome, 'rotulo' => Modelos::ROTULOS[$nome], 'texto' => $texto, 'situacao' => $na[$nome] ?? null])->values();
            } catch (WhatsAppErro $e) {
                $erroModelos = $e->getMessage();
            }
        }
        $modelos ??= collect(Modelos::TEXTOS)->map(fn ($texto, $nome) => ['nome' => $nome, 'rotulo' => Modelos::ROTULOS[$nome], 'texto' => $texto, 'situacao' => null])->values();

        return Inertia::render('Configuracoes/WhatsApp', [
            'integracao' => [
                'existe' => $i->exists,
                'ativo' => (bool) $i->ativo,
                'phone_number_id' => $i->credencial('phone_number_id'),
                'waba_id' => $i->credencial('waba_id'),
                'tem_token' => (bool) $i->credencial('token'),
                'tem_app_secret' => (bool) $i->credencial('app_secret'),
                'numero' => $i->credencial('numero'),
                'nome' => $i->credencial('nome'),
                'testado_em' => $i->testado_em?->format('Y-m-d H:i'),
                'ultimo_erro' => $i->ultimo_erro,
                'webhook_url' => url('/webhooks/whatsapp'),
                'webhook_token' => $i->credencial('verify_token'),
            ],
            'regua' => ['passos' => Regua::passos(), 'hora' => Regua::hora(), 'automatico' => Regua::automatico()],
            'modelos' => $modelos,
            'erro_modelos' => $erroModelos,
            'privacidade_url' => url('/privacidade'),
        ]);
    }

    public function credenciais(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'phone_number_id' => ['required', 'string', 'regex:/^\d{6,25}$/'],
            'waba_id' => ['required', 'string', 'regex:/^\d{6,25}$/'],
            'token' => ['nullable', 'string', 'min:50', 'max:600'],
            'app_secret' => ['nullable', 'string', 'min:16', 'max:100'],
        ], ['phone_number_id.regex' => 'Só os números do ID.', 'waba_id.regex' => 'Só os números do ID.', 'token.min' => 'Esse token parece incompleto.']);
        $i = $this->integracao();
        $cred = $i->credenciais ?? [];
        if (! filled($d['token'] ?? null) && empty($cred['token'])) {
            throw ValidationException::withMessages(['token' => 'Informe o token.']);
        }
        $cred['phone_number_id'] = $d['phone_number_id'];
        $cred['waba_id'] = $d['waba_id'];
        foreach (['token', 'app_secret'] as $k) {
            if (filled($d[$k] ?? null)) {
                $cred[$k] = trim($d[$k]);
            }
        }
        $cred['verify_token'] ??= Str::random(32);
        $i->credenciais = $cred;
        $i->ativo = false; // credencial nova precisa de teste
        $i->save();
        Atividades::registrar('whatsapp_credenciais', 'credenciais do WhatsApp alteradas');

        return back()->with('sucesso', 'Credenciais salvas. Agora clique em "Testar conexão" para ligar o WhatsApp.');
    }

    public function testar(): RedirectResponse
    {
        $i = Integracao::whatsapp();
        if (! $i) {
            return back()->with('erro', 'Salve as credenciais primeiro.');
        }
        try {
            $n = (new WhatsAppCliente($i))->testar();
        } catch (WhatsAppErro $e) {
            $i->update(['ativo' => false, 'ultimo_erro' => mb_substr($e->getMessage(), 0, 255), 'testado_em' => now()]);

            return back()->with('erro', 'O teste falhou: '.$e->getMessage());
        }
        $i->credenciais = array_merge($i->credenciais ?? [], ['numero' => $n['display_phone_number'] ?? null, 'nome' => $n['verified_name'] ?? null]);
        $i->fill(['ativo' => true, 'ultimo_erro' => null, 'testado_em' => now()])->save();
        Atividades::registrar('whatsapp_ligado', 'conexão com o WhatsApp testada e ligada ('.($n['display_phone_number'] ?? '?').')');

        return back()->with('sucesso', 'Conexão funcionando: '.($n['verified_name'] ?? '').' '.($n['display_phone_number'] ?? '').'.');
    }

    public function desligar(): RedirectResponse
    {
        Integracao::whatsapp()?->update(['ativo' => false]);
        Atividades::registrar('whatsapp_desligado', 'WhatsApp desligado');

        return back()->with('sucesso', 'WhatsApp desligado: os avisos voltam a ser só manuais.');
    }

    /** cria na Meta os modelos que ainda não existem (a aprovação leva de minutos a horas) */
    public function modelos(): RedirectResponse
    {
        $i = Integracao::whatsapp();
        if (! $i || ! $i->ativo) {
            return back()->with('erro', 'Ligue o WhatsApp primeiro (Testar conexão).');
        }
        try {
            $api = new WhatsAppCliente($i);
            $na = $api->modelos();
            $criados = 0;
            foreach (array_keys(Modelos::TEXTOS) as $nome) {
                if (! isset($na[$nome])) {
                    $api->criarModelo(Modelos::definicao($nome, url('/')));
                    $criados++;
                }
            }
        } catch (WhatsAppErro $e) {
            return back()->with('erro', 'A Meta recusou: '.$e->getMessage());
        }
        Atividades::registrar('whatsapp_modelos', $criados.' modelo(s) de mensagem enviado(s) para aprovação da Meta');

        return back()->with('sucesso', $criados ? $criados.' modelo(s) enviado(s) para aprovação da Meta. Costuma levar de minutos a algumas horas.' : 'Os modelos já existem na Meta.');
    }

    public function regua(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'passos' => ['required', 'array', 'min:1', 'max:8'],
            'passos.*.dias' => ['required', 'integer', 'min:-30', 'max:90', 'distinct'],
            'passos.*.ativo' => ['boolean'],
            'hora' => ['required', 'date_format:H:i', 'after_or_equal:08:00', 'before_or_equal:19:00'],
            'automatico' => ['boolean'],
        ], ['passos.*.dias.distinct' => 'Dois avisos no mesmo dia.', 'hora.after_or_equal' => 'Entre 8h e 19h.', 'hora.before_or_equal' => 'Entre 8h e 19h.']);
        $e = Empresa::atual();
        $e->configuracoes = array_merge($e->configuracoes ?? [], [
            'whatsapp_regua' => collect($d['passos'])->map(fn ($p) => ['dias' => (int) $p['dias'], 'ativo' => (bool) ($p['ativo'] ?? false)])->sortBy('dias')->values()->all(),
            'whatsapp_hora' => $d['hora'], 'whatsapp_automatico' => (bool) ($d['automatico'] ?? false),
        ]);
        $e->save();
        Atividades::registrar('empresa_config_alterada', 'régua do WhatsApp: '.collect($d['passos'])->where('ativo', true)->pluck('dias')->implode(', ').' dia(s); '.$d['hora'].'; automático '.(! empty($d['automatico']) ? 'sim' : 'não'));

        return back()->with('sucesso', 'Régua salva.');
    }
}
