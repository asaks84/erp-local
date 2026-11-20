<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Integracao;
use App\Models\Nfse;
use App\Models\OrdemServico;
use App\Servicos\Nfse\ConfigNfse;
use App\Servicos\Nfse\Dps;
use App\Servicos\Nfse\Notas;
use App\Servicos\Nfse\SefinNacional;
use App\Support\Atividades;
use App\Support\Documento;
use App\Support\Placa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** NFS-e pelo Sistema Nacional: configuração (Configurações › Nota fiscal) e Gestão › Notas fiscais. */
class NfseController extends Controller
{
    // ---------- configuração ----------

    public function config(): Response
    {
        $i = ConfigNfse::integracao();

        return Inertia::render('Configuracoes/Nfse', [
            'config' => ConfigNfse::ler(),
            'empresa' => ['cnpj' => Documento::formatar(Empresa::atual()->documento), 'nome' => Empresa::atual()->razao_social ?: Empresa::atual()->nome],
            'certificado' => $i?->credencial('certificado') ? ['titular' => $i->credencial('titular'), 'validade' => $i->certificado_validade?->format('d/m/Y'),
                'vencido' => (bool) $i->certificado_validade?->isPast()] : null,
            'ambiente' => ConfigNfse::ambiente(),
            'faltando' => ConfigNfse::faltando(),
            'opcoes' => ['op_simples' => ConfigNfse::OP_SIMPLES, 'reg_ap' => ConfigNfse::REG_AP, 'reg_esp' => ConfigNfse::REG_ESP],
            'nomes' => ConfigNfse::NOMES,
            'federal' => ['cst' => ConfigNfse::CST_PIS_COFINS, 'com_base' => ConfigNfse::CST_COM_BASE,
                'ret' => array_map(fn ($r) => ['nome' => $r[0], 'pis' => $r[1], 'cofins' => $r[2], 'csll' => $r[3]], ConfigNfse::RET_PIS_COFINS)],
        ]);
    }

    public function salvarConfig(Request $r): RedirectResponse
    {
        $r->merge(ConfigNfse::normalizar($r->only(['c_trib_nac', 'c_trib_mun', 'c_nbs', 'im', 'municipio_ibge'])));
        $r->merge(array_map(fn ($v) => is_string($v) ? str_replace(',', '.', $v) : $v, $r->only(['p_aliq_pis', 'p_aliq_cofins', 'p_ret_csll', 'p_ret_irrf', 'p_ret_cp'])));
        $d = $r->validate([
            'im' => ['nullable', 'string', 'max:15'],
            'municipio_ibge' => ['required', 'digits:7'],
            'op_simples' => ['required', Rule::in(array_keys(ConfigNfse::OP_SIMPLES))],
            'reg_ap_trib_sn' => ['required', Rule::in(array_keys(ConfigNfse::REG_AP))],
            'reg_esp_trib' => ['required', Rule::in(array_keys(ConfigNfse::REG_ESP))],
            'c_trib_nac' => ['required', 'digits:6'],
            'c_trib_mun' => ['required', 'digits:3'],
            'c_nbs' => ['required', 'regex:/^\d{9}$/'],
            'serie' => ['required', 'integer', 'min:1', 'max:99999'],
            'descricao' => ['nullable', 'string', 'max:300'],
            'especificar_servicos' => ['boolean'],
            'pis_cofins_cst' => ['required', Rule::in(array_keys(ConfigNfse::CST_PIS_COFINS))],
            'tp_ret_pis_cofins' => ['required', Rule::in(array_keys(ConfigNfse::RET_PIS_COFINS))],
            'p_aliq_pis' => [Rule::requiredIf(fn () => in_array($r->input('pis_cofins_cst'), ConfigNfse::CST_COM_BASE, true) || (ConfigNfse::RET_PIS_COFINS[$r->input('tp_ret_pis_cofins')][1] ?? false)), 'nullable', 'numeric', 'min:0', 'max:100'],
            'p_aliq_cofins' => [Rule::requiredIf(fn () => in_array($r->input('pis_cofins_cst'), ConfigNfse::CST_COM_BASE, true) || (ConfigNfse::RET_PIS_COFINS[$r->input('tp_ret_pis_cofins')][2] ?? false)), 'nullable', 'numeric', 'min:0', 'max:100'],
            'p_ret_csll' => [Rule::requiredIf(fn () => ConfigNfse::RET_PIS_COFINS[$r->input('tp_ret_pis_cofins')][3] ?? false), 'nullable', 'numeric', 'min:0', 'max:100'],
            'p_ret_irrf' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'p_ret_cp' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'ibs_cbs' => ['boolean'],
            'ibs_cbs_cst' => ['nullable', 'required_if:ibs_cbs,true,1', 'digits:3'],
            'ibs_cbs_class' => ['nullable', 'required_if:ibs_cbs,true,1', 'digits:6'],
        ], [
            'p_aliq_pis.required' => 'Essa situação tributária ou retenção pede a alíquota do PIS.',
            'p_aliq_cofins.required' => 'Essa situação tributária ou retenção pede a alíquota da COFINS.',
            'p_ret_csll.required' => 'A retenção escolhida pede a alíquota da CSLL.',
            'ibs_cbs_cst.digits' => 'O código de situação tributária do IBS/CBS tem 3 números.',
            'ibs_cbs_class.digits' => 'O código de classificação tributária tem 6 números.',
            'c_trib_nac.digits' => 'Copie do Emissor Nacional: 11.01.01 (6 números).',
            'c_trib_mun.digits' => 'Copie do Emissor Nacional: 11.01.01.004 (ou só os 3 últimos números).',
            'c_nbs.regex' => 'O item da NBS tem 9 números (ex.: 106043000).',
        ]);
        $d['serie'] = (string) $d['serie'];
        $d['especificar_servicos'] = $r->boolean('especificar_servicos');
        $d['descricao'] = (string) ($d['descricao'] ?? '');
        $d['ibs_cbs'] = $r->boolean('ibs_cbs');
        $d['ibs_cbs_cst'] = (string) ($d['ibs_cbs_cst'] ?? '');
        $d['ibs_cbs_class'] = (string) ($d['ibs_cbs_class'] ?? '');
        foreach (['p_aliq_pis', 'p_aliq_cofins', 'p_ret_csll', 'p_ret_irrf', 'p_ret_cp'] as $k) {
            $d[$k] = ($d[$k] ?? null) === null || $d[$k] === '' ? '' : number_format((float) $d[$k], 2, '.', '');
        }
        foreach (['im'] as $k) {
            $d[$k] = (string) ($d[$k] ?? '');
        }
        ConfigNfse::gravar($d);
        Atividades::registrar('nfse_configuracao', 'configuração da NFS-e salva');

        return back()->with('sucesso', 'Configuração da nota fiscal salva.');
    }

