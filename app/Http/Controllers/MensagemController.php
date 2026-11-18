<?php

namespace App\Http\Controllers;

use App\Models\Integracao;
use App\Models\ModeloMensagem;
use App\Servicos\WhatsApp\Modelos;
use App\Servicos\WhatsApp\Regua;
use App\Servicos\WhatsApp\WhatsAppCliente;
use App\Servicos\WhatsApp\WhatsAppErro;
use App\Support\Atividades;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configurações › WhatsApp › Mensagens: criar e editar as mensagens (modelos da Meta). Rascunho fica só no
 * ERP (e já vale no envio manual); "Enviar para aprovação" manda para a Meta e trava a versão. Editar uma
 * versão enviada cria a seguinte; a aprovada continua valendo pela API até a nova ser aprovada.
 */
class MensagemController extends Controller
{
    private function api(): ?WhatsAppCliente
    {
        $i = Integracao::whatsapp();

        return $i && $i->ativo && WhatsAppCliente::completa($i) ? new WhatsAppCliente($i) : null;
    }

    public function index(Request $request): Response
    {
        Modelos::garantirPadrao();
        $erro = null;
        if ($api = $this->api()) {
            try {
                Modelos::sincronizar($api);
            } catch (WhatsAppErro $e) {
                $erro = $e->getMessage();
            }
        }
        $passos = Regua::passos();
        $naRegua = collect($passos)->mapWithKeys(fn ($p) => [$p['dias'] => Modelos::doPasso($p['dias'], $passos)]);
        $grupos = Modelos::grupos()->map(function ($v, $g) use ($naRegua, $passos) {
            [$api] = Modelos::paraApi((int) $g);

            return [
                'grupo' => (int) $g,
                'rotulo' => $v->first()->rotulo,
                'finalidade' => $v->first()->finalidade,
                'api_versao' => $api?->situacao === 'APPROVED' ? $api->versao : null,
                'regua' => $naRegua->filter(fn ($x) => (int) $x === (int) $g)->keys()
                    ->filter(fn ($d) => collect($passos)->firstWhere('dias', $d)['ativo'] ?? false)->values(),
                'versoes' => $v->map(fn (ModeloMensagem $m) => [
                    'id' => $m->id, 'versao' => $m->versao, 'nome' => $m->nome, 'rotulo' => $m->rotulo, 'finalidade' => $m->finalidade, 'categoria' => $m->categoria,
                    'cabecalho' => $m->cabecalho, 'texto' => $m->texto, 'rodape' => $m->rodape, 'botao' => $m->botao, 'situacao' => $m->situacao, 'motivo' => $m->motivo,
                    'enviado_em' => $m->enviado_em?->format('Y-m-d H:i'), 'atualizado_em' => $m->atualizado_em?->format('Y-m-d H:i'),
                ])->values(),
            ];
        })->values();

        return Inertia::render('Configuracoes/WhatsAppMensagens', [
            'grupos' => $grupos,
            'variaveis' => collect(Modelos::VARIAVEIS)->map(fn ($v, $k) => ['chave' => $k, 'rotulo' => $v[0]])->values(),
            'variaveis_propaganda' => Modelos::VARIAVEIS_PROPAGANDA,
            'exemplos' => Modelos::exemplos(),
            'finalidades' => Modelos::FINALIDADES,
            'max' => Modelos::MAX_TEXTO,
            'max_linha' => Modelos::MAX_LINHA,
            'botao' => Modelos::BOTAO,
            'api' => (bool) $this->api(),
            'erro_meta' => $erro,
            'selecionado' => $request->integer('grupo') ?: null,
        ]);
    }

    private function dados(Request $request): array
    {
        $d = $request->validate([
            'rotulo' => ['required', 'string', 'max:80'],
            'finalidade' => ['required', Rule::in(array_keys(Modelos::FINALIDADES))],
            'texto' => ['required', 'string', 'max:'.(Modelos::MAX_TEXTO + 50)],
            'botao' => ['boolean'],
            'cabecalho' => ['nullable', 'string', 'max:80'],
            'rodape' => ['nullable', 'string', 'max:80'],
        ], ['rotulo.required' => 'Dê um nome para a mensagem.', 'texto.required' => 'Escreva o texto da mensagem.']);
        $d['texto'] = trim(str_replace(["\r\n", "\t"], ["\n", ' '], $d['texto']));
        foreach (['cabecalho', 'rodape'] as $k) {
            $d[$k] = filled($d[$k] ?? null) ? trim($d[$k]) : null;
        }
        $d['botao'] = $d['finalidade'] === 'cobranca' && (bool) ($d['botao'] ?? false);
        $d['categoria'] = $d['finalidade'] === 'propaganda' ? 'MARKETING' : 'UTILITY';

        return $d;
    }

