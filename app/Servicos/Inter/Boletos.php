<?php

namespace App\Servicos\Inter;

use App\Models\Boleto;
use App\Models\Cobranca;
use App\Models\Empresa;
use App\Models\FormaPagamento;
use App\Models\Integracao;
use App\Models\OrdemServico;
use App\Servicos\Baixa;
use App\Support\Atividades;
use App\Support\Formato;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Boleto com Pix do Inter a partir de uma cobrança:
 * emitir → consulta periódica (inter:consultar) → pago: baixa nas ordens da cobrança pelo serviço único de baixa.
 * Cobrança paga por outro meio, cancelada ou levada para a seguinte: o boleto é cancelado no Inter
 * (o cliente não paga duas vezes). Regras de multa, juros, desconto e baixa em Configurações › Banco Inter.
 */
class Boletos
{
    /** regras padrão do boleto (configuracoes da empresa) */
    public const PADRAO = [
        'boleto_multa_tipo' => 'nenhuma',      // nenhuma | percentual | valor
        'boleto_multa_valor' => 0,
        'boleto_juros_tipo' => 'nenhum',       // nenhum | percentual_mes | valor_dia
        'boleto_juros_valor' => 0,
        'boleto_desconto_tipo' => 'nenhum',    // nenhum | percentual | valor
        'boleto_desconto_valor' => 0,
        'boleto_desconto_dias' => 0,           // (não usado: o desconto vale até o vencimento da mensalidade)
        'boleto_baixa_dias' => 30,             // dias depois do vencimento para o Inter baixar o boleto (0 a 60)
    ];

    private static function erro(string $msg): never
    {
        throw ValidationException::withMessages(['boleto' => $msg]);
    }

    /** grupos de regra que o cliente pode ter particulares (cada um substitui o da empresa inteiro) */
    public const GRUPOS = [
        'multa' => ['boleto_multa_tipo', 'boleto_multa_valor'],
        'juros' => ['boleto_juros_tipo', 'boleto_juros_valor'],
        'desconto' => ['boleto_desconto_tipo', 'boleto_desconto_valor'],
        'baixa' => ['boleto_baixa_dias'],
    ];

    /** regras da empresa; com cliente, as particulares dele substituem as da empresa */
    public static function regras(?Empresa $empresa = null, $cliente = null): array
    {
        $cfg = ($empresa ?? Empresa::atual())->configuracoes ?? [];
        $r = [];
        foreach (self::PADRAO as $k => $padrao) {
            $r[$k] = $cfg[$k] ?? $padrao;
        }
        foreach ((array) ($cliente?->regras_boleto ?? []) as $k => $v) {
            if (array_key_exists($k, self::PADRAO)) {
                $r[$k] = $v;
            }
        }

        return $r;
    }

    /**
     * As ordens têm a primeira mensalidade do cadastro (ou uma proporcional)? Então o boleto sai,
     * por padrão, sem as regras (multa, juros, desconto): o desconto de pontualidade, por exemplo,
     * não vale para a primeira cobrança.
     */
    public static function temPrimeiraMensalidade(iterable $ordens): bool
    {
        foreach ($ordens as $o) {
            if ($o->tipo !== 'mensalidade' || $o->situacao === 'cancelada') {
                continue;
            }
            if (str_starts_with((string) $o->descricao, 'Mensalidade proporcional')) {
                return true;
            }
            if ($o->contrato_id && ! OrdemServico::where('tipo', 'mensalidade')->where('contrato_id', $o->contrato_id)
                ->where('situacao', '<>', 'cancelada')->where('numero', '<', $o->numero)->exists()) {
                return true;
            }
        }

        return false;
    }

    /** sugestão para a cobrança: false = gerar o boleto sem as regras */
    public static function aplicarRegrasSugerido(Cobranca $cob): bool
    {
        return ! self::temPrimeiraMensalidade(OrdemServico::where('cobranca_id', $cob->id)->where('situacao', 'aberta')->get());
    }

    /** prazo do boleto: dias depois do vencimento da mensalidade (Configurações gerais › Cobrança) */
    public static function prazo(?Empresa $empresa = null): int
    {
        return max(0, min(30, (int) (($empresa ?? Empresa::atual())->configuracoes['boleto_prazo_dias'] ?? 0)));
    }

