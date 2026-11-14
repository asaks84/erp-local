<?php

namespace App\Http\Controllers;

use App\Models\Boleto;
use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\Empresa;
use App\Models\Integracao;
use App\Models\OrdemServico;
use App\Servicos\Cobrancas;
use App\Servicos\Inter\Boletos;
use App\Servicos\Mensalidades;
use App\Support\Documento;
use App\Support\Placa;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Cobranças: emissão (uma por cliente ou em lote), consulta, impressão e cancelamento. */
class CobrancaController extends Controller
{
    private function mes(Request $request): string
    {
        $mes = (string) $request->query('mes', '');

        return Mensalidades::competenciaValida($mes) ? $mes : now()->format('Y-m');
    }

    public function index(Request $request): Response
    {
        $mes = $this->mes($request);
        $f = $request->validate(['situacao' => ['nullable', 'in:aberta,parcial,paga,levada,cancelada'], 'q' => ['nullable', 'string', 'max:80']]);
        $base = Cobranca::where('competencia', $mes);
        $lista = (clone $base)->with('cliente:id,nome')
            ->when($f['situacao'] ?? null, fn ($q, $s) => $q->where('situacao', $s))
            ->when($f['q'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w->whereHas('cliente', fn ($c) => $c->where('nome', 'like', '%'.$t.'%'))->when(ctype_digit($t), fn ($w) => $w->orWhere('numero', (int) $t))))
            ->orderByDesc('numero')->paginate(50)->withQueryString()
            ->through(fn (Cobranca $c) => self::resumo($c) + ['cliente' => $c->cliente->only(['id', 'nome'])]);
        // situação do último boleto de cada cobrança da página
        $boletos = Boleto::whereIn('cobranca_id', collect($lista->items())->pluck('id'))->orderBy('id')->get(['cobranca_id', 'situacao'])->pluck('situacao', 'cobranca_id');
        $lista->through(fn ($c) => $c + ['boleto' => $boletos[$c['id']] ?? null]);
        $validas = (clone $base)->where('situacao', '<>', 'cancelada');

        return Inertia::render('Cobrancas/Index', [
            'mes' => $mes,
            'cobrancas' => $lista,
            'filtros' => ['situacao' => $f['situacao'] ?? '', 'q' => $f['q'] ?? ''],
            'resumo' => ['emitidas' => (clone $validas)->count(), 'total' => round((float) (clone $validas)->sum('total'), 2),
                'pagas' => (clone $validas)->where('situacao', 'paga')->count()],
        ]);
    }

    public static function resumo(Cobranca $c): array
    {
        return [
            'id' => $c->id,
            'numero' => $c->numero,
            'competencia' => $c->competencia,
            'emitida_em' => $c->emitida_em->format('Y-m-d H:i'),
            'vencimento' => $c->vencimento->format('Y-m-d'),
            'total' => (float) $c->total,
            'desconto' => (float) $c->desconto,
            'desconto_motivo' => $c->desconto_motivo,
            'situacao' => $c->situacao,
            'motivo_cancelamento' => $c->motivo_cancelamento,
        ];
    }

    public function gerar(Request $request): Response
    {
        $mes = $this->mes($request);

        $linhas = Cobrancas::previa($mes);
        if (self::interLigado()) {
            // quem tem a primeira mensalidade (ou proporcional) recebe o boleto sem as regras
            $primeiras = \App\Models\OrdemServico::abertas()->whereIn('cliente_id', $linhas->pluck('cliente_id'))->where('tipo', 'mensalidade')->get()->groupBy('cliente_id');
            $linhas = $linhas->map(fn ($l) => $l + ['boleto_sem_regras' => Boletos::temPrimeiraMensalidade($primeiras[$l['cliente_id']] ?? [])]);
        }

        return Inertia::render('Cobrancas/Gerar', ['mes' => $mes, 'linhas' => $linhas, 'inter' => self::interLigado()]);
    }

    public function emitirLote(Request $request): RedirectResponse
    {
        $d = $request->validate(['mes' => ['required', 'date_format:Y-m'], 'clientes' => ['required', 'array', 'min:1'], 'clientes.*' => ['integer'], 'boleto' => ['nullable', 'boolean'], 'regras' => ['nullable', 'boolean']],
            ['clientes.required' => 'Marque pelo menos um cliente.']);
        [$emitidas, $erros] = Cobrancas::emitirLote($d['mes'], $d['clientes']);
        $msg = count($emitidas).' cobrança(s) emitida(s).';
        if (! empty($d['boleto']) && $emitidas) {
            // regras desmarcadas: nenhum boleto com regras; marcadas: automático (primeira mensalidade sai sem)
            [$ok, $falhas] = self::gerarBoletos($emitidas, array_key_exists('regras', $d) && ! $d['regras'] ? false : null);
            $msg .= ' '.$ok.' boleto(s) gerado(s).';
            $erros = array_merge($erros, $falhas);
        }
        self::cancelarBoletosResolvidos();
        $r = redirect('/cobrancas?mes='.$d['mes'])->with('sucesso', $msg);
        if ($erros) {
            $r->with('aviso', 'Ficaram de fora: '.implode(' ', array_slice($erros, 0, 5)).(count($erros) > 5 ? ' (e mais '.(count($erros) - 5).')' : ''));
        }
        if ($emitidas) {
            $r->with('imprimir', collect($emitidas)->pluck('id')->all());
        }

        return $r;
    }

