<?php

namespace App\Servicos\WhatsApp;

use App\Models\Aviso;
use App\Models\Boleto;
use App\Models\Cobranca;
use App\Models\Empresa;
use App\Models\Integracao;
use App\Servicos\Atualizacoes;
use App\Servicos\Inter\Boletos;
use App\Support\Atividades;
use App\Support\Formato;
use App\Support\Telefone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Régua de cobrança pelo WhatsApp: para cada cobrança em aberto, um aviso por passo (dias antes ou
 * depois do vencimento). Cada passo sai uma vez só; cobrança paga ou cancelada para de receber.
 * Envio manual (wa.me, pela tela "Avisos") ou automático (API oficial, rotina diária).
 */
class Regua
{
    public const PADRAO = [['dias' => -3, 'ativo' => true], ['dias' => 0, 'ativo' => true], ['dias' => 3, 'ativo' => true], ['dias' => 10, 'ativo' => true]];

    /** passo que passou do dia há mais tempo que isto não é mais enviado (não despeja avisos velhos) */
    public const TOLERANCIA_DIAS = 2;

    public static function passos(?Empresa $e = null): array
    {
        $p = ($e ?? Empresa::atual())->config('whatsapp_regua') ?: self::PADRAO;

        return collect($p)->map(fn ($x) => ['dias' => (int) $x['dias'], 'ativo' => (bool) ($x['ativo'] ?? true)])->sortBy('dias')->values()->all();
    }

    public static function hora(?Empresa $e = null): string
    {
        return (string) (($e ?? Empresa::atual())->config('whatsapp_hora') ?: '09:00');
    }

    public static function automatico(?Empresa $e = null): bool
    {
        return (bool) ($e ?? Empresa::atual())->config('whatsapp_automatico');
    }

    public static function ligado(): bool
    {
        $i = Integracao::whatsapp();

        return (bool) ($i && $i->ativo && WhatsAppCliente::completa($i));
    }

    /** link público da cobrança (cria o código na primeira vez) */
    public static function link(Cobranca $c): string
    {
        if (! $c->token) {
            do {
                $t = Str::lower(Str::random(24));
            } while (Cobranca::withoutGlobalScopes()->where('token', $t)->exists());
            $c->forceFill(['token' => $t])->save();
        }

        return url('/c/'.$c->token);
    }

    /** vencimento que vale para o cliente: o do boleto em aberto, se houver; senão o da cobrança */
    public static function vencimento(Cobranca $c, ?Boleto $b = null): Carbon
    {
        return ($b?->vencimento ?? $c->vencimento)->copy()->startOfDay();
    }

    /**
     * Cria os avisos que vencem hoje (e os atrasados dentro da tolerância). Por cobrança, só o passo mais
     * recente que já chegou: ligar a régua hoje não manda três avisos de uma vez. Devolve quantos criou.
     */
    public static function gerar(): int
    {
        $passos = collect(self::passos())->where('ativo', true)->pluck('dias');
        if ($passos->isEmpty()) {
            return 0;
        }
        $hoje = today();
        $empresa = Empresa::atual();
        $cobrancas = Cobranca::with('cliente')->whereIn('situacao', ['aberta', 'parcial'])->get();
        $boletos = Boleto::whereIn('cobranca_id', $cobrancas->pluck('id'))->whereIn('situacao', Boleto::ATIVAS)->orderBy('id')->get()->keyBy('cobranca_id');
        $feitos = Aviso::whereIn('cobranca_id', $cobrancas->pluck('id'))->get()->groupBy('cobranca_id');
        $n = 0;
        foreach ($cobrancas as $c) {
            $cli = $c->cliente;
            if (! $cli || ! $cli->ativo || $cli->nao_recebe_avisos) {
                continue;
            }
            $venc = self::vencimento($c, $boletos->get($c->id));
            // o passo mais recente cujo dia já chegou
            $dias = $passos->filter(fn ($d) => $venc->copy()->addDays($d)->lte($hoje))->max();
            if ($dias === null) {
                continue;
            }
            $dia = $venc->copy()->addDays($dias);
            $ja = $feitos->get($c->id, collect());
            // já tem este passo, ou um passo posterior (régua não volta), ou passou da tolerância
            if ($ja->contains(fn ($a) => $a->dias >= $dias) || $dia->diffInDays($hoje) > self::TOLERANCIA_DIAS) {
                continue;
            }
            $valor = round(Boletos::valorEmAberto($c) - (float) $c->desconto, 2);
            if ($valor <= 0) {
                continue;
            }
            $modelo = Modelos::doPasso($dias);
            $valores = self::valores($cli->nome, $empresa->nome, $valor, $venc);
            Aviso::create([
                'cobranca_id' => $c->id, 'cliente_id' => $cli->id, 'dias' => $dias, 'previsto_para' => $dia->toDateString(), 'vencimento' => $venc->toDateString(),
                'celular' => Telefone::celular($cli->celular), 'texto' => Modelos::texto($modelo, $valores, self::link($c)),
            ]);
            $n++;
        }

        return $n;
    }

    /** valores de {{1}}..{{4}} dos modelos */
    public static function valores(string $nome, string $empresa, float $valor, Carbon $venc): array
    {
        $primeiro = mb_convert_case(strtok(trim($nome), ' ') ?: 'cliente', MB_CASE_TITLE);

        return [$primeiro, $empresa, Formato::moeda($valor), $venc->format('d/m/Y')];
    }