    /**
     * Desconto de pontualidade: só sobre as mensalidades da cobrança que ainda não venceram
     * (mensalidade atrasada trazida como saldo anterior não ganha); % sobre a soma delas ou
     * R$ fixo por mensalidade. Vale até o vencimento da cobrança (o dia combinado com o cliente).
     */
    public static function descontoPontualidade(Cobranca $cob, array $regras): float
    {
        $tipo = $regras['boleto_desconto_tipo'] ?? 'nenhum';
        $valor = (float) ($regras['boleto_desconto_valor'] ?? 0);
        if ($tipo === 'nenhum' || $valor <= 0 || $cob->vencimento->isBefore(today())) {
            return 0.0;
        }
        $mens = OrdemServico::abertas()->where('cobranca_id', $cob->id)->where('tipo', 'mensalidade')
            ->where(fn ($q) => $q->whereNull('vencimento')->orWhere('vencimento', '>=', today()->toDateString()))->get(['valor']);
        if ($mens->isEmpty()) {
            return 0.0;
        }
        $base = (float) $mens->sum('valor');
        $d = $tipo === 'percentual' ? $base * min($valor, 100) / 100 : $valor * $mens->count();

        return round(min($d, $base), 2);
    }

    /** regras sem multa, juros e desconto (a baixa automática continua) */
    public static function semRegras(array $regras): array
    {
        return array_merge($regras, ['boleto_multa_tipo' => 'nenhuma', 'boleto_juros_tipo' => 'nenhum', 'boleto_desconto_tipo' => 'nenhum', 'sem_regras' => true]);
    }

    public static function integracao(): Integracao
    {
        $i = Integracao::inter();
        if (! $i || ! $i->ativo || ! $i->completa()) {
            self::erro('Banco Inter não está ligado: configure e teste em Configurações › Banco Inter.');
        }

        return $i;
    }

    public static function ativo(Cobranca $cob): ?Boleto
    {
        return Boleto::where('cobranca_id', $cob->id)->whereIn('situacao', Boleto::ATIVAS)->latest('id')->first();
    }

    /** o que ainda falta pagar da cobrança (ordens dela em aberto; créditos ligados a ela abatem) */
    public static function valorEmAberto(Cobranca $cob): float
    {
        return round((float) OrdemServico::abertas()->where('cobranca_id', $cob->id)->sum(DB::raw(OrdemServico::SQL_VALOR)), 2);
    }

    /** dados do cliente que o Inter exige; devolve a lista do que falta (vazia = pode emitir) */
    public static function faltaNoCadastro($cliente): array
    {
        $doc = preg_replace('/\D/', '', (string) $cliente->documento);
        $falta = [];
        if (! in_array(strlen($doc), [11, 14], true)) {
            $falta[] = 'CPF ou CNPJ';
        }
        if (strlen(preg_replace('/\D/', '', (string) $cliente->cep)) !== 8) {
            $falta[] = 'CEP';
        }
        foreach (['logradouro' => 'endereço', 'cidade' => 'cidade', 'uf' => 'UF'] as $campo => $nome) {
            if (trim((string) $cliente->{$campo}) === '') {
                $falta[] = $nome;
            }
        }

        return $falta;
    }

