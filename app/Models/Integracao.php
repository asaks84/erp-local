<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;

/**
 * Credenciais de uma integração (Banco Inter; WhatsApp depois) da empresa.
 * "credenciais" fica criptografado com a APP_KEY: trocar a APP_KEY obriga a cadastrar de novo.
 */
class Integracao extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'integracoes';

    protected $hidden = ['credenciais'];

    protected function casts(): array
    {
        return ['credenciais' => 'encrypted:array', 'ativo' => 'boolean', 'certificado_validade' => 'date:Y-m-d', 'testado_em' => 'datetime'];
    }

    public static function inter(): ?self
    {
        return static::where('tipo', 'inter')->first();
    }

    public function credencial(string $chave): ?string
    {
        return ($this->credenciais ?? [])[$chave] ?? null;
    }

    /** a integração tem tudo para chamar a API (não quer dizer que já foi testada) */
    public function completa(): bool
    {
        return (bool) ($this->credencial('client_id') && $this->credencial('client_secret') && $this->credencial('certificado') && $this->credencial('chave'));
    }
}