    /** certificado e chave em PEM, tirados do .pfx no navegador (a senha do .pfx não vem para cá) */
    public function certificado(Request $r): RedirectResponse
    {
        $d = $r->validate(['certificado' => ['required', 'string', 'max:20000'], 'chave' => ['required', 'string', 'max:20000']]);
        Notas::guardarCertificado($d['certificado'], $d['chave']);

        return back()->with('sucesso', 'Certificado A1 guardado (criptografado).');
    }

    public function tirarCertificado(): RedirectResponse
    {
        ConfigNfse::integracao()?->forceFill(['credenciais' => null, 'certificado_validade' => null])->save();
        Atividades::registrar('nfse_certificado', 'certificado A1 da NFS-e retirado');

        return back()->with('sucesso', 'Certificado retirado.');
    }

    public function ambiente(Request $r): RedirectResponse
    {
        $d = $r->validate(['ambiente' => ['required', 'in:restrita,producao']]);
        $i = ConfigNfse::integracao() ?? new Integracao(['tipo' => 'nfse', 'ativo' => true]);
        $i->ambiente = $d['ambiente'];
        $i->save();
        Atividades::registrar('nfse_ambiente', $d['ambiente'] === 'producao' ? 'NFS-e em produção (notas valendo)' : 'NFS-e em testes (produção restrita)');

        return back()->with('sucesso', $d['ambiente'] === 'producao' ? 'Pronto: as notas agora valem de verdade.' : 'Notas em modo de teste (sem valor fiscal).');
    }

    public function testar(): RedirectResponse
    {
        $i = ConfigNfse::integracao();
        if (! $i?->credencial('certificado')) {
            return back()->with('erro', 'Cadastre o certificado primeiro.');
        }
        try {
            (new SefinNacional($i))->testar();
        } catch (\RuntimeException $e) {
            return back()->with('erro', $e->getMessage());
        }
        $i->forceFill(['testado_em' => now()])->save();

        return back()->with('sucesso', 'Conexão com o Sistema Nacional aceitou o certificado.');
    }

