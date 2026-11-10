<?php

namespace App\Support;

/** Formatos em pt-BR feitos pelo próprio sistema (nunca pelo locale do servidor). */
class Formato
{
    public static function moeda($valor): string
    {
        return 'R$ '.number_format((float) $valor, 2, ',', '.');
    }

    /** 90 → "1h30", 120 → "2h", 45 → "45 min" */
    public static function duracao(int $min): string
    {
        if ($min < 60) {
            return $min.' min';
        }

        return intdiv($min, 60).'h'.($min % 60 ? sprintf('%02d', $min % 60) : '');
    }
}
