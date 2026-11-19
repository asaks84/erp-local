<?php

namespace App\Servicos\Pdv;

use App\Models\Empresa;
use App\Models\FormaPagamento;
use App\Models\Integracao;
use App\Models\Pagamento;
use App\Models\PixCobranca;
use App\Servicos\Inter\InterCliente;
use App\Servicos\Inter\InterErro;
use App\Support\Atividades;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pix imediato do PDV (etapa 8.5): o QR do Inter na tela do celular, a consulta até ficar pago e o vínculo
 * com o pagamento registrado em seguida (o PDV manda pix_id; o gancho do Pagamento confere e grava o txid).
 */
class PixPdv
{
    /** validade do QR: o cliente tem esse tempo para pagar */
    public const EXPIRA_SEG = 900;

    /** Inter ligado e chave Pix cadastrada */
    public static function ligado(): bool
    {
        $i = Integracao::inter();

        return (bool) ($i?->ativo && $i->completa() && self::chave());
    }

    public static function chave(): ?string
    {
        return Empresa::atual()->config('pix_chave') ?: null;
    }

    /** chave Pix: e-mail, CPF, CNPJ, celular (+55...) ou aleatória; devolve normalizada ou null */
    public static function normalizarChave(string $c): ?string
    {
        $c = trim($c);
        if (filter_var($c, FILTER_VALIDATE_EMAIL)) {
            return mb_strtolower($c);
        }
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $c)) {
            return mb_strtolower($c);
        }
        if (str_starts_with($c, '+')) {
            $d = preg_replace('/\D/', '', $c);

            return strlen($d) >= 12 && strlen($d) <= 13 ? '+'.$d : null;
        }
        $d = preg_replace('/\D/', '', $c);
        if (in_array(strlen($d), [11, 14], true)) {
            return $d;
        }

        return null;
    }

    private static function erro(string $msg): never
    {
        throw ValidationException::withMessages(['pix' => $msg]);
    }

    private static function cliente(): InterCliente
    {
        return new InterCliente(Integracao::inter());
    }

    /** cria o Pix no Inter e devolve com o copia e cola */
    public static function criar(float $valor, ?string $descricao = null): PixCobranca
    {
        if (! self::ligado()) {
            self::erro('Pix com QR desligado: ligue o Inter e cadastre a chave Pix em Configurações › Banco Inter.');
        }
        $valor = round($valor, 2);
        if ($valor < 0.01) {
            self::erro('Valor do Pix inválido.');
        }
        $a = request()?->attributes->get('aparelho');
        $p = PixCobranca::create([
            'txid' => 'PDV'.now()->format('ymd').Str::random(23),
            'valor' => $valor,
            'descricao' => $descricao ? mb_substr($descricao, 0, 140) : null,
            'aparelho_id' => $a?->id,
            'caixa_id' => Caixas::atual()->id,
        ]);
        try {
            $j = self::cliente()->pixCriar($p->txid, array_filter([
                'calendario' => ['expiracao' => self::EXPIRA_SEG],
                'valor' => ['original' => number_format($valor, 2, '.', ''), 'modalidadeAlteracao' => 0],
                'chave' => self::chave(),
                'solicitacaoPagador' => $p->descricao,
            ]));
        } catch (\Throwable $e) {
            $msg = $e instanceof InterErro ? $e->getMessage() : 'Sem conexão com o Inter.';
            $p->update(['situacao' => 'falha', 'erro' => mb_substr($msg, 0, 255)]);
            self::erro('O Inter não gerou o Pix: '.$msg);
        }
        if (empty($j['pixCopiaECola'])) {
            $p->update(['situacao' => 'falha', 'erro' => 'O Inter não devolveu o copia e cola.']);
            self::erro('O Inter não devolveu o QR do Pix. Tente de novo.');
        }
        $p->update(['situacao' => 'ativa', 'copia_cola' => $j['pixCopiaECola'], 'expira_em' => now()->addSeconds(self::EXPIRA_SEG)]);

        return $p;
    }

    /** pergunta ao Inter (no máximo a cada 2 segundos por Pix) e atualiza a situação */
    public static function atualizar(PixCobranca $p, bool $forcar = false): PixCobranca
    {
        if ($p->situacao !== 'ativa' || (! $forcar && $p->consultado_em && $p->consultado_em->gt(now()->subSeconds(2)))) {
            return $p;
        }
        try {
            $j = self::cliente()->pixConsultar($p->txid);
        } catch (\Throwable $e) {
            // falha de rede: tenta de novo na próxima consulta
            $p->update(['consultado_em' => now(), 'erro' => mb_substr($e instanceof InterErro ? $e->getMessage() : 'Sem conexão com o Inter.', 0, 255)]);

            return $p;
        }
        $status = (string) ($j['status'] ?? '');
        $dados = ['consultado_em' => now(), 'erro' => null];
        if ($status === 'CONCLUIDA') {
            $pix = collect($j['pix'] ?? []);
            $primeiro = $pix->first() ?? [];
            $dados += [
                'situacao' => 'paga',
                'valor_pago' => round((float) $pix->sum(fn ($x) => (float) ($x['valor'] ?? 0)), 2) ?: (float) $p->valor,
                'pago_em' => isset($primeiro['horario']) ? Carbon::parse($primeiro['horario'])->setTimezone(config('app.timezone')) : now(),
                'e2e_id' => isset($primeiro['endToEndId']) ? mb_substr((string) $primeiro['endToEndId'], 0, 40) : null,
                'pagador' => isset($primeiro['pagador']['nome']) ? mb_substr((string) $primeiro['pagador']['nome'], 0, 120) : null,
            ];
        } elseif (str_starts_with($status, 'REMOVIDA')) {
            $dados['situacao'] = 'cancelada';
        } elseif ($p->expira_em && $p->expira_em->lt(now()->subMinute())) {
            $dados['situacao'] = 'expirada';
        }
        $p->update($dados);
        if (($dados['situacao'] ?? null) === 'paga') {
            Atividades::registrar('pix_pdv_pago', 'Pix do PDV pago: R$ '.number_format((float) $p->valor_pago, 2, ',', '.').' · '.$p->txid);
        }

        return $p;
    }

    /** o operador desistiu: confere antes (pode ter acabado de pagar) e tira o QR do ar */
    public static function cancelar(PixCobranca $p): PixCobranca
    {
        self::atualizar($p, true);
        if ($p->situacao !== 'ativa') {
            return $p;
        }
        try {
            self::cliente()->pixRemover($p->txid);
        } catch (\Throwable) {
            // QR vence sozinho; se for pago mesmo assim, aparece em "Pix pagos sem uso" no fechamento
        }
        $p->update(['situacao' => 'cancelada']);

        return $p;
    }

    /**
     * Gancho do Pagamento criado pelo PDV com forma Pix: com pix_id, confere (pago, sem uso, mesmo valor)
     * e grava o txid; sem pix_id e com o Pix do QR ligado, marca para conferir no extrato.
     * Devolve o Pix a vincular depois de gravado o pagamento.
     */
    public static function noPagamento(Pagamento $pg): ?PixCobranca
    {
        $forma = $pg->forma_pagamento_id ? FormaPagamento::find($pg->forma_pagamento_id) : null;
        if ($forma?->tipo !== 'pix') {
            return null;
        }
        $id = (int) request()?->input('pix_id');
        if (! $id) {
            if (self::ligado()) {
                $pg->observacao = trim(($pg->observacao ? $pg->observacao.' · ' : '').'Pix sem QR: conferir no extrato');
            }

            return null;
        }
        $p = PixCobranca::lockForUpdate()->find($id);
        if (! $p) {
            self::erro('Pix não encontrado.');
        }
        if ($p->situacao === 'ativa') {
            self::atualizar($p, true);
        }
        if ($p->situacao !== 'paga') {
            self::erro('Esse Pix ainda não foi pago.');
        }
        if ($p->pagamento_id) {
            self::erro('Esse Pix já foi usado em outro recebimento.');
        }
        if (abs((float) $p->valor_pago - (float) $pg->valor) > 0.005) {
            self::erro('O Pix pago (R$ '.number_format((float) $p->valor_pago, 2, ',', '.').') não bate com o valor a receber (R$ '.number_format((float) $pg->valor, 2, ',', '.').'). Volte e confira.');
        }
        $pg->pix_txid = $p->txid;
        $pg->observacao = trim(($pg->observacao ? $pg->observacao.' · ' : '').'Pix QR'.($p->pagador ? ' de '.$p->pagador : ''));

        return $p;
    }

    /** Pix pagos no balcão que não viraram recebimento (o app fechou, a internet caiu): aparecem no fechamento */
    public static function semUso(?int $caixaId = null)
    {
        // os QR que ficaram "aguardando" (a tela saiu antes da consulta ver o pagamento): pergunta de novo ao Inter
        if ($caixaId) {
            PixCobranca::where('caixa_id', $caixaId)->where('situacao', 'ativa')->limit(20)->get()->each(fn ($p) => self::atualizar($p, true));
        }

        return PixCobranca::where('situacao', 'paga')->whereNull('pagamento_id')
            ->when($caixaId, fn ($q) => $q->where('caixa_id', $caixaId))
            ->orderBy('pago_em')->get(['id', 'txid', 'valor_pago', 'pago_em', 'pagador', 'descricao']);
    }

    public static function linha(PixCobranca $p): array
    {
        return [
            'id' => $p->id,
            'valor' => (float) $p->valor,
            'situacao' => $p->situacao,
            'copia_cola' => $p->situacao === 'ativa' ? $p->copia_cola : null,
            'expira_em' => $p->expira_em?->toIso8601String(),
            'segundos' => $p->expira_em ? max(0, now()->diffInSeconds($p->expira_em, false)) : null,
            'pagador' => $p->pagador,
            'pago_em' => $p->pago_em?->format('H:i'),
            'usado' => (bool) $p->pagamento_id,
        ];
    }
}