    // ---------- Gestão › Notas fiscais ----------

    /**
     * Notas fiscais: abas Clientes (serviços sem nota no período, por cliente, para marcar), Avulsos (busca por OS,
     * ticket ou placa) e Notas (as emitidas).
     */
    public function index(Request $r): Response
    {
        $aba = in_array($r->query('aba'), ['clientes', 'avulsos', 'notas'], true) ? $r->query('aba') : 'clientes';
        [$de, $ate] = $this->periodo($r);
        $props = [
            'aba' => $aba, 'filtros' => ['de' => $de, 'ate' => $ate, 'q' => (string) $r->query('q', '')],
            'faltando' => ConfigNfse::faltando(), 'ambiente' => ConfigNfse::ambiente(),
            'aliquotas' => collect(ConfigNfse::ler()['aliquotas'])->map(fn ($a, $c) => ['competencia' => $c] + $a)->values(),
            'competenciaAtual' => Dps::competencia(), 'retFederal' => ConfigNfse::retencaoFederal(ConfigNfse::ler()),
            'clientes' => [], 'avulsos' => [], 'notas' => null,
        ];

        if ($aba === 'clientes') {
            // quem teve nota no mês passado já vem marcado
            $mesPassado = now()->setTimezone('America/Sao_Paulo')->subMonthNoOverflow();
            $sugeridos = Nfse::whereIn('situacao', ['emitida', 'enviando'])->whereNotNull('cliente_id')
                ->whereBetween('criado_em', [$mesPassado->copy()->startOfMonth(), $mesPassado->copy()->endOfMonth()])->pluck('cliente_id')->flip();
            $props['clientes'] = Notas::semNotaDosClientes($de, $ate)
                ->selectRaw('cliente_id, count(*) as qtd, sum(valor) as total')->groupBy('cliente_id')->get()
                ->load('cliente:id,nome,documento')
                ->map(fn ($l) => ['id' => $l->cliente_id, 'nome' => $l->cliente?->nome, 'documento' => Documento::valido($l->cliente?->documento) ? Documento::formatar($l->cliente->documento) : null,
                    'qtd' => (int) $l->qtd, 'total' => round((float) $l->total, 2), 'sugerido' => isset($sugeridos[$l->cliente_id])])
                ->sortBy(fn ($c) => mb_strtolower((string) $c['nome']))->values();
        } elseif ($aba === 'avulsos') {
            $props['avulsos'] = $this->buscarAvulsos((string) $r->query('q', ''));
        } else {
            $props['notas'] = Nfse::with('cliente:id,nome')->withCount('ordens')->orderByDesc('id')->paginate(30)->withQueryString()
                ->through(fn (Nfse $n) => ['id' => $n->id, 'situacao' => $n->situacao, 'ambiente' => $n->ambiente, 'numero' => $n->numero_nfse, 'dps' => $n->serie.'-'.$n->numero_dps,
                    'valor' => (float) $n->valor, 'tomador' => $n->tomador_nome, 'cliente' => $n->tomador_identificado ? null : $n->cliente?->nome, 'documento' => $n->tomador_documento ? Documento::formatar($n->tomador_documento) : null,
                    'emitida_em' => $n->emitida_em?->format('d/m/Y H:i') ?? $n->criado_em?->format('d/m/Y H:i'), 'mensagem' => $n->mensagem, 'chave' => $n->chave_acesso,
                    'iss_retido' => $n->iss_retido, 'valor_iss' => $n->valor_iss !== null ? (float) $n->valor_iss : null,
                    'valor_ret_federal' => $n->valor_ret_federal !== null ? (float) $n->valor_ret_federal : null, 'servicos' => $n->ordens_count]);
        }

        return Inertia::render('NotasFiscais/Index', $props);
    }

    /** período De/Até da lista de clientes (padrão: início do mês anterior ao fim do mês atual) */
    private function periodo(Request $r): array
    {
        [$de, $ate] = Notas::periodoPadrao();
        $ok = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);

