<?php

namespace App\Http\Controllers;

use App\Models\Boleto;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Integracao;
use App\Servicos\Inter\Boletos;
use App\Servicos\Inter\InterCliente;
use App\Servicos\Inter\InterErro;
use App\Support\Atividades;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configurações › Banco Inter: credenciais da integração (guardadas criptografadas), teste de conexão
 * e regras do boleto (multa, juros, desconto e baixa automática).
 */
class InterController extends Controller
{
    private function integracao(): Integracao
    {
        return Integracao::inter() ?? new Integracao(['tipo' => 'inter', 'ambiente' => 'producao', 'ativo' => false]);
    }

    public function index(): Response
    {
        $i = $this->integracao();
        $id = (string) $i->credencial('client_id');

        return Inertia::render('Configuracoes/Inter', [
            'integracao' => [
                'existe' => $i->exists,
                'ativo' => (bool) $i->ativo,
                'ambiente' => $i->ambiente ?? 'producao',
                'conta_corrente' => $i->conta_corrente,
                'client_id' => $id ? '••••'.substr($id, -4) : null,
                'tem_secret' => (bool) $i->credencial('client_secret'),
                'tem_certificado' => (bool) $i->credencial('certificado'),
                'tem_chave' => (bool) $i->credencial('chave'),
                'certificado_validade' => $i->certificado_validade?->format('Y-m-d'),
                'testado_em' => $i->testado_em?->format('Y-m-d H:i'),
                'ultimo_erro' => $i->ultimo_erro,
            ],
            'regras' => Boletos::regras() + ['boleto_prazo_dias' => Boletos::prazo()],
            'boletos' => ['ativos' => Boleto::whereIn('situacao', Boleto::ATIVAS)->count(), 'com_erro' => Boleto::whereNotNull('erro')->whereIn('situacao', ['recebido', 'falha'])->where('criado_em', '>=', now()->subDays(30))->count()],
        ]);
    }

    /** certificado em texto PEM e data de validade (lida do próprio certificado) */
    private function lerCertificado(string $pem): ?string
    {
        if (! str_contains($pem, 'BEGIN CERTIFICATE')) {
            throw ValidationException::withMessages(['certificado' => 'Esse arquivo não é o certificado (.crt) do Inter.']);
        }
        $info = function_exists('openssl_x509_parse') ? @openssl_x509_parse($pem) : false;

        return $info && isset($info['validTo_time_t']) ? Carbon::createFromTimestamp($info['validTo_time_t'])->toDateString() : null;
    }

