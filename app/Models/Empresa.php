<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Empresa extends Modelo
{
    protected $table = 'empresas';

    protected $guarded = ['id'];

    protected $attributes = ['ativa' => true];

    protected function casts(): array
    {
        return ['configuracoes' => 'array', 'ativa' => 'boolean'];
    }

    /** Configurações da empresa com os valores padrão para o que ainda não foi definido. */
    public const PADRAO = [
        'dia_vencimento_padrao' => 5,
        'periodos' => ['Diurno', 'Noturno', '24 horas'],
        'pausa_taxa_tipo' => 'valor',
        'pausa_taxa_valor' => 0,
        'cobranca_instrucoes' => '',
    ];

    public function config(string $chave): mixed
    {
        return ($this->configuracoes ?? [])[$chave] ?? self::PADRAO[$chave] ?? null;
    }

    public static function atual(): self
    {
        return static::findOrFail(app(\App\Support\EmpresaAtual::class)->id());
    }

    public function unidades(): HasMany
    {
        return $this->hasMany(Unidade::class);
    }
}