    /** por que este aviso não pode sair (null = pode) */
    public static function impedimento(Aviso $a): ?string
    {
        $c = $a->cobranca;
        if (! $c || ! in_array($c->situacao, ['aberta', 'parcial'], true)) {
            return 'A cobrança não está mais em aberto.';
        }
        if ($a->cliente?->nao_recebe_avisos) {
            return 'O cliente está marcado como "não recebe avisos".';
        }
        if (! Telefone::celular($a->cliente?->celular)) {
            return 'Celular: '.mb_strtolower(Atualizacoes::problemaCelular($a->cliente?->celular) ?? 'inválido').'.';
        }

        return null;
    }

    /** cobranças resolvidas: os avisos pendentes delas são cancelados */
    public static function cancelarResolvidos(): int
    {
        $ids = Aviso::where('situacao', 'pendente')->whereHas('cobranca', fn ($q) => $q->whereNotIn('situacao', ['aberta', 'parcial']))->pluck('id');
        Aviso::whereIn('id', $ids)->update(['situacao' => 'cancelado']);

        return $ids->count();
    }

    /** situações de aviso que já saiu (dá para reenviar) */
    public const SAIU = ['enviado', 'entregue', 'lido'];

    /** envio manual: o operador abriu o WhatsApp com o texto pronto (também reenvio) */
    public static function marcarManual(Aviso $a): void
    {
        if (! in_array($a->situacao, ['pendente', 'falha', ...self::SAIU], true)) {
            return;
        }
        $a->update(['situacao' => 'enviado', 'canal' => 'manual', 'celular' => Telefone::celular($a->cliente->celular), 'enviado_em' => now(), 'enviado_por' => Auth::id(), 'erro' => null]);
    }

    /** envio pela API oficial (modelo aprovado). $reenviar: manda de novo um aviso que já saiu. Devolve true se saiu. */
    public static function enviar(Aviso $a, ?WhatsAppCliente $api = null, bool $reenviar = false): bool
    {
        if (! in_array($a->situacao, $reenviar ? ['pendente', 'falha', ...self::SAIU] : ['pendente', 'falha'], true)) {
            return false;
        }
        if ($motivo = self::impedimento($a)) {
            if (in_array($a->situacao, self::SAIU, true)) {
                $a->setAttribute('erro', $motivo);

                return false;
            }
            $a->update(['situacao' => in_array($a->cobranca?->situacao, ['aberta', 'parcial'], true) ? 'falha' : 'cancelado', 'erro' => mb_substr($motivo, 0, 255)]);

            return false;
        }
        $c = $a->cobranca;
        $celular = Telefone::celular($a->cliente->celular);
        $valor = round(Boletos::valorEmAberto($c) - (float) $c->desconto, 2);
        $modelo = Modelos::doPasso($a->dias);
        $valores = self::valores($a->cliente->nome, Empresa::atual()->nome, $valor, $a->vencimento);
        self::link($c);
        try {
            $api ??= new WhatsAppCliente(Integracao::whatsapp() ?? throw new WhatsAppErro('WhatsApp não configurado.'));
            $id = $api->enviarModelo($celular, $modelo, $valores, $c->token);
        } catch (WhatsAppErro $e) {
            if (in_array($a->situacao, self::SAIU, true)) {
                $a->setAttribute('erro', mb_substr($e->getMessage(), 0, 255)); // reenvio falhou: o aviso já tinha saído, fica como estava

                return false;
            }
            $a->update(['situacao' => 'falha', 'canal' => 'api', 'celular' => $celular, 'erro' => mb_substr($e->getMessage(), 0, 255)]);

            return false;
        }
        $a->update(['situacao' => 'enviado', 'canal' => 'api', 'celular' => $celular, 'mensagem_id' => $id ?: null, 'texto' => Modelos::texto($modelo, $valores, self::link($c)),
            'enviado_em' => now(), 'enviado_por' => Auth::id(), 'erro' => null]);

        return true;
    }

    /**
     * Rotina (a cada 10 minutos): cancela avisos de cobranças resolvidas e cria os do dia. Com a API ligada,
     * o envio automático marcado e depois do horário escolhido, envia os pendentes. [criados, enviados, falhas]
     */
    public static function rotina(): array
    {
        self::cancelarResolvidos();
        $criados = self::gerar();
        $enviados = $falhas = 0;
        $agora = now();
        if (self::ligado() && self::automatico() && $agora->format('H:i') >= self::hora() && $agora->format('H:i') < '20:00') {
            $api = new WhatsAppCliente(Integracao::whatsapp());
            foreach (Aviso::with(['cobranca', 'cliente'])->where('situacao', 'pendente')->where('previsto_para', '<=', today())->orderBy('id')->limit(200)->get() as $a) {
                self::enviar($a, $api) ? $enviados++ : $falhas++;
            }
            if ($enviados || $falhas) {
                Atividades::registrar('whatsapp_regua', "régua de cobrança: {$enviados} aviso(s) enviado(s)".($falhas ? ", {$falhas} com falha" : ''));
            }
        }

        return [$criados, $enviados, $falhas];
    }

    /** status de entrega vindo do webhook da Meta */
    public static function atualizarStatus(string $mensagemId, string $status, ?string $erro = null): void
    {
        $a = Aviso::where('mensagem_id', $mensagemId)->first();
        if (! $a) {
            return;
        }
        $ordem = ['enviado' => 1, 'entregue' => 2, 'lido' => 3];
        $novo = ['sent' => 'enviado', 'delivered' => 'entregue', 'read' => 'lido', 'failed' => 'falha'][$status] ?? null;
        if ($novo === 'falha') {
            $a->update(['situacao' => 'falha', 'erro' => mb_substr((string) $erro, 0, 255) ?: 'A Meta não conseguiu entregar.']);
        } elseif ($novo && ($ordem[$novo] ?? 0) > ($ordem[$a->situacao] ?? 0)) {
            $a->update(['situacao' => $novo]);
        }
    }
}