    public function emitir(Request $request, Cliente $cliente): RedirectResponse
    {
        $d = $request->validate(['mes' => ['required', 'date_format:Y-m'], 'vencimento' => ['nullable', 'date_format:Y-m-d'], 'boleto' => ['nullable', 'boolean'], 'regras' => ['nullable', 'boolean'],
            'desconto' => ['nullable', 'numeric', 'min:0', 'max:99999'], 'desconto_motivo' => ['nullable', 'string', 'max:120']]);
        $desconto = round((float) ($d['desconto'] ?? 0), 2);
        if ($desconto > 0 && ! $request->user()->pode('desconto')) {
            throw \Illuminate\Validation\ValidationException::withMessages(['desconto' => 'Seu nível de acesso não permite dar desconto.']);
        }
        if ($desconto > 0 && ! trim((string) ($d['desconto_motivo'] ?? ''))) {
            throw \Illuminate\Validation\ValidationException::withMessages(['desconto_motivo' => 'Informe o motivo do desconto (ex.: negociado com o cliente).']);
        }
        $c = \Illuminate\Support\Facades\DB::transaction(function () use ($cliente, $d, $desconto) {
            $c = Cobrancas::emitir($cliente, $d['mes'], $d['vencimento'] ?? null);
            if ($desconto > 0) {
                Cobrancas::definirDesconto($c, $desconto, $d['desconto_motivo'] ?? null);
            }

            return $c;
        });
        $r = redirect("/cobrancas/{$c->id}")->with('sucesso', 'Cobrança '.$c->numero.' emitida.');
        if (! empty($d['boleto'])) {
            [$ok, $falhas] = self::gerarBoletos([$c], array_key_exists('regras', $d) && $d['regras'] !== null ? (bool) $d['regras'] : null);
            $ok ? $r->with('sucesso', 'Cobrança '.$c->numero.' emitida com boleto.') : $r->with('aviso', 'Cobrança emitida, mas o boleto não saiu: '.($falhas[0] ?? ''));
        }
        self::cancelarBoletosResolvidos();

        return $r;
    }

    public static function interLigado(): bool
    {
        $i = Integracao::inter();

        return (bool) ($i && $i->ativo && $i->completa());
    }

    /** gera o boleto de cada cobrança; devolve [quantos saíram, mensagens de erro] */
    private static function gerarBoletos(array $cobrancas, ?bool $aplicarRegras = null): array
    {
        $ok = 0;
        $erros = [];
        foreach ($cobrancas as $c) {
            try {
                Boletos::emitir($c, null, $aplicarRegras);
                $ok++;
            } catch (ValidationException $e) {
                $erros[] = collect($e->errors())->flatten()->first();
            } catch (\Throwable $e) {
                $erros[] = "Cobrança {$c->numero}: sem conexão com o Inter (".mb_substr($e->getMessage(), 0, 120).').';
            }
        }

        return [$ok, $erros];
    }

    /** cobrança levada, paga ou cancelada: cancela o boleto antigo no Inter (falhou agora, a consulta periódica tenta de novo) */
    public static function cancelarBoletosResolvidos(): void
    {
        try {
            Boletos::cancelarResolvidos();
        } catch (\Throwable) {
        }
    }

