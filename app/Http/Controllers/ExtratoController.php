<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\ExtratoLancamento;
use App\Models\FormaPagamento;
use App\Models\OrdemServico;
use App\Models\Servico;
use App\Servicos\Baixa;
use App\Servicos\Extrato;
use App\Servicos\FormasPagamento;
use App\Support\Atividades;
use App\Support\Formato;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Recebimento em lote pelo extrato do banco (CSV): enviar o arquivo, conferir cliente a cliente
 * e confirmar. As entradas lidas ficam guardadas no servidor durante a conferência, então o valor
 * e a data que dão baixa são sempre os do arquivo, não os da tela.
 */
class ExtratoController extends Controller
{
    private function chave(string $token): string
    {
        return 'extrato:'.app(\App\Support\EmpresaAtual::class)->id().':'.auth()->id().':'.$token;
    }

    public function index(): Response
    {
        return Inertia::render('Extrato/Index', [
            'ultimas' => ExtratoLancamento::with('cliente:id,nome')->orderByDesc('id')->limit(30)->get()->map(fn ($e) => [
                'id' => $e->id, 'data' => $e->data->format('Y-m-d'), 'valor' => (float) $e->valor, 'nome' => $e->nome,
                'cliente' => $e->cliente?->only(['id', 'nome']), 'processado_em' => $e->processado_em->format('Y-m-d H:i'), 'usuario' => $e->usuario_nome,
            ]),
            'tamanho_max_kb' => Extrato::TAMANHO_MAX_KB,
            'inter' => CobrancaController::interLigado(),
            // saldo do Inter carregado depois da tela (não segura a página se o banco demorar)
            'saldo_inter' => Inertia::defer(fn () => self::saldoInter()),
            'periodo_inter' => ['de' => now()->subDays(7)->toDateString(), 'ate' => now()->toDateString()],
        ]);
    }

    private static function saldoInter(): array
    {
        if (! CobrancaController::interLigado()) {
            return ['valor' => null, 'erro' => null];
        }
        try {
            return ['valor' => \App\Servicos\Inter\ExtratoInter::saldo(), 'erro' => null, 'em' => now()->format('Y-m-d H:i')];
        } catch (\App\Servicos\Inter\InterErro $e) {
            return ['valor' => null, 'erro' => mb_substr($e->getMessage(), 0, 240)];
        } catch (\Throwable) {
            return ['valor' => null, 'erro' => 'sem conexão com o Inter agora. Tente de novo daqui a pouco.'];
        }
    }

    /** busca o extrato do período direto do Inter e abre a mesma conferência do CSV */
    public function inter(Request $request): RedirectResponse
    {
        $d = $request->validate(['de' => ['required', 'date_format:Y-m-d'], 'ate' => ['required', 'date_format:Y-m-d', 'after_or_equal:de', 'before_or_equal:today']],
            ['ate.after_or_equal' => 'O fim do período precisa ser depois do início.', 'ate.before_or_equal' => 'O fim do período não pode ser no futuro.']);
        if (\Carbon\Carbon::parse($d['de'])->diffInDays(\Carbon\Carbon::parse($d['ate'])) > 90) {
            throw ValidationException::withMessages(['de' => 'Busque no máximo 90 dias por vez.']);
        }
        try {
            $lido = \App\Servicos\Inter\ExtratoInter::buscar($d['de'], $d['ate']);
        } catch (\App\Servicos\Inter\InterErro $e) {
            throw ValidationException::withMessages(['de' => 'O Inter respondeu: '.$e->getMessage()]);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            throw ValidationException::withMessages(['de' => 'Sem conexão com o Inter agora. Tente de novo daqui a pouco.']);
        }
        $token = Str::random(24);
        Cache::put($this->chave($token), $lido + ['origem' => 'inter'], now()->addHours(3));

        return redirect("/extrato/conferir/{$token}");
    }

    public function enviar(Request $request): RedirectResponse
    {
        $request->validate(['arquivo' => ['required', 'file', 'max:'.Extrato::TAMANHO_MAX_KB, 'mimes:csv,txt']],
            ['arquivo.required' => 'Escolha o arquivo CSV do extrato.', 'arquivo.mimes' => 'O arquivo precisa ser CSV.', 'arquivo.max' => 'O arquivo passa de 2 MB.']);
        $lido = Extrato::lerCsv((string) file_get_contents($request->file('arquivo')->getRealPath()));
        if ($lido['erro']) {
            throw ValidationException::withMessages(['arquivo' => $lido['erro']]);
        }
        $token = Str::random(24);
        Cache::put($this->chave($token), ['entradas' => $lido['entradas'], 'saidas' => $lido['saidas'], 'arquivo' => $request->file('arquivo')->getClientOriginalName()], now()->addHours(3));

        return redirect("/extrato/conferir/{$token}");
    }