    private function conferir(array $d): void
    {
        $erros = array_filter(['cabecalho' => Modelos::problemasCabecalho($d['cabecalho'], $d['finalidade'])[0] ?? null,
            'texto' => Modelos::problemas($d['texto'], $d['finalidade'])[0] ?? null, 'rodape' => Modelos::problemasRodape($d['rodape'])[0] ?? null]);
        if ($erros) {
            throw ValidationException::withMessages($erros);
        }
    }

    /** mensagem nova (rascunho, versão 1) */
    public function store(Request $request): RedirectResponse
    {
        $d = $this->dados($request);
        foreach (['cabecalho', 'rodape'] as $k) {
            if ($d[$k] !== null && mb_strlen($d[$k]) > Modelos::MAX_LINHA) {
                throw ValidationException::withMessages([$k => 'Até '.Modelos::MAX_LINHA.' caracteres.']);
            }
        }
        $m = DB::transaction(function () use ($d) {
            $m = ModeloMensagem::create($d + ['nome' => Modelos::nomeNovo($d['rotulo'], $d['finalidade']), 'situacao' => 'rascunho', 'versao' => 1]);
            $m->update(['grupo' => $m->id]);

            return $m;
        });
        Atividades::registrar('whatsapp_mensagem', 'mensagem do WhatsApp criada: '.$m->rotulo);

        return redirect('/configuracoes/whatsapp/mensagens?grupo='.$m->grupo)->with('sucesso', 'Mensagem criada como rascunho.');
    }

    /** salva o rascunho; com "enviar", manda para a aprovação da Meta */
    public function update(Request $request, ModeloMensagem $mensagem): RedirectResponse
    {
        if (! $mensagem->rascunho()) {
            return back()->with('erro', 'Essa versão já foi para a Meta e não muda mais. Use "Editar" para criar a versão seguinte.');
        }
        $d = $this->dados($request);
        // a finalidade é da mensagem toda (todas as versões): só muda enquanto nada foi para a Meta
        if ($d['finalidade'] !== $mensagem->finalidade && Modelos::versoes($mensagem->grupo)->contains(fn ($v) => ! $v->rascunho())) {
            throw ValidationException::withMessages(['finalidade' => 'Uma versão já foi para a Meta: para outra finalidade, crie uma mensagem nova.']);
        }
        if ($d['finalidade'] === 'propaganda' && collect(Regua::passos())->contains(fn ($p) => Modelos::doPasso($p['dias']) === $mensagem->grupo)) {
            throw ValidationException::withMessages(['finalidade' => 'Essa mensagem está na régua de cobrança: escolha outra para o passo antes.']);
        }
        if ($request->boolean('enviar')) {
            $this->conferir($d);
        }
        // cabeçalho e rodapé maiores que a coluna: só no envio para a Meta é que barra (o rascunho guarda até 60)
        if (! $request->boolean('enviar')) {
            foreach (['cabecalho', 'rodape'] as $k) {
                if ($d[$k] !== null && mb_strlen($d[$k]) > Modelos::MAX_LINHA) {
                    throw ValidationException::withMessages([$k => 'Até '.Modelos::MAX_LINHA.' caracteres.']);
                }
            }
        }
        $mensagem->update($d);
        // o nome é de todas as versões
        ModeloMensagem::where('grupo', $mensagem->grupo)->where('id', '<>', $mensagem->id)->update(['rotulo' => $d['rotulo']]);
        if (! $request->boolean('enviar')) {
            return back()->with('sucesso', 'Rascunho salvo. No envio manual já vale este texto; pela API, só depois de aprovado pela Meta.');
        }

        return $this->enviarParaMeta($mensagem);
    }

