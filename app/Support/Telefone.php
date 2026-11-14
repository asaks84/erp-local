<?php

namespace App\Support;

use App\Servicos\Atualizacoes;

/** Telefones: só dígitos. Celular brasileiro = DDD + 9 + 8 dígitos (com ou sem o 55 na frente). */
class Telefone
{
    /** celular válido (sem cara de número inventado), sempre com 11 dígitos; senão null */
    public static function celular(?string $numero): ?string
    {
        $d = preg_replace('/\D/', '', (string) $numero);
        if (strlen($d) > 11 && str_starts_with($d, '55')) {
            $d = substr($d, 2);
        }

        return $d !== '' && Atualizacoes::problemaCelular($d) === null ? $d : null;
    }

    /**
     * Celular vazio e o número do campo telefone é de celular: passa para o celular (e o telefone fica vazio).
     * Devolve [telefone, celular].
     */
    public static function arrumar(?string $telefone, ?string $celular): array
    {
        if (trim((string) $celular, " 0") === '' && ($c = self::celular($telefone))) {
            return [null, $c];
        }

        return [$telefone, $celular];
    }
}
