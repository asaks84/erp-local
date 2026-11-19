<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use App\Servicos\Pdv\Caixas;
use App\Servicos\Pdv\PixPdv;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Dinheiro recebido. Dá baixa em uma ou mais ordens; nunca é apagado (estorno com motivo). */
class Pagamento extends Modelo
{
    use PertenceAEmpresa;

    protected $table = 'pagamentos';

    protected $attributes = ['situacao' => 'confirmado', 'origem' => 'gestao', 'parcelas' => 1, 'taxa' => 0];

    protected static function booted(): void
    {
        // recebido pelo PDV: origem "pdv" e o aparelho (o fechamento do caixa soma por aqui)
        static::creating(function (Pagamento $p) {
            $a = request()?->attributes->get('aparelho');
            if ($a && in_array($p->origem ?? 'gestao', ['gestao', null], true)) {
                $p->origem = 'pdv';
                $p->aparelho_id = $a->id;
            }
            // tudo o que entra pelo PDV cai no caixa aberto do balcão
            if ($p->origem === 'pdv' && ! $p->caixa_id) {
                $p->caixa_id = Caixas::atual()->id;
            }
            // Pix com QR do balcão (8.5): confere o Pix pago e grava o txid
            if ($a && ($pix = PixPdv::noPagamento($p))) {
                request()->attributes->set('pix_vincular', $pix);
            }
        });
        static::created(function (Pagamento $p) {
            $pix = request()?->attributes->get('pix_vincular');
            if ($pix && $p->pix_txid === $pix->txid) {
                $pix->update(['pagamento_id' => $p->id]);
                request()->attributes->remove('pix_vincular');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'taxa' => 'decimal:2',
            'liquido' => 'decimal:2',
            'previsto_para' => 'date:Y-m-d',
            'recebido_em' => 'datetime',
            'estornado_em' => 'datetime',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function forma(): BelongsTo
    {
        return $this->belongsTo(FormaPagamento::class, 'forma_pagamento_id');
    }

    public function conta(): BelongsTo
    {
        return $this->belongsTo(ContaFinanceira::class, 'conta_financeira_id');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(PagamentoItem::class);
    }

    public function criador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'criado_por');
    }
}
