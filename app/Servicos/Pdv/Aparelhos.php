<?php

namespace App\Servicos\Pdv;

use App\Models\Aparelho;
use App\Support\Atividades;
use App\Support\EmpresaAtual;
use App\Models\Concerns\EmpresaScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Aparelhos do PDV. A gestão cria o aparelho e gera um código de 6 dígitos (vale 15 minutos, uso único);
 * o celular digita o código e recebe um token, que só aparece essa vez (no banco fica só o hash).
 * Desativar o aparelho na gestão corta o token na hora.
 */
class Aparelhos
{
    public const VALIDADE_CODIGO_MIN = 15;

    private static function hash(string $valor): string
    {
        return hash('sha256', $valor);
    }

    /** gera (ou troca) o código de pareamento; devolve o código em texto (só para mostrar na tela) */
    public static function novoCodigo(Aparelho $a): string
    {
        do {
            $codigo = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (Aparelho::withoutGlobalScope(EmpresaScope::class)->where('codigo_hash', self::hash($codigo))->exists());
        // novo código = novo pareamento: o token antigo deixa de valer
        $a->forceFill(['codigo_hash' => self::hash($codigo), 'codigo_expira_em' => now()->addMinutes(self::VALIDADE_CODIGO_MIN), 'token_hash' => null, 'pareado_em' => null, 'ativo' => true])->save();
        Atividades::registrar('pdv_codigo', 'código de pareamento gerado para o aparelho '.$a->nome);

        return $codigo;
    }

    /** o celular troca o código pelo token. Devolve [aparelho, token]. */
    public static function parear(string $codigo, ?string $versao = null): array
    {
        $codigo = preg_replace('/\D/', '', $codigo);
        return DB::transaction(function () use ($codigo, $versao) {
            $a = strlen($codigo) === 6 ? Aparelho::withoutGlobalScope(EmpresaScope::class)->where('codigo_hash', self::hash($codigo))->lockForUpdate()->first() : null;
            if (! $a || ! $a->ativo || ! $a->codigo_expira_em || $a->codigo_expira_em->isPast()) {
                throw ValidationException::withMessages(['codigo' => 'Código inválido ou vencido. Gere outro na gestão (Configurações › Aparelhos).']);
            }
            $token = Str::random(64);
            $a->forceFill(['token_hash' => self::hash($token), 'codigo_hash' => null, 'codigo_expira_em' => null, 'pareado_em' => now(),
                'ultimo_acesso_em' => now(), 'versao_app' => $versao ? mb_substr($versao, 0, 20) : null, 'pin_erros' => 0, 'pin_travado_ate' => null])->save();
            app(EmpresaAtual::class)->como($a->empresa_id, fn () => Atividades::registrar('pdv_pareado', 'aparelho '.$a->nome.' pareado'));

            return [$a, $token];
        });
    }

    /** aparelho do token (ativo e pareado) ou null */
    public static function doToken(?string $token): ?Aparelho
    {
        if (! $token || strlen($token) < 32) {
            return null;
        }
        $a = Aparelho::withoutGlobalScope(EmpresaScope::class)->where('token_hash', self::hash($token))->first();

        return $a && $a->ativo ? $a : null;
    }

    public static function desativar(Aparelho $a): void
    {
        $a->forceFill(['ativo' => false, 'token_hash' => null, 'codigo_hash' => null, 'codigo_expira_em' => null])->save();
        Atividades::registrar('pdv_desativado', 'aparelho '.$a->nome.' desativado');
    }
}
