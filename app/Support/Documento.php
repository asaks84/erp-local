<?php

namespace App\Support;

/** CPF e CNPJ: só números, com conferência dos dígitos verificadores. */
class Documento
{
    public static function limpar(?string $doc): ?string
    {
        $d = preg_replace('/\D/', '', (string) $doc);

        return $d === '' ? null : $d;
    }

    public static function valido(?string $doc): bool
    {
        $d = (string) self::limpar($doc);

        return match (strlen($d)) {
            11 => self::cpf($d),
            14 => self::cnpj($d),
            default => false,
        };
    }

    private static function cpf(string $d): bool
    {
        if (preg_match('/^(\d)\1{10}$/', $d)) {
            return false;
        }
        for ($t = 9; $t < 11; $t++) {
            $soma = 0;
            for ($i = 0; $i < $t; $i++) {
                $soma += (int) $d[$i] * (($t + 1) - $i);
            }
            if ((int) $d[$t] !== ((10 * $soma) % 11) % 10) {
                return false;
            }
        }

        return true;
    }

    private static function cnpj(string $d): bool
    {
        if (preg_match('/^(\d)\1{13}$/', $d)) {
            return false;
        }
        foreach ([12, 13] as $t) {
            $pesos = $t === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
            $soma = 0;
            foreach ($pesos as $i => $p) {
                $soma += (int) $d[$i] * $p;
            }
            $dv = $soma % 11 < 2 ? 0 : 11 - $soma % 11;
            if ((int) $d[$t] !== $dv) {
                return false;
            }
        }

        return true;
    }

    public static function formatar(?string $doc): string
    {
        $d = (string) self::limpar($doc);
        if (strlen($d) === 11) {
            return substr($d, 0, 3).'.'.substr($d, 3, 3).'.'.substr($d, 6, 3).'-'.substr($d, 9);
        }
        if (strlen($d) === 14) {
            return substr($d, 0, 2).'.'.substr($d, 2, 3).'.'.substr($d, 5, 3).'/'.substr($d, 8, 4).'-'.substr($d, 12);
        }

        return $d;
    }
}