    public function conferir(string $token): Response|RedirectResponse
    {
        $lote = Cache::get($this->chave($token));
        if (! $lote) {
            return redirect('/extrato')->with('erro', 'A conferência expirou. Envie o arquivo de novo.');
        }
        FormasPagamento::garantirPadrao();
        $clientes = Extrato::clientes();
        $ja = Extrato::jaProcessados(array_column($lote['entradas'], 'id'));
        // pela API: o mesmo Pix pode ter sido baixado antes pelo CSV, com outro identificador
        $parecidos = ($lote['origem'] ?? '') === 'inter' ? \App\Servicos\Inter\ExtratoInter::parecidos($lote['entradas']) : [];
        foreach ($parecidos as $id => $l) {
            $ja->put($id, $l);
        }
        $abertas = OrdemServico::abertas()->whereNotNull('cliente_id')->orderByRaw('COALESCE(vencimento, data)')->orderBy('numero')->get()->groupBy('cliente_id');

        $entradas = [];
        $sugeridos = [];
        foreach ($lote['entradas'] as $i => $e) {
            $proc = $ja->get($e['id']);
            [$cli, $certeza, $motivo] = $proc ? [null, '', ''] : Extrato::sugerir($e, $clientes);
            if ($cli) {
                $sugeridos[$cli] = true;
            }
            $entradas[] = [
                'indice' => $i,
                'data' => $e['data'],
                'valor' => $e['valor'],
                'nome' => $e['nome'],
                'documento' => $e['documento'],
                'tipo' => $e['tipo'],
                'cliente_id' => $cli,
                'certeza' => $certeza,
                'motivo' => $motivo,
                'processado' => $proc ? ['cliente' => $proc->cliente?->nome, 'quando' => $proc->processado_em->format('Y-m-d H:i')] : null,
            ];
        }

        return Inertia::render('Extrato/Conferir', [
            'token' => $token,
            'arquivo' => $lote['arquivo'],
            'saidas' => $lote['saidas'],
            'boletos' => $lote['boletos'] ?? 0,
            'lista_saidas' => $lote['lista_saidas'] ?? null,
            'entradas' => $entradas,
            'clientes' => collect(array_keys($sugeridos))->mapWithKeys(fn ($id) => [$id => $this->dadosCliente($clientes[$id]->id, $clientes[$id]->nome, $abertas->get($id, collect()))]),
            'formas' => FormaPagamento::where('ativa', true)->orderBy('ordem')->get(['id', 'nome', 'tipo']),
            'servicos' => Servico::where('ativo', true)->where('papel', 'principal')->orderBy('nome')->get(['id', 'nome']),
        ]);
    }

    private function dadosCliente(int $id, string $nome, $ordens): array
    {
        return [
            'id' => $id,
            'nome' => $nome,
            'total' => round($ordens->sum(fn ($o) => $o->valorComSinal()), 2),
            'ordens' => $ordens->map(fn ($o) => ['id' => $o->id, 'numero' => $o->numero, 'tipo' => $o->tipo, 'descricao' => $o->descricao,
                'competencia' => $o->competencia, 'placa' => $o->placa, 'data' => $o->dataReferencia(), 'valor' => (float) $o->valor])->values(),
            'veiculos' => \App\Models\Veiculo::where('cliente_id', $id)->where('ativo', true)->get()->map(fn ($v) => ['id' => $v->id, 'rotulo' => $v->rotulo()]),
        ];
    }

    /** contas em aberto de um cliente escolhido na conferência */
    public function cliente(Cliente $cliente): JsonResponse
    {
        return response()->json($this->dadosCliente($cliente->id, $cliente->nome, Baixa::abertas($cliente)));
    }

