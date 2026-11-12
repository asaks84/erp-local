<?php

namespace App\Servicos;

use App\Models\Cartao;
use App\Models\CartaoRegra;
use App\Models\Despesa;
use App\Models\FaturaCartao;
use App\Models\FaturaItem;
use App\Support\Atividades;
use App\Support\Formato;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Fatura de cartão de crédito: lê o arquivo do banco (OFX, ou CSV do Nubank), cria a fatura com as
 * compras e uma conta a pagar pelo total. As compras são classificadas no plano de contas; é a compra
 * que conta no resultado do mês (a conta a pagar só conta no caixa).
 * Sugestão de classificação: parcela seguinte de uma compra já classificada; regra do estabelecimento
 * ("fixo" = sempre a mesma conta; marketplaces e lojas classificadas de jeitos diferentes = perguntar).
 * Cartão pessoal do sócio: só entram as compras marcadas como da empresa; a conta sai como paga pelo
 * sócio (conta no resultado, não sai do caixa da empresa).
 */
class Cartoes
{
    /** lojas que vendem de tudo: a conta é perguntada a cada compra */
    public const MARKETPLACES = ['mercadolivre', 'mercado livre', 'mercadopago', 'amazon', 'amazonmktplc', 'shopee', 'magalu', 'magazine luiza', 'aliexpress',
        'shein', 'americanas', 'kabum', 'casas bahia', 'casasbahia', 'temu', 'submarino', 'carrefour', 'leroy merlin', 'leroymerlin'];

    private static function erro(string $campo, string $msg): never
    {
        throw ValidationException::withMessages([$campo => $msg]);
    }

    // ---------------------------------------------------------------- leitura do arquivo

    /** "Mercadolivre*Lojayama - Parcela 2/2" -> [chave do estabelecimento, parcela, parcelas] */
    public static function estabelecimento(string $original): array
    {
        $parcela = $parcelas = null;
        if (preg_match('/\s*-?\s*Parcela\s+(\d{1,2})\s*\/\s*(\d{1,2})\s*$/iu', $original, $m)) {
            [$parcela, $parcelas] = [(int) $m[1], (int) $m[2]];
            $original = mb_substr($original, 0, mb_strlen($original) - mb_strlen($m[0]));
        }
        $chave = mb_substr(trim(preg_replace('/\s+/', ' ', mb_strtolower($original))), 0, 100);

        return [$chave, $parcela, $parcelas];
    }

    public static function marketplace(string $chave): bool
    {
        $inicio = trim(preg_split('/[*\-]/', $chave)[0]);
        foreach (self::MARKETPLACES as $m) {
            if (str_starts_with($inicio, $m)) {
                return true;
            }
        }

        return false;
    }

    private static function data(string $ofx): ?string
    {
        return preg_match('/^(\d{4})(\d{2})(\d{2})/', trim($ofx), $m) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
    }

    private static function campo(string $bloco, string $tag): ?string
    {
        return preg_match('/<'.$tag.'>([^<\r\n]*)/i', $bloco, $m) ? trim(html_entity_decode($m[1])) : null;
    }

