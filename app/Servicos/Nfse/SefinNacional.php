<?php

namespace App\Servicos\Nfse;

use App\Models\Integracao;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * API do Sistema Nacional da NFS-e (Sefin Nacional), com o certificado A1 da empresa na conexão (TLS mútuo).
 * Produção restrita = ambiente de testes, notas sem valor fiscal.
 */
class SefinNacional
{
    public const BASES = [
        'producao' => 'https://sefin.nfse.gov.br/SefinNacional',
        'restrita' => 'https://sefin.producaorestrita.nfse.gov.br/SefinNacional',
    ];

    public const BASES_ADN = [
        'producao' => 'https://adn.nfse.gov.br',
        'restrita' => 'https://adn.producaorestrita.nfse.gov.br',
    ];

    private array $temporarios = [];

    private ?array $arquivos = null;

    public function __construct(private Integracao $integracao)
    {
        if (! $integracao->credencial('certificado') || ! $integracao->credencial('chave')) {
            throw new RuntimeException('Certificado A1 não cadastrado (Configurações › Nota fiscal).');
        }
    }

    public function __destruct()
    {
        foreach ($this->temporarios as $f) {
            @unlink($f);
        }
    }

    public function ambiente(): string
    {
        return $this->integracao->ambiente === 'producao' ? 'producao' : 'restrita';
    }

    /** envia a DPS assinada; devolve a resposta crua (ok ou erro) */
    public function enviar(string $xmlAssinado): array
    {
        $r = $this->post(self::BASES[$this->ambiente()].'/nfse', ['dpsXmlGZipB64' => base64_encode(gzencode($xmlAssinado))]);

        return self::ler($r);
    }

    /** consulta pela DPS (para quando o envio caiu no meio e não se sabe se a nota saiu) */
    public function consultarDps(string $idDps): array
    {
        return self::ler($this->http()->get(self::BASES[$this->ambiente()].'/dps/'.$idDps));
    }

    public function consultarNfse(string $chave): array
    {
        return self::ler($this->http()->get(self::BASES[$this->ambiente()].'/nfse/'.$chave));
    }

    /** cancelamento: evento e101101, assinado */
    public function evento(string $chave, string $xmlAssinado): array
    {
        return self::ler($this->post(self::BASES[$this->ambiente()].'/nfse/'.$chave.'/eventos', ['pedidoRegistroEventoXmlGZipB64' => base64_encode(gzencode($xmlAssinado))]));
    }

    /** PDF da nota (DANFSe), do Ambiente de Dados Nacional */
    public function danfse(string $chave): string
    {
        $r = $this->http()->accept('application/pdf')->get(self::BASES_ADN[$this->ambiente()].'/danfse/'.$chave);
        if (! $r->successful()) {
            throw new RuntimeException('O Sistema Nacional não devolveu o PDF agora (HTTP '.$r->status().'). Tente de novo em alguns minutos.');
        }

        return $r->body();
    }

    /** conecta com o certificado (só confere a conexão e o certificado) */
    public function testar(): void
    {
        try {
            $r = $this->http()->get(self::BASES[$this->ambiente()].'/nfse/'.str_repeat('0', 50));
        } catch (ConnectionException $e) {
            throw new RuntimeException('Não conectou ao Sistema Nacional com este certificado: '.$e->getMessage());
        }
        if (in_array($r->status(), [401, 403, 495, 496], true)) {
            throw new RuntimeException('O Sistema Nacional recusou o certificado (HTTP '.$r->status().').');
        }
    }

    private function post(string $url, array $corpo): Response
    {
        try {
            return $this->http()->post($url, $corpo);
        } catch (ConnectionException $e) {
            throw new SemResposta('Sem resposta do Sistema Nacional: '.$e->getMessage());
        }
    }

    private function http(): PendingRequest
    {
        $this->arquivos ??= [
            'cert' => $this->temporario((string) $this->integracao->credencial('certificado')),
            'ssl_key' => $this->temporario((string) $this->integracao->credencial('chave')),
        ];

        return Http::withOptions($this->arquivos)->timeout(40)->connectTimeout(10)->acceptJson();
    }

    private function temporario(string $conteudo): string
    {
        $pasta = storage_path('app/private/nfse-tmp');
        if (! is_dir($pasta)) {
            mkdir($pasta, 0700, true);
        }
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

    /**
     * Resposta em formato único: ['ok' => bool, 'status' => int, 'dados' => array, 'erros' => [mensagens]].
     * Os erros do Sistema Nacional vêm como lista ("erros": [{codigo, descricao, complemento}]) ou campos soltos.
     */
    public static function ler(Response $r): array
    {
        $j = $r->json() ?? [];
        $j = is_array($j) ? $j : [];
        $erros = [];
        foreach ((array) ($j['erros'] ?? $j['Erros'] ?? $j['erro'] ?? []) as $e) {
            if (is_array($e)) {
                $cod = $e['codigo'] ?? $e['Codigo'] ?? null;
                $txt = trim(($e['descricao'] ?? $e['Descricao'] ?? $e['mensagem'] ?? '').' '.($e['complemento'] ?? $e['Complemento'] ?? ''));
                $erros[] = trim(($cod ? $cod.': ' : '').$txt);
            } elseif (is_string($e)) {
                $erros[] = $e;
            }
        }
        if (! $erros && ! $r->successful()) {
            $erros[] = trim(($j['codigo'] ?? '').' '.($j['mensagem'] ?? $j['message'] ?? $j['title'] ?? 'HTTP '.$r->status()));
        }

        return ['ok' => $r->successful() && ! $erros, 'status' => $r->status(), 'dados' => $j, 'erros' => array_values(array_filter($erros))];
    }

    public static function descompactar(?string $b64): ?string
    {
        if (! $b64) {
            return null;
        }
        $bin = base64_decode($b64, true);

        return $bin === false ? null : (@gzdecode($bin) ?: null);
    }
}
