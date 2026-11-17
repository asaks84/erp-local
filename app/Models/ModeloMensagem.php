<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;

/** Uma versão de uma mensagem do WhatsApp (modelo da Meta). Versões da mesma mensagem têm o mesmo grupo. */
class ModeloMensagem extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'modelos_mensagem';

    protected $attributes = ['situacao' => 'rascunho', 'finalidade' => 'cobranca', 'categoria' => 'UTILITY', 'botao' => true, 'versao' => 1];

    /** situações em que a versão já foi para a Meta (texto travado) */
    public const ENVIADAS = ['verificar', 'PENDING', 'APPROVED', 'REJECTED', 'PAUSED', 'DISABLED'];

    protected function casts(): array
    {
        return ['botao' => 'boolean', 'versao' => 'integer', 'grupo' => 'integer', 'enviado_em' => 'datetime'];
    }

    public function rascunho(): bool
    {
        return $this->situacao === 'rascunho';
    }
}
