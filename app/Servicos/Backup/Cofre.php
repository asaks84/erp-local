<?php

namespace App\Servicos\Backup;

use RuntimeException;

/**
 * Criptografia do backup que sai do servidor: AES-256-CBC com chave tirada da senha do backup por PBKDF2-SHA256
 * (200 mil voltas), no formato do OpenSSL ("Salted__" + sal). Assim o arquivo abre de dois jeitos, mesmo sem o ERP:
 *   php artisan backup:abrir arquivo.sql.gz.enc
 *   openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -md sha256 -in arquivo.sql.gz.enc -out arquivo.sql.gz
 * Processa em blocos (o arquivo não precisa caber na memória).
 */
class Cofre
{
    public const VOLTAS = 200000;

    private const BLOCO = 1048576; // 1 MB, múltiplo de 16

    public static function criptografar(string $origem, string $destino, string $senha): void
    {
        $sal = random_bytes(8);
        [$chave, $iv] = self::derivar($senha, $sal);
        $in = fopen($origem, 'rb');
        $out = fopen($destino, 'wb');
        if (! $in || ! $out) {
            throw new RuntimeException('Não foi possível abrir os arquivos do backup.');
        }
        try {
            fwrite($out, 'Salted__'.$sal);
            $atual = fread($in, self::BLOCO);
            while (true) {
                $proximo = fread($in, self::BLOCO);
                if ($proximo === '' || $proximo === false) {
                    // último pedaço: com o preenchimento padrão (PKCS#7)
                    fwrite($out, openssl_encrypt((string) $atual, 'aes-256-cbc', $chave, OPENSSL_RAW_DATA, $iv));
                    break;
                }
                $cifra = openssl_encrypt($atual, 'aes-256-cbc', $chave, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
                fwrite($out, $cifra);
                $iv = substr($cifra, -16);
                $atual = $proximo;
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    public static function abrir(string $origem, string $destino, string $senha): void
    {
        $in = fopen($origem, 'rb');
        if (! $in || fread($in, 8) !== 'Salted__') {
            throw new RuntimeException('Este arquivo não é um backup criptografado.');
        }
        [$chave, $iv] = self::derivar($senha, fread($in, 8));
        $out = fopen($destino, 'wb');
        try {
            $atual = fread($in, self::BLOCO);
            while (true) {
                $proximo = fread($in, self::BLOCO);
                if ($proximo === '' || $proximo === false) {
                    $claro = openssl_decrypt((string) $atual, 'aes-256-cbc', $chave, OPENSSL_RAW_DATA, $iv);
                    if ($claro === false) {
                        throw new RuntimeException('Senha errada ou arquivo danificado.');
                    }
                    fwrite($out, $claro);
                    break;
                }
                fwrite($out, (string) openssl_decrypt($atual, 'aes-256-cbc', $chave, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv));
                $iv = substr($atual, -16);
                $atual = $proximo;
            }
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($destino);
            throw $e;
        } finally {
            fclose($in);
            if (is_resource($out)) {
                fclose($out);
            }
        }
    }

    /** chave (32 bytes) e IV (16) como o "openssl enc -pbkdf2 -md sha256" faz */
    private static function derivar(string $senha, string $sal): array
    {
        $d = hash_pbkdf2('sha256', $senha, $sal, self::VOLTAS, 48, true);

        return [substr($d, 0, 32), substr($d, 32, 16)];
    }
}
