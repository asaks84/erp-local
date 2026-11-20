<?php

namespace App\Models\Concerns;

/**
 * Dados de veículo (marca, modelo, cor, identificação, descrição) sempre em maiúsculas,
 * venham do PDV, do cadastro, do link público ou da importação. O modelo lista as colunas em $maiusculas.
 */
trait Maiusculas
{
    protected static function bootMaiusculas(): void
    {
        static::saving(function ($m) {
            foreach ($m->maiusculas as $c) {
                $v = $m->getAttribute($c);
                if (is_string($v) && $m->isDirty($c)) {
                    $m->setAttribute($c, trim(mb_strtoupper($v, 'UTF-8')));
                }
            }
        });
    }
}
