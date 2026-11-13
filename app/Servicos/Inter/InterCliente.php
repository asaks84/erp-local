<?php

namespace App\Servicos\Inter;

use App\Models\Integracao;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Chamadas à API do Banco Inter (cobrança v3) com o certificado da empresa (mTLS) e token OAuth.
 * Só chamadas de saída: a baixa vem da consulta periódica (a hospedagem não aceita webhook com mTLS).
 * Referência: developers.inter.co (Cobrança — Boleto com Pix, Autenticação OAuth v2).
 */
class InterCliente
{
    public const BASES = [
        'producao' => 'https://cdpj.partners.bancointer.com.br',
        'sandbox' => 'https://cdpj-sandbox.partners.uatinter.co',
    ];

    public const ESCOPOS = 'boleto-cobranca.read boleto-cobranca.write';

    /** arquivos temporários do certificado e da chave (apagados ao fim; o Guzzle só aceita arquivo) */
    private array $temporarios = [];

    private ?array $arquivos = null;

    public function __construct(private Integracao $integracao)
    {
        if (! $integracao->completa()) {
            throw new InterErro('Banco Inter não configurado: preencha as credenciais em Configurações › Banco Inter.');
        }
    }

    public function __destruct()
    {
        foreach ($this->temporarios as $f) {
            @unlink($f);
        }
    }

    private function base(): string
    {
        return self::BASES[$this->integracao->ambiente] ?? self::BASES['producao'];
    }

    /**
     * Requisição com o certificado da empresa. O Guzzle não aceita o certificado em memória
     * (CURLOPT_SSLCERT_BLOB fica fora da lista permitida), então o certificado e a chave vão para
     * arquivos temporários fora da pasta pública, só leitura do dono, apagados ao fim desta conexão.
     */
    private function http(): PendingRequest
    {
        $this->arquivos ??= [
            'cert' => $this->temporario((string) $this->integracao->credencial('certificado')),
            'ssl_key' => $this->temporario((string) $this->integracao->credencial('chave')),
        ];

        return Http::withOptions($this->arquivos)->timeout(30)->connectTimeout(10)->acceptJson();
    }

    private function temporario(string $conteudo): string
    {
        $pasta = storage_path('app/private/inter-tmp');
        if (! is_dir($pasta)) {
            mkdir($pasta, 0700, true);
        }
        // sobra de processo interrompido: não deixa certificado parado em disco
        foreach (glob($pasta.'/*.pem') ?: [] as $velho) {
            if (filemtime($velho) < time() - 600) {
                @unlink($velho);
            }
        }
        $f = $pasta.'/'.bin2hex(random_bytes(12)).'.pem';
        file_put_contents($f, $conteudo);
        chmod($f, 0600);

        return $this->temporarios[] = $f;
    }

    /** token OAuth (vale 1 hora; o Inter limita a 5 pedidos de token por minuto, por isso fica guardado) */
    public function token(bool $novo = false): string
    {
        $chave = 'inter_token_'.$this->integracao->empresa_id.'_'.md5($this->integracao->ambiente.$this->integracao->credencial('client_id'));
        if (! $novo && ($t = Cache::get($chave))) {
            return $t;
        }
        $r = $this->http()->asForm()->post($this->base().'/oauth/v2/token', [
            'client_id' => $this->integracao->credencial('client_id'),
            'client_secret' => $this->integracao->credencial('client_secret'),
            'grant_type' => 'client_credentials',
            'scope' => self::ESCOPOS,
        ]);
        if (! $r->successful() || ! $r->json('access_token')) {
            throw new InterErro('O Inter recusou as credenciais: '.self::mensagem($r).' Confira o client id, o client secret, o certificado e se a integração tem as permissões de boleto.');
        }
        Cache::put($chave, $r->json('access_token'), max(60, (int) $r->json('expires_in', 3600) - 120));

        return $r->json('access_token');
    }

    private function chamar(string $metodo, string $caminho, array $corpo = [], bool $repetiu = false): Response
    {
        $req = $this->http()->withToken($this->token());
        if ($this->integracao->conta_corrente) {
            $req = $req->withHeaders(['x-conta-corrente' => $this->integracao->conta_corrente]);
        }
        $url = $this->base().'/cobranca/v3'.$caminho;
        $r = match ($metodo) {
            'GET' => $req->get($url, $corpo),
            default => $req->send($metodo, $url, $corpo ? ['json' => $corpo] : []),
        };
        // token vencido ou revogado: pede outro uma vez
        if ($r->status() === 401 && ! $repetiu) {
            $this->token(true);

            return $this->chamar($metodo, $caminho, $corpo, true);
        }
        if (! $r->successful()) {
            throw new InterErro(self::mensagem($r), $r->status());
        }

        return $r;
    }

    /** emite a cobrança; o Inter responde só o código da solicitação (o boleto fica pronto em seguida) */
    public function emitir(array $dados): string
    {
        return (string) $this->chamar('POST', '/cobrancas', $dados)->json('codigoSolicitacao');
    }

    /** dados da cobrança: cobranca (situação, valores), boleto (linha digitável) e pix (copia e cola) */
    public function consultar(string $codigo): array
    {
        return $this->chamar('GET', '/cobrancas/'.$codigo)->json();
    }

    public function cancelar(string $codigo, string $motivo): void
    {
        $this->chamar('POST', '/cobrancas/'.$codigo.'/cancelar', ['motivoCancelamento' => mb_substr($motivo, 0, 50)]);
    }

    /** PDF do boleto (binário) */
    public function pdf(string $codigo): string
    {
        $b64 = (string) $this->chamar('GET', '/cobrancas/'.$codigo.'/pdf')->json('pdf');

        return base64_decode($b64, true) ?: throw new InterErro('O Inter não devolveu o PDF do boleto.');
    }

    /** teste de conexão: pede um token e consulta o resumo do dia */
    public function testar(): void
    {
        $this->token(true);
        $hoje = now()->toDateString();
        $this->chamar('GET', '/cobrancas/sumario', ['dataInicial' => $hoje, 'dataFinal' => $hoje]);
    }

    /** texto do erro do Inter: título, detalhe e as violações (campo e motivo) */
    public static function mensagem(Response $r): string
    {
        $j = $r->json() ?? [];
        $partes = array_filter([$j['title'] ?? null, $j['detail'] ?? null]);
        foreach ($j['violacoes'] ?? [] as $v) {
            $partes[] = trim(($v['propriedade'] ?? '').': '.($v['razao'] ?? ''), ': ');
        }
        if (! $partes) {
            $partes[] = $j['error_description'] ?? $j['error'] ?? ('HTTP '.$r->status());
        }

        return mb_substr(implode(' · ', array_unique($partes)), 0, 240);
    }
}