    /** corpo da emissão no formato da API cobrança v3 */
    public static function montar(Boleto $b, Cobranca $cob, array $regras, string $instrucoes = ''): array
    {
        $c = $cob->cliente;
        $doc = preg_replace('/\D/', '', (string) $c->documento);
        $fone = preg_replace('/\D/', '', (string) ($c->celular ?: $c->telefone));
        $fone = strlen($fone) > 11 && str_starts_with($fone, '55') ? substr($fone, 2) : $fone;
        $pagador = array_filter([
            'cpfCnpj' => $doc,
            'tipoPessoa' => strlen($doc) === 14 ? 'JURIDICA' : 'FISICA',
            'nome' => mb_substr(trim($c->nome), 0, 100),
            'endereco' => mb_substr(trim($c->logradouro), 0, 100),
            'numero' => mb_substr(trim((string) $c->numero), 0, 10),
            'complemento' => mb_substr(trim((string) $c->complemento), 0, 30),
            'bairro' => mb_substr(trim((string) $c->bairro), 0, 60),
            'cidade' => mb_substr(trim($c->cidade), 0, 60),
            'uf' => mb_strtoupper(trim($c->uf)),
            'cep' => preg_replace('/\D/', '', (string) $c->cep),
            'email' => mb_substr(trim((string) $c->email), 0, 50),
            'ddd' => strlen($fone) >= 10 ? substr($fone, 0, 2) : '',
            'telefone' => strlen($fone) >= 10 ? substr($fone, 2, 9) : '',
        ], fn ($v) => $v !== '');

        $dados = [
            'seuNumero' => $b->seu_numero,
            'valorNominal' => (float) $b->valor,
            'dataVencimento' => $b->vencimento->toDateString(),
            'numDiasAgenda' => max(0, min(60, (int) $regras['boleto_baixa_dias'])),
            'pagador' => $pagador,
        ];
        $v = fn ($k) => round((float) $regras[$k], 2);
        if ($regras['boleto_multa_tipo'] === 'percentual' && $v('boleto_multa_valor') > 0) {
            $dados['multa'] = ['codigo' => 'PERCENTUAL', 'taxa' => $v('boleto_multa_valor')];
        } elseif ($regras['boleto_multa_tipo'] === 'valor' && $v('boleto_multa_valor') > 0) {
            $dados['multa'] = ['codigo' => 'VALORFIXO', 'valor' => $v('boleto_multa_valor')];
        }
        if ($regras['boleto_juros_tipo'] === 'percentual_mes' && $v('boleto_juros_valor') > 0) {
            $dados['mora'] = ['codigo' => 'TAXAMENSAL', 'taxa' => $v('boleto_juros_valor')];
        } elseif ($regras['boleto_juros_tipo'] === 'valor_dia' && $v('boleto_juros_valor') > 0) {
            $dados['mora'] = ['codigo' => 'VALORDIA', 'valor' => $v('boleto_juros_valor')];
        }
        // desconto de pontualidade: valor já calculado só sobre as mensalidades (descontoPontualidade),
        // válido até o vencimento da mensalidade = N dias antes do vencimento do boleto
        if (($regras['desconto_calculado'] ?? 0) > 0) {
            $dados['desconto'] = ['codigo' => 'VALORFIXODATAINFORMADA', 'valor' => round((float) $regras['desconto_calculado'], 2), 'quantidadeDias' => max(0, (int) ($regras['desconto_ate_dias'] ?? 0))];
        }

        // mensagem do boleto: número da cobrança + instruções da empresa (até 5 linhas de 78)
        $linhas = ['Cobrança nº '.$cob->numero.' · referência '.Carbon::parse($cob->competencia.'-01')->format('m/Y')];
        foreach (preg_split('/\R/', trim($instrucoes)) as $l) {
            foreach (mb_str_split(trim($l), 78) ?: [] as $pedaco) {
                $linhas[] = $pedaco;
            }
        }
        $linhas = array_slice(array_values(array_filter($linhas, fn ($l) => $l !== '')), 0, 5);
        foreach ($linhas as $i => $l) {
            $dados['mensagem']['linha'.($i + 1)] = $l;
        }

        return $dados;
    }

