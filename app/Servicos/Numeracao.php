<?php

namespace App\Servicos;

use App\Support\EmpresaAtual;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Número que o cliente vê (ordem, cobrança), em sequência própria por empresa e sem buracos.
 * Trava a linha da sequência até o fim da transação, então dois lançamentos ao mesmo tempo
 * nunca recebem o mesmo número.
 */
class Numeracao
{
    private const TABELAS = ['ordem' => 'ordens_servico', 'cobranca' => 'cobrancas'];

    public static function proximo(string $tipo): int
    {
        $empresa = app(EmpresaAtual::class)->id() ?? throw new LogicException('Numeração sem empresa definida.');

        return DB::transaction(function () use ($empresa, $tipo) {
            $linha = DB::table('numeracoes')->where('empresa_id', $empresa)->where('tipo', $tipo)->lockForUpdate()->first();
            if (! $linha) {
                // primeira vez: continua do maior número já gravado (ex.: ordens importadas)
                $maior = (int) DB::table(self::TABELAS[$tipo])->where('empresa_id', $empresa)->max('numero');
                DB::table('numeracoes')->insertOrIgnore(['empresa_id' => $empresa, 'tipo' => $tipo, 'ultimo' => $maior]);
                $linha = DB::table('numeracoes')->where('empresa_id', $empresa)->where('tipo', $tipo)->lockForUpdate()->first();
            }
            $numero = $linha->ultimo + 1;
            DB::table('numeracoes')->where('id', $linha->id)->update(['ultimo' => $numero]);

            return $numero;
        });
    }

    /** depois de importar: a sequência continua do maior número existente */
    public static function acertar(string $tipo): void
    {
        $empresa = app(EmpresaAtual::class)->id();
        $maior = (int) DB::table(self::TABELAS[$tipo])->where('empresa_id', $empresa)->max('numero');
        $linha = DB::table('numeracoes')->where('empresa_id', $empresa)->where('tipo', $tipo)->first();
        if (! $linha) {
            DB::table('numeracoes')->insert(['empresa_id' => $empresa, 'tipo' => $tipo, 'ultimo' => $maior]);
        } elseif ($linha->ultimo < $maior) {
            DB::table('numeracoes')->where('id', $linha->id)->update(['ultimo' => $maior]);
        }
    }
}
