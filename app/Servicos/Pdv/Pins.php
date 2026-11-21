<?php

namespace App\Servicos\Pdv;

use App\Models\Aparelho;
use App\Models\Concerns\EmpresaScope;
use App\Models\Integracao;
use App\Models\Usuario;
use App\Support\Atividades;
use App\Support\Senha;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * PIN de 4 dígitos de cada pessoa (docs/07-pdv.md, "Regras do PIN"):
 * - a pessoa escolhe o próprio PIN (com login e senha, ou, quem é só do PDV e não tem senha, com um código de cadastro
 *   de 6 dígitos gerado na gestão, que vale 24 horas); PIN óbvio é recusado;
 * - PIN é único na empresa; quem escolhe um PIN que já é de outra pessoa recebe só "PIN indisponível" e
 *   o PIN antigo é cancelado (acabou de ser descoberto) — o dono cadastra outro;
 * - 5 PINs errados seguidos travam o aparelho por 1 minuto (vai para o registro de atividades).
 * Guardado como HMAC (empresa + PIN, com a chave do sistema): dá para achar o dono sem guardar o PIN.
 */
class Pins
{
    public const ERROS_PARA_TRAVAR = 5;

    public const TRAVA_SEGUNDOS = 60;

    public static function hash(int $empresaId, string $pin): string
    {
        return hash_hmac('sha256', $empresaId.':'.$pin, (string) config('app.key'));
    }

    /** rodadas do PBKDF2 da versão sem internet (dela sai só a dica que vai para o celular) */
    public const OFFLINE_RODADAS = 100000;

    /** sal da versão sem internet: um por empresa, derivado da chave do sistema (não precisa guardar) */
    public static function salOffline(int $empresaId): string
    {
        return substr(hash_hmac('sha256', 'pdv-pin-offline:'.$empresaId, (string) config('app.key')), 0, 32);
    }

    /** versão do PIN sem internet; o celular recebe só o começo dela (dica). Mesma conta em resources/js/pdv/offline/pins.js */
    public static function hashOffline(int $empresaId, string $pin): string
    {
        return hash_pbkdf2('sha256', $pin, self::salOffline($empresaId), self::OFFLINE_RODADAS, 64);
    }

    /**
     * "Dica" do PIN que vai para o celular no lugar da versão completa (auditoria de 21/11/2026): o começo da versão
     * sem internet, curto de propósito. Serve para o celular pegar erro de digitação e mostrar o nome de quem é, mas
     * não revela o PIN: cada dica serve para dezenas de PINs (gerente, com dica mais curta, para centenas). A
     * conferência de verdade é no servidor, na sincronização (abrirProva).
     */
    public static function dica(Usuario $u): ?string
    {
        return $u->pin_offline_hash ? substr($u->pin_offline_hash, 0, $u->pode('pdv_gerente') ? self::DICA_GERENTE : self::DICA) : null;
    }

    /** caracteres hexadecimais da dica: 2 = 256 grupos (uns 39 PINs por dica); gerente 1 = 16 grupos (uns 625 PINs) */
    public const DICA = 2;

    public const DICA_GERENTE = 1;

    /**
     * Chave pública (RSA, por empresa) com que o celular tranca o PIN de cada ação feita sem internet. Só o servidor
     * abre: a fila guardada no celular não revela o PIN. A chave privada fica em integracoes (tipo pdv_chave),
     * criptografada com a APP_KEY; é criada no primeiro uso.
     */
    public static function chavePublica(int $empresaId): string
    {
        return self::chave($empresaId)['publica'];
    }

    /**
     * Abre o PIN trancado pelo celular e devolve quem é (PIN é único na empresa), ou o motivo de não valer.
     * A prova leva o uuid da ação: não serve para outra ação.
     */
    public static function abrirProva(int $empresaId, ?string $prova, string $uuid): Usuario|string
    {
        $bruto = $prova ? base64_decode($prova, true) : false;
        $chave = self::chave($empresaId)['privada'];
        $json = null;
        if ($bruto !== false && $bruto !== '' && openssl_private_decrypt($bruto, $texto, $chave, OPENSSL_PKCS1_OAEP_PADDING)) {
            $json = json_decode($texto, true);
        }
        if (! is_array($json) || ! isset($json['p'], $json['u'])) {
            return 'A ação veio sem o PIN de quem fez (app do PDV desatualizado?). Confira e lance na gestão.';
        }
        if (mb_strtolower((string) $json['u']) !== $uuid) {
            return 'O PIN desta ação não é desta ação. Confira e lance na gestão.';
        }
        $pin = (string) $json['p'];
        $u = preg_match('/^\d{4}$/', $pin)
            ? Usuario::withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $empresaId)->where('pin_hash', self::hash($empresaId, $pin))->first()
            : null;
        if (! $u) {
            return 'O PIN digitado no celular não é de ninguém (errado ou trocado depois). Confira e lance na gestão.';
        }
        if (! $u->ativo) {
            return 'A pessoa que fez a ação não existe mais ou está inativa.';
        }