    public function show(Cobranca $cobranca): Response
    {
        $cobranca->load(['cliente', 'itens.ordem']);

        return Inertia::render('Cobrancas/Show', [
            'inter' => self::interLigado(),
            'boleto_vencimento' => Boletos::vencimentoSugerido($cobranca)->format('Y-m-d'),
            'regras_sugerido' => Boletos::aplicarRegrasSugerido($cobranca),
            'em_aberto' => Boletos::valorEmAberto($cobranca),
            'falta_cadastro' => Boletos::faltaNoCadastro($cobranca->cliente),
            'boletos' => Boleto::where('cobranca_id', $cobranca->id)->orderByDesc('id')->get()->map(fn (Boleto $b) => [
                'id' => $b->id, 'seu_numero' => $b->seu_numero, 'situacao' => $b->situacao, 'situacao_nome' => Boleto::SITUACOES[$b->situacao] ?? $b->situacao,
                'valor' => (float) $b->valor, 'vencimento' => $b->vencimento->format('Y-m-d'), 'linha_digitavel' => $b->linha_digitavel,
                'pix_copia_cola' => $b->pix_copia_cola, 'valor_recebido' => $b->valor_recebido !== null ? (float) $b->valor_recebido : null,
                'recebido_em' => $b->recebido_em?->format('Y-m-d'), 'recebido_por' => $b->recebido_por, 'erro' => $b->erro,
                'motivo_cancelamento' => $b->motivo_cancelamento, 'consultado_em' => $b->consultado_em?->format('Y-m-d H:i'),
                'criado_em' => $b->criado_em?->format('Y-m-d H:i'), 'ativo' => $b->ativo(),
                'tem_pdf' => $b->codigo_solicitacao && ! in_array($b->situacao, ['falha', 'em_processamento'], true),
                'sem_regras' => ! empty($b->regras['sem_regras']),
            ]),
            'cobranca' => self::resumo($cobranca),
            'cliente' => $cobranca->cliente->only(['id', 'nome']),
            'itens' => $cobranca->itens->map(fn ($i) => [
                'grupo' => $i->grupo,
                'numero' => $i->numero,
                'tipo' => $i->tipo,
                'descricao' => $i->descricao,
                'placa' => $i->placa ? Placa::formatar($i->placa) : null,
                'data' => $i->data->format('Y-m-d'),
                'vencimento' => $i->vencimento?->format('Y-m-d'),
                'valor' => (float) $i->valor,
                // como a ordem está agora
                'agora' => match (true) {
                    ! $i->ordem => 'removida',
                    $i->ordem->situacao === 'aberta' && $i->ordem->cobranca_id != $cobranca->id => 'levada',
                    default => $i->ordem->situacao,
                },
            ]),
        ]);
    }

    public function imprimir(Request $request): Response
    {
        $ids = array_slice(array_map('intval', (array) $request->query('ids', [])), 0, 200);
        $empresa = Empresa::atual();
        $cobrancas = Cobranca::with(['cliente', 'itens.ordem:id,competencia,veiculo_descricao'])->whereIn('id', $ids)->get()->sortBy(fn ($c) => $c->cliente->nome)->values();
        // atualização cadastral pendente: o QR Code sai no pé da cobrança
        $atualizacoes = \App\Models\AtualizacaoCadastral::whereIn('cliente_id', $cobrancas->pluck('cliente_id'))->where('situacao', 'aberta')
            ->where('expira_em', '>', now())->get()->keyBy('cliente_id');

        return Inertia::render('Cobrancas/Imprimir', [
            'empresa' => [
                'nome' => $empresa->nome,
                'documento' => Documento::formatar($empresa->documento),
                'telefone' => $empresa->telefone,
                'logo_url' => $empresa->logo ? '/storage/'.$empresa->logo : null,
                'instrucoes' => $empresa->config('cobranca_instrucoes'),
            ],
            'cobrancas' => $cobrancas->map(fn (Cobranca $c) => self::resumo($c) + [
                'cliente' => ['id' => $c->cliente->id, 'nome' => $c->cliente->nome, 'documento' => Documento::formatar($c->cliente->documento)],
                'atualizacao_link' => isset($atualizacoes[$c->cliente_id]) ? $atualizacoes[$c->cliente_id]->link() : null,
                'mes' => Carbon::parse($c->competencia.'-01')->format('m/Y'),
                'itens' => $c->itens->map(fn ($i) => [
                    'grupo' => $i->grupo, 'numero' => $i->numero, 'descricao' => $i->descricao,
                    'placa' => $i->placa ? Placa::formatar($i->placa) : null, 'data' => $i->data->format('Y-m-d'),
                    'vencimento' => $i->vencimento?->format('Y-m-d'), 'valor' => (float) $i->valor, 'tipo' => $i->tipo,
                    // a ordem guarda a cópia do veículo e o mês da mensalidade de quando foi lançada
                    'competencia' => $i->ordem?->competencia, 'veiculo' => $i->ordem?->veiculo_descricao,
                ]),
            ]),
        ]);
    }

    public function cancelar(Request $request, Cobranca $cobranca): RedirectResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:200']], ['motivo.required' => 'Informe o motivo do cancelamento.']);
        Cobrancas::cancelar($cobranca, $d['motivo']);
        self::cancelarBoletosResolvidos();

        return back()->with('sucesso', 'Cobrança '.$cobranca->numero.' cancelada; as contas continuam em aberto.');
    }
}
