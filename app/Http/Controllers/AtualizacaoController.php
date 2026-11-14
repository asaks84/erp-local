<?php

namespace App\Http\Controllers;

use App\Models\AtualizacaoCadastral;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Veiculo;
use App\Servicos\Atualizacoes;
use App\Support\Atividades;
use App\Support\Documento;
use App\Support\Placa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Clientes › Atualização cadastral: quem tem CPF inválido ou celular sem formato, envio do link
 * (WhatsApp, cartão impresso, cobrança) e revisão do que o cliente mandou.
 */
class AtualizacaoController extends Controller
{
    public function index(): Response
    {
        $clientes = Cliente::where('ativo', true)->orderBy('nome')->get(['id', 'nome', 'documento', 'celular', 'telefone']);
        $repetidos = Cliente::where('ativo', true)->whereNotNull('documento')->groupBy('documento')->havingRaw('COUNT(*) > 1')->pluck('documento')->all();
        $placas = Veiculo::where('ativo', true)->whereIn('cliente_id', $clientes->pluck('id'))->whereNotNull('placa')->orderBy('id')->get(['cliente_id', 'placa'])
            ->groupBy('cliente_id')->map(fn ($v) => $v->pluck('placa')->map(fn ($p) => Placa::formatar($p))->all());
        $ultimas = AtualizacaoCadastral::whereIn('id', AtualizacaoCadastral::selectRaw('MAX(id)')->groupBy('cliente_id'))->get()->keyBy('cliente_id');

        $linhas = $clientes->map(function (Cliente $c) use ($repetidos, $placas, $ultimas) {
            $a = $ultimas[$c->id] ?? null;
            $sit = $a?->situacaoAtual();

            return [
                'id' => $c->id, 'nome' => $c->nome,
                'documento' => $c->documento ? Documento::formatar($c->documento) : null,
                'problema_documento' => Atualizacoes::problemaDocumento($c->documento, $repetidos),
                'celular' => $c->celular, 'telefone' => $c->telefone,
                'problema_celular' => Atualizacoes::problemaCelular($c->celular),
                'placas' => $placas[$c->id] ?? [],
                'atualizacao' => $a ? [
                    'id' => $a->id, 'situacao' => $sit, 'canal' => $a->canal, 'enviado_em' => $a->enviado_em?->format('Y-m-d H:i'),
                    'expira_em' => $a->expira_em->format('Y-m-d'), 'respondido_em' => $a->respondido_em?->format('Y-m-d H:i'),
                    'link' => $sit === 'aberta' ? $a->link() : null,
                ] : null,
            ];
        })->values();

        return Inertia::render('Atualizacao/Index', [
            'linhas' => $linhas,
            'resumo' => [
                'clientes' => $linhas->count(),
                'documento' => $linhas->whereNotNull('problema_documento')->count(),
                'celular' => $linhas->whereNotNull('problema_celular')->count(),
                'respondidas' => $linhas->where('atualizacao.situacao', 'respondida')->count(),
                'aprovadas' => $linhas->where('atualizacao.situacao', 'aprovada')->count(),
            ],
            'empresa_nome' => Empresa::atual()->nome,
        ]);
    }

    /** gera (ou renova) o link dos clientes escolhidos */
    public function gerar(Request $request): RedirectResponse
    {
        $d = $request->validate(['clientes' => ['required', 'array', 'min:1', 'max:1000'], 'clientes.*' => ['integer'], 'renovar' => ['nullable', 'boolean']],
            ['clientes.required' => 'Escolha pelo menos um cliente.']);
        $n = 0;
        foreach (Cliente::where('ativo', true)->whereIn('id', $d['clientes'])->get() as $c) {
            Atualizacoes::gerar($c, (bool) ($d['renovar'] ?? false));
            $n++;
        }
        Atividades::registrar('atualizacao_links', $n.' link(s) de atualização cadastral '.(! empty($d['renovar']) ? 'renovado(s)' : 'gerado(s)'));

        return back()->with('sucesso', $n.' link(s) pronto(s). Valem por '.Atualizacoes::VALIDADE_DIAS.' dias.');
    }

