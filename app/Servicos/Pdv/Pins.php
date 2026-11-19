<?php

namespace App\Servicos\Pdv;

use App\Models\Aparelho;
use App\Models\Concerns\EmpresaScope;
use App\Models\Usuario;
use App\Support\Atividades;
use App\Support\Senha;
use Illuminate\Support\Facades\DB;
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

    public static function obvio(string $pin): bool
    {
        if (preg_match('/^(\d)\1{3}$/', $pin)) {
            return true; // 0000, 1111...
        }
        $seq = '01234567890';
        $inv = strrev($seq);

        return str_contains($seq, $pin) || str_contains($inv, $pin) || in_array($pin, ['1212', '2121', '1122', '6969', '2580', '0852'], true);
    }

    /** a pessoa cadastra o próprio PIN com login e senha do sistema */
    public static function cadastrar(int $empresaId, string $login, string $senha, string $pin): Usuario
    {
        $u = Usuario::withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $empresaId)->where('login', trim($login))->first();
        if (! $u || ! $u->ativo || ! Senha::confere($senha, $u->senha)) {
            if ($u) {
                Atividades::registrar('pdv_pin_falhou', 'cadastro de PIN: senha incorreta ou usuário inativo', $u);
            }
            throw ValidationException::withMessages(['login' => 'Login ou senha incorretos.']);
        }

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
        $codigo = preg_replace('/\D/', '', $codigo);
        $u = strlen($codigo) === 6
            ? Usuario::withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $empresaId)->where('pin_codigo_hash', self::hashCodigo($empresaId, $codigo))
                ->where('pin_codigo_ate', '>', now())->where('ativo', true)->first()
            : null;
        if (! $u) {
            throw ValidationException::withMessages(['codigo' => 'Código inválido ou vencido. Peça um código novo ao gerente.']);
        }
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

        $descoberto = DB::transaction(function () use ($u, $hash, $empresaId) {
            $dono = Usuario::withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $empresaId)->where('pin_hash', $hash)->lockForUpdate()->first();
            if ($dono && $dono->id !== $u->id) {
                // não diz de quem é; o PIN do outro foi descoberto, então deixa de valer (gravado antes do erro)
                $dono->forceFill(['pin_hash' => null, 'pin_definido_em' => null])->save();
                Atividades::registrar('pdv_pin_cancelado', 'PIN de '.$dono->nome.' cancelado: outra pessoa tentou o mesmo PIN', $u);

                return true;
            }
            $u->forceFill(['pin_hash' => $hash, 'pin_definido_em' => now()])->save();
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

        return $u;
    }

    /** a gestão apaga o PIN de alguém (a pessoa cadastra outro no PDV) */
    public static function apagar(Usuario $u): void
    {
        $u->forceFill(['pin_hash' => null, 'pin_definido_em' => null])->save();
        Atividades::registrar('pdv_pin_apagado', 'PIN do PDV de '.$u->nome.' apagado');
    }
}
