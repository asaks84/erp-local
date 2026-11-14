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

    /** para exibir: sempre junto, só letras e números ("ABC1234", "ABC1D23") */
    public static function formatar(?string $placa): string
    {
        return (string) self::normalizar($placa);
    }

    /** identificação de veículo sem placa: se for uma placa brasileira, fica no padrão (sem traço); senão, texto livre */
    public static function identificacao(?string $texto): ?string
    {
        $t = trim((string) $texto);
        if ($t === '') {
            return null;
        }

        return self::brasileira($t) ? self::normalizar($t) : $t;
    }
}
