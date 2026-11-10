<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Usuario extends Authenticatable
{
    use PertenceAEmpresa;

    public const CREATED_AT = 'criado_em';

    public const UPDATED_AT = 'atualizado_em';

    protected $table = 'usuarios';

    protected $authPasswordName = 'senha';

    protected $guarded = ['id', 'empresa_id', 'senha', 'remember_token'];

    protected $hidden = ['senha', 'remember_token'];

    // valores padrão já no objeto (iguais aos do banco)
    protected $attributes = ['ativo' => true, 'operador_caixa' => false, 'remember_token' => null];

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
            'operador_caixa' => 'boolean',
            'ultimo_acesso_em' => 'datetime',
        ];
    }

    public function nivel(): BelongsTo
    {
        return $this->belongsTo(Nivel::class);
    }

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    /** Pode fazer a ação? "livre" = qualquer usuário ativo. */
    public function pode(string $chave): bool
    {
        if (! $this->ativo || ! $this->nivel) {
            return false;
        }
        if ($chave === 'livre') {
            return true;
        }

        return $this->nivel->permite($chave);
    }

    public function definirSenha(string $senha): void
    {
        $this->senha = password_hash($senha, PASSWORD_BCRYPT);
    }
}