    private function enviarParaMeta(ModeloMensagem $m): RedirectResponse
    {
        if (! $api = $this->api()) {
            return back()->with('erro', 'Para enviar à Meta, ligue a API do WhatsApp (Configurações › WhatsApp › Testar conexão). O rascunho foi salvo.');
        }
        try {
            $r = $api->criarModelo(Modelos::definicao($m, url('/')));
        } catch (WhatsAppErro $e) {
            return back()->with('erro', 'A Meta não aceitou: '.$e->getMessage().' O rascunho foi salvo.');
        }
        $m->update(['situacao' => (string) ($r['status'] ?? 'PENDING'), 'meta_id' => isset($r['id']) ? (string) $r['id'] : null, 'categoria' => (string) ($r['category'] ?? $m->categoria),
            'motivo' => null, 'enviado_em' => now(), 'enviado_por' => Auth::id()]);
        Atividades::registrar('whatsapp_mensagem', "mensagem do WhatsApp enviada para aprovação: {$m->rotulo} (versão {$m->versao})");

        return back()->with('sucesso', $m->situacao === 'APPROVED' ? 'A Meta já aprovou a mensagem.' : 'Enviada para aprovação da Meta. Costuma levar de minutos a algumas horas; a situação aparece aqui.');
    }

    /** editar uma versão que já foi para a Meta: cria (ou abre) o rascunho da versão seguinte */
    public function novaVersao(int $grupo): RedirectResponse
    {
        $v = Modelos::versoes($grupo);
        abort_if($v->isEmpty(), 404);
        if (! $v->first()->rascunho()) {
            $ult = $v->first();
            $n = $ult->versao + 1;
            ModeloMensagem::create(['grupo' => $grupo, 'versao' => $n, 'nome' => Modelos::nomeVersao($v->last(), $n), 'situacao' => 'rascunho']
                + $ult->only(['rotulo', 'finalidade', 'categoria', 'cabecalho', 'texto', 'rodape', 'botao']));
        }

        return redirect('/configuracoes/whatsapp/mensagens?grupo='.$grupo);
    }

    /** descarta um rascunho (se for a única versão, a mensagem some) */
    /**
     * Rascunho: descarta. Versão já enviada à Meta: exclui lá pela API e aqui. A régua não pode ficar sem
     * versão (nem sem a aprovada, se era a única aprovada).
     */
    public function destroy(ModeloMensagem $mensagem): RedirectResponse
    {
        $outras = Modelos::versoes($mensagem->grupo)->where('id', '<>', $mensagem->id);
        $unica = $outras->isEmpty();
        $passos = Regua::passos();
        $naRegua = collect($passos)->contains(fn ($p) => Modelos::doPasso($p['dias'], $passos) === $mensagem->grupo);
        if ($naRegua && ($unica || ($mensagem->situacao === 'APPROVED' && ! $outras->contains('situacao', 'APPROVED')))) {
            return back()->with('erro', $unica
                ? 'Essa mensagem está na régua de cobrança: escolha outra mensagem para o passo antes de apagar.'
                : 'Essa é a única versão aprovada de uma mensagem da régua: os avisos pela API param. Troque a mensagem do passo na régua ou espere outra versão ser aprovada.');
        }
        if (! $mensagem->rascunho()) {
            $api = $this->api();
            if ($api) {
                try {
                    $api->excluirModelo($mensagem->nome, $mensagem->meta_id);
                } catch (WhatsAppErro $e) {
                    return back()->with('erro', 'A Meta não excluiu: '.$e->getMessage());
                }
            }
            $rotulo = $mensagem->rotulo.' v'.$mensagem->versao;
            $grupo = $mensagem->grupo;
            $mensagem->delete();
            Atividades::registrar('whatsapp_mensagem', 'modelo do WhatsApp excluído: '.$rotulo.' ('.$mensagem->nome.')'.($api ? '' : ' só no ERP'));

            return redirect('/configuracoes/whatsapp/mensagens'.($unica ? '' : '?grupo='.$grupo))->with('sucesso', $api
                ? 'Modelo excluído aqui e na Meta. O nome '.$mensagem->nome.' fica bloqueado na Meta por 30 dias.'
                : 'Modelo excluído no ERP. Com a integração desligada, exclua também no Gerenciador do WhatsApp.');
        }
        $rotulo = $mensagem->rotulo;
        $grupo = $mensagem->grupo;
        $mensagem->delete();
        Atividades::registrar('whatsapp_mensagem', ($unica ? 'mensagem do WhatsApp apagada: ' : 'rascunho descartado: ').$rotulo);

        return redirect('/configuracoes/whatsapp/mensagens'.($unica ? '' : '?grupo='.$grupo))->with('sucesso', $unica ? 'Mensagem apagada.' : 'Rascunho descartado.');
    }
}
