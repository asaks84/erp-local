<?php

namespace App\Auth;

use App\Models\Concerns\EmpresaScope;
use Illuminate\Auth\EloquentUserProvider;

/**
 * Busca o usuário do login/sessão sem o filtro de empresa (a empresa ainda não é conhecida
 * nesse momento: é justamente o usuário que diz qual é). Inativos não entram.
 */
class UsuarioProvider extends EloquentUserProvider
{
    protected function newModelQuery($model = null)
    {
        return parent::newModelQuery($model)
            ->withoutGlobalScope(EmpresaScope::class)
            ->where('ativo', true);
    }
}
