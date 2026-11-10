<?php

namespace App\Servicos;

use App\Models\EtapaLavagem;
use Illuminate\Support\Str;

/**
 * Etapas da lavagem de cada empresa. Aguardando (entrada) é sempre a primeira e Entregue a última;
 * as do meio a empresa cria, renomeia, reordena e desativa. Padrão: aguardando, pronta e entregue,
 * com "Lavando" criada desativada como exemplo.
 */
class EtapasLavagem
{
    public const PRIMEIRA = 'aguardando';

    public const ULTIMA = 'entregue';

    public static function garantirPadrao(): void
    {
        if (EtapaLavagem::exists()) {
            return;
        }
        EtapaLavagem::create(['chave' => self::PRIMEIRA, 'nome' => 'Aguardando', 'ordem' => 0, 'fixa' => true]);
        EtapaLavagem::create(['chave' => 'lavando', 'nome' => 'Lavando', 'ordem' => 10, 'ativa' => false]);
        EtapaLavagem::create(['chave' => 'pronta', 'nome' => 'Pronta', 'ordem' => 20]);
        EtapaLavagem::create(['chave' => self::ULTIMA, 'nome' => 'Entregue', 'ordem' => 1000, 'fixa' => true]);
    }

    /** todas, na ordem */
    public static function todas()
    {
        self::garantirPadrao();

        return EtapaLavagem::orderBy('ordem')->orderBy('id')->get();
    }

    /** ativas, na ordem: [chave => nome] */
    public static function ativas(): array
    {
        return self::todas()->where('ativa', true)->pluck('nome', 'chave')->all();
    }

    /** chave nova a partir do nome, sem repetir na empresa */
    public static function chavePara(string $nome): string
    {
        $base = Str::limit(Str::slug($nome, '_'), 24, '') ?: 'etapa';
        $chave = $base;
        for ($i = 2; EtapaLavagem::where('chave', $chave)->exists(); $i++) {
            $chave = $base.'_'.$i;
        }

        return $chave;
    }

    /** reescreve a ordem: fixas nas pontas, as do meio na sequência dada */
    public static function reordenar(array $idsDoMeio): void
    {
        foreach (array_values($idsDoMeio) as $i => $id) {
            EtapaLavagem::where('fixa', false)->whereKey($id)->update(['ordem' => ($i + 1) * 10]);
        }
    }
}