    /**
     * Emite o boleto da cobrança. $aplicarRegras: null = automático (sem regras quando há primeira
     * mensalidade ou proporcional). Erro de cadastro ou do Inter: ValidationException com a mensagem.
     */
    public static function emitir(Cobranca $cob, ?InterCliente $api = null, ?bool $aplicarRegras = null, ?Carbon $vencimentoEscolhido = null): Boleto
    {
        $integracao = self::integracao();
        if (! in_array($cob->situacao, ['aberta', 'parcial'], true)) {
            self::erro("Cobrança {$cob->numero}: só dá para gerar boleto de cobrança em aberto.");
        }
        if (self::ativo($cob)) {
            self::erro("Cobrança {$cob->numero} já tem boleto. Cancele o atual para gerar outro.");
        }
        $cob->loadMissing('cliente');
        if ($falta = self::faltaNoCadastro($cob->cliente)) {
            self::erro("{$cob->cliente->nome}: o Inter exige ".implode(', ', $falta).' no cadastro do cliente.');
        }
        $valor = self::valorEmAberto($cob);
        if ($valor < 2.5) {
            self::erro("Cobrança {$cob->numero}: o Inter só emite boleto a partir de R$ 2,50.");
        }
        $empresa = Empresa::atual();
        // o boleto vence alguns dias depois do vencimento combinado com o cliente (prazo da empresa)
        $prazo = self::prazo($empresa);
        $vencimento = $cob->vencimento->copy()->addDays($prazo);
        if ($vencimentoEscolhido) {
            // cobrança atrasada (ou vencimento combinado na hora): o boleto vence na data escolhida
            $vencimento = $vencimentoEscolhido->copy()->startOfDay();
            if ($vencimento->isBefore(today()) || $vencimento->isAfter(today()->addDays(60))) {
                self::erro('O vencimento do boleto precisa ser entre hoje e '.today()->addDays(60)->format('d/m/Y').'.');
            }
            $prazo = max(0, (int) $cob->vencimento->copy()->startOfDay()->diffInDays($vencimento, false));
        } elseif ($vencimento->isBefore(today())) {
            self::erro("Cobrança {$cob->numero}: o vencimento do boleto ({$vencimento->format('d/m/Y')}) já passou. Escolha um novo vencimento para o boleto.");
        }
        $regras = self::regras($empresa, $cob->cliente);
        if (! ($aplicarRegras ?? self::aplicarRegrasSugerido($cob))) {
            $regras = self::semRegras($regras);
        }
        $regras['desconto_calculado'] = self::descontoPontualidade($cob, $regras);
        $regras['desconto_ate_dias'] = $prazo;
        $regras['prazo_dias'] = $prazo;
        $seq = Boleto::where('cobranca_id', $cob->id)->count() + 1;
        $b = Boleto::create([
            'cobranca_id' => $cob->id,
            'cliente_id' => $cob->cliente_id,
            'seu_numero' => mb_substr($cob->numero.'-'.$seq, 0, 15),
            'valor' => $valor,
            'vencimento' => $vencimento,
            'regras' => $regras,
            'criado_por' => Auth::id(),
        ]);
        $api ??= new InterCliente($integracao);
        try {
            $b->update(['codigo_solicitacao' => $api->emitir(self::montar($b, $cob, $regras, (string) $empresa->config('cobranca_instrucoes')))]);
        } catch (InterErro $e) {
            $b->update(['situacao' => 'falha', 'erro' => mb_substr($e->getMessage(), 0, 255)]);
            self::erro("Cobrança {$cob->numero}: o Inter recusou o boleto — ".$e->getMessage());
        }
        Atividades::registrar('boleto_emitido', 'cobrança '.$cob->numero.' de '.$cob->cliente->nome.': '.Formato::moeda($valor).', vencimento '.$b->vencimento->format('d/m/Y'));
        // a linha digitável e o Pix ficam prontos em segundos: tenta algumas vezes antes de devolver
        foreach ([2, 3, 4] as $espera) {
            app()->runningUnitTests() || sleep($espera);
            try {
                self::atualizar($b, $api);
            } catch (Throwable) {
                // fica "em emissão"; a consulta periódica completa depois
            }
            if ($b->situacao !== 'em_processamento') {
                break;
            }
        }

        return $b->refresh();
    }

    private const SITUACAO_INTER = [
        'EM_PROCESSAMENTO' => 'em_processamento', 'A_RECEBER' => 'a_receber', 'ATRASADO' => 'atrasado', 'PROTESTO' => 'atrasado',
        'RECEBIDO' => 'recebido', 'MARCADO_RECEBIDO' => 'recebido', 'CANCELADO' => 'cancelado', 'EXPIRADO' => 'expirado', 'FALHA_EMISSAO' => 'falha',
    ];

