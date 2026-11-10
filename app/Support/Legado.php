<?php

namespace App\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Acesso de leitura ao banco do sistema atual. No servidor (Linux) as tabelas estão em
 * MAIÚSCULAS e na cópia do Windows em minúsculas; aqui o nome é achado nos dois casos.
 */
class Legado
{
    private ?array $tabelas = null;

    public function conexao(): Connection
    {
        return DB::connection(config('erp.legado_conexao'));
    }

    /** Nome real da tabela (ex.: "USUARIOS" ou "usuarios"), ou null se não existir. */
    public function tabela(string $nome): ?string
    {
        if ($this->tabelas === null) {
            $this->tabelas = [];
            foreach ($this->conexao()->select('SHOW TABLES') as $linha) {
                $real = array_values((array) $linha)[0];
                $this->tabelas[strtolower($real)] = $real;
            }
        }

        return $this->tabelas[strtolower($nome)] ?? null;
    }

    public function exige(string $nome): string
    {
        return $this->tabela($nome) ?? throw new RuntimeException("Tabela {$nome} não encontrada no banco do sistema atual.");
    }

    public function tem(string $tabela, string $coluna): bool
    {
        $real = $this->tabela($tabela);
        if (! $real) {
            return false;
        }
        foreach ($this->conexao()->select("SHOW COLUMNS FROM `{$real}`") as $c) {
            if (strcasecmp($c->Field, $coluna) === 0) {
                return true;
            }
        }

        return false;
    }

    public function contar(string $nome, string $onde = '1 = 1'): ?int
    {
        $real = $this->tabela($nome);

        return $real ? (int) $this->conexao()->selectOne("SELECT COUNT(*) AS n FROM `{$real}` WHERE {$onde}")->n : null;
    }
}