    /** marca como enviado (WhatsApp aberto, link copiado ou cartões entregues) */
    public function enviado(Request $request): RedirectResponse
    {
        $d = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer'], 'canal' => ['required', 'in:whatsapp,cartao,link']]);
        AtualizacaoCadastral::whereIn('id', $d['ids'])->where('situacao', 'aberta')->update(['enviado_em' => now(), 'canal' => $d['canal']]);

        return back();
    }

    /** folha A4 de cartões com QR Code (para quem não tem celular válido) */
    public function cartoes(Request $request): Response
    {
        // sem ids: todos os links abertos (a seleção do que imprimir é feita na tela)
        $ids = array_filter(array_map('intval', explode(',', (string) $request->query('ids'))));
        $lista = AtualizacaoCadastral::with('cliente:id,nome')->when($ids, fn ($q) => $q->whereIn('id', array_slice($ids, 0, 2000)))->where('situacao', 'aberta')->get()
            ->filter(fn ($a) => ! $a->expirada())->sortBy(fn ($a) => $a->cliente->nome)->values();

        return Inertia::render('Atualizacao/Cartoes', [
            'empresa_nome' => Empresa::atual()->nome,
            'cartoes' => $lista->map(fn ($a) => [
                'id' => $a->id, 'nome' => $a->cliente->nome, 'primeiro_nome' => mb_convert_case(strtok($a->cliente->nome, ' ') ?: '', MB_CASE_TITLE),
                'link' => $a->link(), 'expira_em' => $a->expira_em->format('Y-m-d'),
            ]),
        ]);
    }

    public function show(AtualizacaoCadastral $atualizacao): Response
    {
        $atualizacao->load('cliente');
        $rotulos = ['documento' => 'CPF/CNPJ', 'nome' => 'Nome', 'celular' => 'Celular', 'telefone' => 'Telefone', 'email' => 'E-mail', 'cep' => 'CEP',
            'logradouro' => 'Endereço', 'numero' => 'Número', 'complemento' => 'Complemento', 'bairro' => 'Bairro', 'cidade' => 'Cidade', 'uf' => 'UF'];
        $mudou = $atualizacao->situacao === 'respondida' ? Atualizacoes::mudancas($atualizacao) : [];
        $base = $atualizacao->situacao === 'respondida' ? Atualizacoes::retrato($atualizacao->cliente) : ($atualizacao->antes ?? []);

        return Inertia::render('Atualizacao/Revisar', [
            'atualizacao' => [
                'id' => $atualizacao->id, 'situacao' => $atualizacao->situacaoAtual(), 'respondido_em' => $atualizacao->respondido_em?->format('Y-m-d H:i'),
                'consentimento_em' => $atualizacao->consentimento_em?->format('Y-m-d H:i'), 'motivo_recusa' => $atualizacao->motivo_recusa,
                'revisado_em' => $atualizacao->revisado_em?->format('Y-m-d H:i'),
            ],
            'cliente' => ['id' => $atualizacao->cliente->id, 'nome' => $atualizacao->cliente->nome],
            'linhas' => collect($rotulos)->map(fn ($r, $k) => [
                'campo' => $k, 'rotulo' => $r, 'antes' => $base[$k] ?? null, 'depois' => $atualizacao->dados[$k] ?? null, 'mudou' => isset($mudou[$k]),
            ])->values(),
            'documento_valido' => Documento::valido($atualizacao->dados['documento'] ?? null),
        ]);
    }

    public function aprovar(AtualizacaoCadastral $atualizacao): RedirectResponse
    {
        $m = Atualizacoes::aprovar($atualizacao);

        return redirect('/atualizacao-cadastral')->with('sucesso', $m ? 'Cadastro atualizado: '.count($m).' campo(s). Esses campos agora valem pelo ERP (o sistema atual não sobrescreve).' : 'Aprovado: o cliente confirmou os dados, nada mudou.');
    }

    public function recusar(Request $request, AtualizacaoCadastral $atualizacao): RedirectResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:200']], ['motivo.required' => 'Informe o motivo.']);
        Atualizacoes::recusar($atualizacao, $d['motivo']);

        return redirect('/atualizacao-cadastral')->with('sucesso', 'Atualização recusada. Nada mudou no cadastro.');
    }
}