        return [$ok($r->query('de')) ? $r->query('de') : $de, $ok($r->query('ate')) ? $r->query('ate') : $ate];
    }

    /** busca de serviços sem nota por número da OS, número do ticket ou placa (qualquer data) */
    private function buscarAvulsos(string $q): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $n = ctype_digit($q) ? (int) $q : null;
        $placa = Placa::normalizar($q);
        $ordens = Notas::semNota()->with('cliente:id,nome,documento,cidade,cep,logradouro,bairro,cidade_ibge,cidade_ibge_cep,nf_tomador,nf_iss_retido,nf_retem_federal', 'estadia:id,numero,numero_impresso')
            ->where(function ($w) use ($n, $placa) {
                if ($n) {
                    $w->orWhere('numero', $n)->orWhereHas('estadia', fn ($e) => $e->where('numero', $n)->orWhere('numero_impresso', $n));
                }
                if ($placa && strlen($placa) >= 3) {
                    $w->orWhere('placa', 'like', '%'.$placa.'%');
                }
            })->orderByDesc('data')->limit(50)->get();

        return $ordens->map(fn (OrdemServico $o) => $this->servico($o) + ['cliente' => $o->cliente ? $this->dadosCliente($o->cliente) : null])->all();
    }

    /** um serviço como a tela mostra */
    private function servico(OrdemServico $o): array
    {
        return ['id' => $o->id, 'numero' => $o->numero, 'data' => $o->data?->format('d/m/Y'), 'tipo' => OrdemServico::TIPOS[$o->tipo] ?? $o->tipo,
            'descricao' => $o->descricao, 'placa' => $o->placa ? Placa::formatar($o->placa) : null, 'valor' => (float) $o->valor,
            'pago' => $o->situacao === 'paga', 'ticket' => $o->estadia?->numero_impresso ?: $o->estadia?->numero];
    }

    /** cliente e as escolhas de nota do cadastro dele (dados do tomador, ISS retido, retenção federal) */
    private function dadosCliente(Cliente $c): array
    {
        $doc = Documento::valido($c->documento);
        $reter = $doc && Dps::municipioDoCliente($c) === ConfigNfse::ler()['municipio_ibge'];

        return ['id' => $c->id, 'nome' => $c->nome, 'documento' => $doc ? Documento::formatar($c->documento) : null,
            'pode_identificar' => $doc, 'pode_reter' => $reter,
            'identificar' => $doc && $c->nf_tomador, 'retido' => $reter && $c->nf_tomador && $c->nf_iss_retido,
            'federal' => $doc && $c->nf_tomador && $c->nf_retem_federal];
    }

    /** conferir e emitir: os clientes marcados, com os serviços sem nota do período */
    public function conferirClientes(Request $r): Response|RedirectResponse
    {
        [$de, $ate] = $this->periodo($r);
        $ids = collect(explode(',', (string) $r->query('clientes')))->map(fn ($v) => (int) $v)->filter()->unique();
        if ($ids->isEmpty()) {
            return redirect('/notas-fiscais')->with('aviso', 'Marque ao menos um cliente.');
        }
        $ordens = Notas::semNotaDosClientes($de, $ate)->whereIn('cliente_id', $ids)->with('cliente:id,nome,documento,cidade,cep,logradouro,bairro,cidade_ibge,cidade_ibge_cep,nf_tomador,nf_iss_retido,nf_retem_federal', 'estadia:id,numero,numero_impresso')
            ->orderBy('data')->orderBy('id')->get()->groupBy('cliente_id');
        $clientes = $ordens->map(fn ($lista) => $this->dadosCliente($lista->first()->cliente) + ['servicos' => $lista->map(fn ($o) => $this->servico($o))->values()])
            ->sortBy(fn ($c) => mb_strtolower((string) $c['nome']))->values();

        return Inertia::render('NotasFiscais/Conferir', [
            'clientes' => $clientes, 'filtros' => ['de' => $de, 'ate' => $ate],
            'faltando' => ConfigNfse::faltando(), 'ambiente' => ConfigNfse::ambiente(), 'competenciaAtual' => Dps::competencia(),
            'aliquota' => ConfigNfse::aliquotas(Dps::competencia()), 'retFederal' => ConfigNfse::retencaoFederal(ConfigNfse::ler()),
        ]);
    }

    /** alíquotas do mês (e-mail da contabilidade) */
    public function aliquotas(Request $r): RedirectResponse
    {
        $r->merge(array_map(fn ($v) => is_string($v) ? str_replace(',', '.', $v) : $v, $r->only(['iss', 'tributos'])));
        $d = $r->validate([
            'competencia' => ['required', 'date_format:Y-m'],
            'iss' => ['required', 'numeric', 'gt:0', 'max:5'],
            'tributos' => ['required', 'numeric', 'gt:0', 'max:100'],
        ], ['iss.max' => 'O ISS vai até 5%.', 'iss.gt' => 'Informe a alíquota de ISS do e-mail.', 'tributos.gt' => 'Informe o valor aproximado dos tributos do e-mail.'], ['iss' => 'alíquota de ISS', 'tributos' => 'valor aproximado dos tributos']);
        $fmt = fn ($v) => number_format((float) $v, 2, '.', '');
        ConfigNfse::gravarAliquotas($d['competencia'], $fmt($d['iss']), $fmt($d['tributos']));
        $mes = Carbon::parse($d['competencia'].'-01')->format('m/Y');
        Atividades::registrar('nfse_aliquotas', "alíquotas da NFS-e de {$mes}: ISS {$fmt($d['iss'])}%, tributos {$fmt($d['tributos'])}%");

        return back()->with('sucesso', "Alíquotas de {$mes} salvas.");
    }

    /**
     * Emite uma nota com os serviços escolhidos. A tela de conferir chama uma vez por cliente (em lote, uma de cada
     * vez) e recebe JSON; a busca de avulsos também.
     */
    public function emitir(Request $r): JsonResponse
    {
        $d = $r->validate([
            'ordens' => ['required', 'array', 'min:1', 'max:200'], 'ordens.*' => ['integer'],
            'identificar' => ['boolean'], 'retido' => ['boolean'], 'retem_federal' => ['boolean'],
            'avulso_documento' => ['nullable', 'string', 'max:20'], 'avulso_nome' => ['nullable', 'string', 'max:150'],
        ]);
        try {
            $n = Notas::emitir($d['ordens'], [
                'identificar' => $r->boolean('identificar', true), 'retido' => $r->boolean('retido'), 'retem_federal' => $r->boolean('retem_federal'),
                'avulso' => ['documento' => $d['avulso_documento'] ?? null, 'nome' => $d['avulso_nome'] ?? null],
            ]);
        } catch (ValidationException $e) {
            return response()->json(['situacao' => 'erro', 'mensagem' => collect($e->errors())->flatten()->first()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['situacao' => 'erro', 'mensagem' => $e->getMessage()], 422);
        }

        return response()->json(['situacao' => $n->situacao, 'id' => $n->id, 'numero' => $n->numero_nfse, 'mensagem' => match ($n->situacao) {
            'emitida' => 'NFS-e '.($n->numero_nfse ?? '').' emitida'.($n->ambiente === 'restrita' ? ' (teste)' : ''),
            'enviando' => $n->mensagem,
            default => 'Rejeitada: '.$n->mensagem,
        }]);
    }

    public function conferir(Nfse $nota): RedirectResponse
    {
        try {
            $n = Notas::conferir($nota);
        } catch (\RuntimeException $e) {
            return back()->with('erro', $e->getMessage());
        }

        return back()->with($n->situacao === 'emitida' ? 'sucesso' : 'aviso', $n->situacao === 'emitida' ? 'A nota saiu: NFS-e '.$n->numero_nfse.'.' : 'Ainda sem resposta do Sistema Nacional.');
    }

    public function cancelar(Request $r, Nfse $nota): RedirectResponse
    {
        $d = $r->validate(['motivo' => ['required', 'string', 'min:15', 'max:255'], 'codigo' => ['required', 'in:1,2,9']],
            ['motivo.min' => 'Explique o motivo (pelo menos 15 letras).']);
        try {
            Notas::cancelar($nota, $d['motivo'], (int) $d['codigo']);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['motivo' => $e->getMessage()]);
        }

        return back()->with('sucesso', 'Nota cancelada.');
    }

    public function xml(Nfse $nota): HttpResponse
    {
        $xml = $nota->xml_nfse ?: $nota->xml_dps;
        abort_unless($xml, 404);

        return response($xml, 200, ['Content-Type' => 'application/xml', 'Content-Disposition' => 'attachment; filename="'.($nota->chave_acesso ?: $nota->id_dps).'.xml"']);
    }

    public function pdf(Nfse $nota): HttpResponse|RedirectResponse
    {
        abort_unless($nota->chave_acesso, 404);
        try {
            $pdf = (new SefinNacional(ConfigNfse::integracao()))->danfse($nota->chave_acesso);
        } catch (\RuntimeException $e) {
            return back()->with('erro', $e->getMessage());
        }

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="nfse-'.($nota->numero_nfse ?: $nota->id).'.pdf"']);
    }
}