    public function credenciais(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'ambiente' => ['required', 'in:producao,sandbox'],
            'conta_corrente' => ['nullable', 'string', 'max:20', 'regex:/^[0-9-]*$/'],
            'client_id' => ['nullable', 'string', 'max:100'],
            'client_secret' => ['nullable', 'string', 'max:200'],
            'certificado' => ['nullable', 'file', 'max:20'],
            'chave' => ['nullable', 'file', 'max:20'],
        ], ['conta_corrente.regex' => 'Só números (e traço).']);

        $i = $this->integracao();
        $cred = $i->credenciais ?? [];
        foreach (['client_id', 'client_secret'] as $k) {
            if (filled($d[$k] ?? null)) {
                $cred[$k] = trim($d[$k]);
            }
        }
        if ($request->hasFile('certificado')) {
            $pem = (string) file_get_contents($request->file('certificado')->getRealPath());
            $i->certificado_validade = $this->lerCertificado($pem);
            $cred['certificado'] = $pem;
        }
        if ($request->hasFile('chave')) {
            $pem = (string) file_get_contents($request->file('chave')->getRealPath());
            if (! str_contains($pem, 'PRIVATE KEY')) {
                throw ValidationException::withMessages(['chave' => 'Esse arquivo não é a chave (.key) do Inter.']);
            }
            $cred['chave'] = $pem;
        }
        $i->fill(['ambiente' => $d['ambiente'], 'conta_corrente' => filled($d['conta_corrente'] ?? null) ? preg_replace('/\D/', '', $d['conta_corrente']) : null]);
        $i->credenciais = $cred;
        // credencial nova precisa de teste antes de emitir boletos
        $i->ativo = false;
        $i->save();
        Atividades::registrar('inter_credenciais', 'credenciais do Banco Inter alteradas ('.$d['ambiente'].')');

        return back()->with('sucesso', 'Credenciais salvas. Agora clique em "Testar conexão" para ligar o Inter.');
    }

    public function testar(): RedirectResponse
    {
        $i = Integracao::inter();
        if (! $i) {
            return back()->with('erro', 'Salve as credenciais primeiro.');
        }
        try {
            (new InterCliente($i))->testar();
        } catch (InterErro $e) {
            $i->update(['ativo' => false, 'ultimo_erro' => mb_substr($e->getMessage(), 0, 255), 'testado_em' => now()]);

            return back()->with('erro', 'O teste falhou: '.$e->getMessage());
        } catch (\Throwable $e) {
            $i->update(['ativo' => false, 'ultimo_erro' => mb_substr('Sem conexão com o Inter: '.$e->getMessage(), 0, 255), 'testado_em' => now()]);

            return back()->with('erro', 'Sem conexão com o Inter: '.mb_substr($e->getMessage(), 0, 200));
        }
        $i->update(['ativo' => true, 'ultimo_erro' => null, 'testado_em' => now()]);
        Atividades::registrar('inter_ligado', 'conexão com o Banco Inter testada e ligada');

        return back()->with('sucesso', 'Conexão com o Inter funcionando. Os boletos já podem ser gerados.');
    }

    public function desligar(): RedirectResponse
    {
        Integracao::inter()?->update(['ativo' => false]);
        Atividades::registrar('inter_desligado', 'Banco Inter desligado');

        return back()->with('sucesso', 'Inter desligado: nenhum boleto novo e a consulta automática parou.');
    }

    /** valida as regras (todas ou só os grupos informados) */
    private static function validarRegras(Request $request, array $prefixo = []): array
    {
        return $request->validate([
            'boleto_multa_tipo' => ['required', 'in:nenhuma,percentual,valor'],
            'boleto_multa_valor' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'boleto_juros_tipo' => ['required', 'in:nenhum,percentual_mes,valor_dia'],
            'boleto_juros_valor' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'boleto_desconto_tipo' => ['required', 'in:nenhum,percentual,valor'],
            'boleto_desconto_valor' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'boleto_desconto_dias' => ['nullable', 'integer', 'min:0', 'max:60'],
            'boleto_baixa_dias' => ['required', 'integer', 'min:0', 'max:60'],
        ]);
    }

    public function regras(Request $request): RedirectResponse
    {
        $d = self::validarRegras($request);
        foreach (['multa', 'desconto'] as $k) {
            if ($d["boleto_{$k}_tipo"] === 'percentual' && (float) ($d["boleto_{$k}_valor"] ?? 0) > 100) {
                throw ValidationException::withMessages(["boleto_{$k}_valor" => 'Percentual acima de 100%.']);
            }
        }
        if ($d['boleto_multa_tipo'] === 'percentual' && (float) ($d['boleto_multa_valor'] ?? 0) > 2) {
            // o Código de Defesa do Consumidor limita a multa por atraso a 2%: só avisa
            session()->flash('aviso', 'Atenção: para consumidor (pessoa física), a multa por atraso é limitada a 2% pelo Código de Defesa do Consumidor.');
        }
        $d = array_map(fn ($v) => $v ?? 0, $d);
        // prazo do boleto é da empresa (não entra nas regras específicas do cliente)
        $d['boleto_prazo_dias'] = (int) ($request->validate(['boleto_prazo_dias' => ['nullable', 'integer', 'min:0', 'max:30']])['boleto_prazo_dias'] ?? 0);
        $e = Empresa::atual();
        $e->configuracoes = array_merge($e->configuracoes ?? [], $d);
        $e->save();
        Atividades::registrar('empresa_config_alterada', 'regras do boleto: multa '.$d['boleto_multa_tipo'].' '.$d['boleto_multa_valor'].', juros '.$d['boleto_juros_tipo'].' '.$d['boleto_juros_valor']
            .', desconto '.$d['boleto_desconto_tipo'].' '.$d['boleto_desconto_valor'].', baixa em '.$d['boleto_baixa_dias'].' dia(s), boleto vence '.$d['boleto_prazo_dias'].' dia(s) depois do vencimento do cliente');

        return back()->with('sucesso', 'Regras do boleto salvas. Valem para os próximos boletos.');
    }

    /**
     * Regras particulares de um cliente: cada grupo (multa, juros, desconto, baixa) marcado como
     * particular substitui o da empresa; os outros seguem o padrão.
     */
    public function cliente(Request $request, Cliente $cliente): RedirectResponse
    {
        $particular = (array) $request->input('particular', []);
        $d = self::validarRegras($request);
        foreach (['multa', 'desconto'] as $k) {
            if (! empty($particular[$k]) && $d["boleto_{$k}_tipo"] === 'percentual' && (float) ($d["boleto_{$k}_valor"] ?? 0) > 100) {
                throw ValidationException::withMessages(["boleto_{$k}_valor" => 'Percentual acima de 100%.']);
            }
        }
        $regras = [];
        foreach (Boletos::GRUPOS as $grupo => $chaves) {
            if (! empty($particular[$grupo])) {
                foreach ($chaves as $k) {
                    $regras[$k] = $d[$k] ?? 0;
                }
            }
        }
        $cliente->update(['regras_boleto' => $regras ?: null]);
        Atividades::registrar('cliente_regras_boleto', 'cliente '.$cliente->id.' ('.$cliente->nome.'): '.($regras ? 'regras particulares do boleto '.json_encode($regras) : 'volta ao padrão da empresa'));

        return back()->with('sucesso', $regras ? 'Regras particulares do boleto salvas para '.$cliente->nome.'.' : $cliente->nome.' volta a seguir as regras da empresa.');
    }
}