    /**
     * Lê o arquivo. Devolve identificador do cartão, início, fechamento, vencimento sugerido (pelo nome
     * do arquivo do Nubank, "Nubank_2026-10-23"), saldo do arquivo e as compras (pagamento da fatura sai).
     *
     * @return array{identificador:?string,inicio:?string,fechamento:string,vencimento:?string,saldo:?float,itens:array}
     */
    public static function lerArquivo(string $conteudo, string $nome): array
    {
        if (! mb_check_encoding($conteudo, 'UTF-8')) {
            $conteudo = mb_convert_encoding($conteudo, 'UTF-8', 'Windows-1252');
        }
        $venc = preg_match('/(20\d{2})-(\d{2})-(\d{2})/', $nome, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
        $itens = [];
        if (stripos($conteudo, '<OFX>') !== false) {
            foreach (array_slice(preg_split('/<STMTTRN>/i', $conteudo), 1) as $b) {
                $b = preg_split('/<\/STMTTRN>/i', $b)[0];
                $memo = self::campo($b, 'MEMO') ?: self::campo($b, 'NAME') ?: '';
                $valor = (float) str_replace(',', '.', (string) self::campo($b, 'TRNAMT'));
                if ($valor > 0 && preg_match('/^pagamento/iu', $memo)) {
                    continue; // pagamento da fatura anterior
                }
                $itens[] = ['fitid' => self::campo($b, 'FITID'), 'data' => self::data((string) self::campo($b, 'DTPOSTED')), 'original' => $memo, 'valor' => round(-$valor, 2)];
            }
            $saldo = self::campo($conteudo, 'BALAMT');
            $r = [
                'identificador' => self::campo($conteudo, 'ACCTID'),
                'inicio' => self::data((string) self::campo($conteudo, 'DTSTART')),
                'fechamento' => self::data((string) self::campo($conteudo, 'DTEND')),
                'saldo' => $saldo !== null ? round(-(float) str_replace(',', '.', $saldo), 2) : null,
            ];
        } else {
            // CSV do Nubank: date,title,amount (compra positiva; pagamento e estorno negativos)
            $linhas = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $conteudo))));
            $cab = str_getcsv(mb_strtolower(array_shift($linhas) ?? ''));
            [$iData, $iTit, $iVal] = [array_search('date', $cab, true), array_search('title', $cab, true), array_search('amount', $cab, true)];
            if ($iData === false || $iTit === false || $iVal === false) {
                self::erro('arquivo', 'Arquivo não reconhecido. Use o OFX do banco ou o CSV do Nubank (colunas date, title, amount).');
            }
            foreach ($linhas as $n => $l) {
                $c = str_getcsv($l);
                $valor = (float) ($c[$iVal] ?? 0);
                $titulo = trim($c[$iTit] ?? '');
                if ($valor < 0 && preg_match('/^pagamento/iu', $titulo)) {
                    continue;
                }
                $itens[] = ['fitid' => 'csv-'.md5($c[$iData].'|'.$titulo.'|'.$valor.'|'.$n), 'data' => $c[$iData], 'original' => $titulo, 'valor' => round($valor, 2)];
            }
            $datas = array_filter(array_column($itens, 'data'));
            $r = ['identificador' => null, 'inicio' => $datas ? min($datas) : null, 'fechamento' => $datas ? max($datas) : null, 'saldo' => null];
        }
        if (! $itens) {
            self::erro('arquivo', 'Não achei compras neste arquivo.');
        }
        $r['fechamento'] ??= max(array_column($itens, 'data'));
        $r['vencimento'] = $venc;
        $r['itens'] = array_map(fn ($i) => $i + ['original' => mb_substr($i['original'], 0, 150)], $itens);

        return $r;
    }

    // ---------------------------------------------------------------- importar e classificar

    /** sugestão para uma compra: parcela anterior já classificada; senão a regra do estabelecimento */
    private static function sugerir(string $chave, ?int $parcela, ?int $parcelas, float $valor, Cartao $cartao): array
    {
        if ($parcela && $parcela > 1) {
            $anterior = FaturaItem::whereHas('fatura', fn ($f) => $f->where('cartao_id', $cartao->id))
                ->where('estabelecimento', $chave)->where('parcelas', $parcelas)->where('parcela', '<', $parcela)
                ->whereBetween('valor', [$valor - 0.01, $valor + 0.01])->orderByDesc('parcela')->first();
            if ($anterior) {
                return ['conta_id' => $anterior->conta_id, 'descricao' => $anterior->descricao, 'da_empresa' => $anterior->da_empresa];
            }
        }
        $regra = CartaoRegra::where('estabelecimento', $chave)->first();
        $conta = $regra && $regra->modo === 'fixo' && $regra->conta_id && PlanoContas::lancavel($regra->conta_id) ? $regra->conta_id : null;

        return ['conta_id' => $conta, 'descricao' => null, 'da_empresa' => $cartao->tipo === 'empresa' || $conta !== null];
    }

    public static function importar(Cartao $cartao, UploadedFile $arquivo, ?string $vencimento = null): FaturaCartao
    {
        $lido = self::lerArquivo((string) file_get_contents($arquivo->getRealPath()), $arquivo->getClientOriginalName());
        if ($lido['identificador']) {
            $outro = Cartao::where('identificador', $lido['identificador'])->where('id', '<>', $cartao->id)->first();
            if ($outro) {
                self::erro('arquivo', "Este arquivo é do cartão {$outro->nome}.");
            }
            if ($cartao->identificador && $cartao->identificador !== $lido['identificador']) {
                self::erro('arquivo', "Este arquivo não é do cartão {$cartao->nome}.");
            }
        }
        if (FaturaCartao::where('cartao_id', $cartao->id)->where('fechamento', $lido['fechamento'])->exists()) {
            self::erro('arquivo', 'Esta fatura (fechamento em '.Carbon::parse($lido['fechamento'])->format('d/m/Y').') já foi importada.');
        }
        $venc = $vencimento ?: $lido['vencimento'];
        if (! $venc) {
            $f = Carbon::parse($lido['fechamento']);
            $venc = $cartao->dia_vencimento
                ? ($f->day < $cartao->dia_vencimento ? $f->copy()->day(min($cartao->dia_vencimento, $f->daysInMonth)) : $f->copy()->addMonthNoOverflow()->day(min($cartao->dia_vencimento, $f->copy()->addMonthNoOverflow()->daysInMonth)))->toDateString()
                : $f->copy()->addDays(7)->toDateString();
        }
        $caminho = $arquivo->storeAs('faturas/'.now()->format('Y/m'), Str::uuid().'.'.(strtolower($arquivo->getClientOriginalExtension()) ?: 'ofx'), 'local');

        $fatura = DB::transaction(function () use ($cartao, $lido, $venc, $caminho, $arquivo) {
            if ($lido['identificador'] && ! $cartao->identificador) {
                $cartao->update(['identificador' => $lido['identificador']]);
            }
            $fatura = FaturaCartao::create([
                'cartao_id' => $cartao->id, 'inicio' => $lido['inicio'], 'fechamento' => $lido['fechamento'], 'vencimento' => $venc,
                'total' => round(array_sum(array_column($lido['itens'], 'valor')), 2), 'saldo_arquivo' => $lido['saldo'],
                'arquivo' => $caminho, 'arquivo_nome' => mb_substr($arquivo->getClientOriginalName(), 0, 150), 'criado_por' => Auth::id(),
            ]);
            foreach ($lido['itens'] as $i) {
                [$chave, $parcela, $parcelas] = self::estabelecimento($i['original']);
                $fatura->itens()->create([
                    'fitid' => $i['fitid'], 'data' => $i['data'] ?? $lido['fechamento'], 'original' => $i['original'], 'estabelecimento' => $chave,
                    'parcela' => $parcela, 'parcelas' => $parcelas, 'valor' => $i['valor'],
                ] + self::sugerir($chave, $parcela, $parcelas, (float) $i['valor'], $cartao));
            }
            self::sincronizar($fatura);

            return $fatura;
        });
        Atividades::registrar('fatura_importada', $cartao->nome.' · fechamento '.$fatura->fechamento->format('d/m/Y').' · '.Formato::moeda($fatura->total));

        return $fatura;
    }

    /** nome da conta a pagar da fatura */
    private static function descricao(FaturaCartao $f): string
    {
        return mb_substr(($f->cartao->tipo === 'pessoal' ? 'Compras da empresa no cartão ' : 'Fatura ').$f->cartao->nome.' '.$f->fechamento->format('m/Y'), 0, 120);
    }

    /**
     * Mantém a conta a pagar da fatura: cartão da empresa = aberta pelo total (a empresa paga a fatura);
     * cartão pessoal = paga pelo sócio, no valor das compras marcadas como da empresa.
     */
    public static function sincronizar(FaturaCartao $f): void
    {
        $f->loadMissing('cartao');
        $contaCartao = PlanoContas::porChave('despesa_cartao')->id;
        $base = ['unidade_id' => Ordens::unidadePadrao(), 'fornecedor_id' => $f->cartao->fornecedor_id, 'conta_id' => $contaCartao, 'fatura_id' => $f->id,
            'descricao' => self::descricao($f), 'competencia' => $f->fechamento->copy()->startOfMonth(), 'vencimento' => $f->vencimento,
            'comprovante' => $f->arquivo, 'comprovante_nome' => $f->arquivo_nome];
        $atual = $f->despesa_id ? Despesa::with('forma')->find($f->despesa_id) : null;

        if ($f->cartao->tipo === 'empresa') {
            if (! $atual) {
                // o que o banco cobra (o total do arquivo pode diferir da soma das compras em centavos)
                $d = Despesa::create($base + ['valor' => $f->saldo_arquivo !== null && $f->saldo_arquivo > 0 ? $f->saldo_arquivo : $f->total, 'criado_por' => Auth::id()]);
                $f->update(['despesa_id' => $d->id]);
            }

            return;
        }
        $valor = round((float) $f->itens()->where('da_empresa', true)->sum('valor'), 2);
        if ($atual && $atual->situacao !== 'cancelada') {
            if ($valor > 0) {
                $atual->update(['valor' => $valor] + ($atual->situacao === 'paga' && $atual->forma?->tipo === 'socio' ? ['valor_pago' => $valor] : []));
            } elseif ($atual->situacao === 'paga' && $atual->forma?->tipo === 'socio') {
                $atual->update(['situacao' => 'cancelada', 'cancelada_em' => now(), 'cancelada_por' => Auth::id(), 'motivo_cancelamento' => 'Nenhuma compra da empresa nesta fatura']);
                $f->update(['despesa_id' => null]);
            }

            return;
        }
        if ($valor > 0) {
            $socio = FormasPagamento::socio();
            $d = Despesa::create($base + ['valor' => $valor, 'situacao' => 'paga', 'pago_em' => $f->vencimento, 'valor_pago' => $valor,
                'forma_pagamento_id' => $socio->id, 'criado_por' => Auth::id(), 'pago_por' => Auth::id()]);
            $f->update(['despesa_id' => $d->id]);
        }
    }

    /**
     * Salva a classificação: $itens = [id => [conta_id, descricao, da_empresa]]. Aprende as regras:
     * estabelecimento novo vira "sempre esta conta" (marketplace vira "perguntar"); classificado em
     * outra conta, passa a "perguntar".
     */
    public static function classificar(FaturaCartao $f, array $itens): void
    {
        $f->loadMissing('cartao');
        DB::transaction(function () use ($f, $itens) {
            $lista = $f->itens()->whereIn('id', array_keys($itens))->get();
            foreach ($lista as $item) {
                $d = $itens[$item->id];
                $conta = ! empty($d['conta_id']) ? (int) $d['conta_id'] : null;
                if ($conta && ! PlanoContas::lancavel($conta)) {
                    self::erro("itens.{$item->id}.conta_id", 'Escolha uma conta de despesa do plano de contas.');
                }
                $item->update(['conta_id' => $conta, 'descricao' => ($t = trim((string) ($d['descricao'] ?? ''))) !== '' ? mb_substr($t, 0, 120) : null,
                    'da_empresa' => (bool) ($d['da_empresa'] ?? true)]);
                if ($conta && $item->da_empresa) {
                    $regra = CartaoRegra::firstOrNew(['estabelecimento' => $item->estabelecimento]);
                    if (! $regra->exists) {
                        $regra->fill(self::marketplace($item->estabelecimento) ? ['modo' => 'perguntar', 'conta_id' => null] : ['modo' => 'fixo', 'conta_id' => $conta])->save();
                    } elseif ($regra->modo === 'fixo' && $regra->conta_id !== $conta) {
                        $regra->update(['modo' => 'perguntar', 'conta_id' => null]);
                    }
                }
            }
            self::sincronizar($f->fresh());
        });
        Atividades::registrar('fatura_classificada', $f->cartao->nome.' · fechamento '.$f->fechamento->format('d/m/Y'));
    }

    /** desfaz uma importação errada: só enquanto a conta da fatura não foi paga */
    public static function desfazer(FaturaCartao $f, string $motivo): void
    {
        $f->loadMissing('cartao');
        $d = $f->despesa_id ? Despesa::with('forma')->find($f->despesa_id) : null;
        if ($d && $d->situacao === 'paga' && $d->forma?->tipo !== 'socio') {
            self::erro('motivo', 'A fatura já foi paga: desfaça o pagamento em Contas a pagar antes.');
        }
        DB::transaction(function () use ($f, $d, $motivo) {
            if ($d) {
                if ($d->situacao !== 'cancelada') {
                    $d->update(['situacao' => 'cancelada', 'cancelada_em' => now(), 'cancelada_por' => Auth::id(), 'motivo_cancelamento' => mb_substr('Importação da fatura desfeita: '.$motivo, 0, 200)]);
                }
                $d->update(['fatura_id' => null]);
            }
            $f->update(['despesa_id' => null]);
            $f->delete();
        });
        Atividades::registrar('fatura_desfeita', $f->cartao->nome.' · fechamento '.$f->fechamento->format('d/m/Y').' ('.$motivo.')');
    }
}
