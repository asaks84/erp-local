<?php

namespace App\Support;

/**
 * Confere senhas. Aceita o bcrypt (formato do sistema atual) e o SHA1 antigo, sem sal,
 * que ainda pode existir em usuários que não entram há muito tempo no sistema atual.
 * Quem entrar com o formato antigo tem a senha regravada em bcrypt na hora.
 */
class Senha
{
    public static function confere(string $digitada, string $guardada): bool
    {
        if (self::antiga($guardada)) {
            return hash_equals(strtolower($guardada), sha1($digitada));
        }

        return password_verify($digitada, $guardada);
    }

    public static function antiga(string $guardada): bool
    {
        return (bool) preg_match('/^[0-9a-f]{40}$/i', $guardada);
    }

    public static function precisaRegravar(string $guardada): bool
    {
        return self::antiga($guardada) || password_needs_rehash($guardada, PASSWORD_BCRYPT);
    }
}
