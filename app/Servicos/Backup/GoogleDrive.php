<?php

namespace App\Servicos\Backup;

use App\Models\Integracao;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cópia do backup no Google Drive da empresa. Acesso pelo OAuth do Google com a permissão "drive.file": o ERP só vê
 * e mexe nos arquivos que ele mesmo criou (a pasta de backups), nada mais do Drive.
 *
 * Credenciais (criptografadas em integracoes, tipo google_drive): client_id e client_secret do app criado no Google
 * Cloud pelo dono, refresh_token (vem da conexão), pasta (id da pasta criada), senha (senha do backup), dias (guarda).
 */
class GoogleDrive
{
    private const AUTH = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN = 'https://oauth2.googleapis.com/token';

    private const API = 'https://www.googleapis.com/drive/v3';

    private const UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';

    public const ESCOPO = 'https://www.googleapis.com/auth/drive.file';

    public const PASTA = 'Backups ERP Local Estacionamento';

    private ?string $token = null;

    public function __construct(private Integracao $i) {}

    /** a integração do Drive (há uma só no sistema: o backup é do servidor inteiro) */
    public static function integracao(): ?Integracao
    {
        return Integracao::withoutGlobalScopes()->where('tipo', 'google_drive')->orderBy('id')->first();
    }

    public static function urlRetorno(): string
    {
        return url('/configuracoes/backup/google/retorno');
    }

    public function urlConexao(string $estado): string
    {
        return self::AUTH.'?'.http_build_query([
            'client_id' => $this->i->credencial('client_id'), 'redirect_uri' => self::urlRetorno(), 'response_type' => 'code',
            'scope' => self::ESCOPO, 'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true', 'state' => $estado,
        ]);
    }

    /** troca o código da volta do Google pelo refresh_token e cria (ou confere) a pasta */
    public function conectar(string $codigo): void
    {
        $r = Http::asForm()->timeout(30)->post(self::TOKEN, [
            'code' => $codigo, 'client_id' => $this->i->credencial('client_id'), 'client_secret' => $this->i->credencial('client_secret'),
            'redirect_uri' => self::urlRetorno(), 'grant_type' => 'authorization_code',
        ]);
        if (! $r->successful() || ! $r->json('refresh_token')) {
            throw new RuntimeException('O Google não autorizou: '.($r->json('error_description') ?? $r->json('error') ?? 'resposta sem refresh_token'));
        }
        $this->token = $r->json('access_token');
        $cred = $this->i->credenciais ?? [];
        $cred['refresh_token'] = $r->json('refresh_token');
        $this->i->credenciais = $cred;
        $cred['pasta'] = $this->garantirPasta();
        $cred['conta'] = $this->conta();
        $this->i->forceFill(['credenciais' => $cred, 'ativo' => true, 'ultimo_erro' => null])->save();
    }

    /** envia o arquivo (já criptografado) para a pasta; devolve o id no Drive */
    public function enviar(string $caminho, string $nome): string
    {
        $pasta = $this->garantirPasta();
        $inicio = $this->http()->withHeaders(['X-Upload-Content-Type' => 'application/octet-stream', 'X-Upload-Content-Length' => (string) filesize($caminho)])
            ->post(self::UPLOAD.'?uploadType=resumable&fields=id', ['name' => $nome, 'parents' => [$pasta]]);
        $this->exigir($inicio, 'iniciar o envio');
        $local = $inicio->header('Location');
        if (! $local) {
            throw new RuntimeException('O Google não devolveu o endereço de envio.');
        }
        $fim = $this->http()->timeout(900)->withBody(Utils::streamFor(fopen($caminho, 'rb')), 'application/octet-stream')->put($local);
        $this->exigir($fim, 'enviar o arquivo');

        return (string) $fim->json('id');
    }

    /** apaga da pasta os backups com mais de N dias, mantendo sempre os 3 mais novos; devolve quantos apagou */
    public function limpar(int $dias): int
    {
        $r = $this->http()->get(self::API.'/files', [
            'q' => "'".$this->garantirPasta()."' in parents and trashed = false", 'fields' => 'files(id,name,createdTime)', 'orderBy' => 'createdTime desc', 'pageSize' => 1000,
        ]);
        $this->exigir($r, 'listar a pasta');
        $limite = now()->subDays($dias);
        $apagados = 0;
        foreach (array_slice($r->json('files') ?? [], 3) as $f) {
            if (Carbon::parse($f['createdTime'])->lt($limite)) {
                $this->exigir($this->http()->delete(self::API.'/files/'.$f['id']), 'apagar backup antigo');
                $apagados++;
            }
        }

        return $apagados;
    }

    private function garantirPasta(): string
    {
        $id = $this->i->credencial('pasta');
        if ($id) {
            $r = $this->http()->get(self::API.'/files/'.$id, ['fields' => 'id,trashed']);
            if ($r->successful() && ! $r->json('trashed')) {
                return $id;
            }
        }
        $r = $this->http()->post(self::API.'/files?fields=id', ['name' => self::PASTA, 'mimeType' => 'application/vnd.google-apps.folder']);
        $this->exigir($r, 'criar a pasta');
        $cred = $this->i->credenciais ?? [];
        $cred['pasta'] = $r->json('id');
        $this->i->credenciais = $cred;
        if ($this->i->exists) {
            $this->i->save();
        }

        return $cred['pasta'];
    }

    private function conta(): ?string
    {
        $r = $this->http()->get(self::API.'/about', ['fields' => 'user(emailAddress)']);

        return $r->successful() ? $r->json('user.emailAddress') : null;
    }

    private function http()
    {
        return Http::withToken($this->acesso())->acceptJson()->timeout(60);
    }

    private function acesso(): string
    {
        if ($this->token) {
            return $this->token;
        }
        $r = Http::asForm()->timeout(30)->post(self::TOKEN, [
            'client_id' => $this->i->credencial('client_id'), 'client_secret' => $this->i->credencial('client_secret'),
            'refresh_token' => $this->i->credencial('refresh_token'), 'grant_type' => 'refresh_token',
        ]);
        if (! $r->successful() || ! $r->json('access_token')) {
            throw new RuntimeException('A conexão com o Google Drive caiu ('.($r->json('error') ?? $r->status()).'). Conecte de novo em Configurações › Backup.');
        }

        return $this->token = $r->json('access_token');
    }

    private function exigir($r, string $passo): void
    {
        if (! $r->successful()) {
            throw new RuntimeException("Google Drive: não foi possível {$passo} (".$r->status().': '.($r->json('error.message') ?? 'sem detalhe').').');
        }
    }
}
