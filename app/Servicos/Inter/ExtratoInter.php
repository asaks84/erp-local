<?php

namespace App\Servicos\Inter;

use App\Models\Boleto;
use App\Models\Despesa;
use App\Models\Estorno;
use App\Models\ExtratoLancamento;
use App\Servicos\Extrato;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Extrato pela API do Inter (permissão "Conferir saldo e movimentações"): monta o mesmo lote de
 * conferência do CSV. Entradas de boleto (e do Pix do boleto) já baixadas pela cobrança não entram;
 * as saídas são comparadas com o que está registrado (contas pagas e estornos a clientes).
 */
class ExtratoInter
{
    /** saldo do Inter guardado por 10 minutos (o Inter limita as chamadas) */
    public static function saldo(bool $novo = false): ?float
    {
        $chave = 'inter_saldo_'.app(\App\Support\EmpresaAtual::class)->id();
        if (! $novo && Cache::has($chave)) {
            return Cache::get($chave);
        }
        $v = (new InterCliente(Boletos::integracao()))->saldo();
        Cache::put($chave, $v, now()->addMinutes(10));

        return $v;
    }

    /**
     * Devolve ['entradas' => [...como o CSV], 'saidas' => n, 'boletos' => n, 'lista_saidas' => [...], 'arquivo' => rótulo].
     * $transacoes: para teste (sem chamar o Inter).
     */
    public static function buscar(string $de, string $ate, ?array $transacoes = null): array
    {
        $transacoes ??= (new InterCliente(Boletos::integracao()))->extrato($de, $ate);
        $boletos = Boleto::whereNotNull('codigo_solicitacao')->get(['codigo_solicitacao', 'txid', 'seu_numero', 'nosso_numero']);
        $codigos = $boletos->pluck('codigo_solicitacao')->filter()->flip();
        $txids = $boletos->pluck('txid')->filter()->flip();
        $seus = $boletos->pluck('seu_numero')->filter()->flip();
        $nossos = $boletos->pluck('nosso_numero')->filter()->flip();

        $res = ['entradas' => [], 'saidas' => 0, 'boletos' => 0, 'lista_saidas' => [], 'arquivo' => 'Inter · '.Carbon::parse($de)->format('d/m').' a '.Carbon::parse($ate)->format('d/m/Y')];
        foreach ($transacoes as $t) {
            $d = is_array($t['detalhes'] ?? null) ? $t['detalhes'] : [];
            $valor = round((float) str_replace(',', '.', (string) ($t['valor'] ?? 0)), 2);
            $data = substr((string) ($t['dataEntrada'] ?? $t['dataTransacao'] ?? $t['dataInclusao'] ?? ''), 0, 10);
            if ($valor <= 0 || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
                continue;
            }
            $tipo = (string) ($t['tipoTransacao'] ?? '');
            $titulo = trim((string) ($t['titulo'] ?? ''));
            $desc = trim((string) ($t['descricao'] ?? ''));
            $id = (string) ($t['idTransacao'] ?? '') ?: (string) ($d['endToEndId'] ?? '') ?: 'i'.substr(sha1($data.'|'.$valor.'|'.$titulo.'|'.$desc), 0, 40);

            if (strtoupper((string) ($t['tipoOperacao'] ?? '')) === 'D') {
                $res['saidas']++;
                $res['lista_saidas'][] = ['id' => $id, 'data' => $data, 'valor' => $valor,
                    'descricao' => mb_substr(trim(implode(' · ', array_filter([$titulo, $d['nomeRecebedor'] ?? $d['nomeEmpresaRecebedor'] ?? $d['nomeDestinatario'] ?? null, $desc !== $titulo ? $desc : null]))), 0, 160)];

                continue;
            }
            // boleto da cobrança (ou o Pix do boleto): a baixa já veio pela consulta do boleto
            $doBoleto = $tipo === 'BOLETO_COBRANCA'
                || isset($codigos[$d['codigoSolicitacao'] ?? '-']) || isset($txids[$d['txId'] ?? '-'])
                || isset($seus[$d['seuNumero'] ?? '-']) || isset($nossos[$d['nossoNumero'] ?? '-']);
            if ($doBoleto) {
                $res['boletos']++;

                continue;
            }
            $nome = trim((string) ($d['nomePagador'] ?? $d['nomeEmpresaPagador'] ?? $d['nome'] ?? ''));
            $doc = preg_replace('/\D/', '', (string) ($d['cpfCnpjPagador'] ?? $d['cpfCnpj'] ?? ''));
            $lido = $nome === '' ? Extrato::lerDescricao($desc ?: $titulo) : ['tipo' => $titulo ?: $tipo, 'nome' => $nome, 'documento' => '', 'conta' => ''];
            if (in_array(strlen($doc), [11, 14], true)) {
                $lido['documento'] = $doc;
            } elseif (strlen($doc) >= 6 && ! $lido['documento']) {
                // CPF mascarado (•••.068.258-••): o banco mostra os 6 do meio
                $lido['documento'] = strlen($doc) === 6 ? $doc : '';
            }
            $res['entradas'][] = ['id' => mb_substr($id, 0, 64), 'data' => $data, 'valor' => $valor, 'descricao' => mb_substr($desc ?: $titulo, 0, 300)] + $lido;
        }
        $res['lista_saidas'] = self::conciliarSaidas($res['lista_saidas']);

        return $res;
    }

