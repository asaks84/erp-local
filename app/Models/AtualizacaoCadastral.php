<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Link de atualização cadastral de um cliente (válido 30 dias; confirmação pela placa; aprovação manual). */
class AtualizacaoCadastral extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'atualizacoes_cadastrais';

    protected $attributes = ['situacao' => 'aberta', 'tentativas' => 0];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['dados' => 'array', 'antes' => 'array', 'expira_em' => 'datetime', 'enviado_em' => 'datetime', 'respondido_em' => 'datetime',
            'consentimento_em' => 'datetime', 'revisado_em' => 'datetime', 'bloqueado_ate' => 'datetime'];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function expirada(): bool
    {
        return $this->situacao === 'aberta' && $this->expira_em->isPast();
    }

    /** situação para mostrar: aberta vencida aparece como expirada */
    public function situacaoAtual(): string
    {
        return $this->expirada() ? 'expirada' : $this->situacao;
    }

    public function link(): string
    {
        return url('/atualizar/'.$this->token);
    }
}