    /** consulta o boleto no Inter e atualiza; pago: registra o pagamento (uma vez só) */
    public static function atualizar(Boleto $b, ?InterCliente $api = null): Boleto
    {
        if (! $b->codigo_solicitacao) {
            return $b;
        }
        $api ??= new InterCliente(self::integracao());
        $j = $api->consultar($b->codigo_solicitacao);
        $c = $j['cobranca'] ?? [];
        $sit = self::SITUACAO_INTER[$c['situacao'] ?? ''] ?? $b->situacao;
        $b->fill([
            'situacao' => $sit,
            'nosso_numero' => $j['boleto']['nossoNumero'] ?? $b->nosso_numero,
            'linha_digitavel' => $j['boleto']['linhaDigitavel'] ?? $b->linha_digitavel,
            'codigo_barras' => $j['boleto']['codigoBarras'] ?? $b->codigo_barras,
            'pix_copia_cola' => $j['pix']['pixCopiaECola'] ?? $b->pix_copia_cola,
            'txid' => $j['pix']['txid'] ?? $b->txid,
            'consultado_em' => now(),
        ]);
        if ($sit === 'falha') {
            $b->erro = 'O Inter não conseguiu emitir o boleto. Confira o cadastro do cliente e gere de novo.';
        }
        if ($sit === 'recebido') {
            $b->valor_recebido = isset($c['valorTotalRecebido']) ? (float) $c['valorTotalRecebido'] : $b->valor;
            $b->recebido_em = $c['dataSituacao'] ?? today()->toDateString();
            $b->recebido_por = $c['origemRecebimento'] ?? null;
        }
        $b->save();
        if ($sit === 'recebido' && ! $b->pagamento_id) {
            if (($c['situacao'] ?? '') === 'MARCADO_RECEBIDO') {
                $b->update(['erro' => 'Marcado como recebido direto no Inter (sem dinheiro pelo boleto): dê a baixa em Receber contas.']);
            } else {
                self::registrarPagamento($b);
            }
        }

        return $b;
    }

    /**
     * Baixa do boleto pago nas ordens da cobrança. O que veio a mais até a multa/juros vira "Multa e juros
     * do boleto" (pago); o que veio a menos até o desconto da regra vira desconto. Sobra além disso
     * (ex.: conta já paga por outro meio) vira crédito do cliente; falta além do desconto, débito.
     */
    public static function registrarPagamento(Boleto $b): void
    {
        DB::transaction(function () use ($b) {
            $b = Boleto::whereKey($b->id)->lockForUpdate()->first();
            if ($b->pagamento_id) {
                return;
            }
            $cob = $b->cobranca()->with('cliente')->first();
            $forma = FormaPagamento::where('tipo', $b->recebido_por === 'PIX' ? 'pix' : 'boleto')->orderByDesc('ativa')->orderBy('ordem')->first()
                ?? FormaPagamento::where('tipo', 'boleto')->first();
            $valor = round((float) $b->valor_recebido, 2);
            $ordens = OrdemServico::abertas()->where('cobranca_id', $cob->id)->where('tipo', '<>', 'credito')->pluck('id')->all();
            $creditos = round((float) OrdemServico::abertas()->where('cliente_id', $cob->cliente_id)->where('tipo', 'credito')->sum('valor'), 2);
            $devido = round((float) OrdemServico::whereIn('id', $ordens)->sum('valor') - $creditos, 2);
            $dif = round($valor - $devido, 2);
            $encargos = max(0, round($valor - (float) $b->valor, 2));
            $desconto = max(0, round((float) $b->valor - $valor, 2));

            $d = [
                'ordens' => $ordens, 'valor' => $valor, 'forma_pagamento_id' => $forma?->id, 'recebido_em' => $b->recebido_em->toDateString(),
                'origem' => 'inter', 'sistema' => true, 'sem_ordens' => ! $ordens,
                'observacao' => 'Boleto '.$b->seu_numero.' (Inter'.($b->recebido_por === 'PIX' ? ', pago com Pix' : '').')',
            ];
            if ($dif > 0) {
                $d['sobra'] = $encargos > 0 ? 'encargos' : 'credito';
                $d['encargos'] = min($dif, $encargos);
                $d['motivo'] = $ordens ? 'Pagou o boleto com valor acima do devido' : 'Boleto pago com a cobrança já quitada por outro meio';
            } elseif ($dif < 0) {
                $d['falta'] = -$dif <= $desconto + 0.01 ? 'desconto' : 'debito';
                $d['motivo'] = $d['falta'] === 'desconto' ? 'Desconto do boleto (pagamento antecipado)' : 'Boleto pago com valor abaixo do devido';
            }
            [$pagamento] = Baixa::receber($cob->cliente, $d);
            $b->update(['pagamento_id' => $pagamento->id, 'erro' => $ordens ? null : 'A cobrança já estava paga quando o boleto foi pago: o valor virou crédito do cliente.']);
        });
    }