    /**
     * Já baixado por outro caminho com outro identificador (ex.: o mesmo Pix importado antes pelo CSV):
     * mesma data, mesmo valor e mesmo pagador. Devolve [id da entrada => lançamento].
     */
    public static function parecidos(array $entradas): array
    {
        if (! $entradas) {
            return [];
        }
        $datas = array_unique(array_column($entradas, 'data'));
        $ja = ExtratoLancamento::with('cliente:id,nome')->whereIn('data', $datas)->get();
        $r = [];
        foreach ($entradas as $e) {
            $nome = Extrato::normalNome($e['nome'] ?? '');
            $hit = $ja->first(fn ($l) => $l->data->format('Y-m-d') === $e['data'] && abs((float) $l->valor - (float) $e['valor']) < 0.005
                && $l->identificador !== $e['id'] && ($nome === '' || Extrato::normalNome($l->nome) === $nome || ($e['documento'] && $l->documento === $e['documento'])));
            if ($hit) {
                $r[$e['id']] = $hit;
            }
        }

        return $r;
    }

    /** cada saída: conta paga ou estorno a cliente com o mesmo valor, até 3 dias de diferença (cada registro casa uma vez) */
    public static function conciliarSaidas(array $saidas): array
    {
        if (! $saidas) {
            return [];
        }
        $min = Carbon::parse(min(array_column($saidas, 'data')))->subDays(3)->toDateString();
        $max = Carbon::parse(max(array_column($saidas, 'data')))->addDays(3)->toDateString();
        $despesas = Despesa::where('situacao', 'paga')->whereBetween('pago_em', [$min, $max])->get(['id', 'descricao', 'valor', 'valor_pago', 'pago_em']);
        $estornos = Estorno::with('cliente:id,nome')->where('situacao', 'feito')->whereBetween('estornado_em', [$min, $max])->get();
        $usadas = [];
        foreach ($saidas as &$s) {
            $perto = fn ($dia) => abs(Carbon::parse($dia)->diffInDays(Carbon::parse($s['data']), false)) <= 3;
            $d = $despesas->first(fn ($x) => ! isset($usadas['d'.$x->id]) && abs((float) ($x->valor_pago ?? $x->valor) - $s['valor']) < 0.005 && $perto($x->pago_em));
            if ($d) {
                $usadas['d'.$d->id] = true;
                $s['registro'] = ['tipo' => 'despesa', 'id' => $d->id, 'texto' => 'Conta paga: '.$d->descricao];

                continue;
            }
            $e = $estornos->first(fn ($x) => ! isset($usadas['e'.$x->id]) && abs((float) $x->valor - $s['valor']) < 0.005 && $perto($x->estornado_em));
            if ($e) {
                $usadas['e'.$e->id] = true;
                $s['registro'] = ['tipo' => 'estorno', 'id' => $e->id, 'cliente_id' => $e->cliente_id, 'texto' => 'Estorno a '.$e->cliente?->nome];

                continue;
            }
            $s['registro'] = null;
        }
        unset($s);

        return $saidas;
    }
}
