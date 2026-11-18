<?php

namespace App\Servicos\WhatsApp;

use App\Models\Integracao;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * API oficial do WhatsApp (Cloud API da Meta). Credenciais em integracoes (tipo whatsapp), criptografadas:
 * token (usuário do sistema), phone_number_id (número que envia) e waba_id (conta do WhatsApp Business).
 */
class WhatsAppCliente
{
    public const BASE = 'https://graph.facebook.com/v25.0';

    public function __construct(private Integracao $integracao)
    {
        if (! self::completa($integracao)) {
            throw new WhatsAppErro('Faltam as credenciais do WhatsApp (token, ID do número e ID da conta).');
        }
    }

    public static function completa(?Integracao $i): bool
    {
        return (bool) ($i && $i->credencial('token') && $i->credencial('phone_number_id') && $i->credencial('waba_id'));
    }

    /** mensagem de erro legível a partir da resposta da Meta */
    public static function mensagem(Response $r): string
    {
        $e = $r->json('error') ?? [];
        $msg = $e['error_user_msg'] ?? $e['error_data']['details'] ?? $e['message'] ?? ('HTTP '.$r->status());
        $cod = $e['code'] ?? null;
        $dica = match (true) {
            $cod === 190 => ' O token venceu ou é inválido: gere outro (de usuário do sistema, sem expiração).',
            $cod === 131030 => ' No número de teste da Meta, o destinatário precisa estar na lista de números permitidos.',
            $cod === 132001 => ' O modelo de mensagem ainda não existe ou não foi aprovado nesse idioma.',
            $cod === 131026 => ' O número não tem WhatsApp ou não pode receber a mensagem.',
            in_array($cod, [10, 200], true) => ' O token não tem as permissões do WhatsApp (whatsapp_business_messaging e whatsapp_business_management).',
            default => '',
        };

        return mb_substr(trim((string) $msg), 0, 180).$dica;
    }

    private function chamar(string $metodo, string $caminho, array $dados = []): Response
    {
        try {
            $req = Http::withToken($this->integracao->credencial('token'))->acceptJson()->timeout(20)->connectTimeout(8);
            $r = match ($metodo) {
                'GET' => $req->get(self::BASE.$caminho, $dados),
                'DELETE' => $req->delete(self::BASE.$caminho.'?'.http_build_query($dados)),
                default => $req->post(self::BASE.$caminho, $dados),
            };
        } catch (ConnectionException) {
            throw new WhatsAppErro('Sem conexão com a Meta agora. Tente de novo daqui a pouco.');
        }
        if (! $r->successful()) {
            throw new WhatsAppErro(self::mensagem($r));
        }

        return $r;
    }

    /** dados do número que envia (teste de conexão) */
    public function testar(): array
    {
        return $this->chamar('GET', '/'.$this->integracao->credencial('phone_number_id'), ['fields' => 'display_phone_number,verified_name,quality_rating'])->json();
    }

    /** modelos da conta no idioma pt_BR: [['name', 'status', 'category', 'rejected_reason', 'id'], ...] */
    public function modelosDetalhes(): array
    {
        $r = $this->chamar('GET', '/'.$this->integracao->credencial('waba_id').'/message_templates', ['fields' => 'name,status,language,category,rejected_reason,id', 'limit' => 250])->json('data') ?? [];

        return collect($r)->where('language', Modelos::IDIOMA)->values()->all();
    }

    /** modelos da conta: [nome => situação (APPROVED, PENDING, REJECTED...)] no idioma pt_BR */
    public function modelos(): array
    {
        return collect($this->modelosDetalhes())->mapWithKeys(fn ($m) => [$m['name'] => $m['status']])->all();
    }

    /** cria o modelo na Meta (vai para análise). Devolve ['id', 'status', 'category']. */
    public function criarModelo(array $modelo): array
    {
        return $this->chamar('POST', '/'.$this->integracao->credencial('waba_id').'/message_templates', $modelo)->json() ?? [];
    }

    /**
     * Exclui o modelo na Meta (o nome fica bloqueado por 30 dias). Se a Meta disser que não achou, confere a
     * lista: já apagado lá conta como excluído. Com o id, apaga só aquele idioma.
     */
    public function excluirModelo(string $nome, ?string $metaId = null): void
    {
        try {
            $this->chamar('DELETE', '/'.$this->integracao->credencial('waba_id').'/message_templates', array_filter(['name' => $nome, 'hsm_id' => $metaId]));
        } catch (WhatsAppErro $e) {
            if (collect($this->modelosDetalhes())->contains('name', $nome)) {
                throw $e;
            }
        }
    }

    /**
     * Envia um modelo aprovado. $corpo: valores de {{1}}, {{2}}...; $botaoUrl: o trecho variável do endereço
     * do botão; $cabecalho: valor da informação do cabeçalho, se houver. Devolve o id da mensagem na Meta.
     */
    public function enviarModelo(string $celular, string $modelo, array $corpo, ?string $botaoUrl = null, ?string $cabecalho = null): string
    {
        $componentes = [];
        if ($cabecalho !== null) {
            $componentes[] = ['type' => 'header', 'parameters' => [['type' => 'text', 'text' => $cabecalho]]];
        }
        if ($corpo) {
            $componentes[] = ['type' => 'body', 'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], array_values($corpo))];
        }
        if ($botaoUrl !== null) {
            $componentes[] = ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $botaoUrl]]];
        }
        $r = $this->chamar('POST', '/'.$this->integracao->credencial('phone_number_id').'/messages', [
            'messaging_product' => 'whatsapp', 'to' => '55'.$celular, 'type' => 'template',
            'template' => ['name' => $modelo, 'language' => ['code' => Modelos::IDIOMA], 'components' => $componentes],
        ]);

        return (string) $r->json('messages.0.id');
    }
}