    /** cancela no Inter e marca aqui */
    public static function cancelar(Boleto $b, string $motivo, ?InterCliente $api = null): void
    {
        if (! $b->ativo()) {
            self::erro('Esse boleto não está mais em aberto.');
        }
        if ($b->codigo_solicitacao) {
            $api ??= new InterCliente(self::integracao());
            try {
                $api->cancelar($b->codigo_solicitacao, $motivo);
            } catch (InterErro $e) {
                // já pago ou já cancelado lá: a consulta mostra a situação real
                try {
                    self::atualizar($b, $api);
                } catch (Throwable) {
                }
                if (! $b->refresh()->ativo()) {
                    return;
                }
                self::erro('O Inter não cancelou o boleto: '.$e->getMessage());
            }
        }
        $b->update(['situacao' => 'cancelado', 'cancelado_em' => now(), 'motivo_cancelamento' => mb_substr($motivo, 0, 50)]);
        Atividades::registrar('boleto_cancelado', 'boleto '.$b->seu_numero.' ('.Formato::moeda($b->valor).'): '.$motivo);
    }

    /**
     * Rotina da consulta periódica: atualiza os boletos em aberto e cancela os de cobranças
     * já resolvidas por outro caminho. Devolve [consultados, pagos, cancelados, erros].
     */
    public static function rotina(?InterCliente $api = null, bool $todos = false): array
    {
        $integracao = Integracao::inter();
        if (! $integracao || ! $integracao->ativo || ! $integracao->completa()) {
            return [0, 0, 0, []];
        }
        $api ??= new InterCliente($integracao);
        $consultados = $pagos = 0;
        $erros = [];

        $q = Boleto::whereIn('situacao', Boleto::ATIVAS)->whereNotNull('codigo_solicitacao');
        if (! $todos) {
            $q->where(fn ($w) => $w->whereNull('consultado_em')->orWhere('consultado_em', '<', now()->subMinutes(9)));
        }
        foreach ($q->orderBy('consultado_em')->limit(100)->get() as $b) {
            try {
                self::atualizar($b, $api);
                $consultados++;
                $pagos += $b->situacao === 'recebido' ? 1 : 0;
            } catch (Throwable $e) {
                $erros[] = 'boleto '.$b->seu_numero.': '.$e->getMessage();
            }
        }

        [$cancelados, $errosCancelar] = self::cancelarResolvidos($api);

        return [$consultados, $pagos, $cancelados, array_merge($erros, $errosCancelar)];
    }

    /**
     * Cobrança paga por outro meio, cancelada ou levada para a seguinte: o boleto dela não pode mais
     * ser pago e é cancelado no Inter. Roda na consulta periódica e logo depois de emitir, cancelar
     * ou receber (sem Inter configurado, não faz nada). Devolve [cancelados, erros].
     */
    public static function cancelarResolvidos(?InterCliente $api = null): array
    {
        $resolvidas = Boleto::whereIn('situacao', Boleto::ATIVAS)->whereHas('cobranca', fn ($c) => $c->whereIn('situacao', ['paga', 'cancelada', 'levada']))->with('cobranca')->get();
        if ($resolvidas->isEmpty()) {
            return [0, []];
        }
        $integracao = Integracao::inter();
        if (! $integracao || ! $integracao->completa()) {
            return [0, []];
        }
        $api ??= new InterCliente($integracao);
        $cancelados = 0;
        $erros = [];
        foreach ($resolvidas as $b) {
            $motivo = match ($b->cobranca->situacao) {
                'paga' => 'Cobrança paga por outro meio',
                'cancelada' => 'Cobrança cancelada',
                default => 'Levada para a cobrança seguinte',
            };
            try {
                self::cancelar($b, $motivo, $api);
                $cancelados += $b->refresh()->situacao === 'cancelado' ? 1 : 0;
            } catch (Throwable $e) {
                $erros[] = 'boleto '.$b->seu_numero.': '.$e->getMessage();
            }
        }

        return [$cancelados, $erros];
    }
}
