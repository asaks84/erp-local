<?php

namespace App\Support;

/** Placas: guardadas em maiúsculas, sem traço nem espaço. Formato livre (aceita placas de outros países). */
class Placa
{
    public static function normalizar(?string $placa): ?string
    {
        $p = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $placa));

        return $p === '' ? null : $p;
    }

    /** placa brasileira: antiga (ABC1234) ou Mercosul (ABC1D23) */
    public static function brasileira(?string $placa): bool
    {
        return (bool) preg_match('/^[A-Z]{3}[0-9][A-Z0-9][0-9]{2}$/', (string) self::normalizar($placa));
    }

    /** "ABC1234" → "ABC-1234"; Mercosul fica junto: "ABC1D23" */
    public static function formatar(?string $placa): string
    {
        $p = (string) self::normalizar($placa);
        if (preg_match('/^[A-Z]{3}[0-9]{4}$/', $p)) {
            return substr($p, 0, 3).'-'.substr($p, 3);
        }

        return $p;
    }
}