        return $u;
    }

    /** @return array{privada: string, publica: string} */
    private static function chave(int $empresaId): array
    {
        $achar = fn () => Integracao::withoutGlobalScopes()->where('empresa_id', $empresaId)->where('tipo', 'pdv_chave')->first();
        $i = $achar();
        if (! $i?->credencial('privada')) {
            $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($k, $privada);
            $pem = openssl_pkey_get_details($k)['key'];
            $publica = preg_replace('/-----[^-]+-----|\s+/', '', $pem);
            try {
                $i = $i ?? new Integracao;
                $i->forceFill(['empresa_id' => $empresaId, 'tipo' => 'pdv_chave', 'ativo' => true,
                    'credenciais' => ['privada' => $privada, 'publica' => $publica]])->save();
            } catch (UniqueConstraintViolationException) {
                $i = $achar(); // outro pedido criou ao mesmo tempo: vale a dele
            }
        }

        return ['privada' => (string) $i->credencial('privada'), 'publica' => (string) $i->credencial('publica')];
    }

    public static function obvio(string $pin): bool
    {
        if (preg_match('/^(\d)\1{3}$/', $pin)) {
            return true; // 0000, 1111...
        }
        $seq = '01234567890';
        $inv = strrev($seq);

        return str_contains($seq, $pin) || str_contains($inv, $pin) || in_array($pin, ['1212', '2121', '1122', '6969', '2580', '0852'], true);
    }

    /**
     * Senha ou código errados no cadastro de PIN: 5 por minuto, como na tela de login (por login e IP; o código, por
     * IP). PIN recusado pelas regras (óbvio, repetido) não conta: a pessoa pode tentar outros. Auditoria de 21/11/2026.
     */
    public const ERROS_CADASTRO = 5;

    private static function travaCadastro(string $chave, string $campo): void
    {
        if (RateLimiter::tooManyAttempts($chave, self::ERROS_CADASTRO)) {
            throw ValidationException::withMessages([$campo => 'Muitas tentativas seguidas. Espere '.max(1, RateLimiter::availableIn($chave)).' segundos e tente de novo.']);
        }
    }

    private static function chaveCadastro(int $empresaId, string $quem): string
    {
        return 'pdv-cadastro-pin:'.$empresaId.':'.$quem.'|'.request()->ip();
    }

    /** a pessoa cadastra o próprio PIN com login e senha do sistema */
    public static function cadastrar(int $empresaId, string $login, string $senha, string $pin): Usuario
    {
        $chave = self::chaveCadastro($empresaId, 'login:'.mb_strtolower(trim($login)));
        self::travaCadastro($chave, 'login');
        $u = Usuario::withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $empresaId)->where('login', trim($login))->first();
        if (! $u || ! $u->ativo || ! Senha::confere($senha, $u->senha)) {
            RateLimiter::hit($chave, 60);
            if ($u) {
                Atividades::registrar('pdv_pin_falhou', 'cadastro de PIN: senha incorreta ou usuário inativo', $u);
            }
            throw ValidationException::withMessages(['login' => 'Login ou senha incorretos.']);
        }
        RateLimiter::clear($chave);

        return self::definir($u, $pin);
    }

    public const CODIGO_HORAS = 24;

    private static function hashCodigo(int $empresaId, string $codigo): string
    {
        return hash_hmac('sha256', 'cadastro-pin:'.$empresaId.':'.$codigo, (string) config('app.key'));
    }

    /** a gestão gera um código de cadastro de PIN para a pessoa (mostrado uma vez; guarda só o hash) */
    public static function gerarCodigo(Usuario $u): string
    {
        $empresaId = (int) $u->empresa_id;
        do {
            $codigo = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $hash = self::hashCodigo($empresaId, $codigo);
            $repetido = Usuario::withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $empresaId)->where('pin_codigo_hash', $hash)
                ->where('pin_codigo_ate', '>', now())->where('id', '<>', $u->id)->exists();
        } while ($repetido);
        $u->forceFill(['pin_codigo_hash' => $hash, 'pin_codigo_ate' => now()->addHours(self::CODIGO_HORAS)])->save();
        Atividades::registrar('pdv_pin_codigo', 'código de cadastro de PIN gerado para '.$u->nome);

        return $codigo;
    }

    /** a pessoa (sem senha) escolhe o PIN no PDV com o código que recebeu do gerente */
    public static function cadastrarComCodigo(int $empresaId, string $codigo, string $pin): Usuario
    {
        $chave = self::chaveCadastro($empresaId, 'codigo');
        self::travaCadastro($chave, 'codigo');
        $codigo = preg_replace('/\D/', '', $codigo);
        $u = strlen($codigo) === 6
            ? Usuario::withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $empresaId)->where('pin_codigo_hash', self::hashCodigo($empresaId, $codigo))
                ->where('pin_codigo_ate', '>', now())->where('ativo', true)->first()
            : null;
        if (! $u) {
            RateLimiter::hit($chave, 60);
            throw ValidationException::withMessages(['codigo' => 'Código inválido ou vencido. Peça um código novo ao gerente.']);
        }
        RateLimiter::clear($chave);
        self::definir($u, $pin); // PIN recusado: o código continua valendo para tentar outro
        $u->forceFill(['pin_codigo_hash' => null, 'pin_codigo_ate' => null])->save();

        return $u;
    }

    /**
     * Grava o PIN de alguém com as regras (4 números, nada óbvio, único na empresa). Usado no PDV (com login
     * e senha) e na gestão, no cadastro do usuário.
     */
    public static function definir(Usuario $u, string $pin): Usuario
    {
        $empresaId = (int) $u->empresa_id;
        if (! preg_match('/^\d{4}$/', $pin)) {
            throw ValidationException::withMessages(['pin' => 'O PIN tem 4 números.']);
        }
        if (self::obvio($pin)) {
            throw ValidationException::withMessages(['pin' => 'PIN fácil demais (sequência ou número repetido). Escolha outro.']);
        }
        $hash = self::hash($empresaId, $pin);
        $offline = self::hashOffline($empresaId, $pin);

        $descoberto = DB::transaction(function () use ($u, $hash, $empresaId, $offline) {
            $dono = Usuario::withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $empresaId)->where('pin_hash', $hash)->lockForUpdate()->first();
            if ($dono && $dono->id !== $u->id) {
                // não diz de quem é; o PIN do outro foi descoberto, então deixa de valer (gravado antes do erro)
                $dono->forceFill(['pin_hash' => null, 'pin_definido_em' => null, 'pin_offline_hash' => null])->save();
                Atividades::registrar('pdv_pin_cancelado', 'PIN de '.$dono->nome.' cancelado: outra pessoa tentou o mesmo PIN', $u);

                return true;
            }
            $u->forceFill(['pin_hash' => $hash, 'pin_definido_em' => now(), 'pin_offline_hash' => $offline])->save();
            Atividades::registrar('pdv_pin_definido', 'PIN do PDV cadastrado', $u);

            return false;
        });
        if ($descoberto) {
            throw ValidationException::withMessages(['pin' => 'PIN indisponível, escolha outro.']);
        }

        return $u;
    }

    /** quem digitou o PIN neste aparelho (ou erro, com a trava de 5 tentativas) */
    public static function conferir(Aparelho $a, ?string $pin): Usuario
    {
        if ($a->pin_travado_ate && $a->pin_travado_ate->isFuture()) {
            throw ValidationException::withMessages(['pin' => 'Muitos PINs errados. Aguarde '.max(1, (int) ceil(now()->diffInSeconds($a->pin_travado_ate))).' segundos.']);
        }
        $pin = (string) $pin;
        $u = preg_match('/^\d{4}$/', $pin)
            ? Usuario::withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $a->empresa_id)->where('pin_hash', self::hash($a->empresa_id, $pin))->where('ativo', true)->first()
            : null;
        if (! $u) {
            $erros = $a->pin_erros + 1;
            $trava = $erros >= self::ERROS_PARA_TRAVAR;
            $a->forceFill(['pin_erros' => $trava ? 0 : $erros, 'pin_travado_ate' => $trava ? now()->addSeconds(self::TRAVA_SEGUNDOS) : $a->pin_travado_ate])->save();
            if ($trava) {
                Atividades::registrar('pdv_pin_travado', 'aparelho '.$a->nome.' travado por 1 minuto: '.self::ERROS_PARA_TRAVAR.' PINs errados seguidos');
            }
            throw ValidationException::withMessages(['pin' => $trava ? 'Muitos PINs errados. O aparelho ficou travado por 1 minuto.' : 'PIN incorreto.']);
        }
        if ($a->pin_erros) {
            $a->forceFill(['pin_erros' => 0])->save();
        }
        // PIN de antes da versão sem internet: cria agora, que o PIN acabou de ser digitado (vai na próxima cópia)
        if (! $u->pin_offline_hash) {
            $u->forceFill(['pin_offline_hash' => self::hashOffline((int) $a->empresa_id, $pin)])->saveQuietly();
        }

        return $u;
    }

    /** a gestão apaga o PIN de alguém (a pessoa cadastra outro no PDV) */
    public static function apagar(Usuario $u): void
    {
        $u->forceFill(['pin_hash' => null, 'pin_definido_em' => null, 'pin_offline_hash' => null])->save();
        Atividades::registrar('pdv_pin_apagado', 'PIN do PDV de '.$u->nome.' apagado');
    }
}
