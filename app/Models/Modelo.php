<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Base dos modelos do ERP: datas de criação/alteração com nomes em português. */
abstract class Modelo extends Model
{
    public const CREATED_AT = 'criado_em';

    public const UPDATED_AT = 'atualizado_em';

    protected $guarded = ['id', 'empresa_id'];
}
