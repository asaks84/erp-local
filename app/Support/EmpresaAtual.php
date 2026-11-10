<?php

namespace App\Support;

/**
 * Guarda a empresa da requisição atual (vem do usuário logado ou é definida pelo comando).
 * Todas as consultas de tabelas de negócio são filtradas por ela (ver Concerns\PertenceAEmpresa).
 */
class EmpresaAtual
{
    private ?int $id = null;

    public function definir(?int $id): void
    {
        $this->id = $id;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function definida(): bool
    {
        return $this->id !== null;
    }

    /** Roda um trecho como se fosse outra empresa (comandos e testes) e volta à anterior. */
    public function como(int $id, callable $fazer): mixed
    {
        $anterior = $this->id;
        $this->id = $id;
        try {
            return $fazer();
        } finally {
            $this->id = $anterior;
        }
    }
}