    public function confirmar(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'token' => ['required', 'string'],
            'forma_pagamento_id' => ['required', 'integer'],
            'linhas' => ['array'],
            'linhas.*.indice' => ['required', 'integer'],
            'linhas.*.cliente_id' => ['required', 'integer'],
            'linhas.*.modo' => ['nullable', 'in:tudo,parcial'],
            'linhas.*.ordens' => ['nullable', 'array'],
            'linhas.*.sobra' => ['nullable', 'in:credito,servico,nada'],
            'linhas.*.falta' => ['nullable', 'in:debito,desconto'],
            'linhas.*.servico_id' => ['nullable', 'string', 'max:10'], // id do serviço ou "estadia"
            'linhas.*.veiculo_id' => ['nullable', 'integer'],
            'linhas.*.lembrar' => ['nullable', 'boolean'],
        ]);
        $lote = Cache::get($this->chave($d['token']));
        if (! $lote) {
            return redirect('/extrato')->with('erro', 'A conferência expirou. Envie o arquivo de novo.');
        }
        $forma = FormaPagamento::findOrFail($d['forma_pagamento_id']);
        $resultado = [];
        $feitos = [];
        foreach ($d['linhas'] ?? [] as $l) {
            $e = $lote['entradas'][$l['indice']] ?? null;
            $cliente = Cliente::find($l['cliente_id']);
            if (! $e || ! $cliente || isset($feitos[$l['indice']])) {
                continue;
            }
            $feitos[$l['indice']] = true;
            try {
                $msg = DB::transaction(function () use ($e, $l, $cliente, $forma) {
                    if (ExtratoLancamento::where('identificador', $e['id'])->lockForUpdate()->exists()) {
                        throw ValidationException::withMessages(['linha' => 'já tinha sido importado']);
                    }
                    $abertas = OrdemServico::abertas()->where('cliente_id', $cliente->id)->where('tipo', '<>', 'credito')->pluck('id')->all();
                    $ordens = ($l['modo'] ?? 'tudo') === 'parcial' ? array_map('intval', $l['ordens'] ?? []) : $abertas;
                    [$pagamento, $msg] = Baixa::receber($cliente, [
                        'ordens' => $ordens, 'valor' => $e['valor'], 'forma_pagamento_id' => $forma->id, 'recebido_em' => $e['data'],
                        'sobra' => $l['sobra'] ?? null, 'falta' => $l['falta'] ?? null, 'servico_id' => $l['servico_id'] ?? null,
                        'veiculo_id' => $l['veiculo_id'] ?? null, 'origem' => 'extrato', 'sem_ordens' => ! $abertas,
                        'observacao' => 'Extrato: '.mb_substr($e['nome'] ?: $e['descricao'], 0, 200),
                    ]);
                    ExtratoLancamento::create([
                        'identificador' => $e['id'], 'data' => $e['data'], 'valor' => $e['valor'],
                        'nome' => mb_substr(Extrato::normalNome($e['nome']), 0, 100) ?: null, 'documento' => $e['documento'] ?: null,
                        'cliente_id' => $cliente->id, 'pagamento_id' => $pagamento->id,
                        'total_contas' => round((float) $pagamento->itens()->sum('valor'), 2),
                        'processado_em' => now(), 'usuario_id' => auth()->id(), 'usuario_nome' => auth()->user()->nome,
                    ]);
                    if (! empty($l['lembrar'])) {
                        Extrato::vincular($e, $cliente->id);
                        $msg .= ' · pagador vinculado';
                    }

                    return $msg;
                });
                $resultado[] = ['ok' => true, 'nome' => $e['nome'], 'valor' => $e['valor'], 'data' => $e['data'], 'cliente' => $cliente->only(['id', 'nome']), 'msg' => $msg];
            } catch (ValidationException $ex) {
                $resultado[] = ['ok' => false, 'nome' => $e['nome'], 'valor' => $e['valor'], 'data' => $e['data'], 'cliente' => $cliente->only(['id', 'nome']), 'msg' => collect($ex->errors())->flatten()->first()];
            }
        }
        $ok = collect($resultado)->where('ok', true);
        if ($ok->count()) {
            Atividades::registrar('extrato_baixa', $lote['arquivo'].': '.$ok->count().' entrada(s), '.Formato::moeda($ok->sum('valor')));
        }
        Cache::put($this->chave($d['token'].':resultado'), $resultado, now()->addHours(3));

        return redirect("/extrato/resultado/{$d['token']}");
    }

    public function resultado(string $token): Response|RedirectResponse
    {
        $resultado = Cache::get($this->chave($token.':resultado'));
        if ($resultado === null) {
            return redirect('/extrato');
        }

        return Inertia::render('Extrato/Resultado', ['resultado' => $resultado, 'token' => $token]);
    }
}
