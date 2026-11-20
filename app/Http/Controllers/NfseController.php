<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Models\Integracao;
use App\Models\Nfse;
use App\Models\Pagamento;
use App\Servicos\Nfse\ConfigNfse;
use App\Servicos\Nfse\Dps;
use App\Servicos\Nfse\Notas;
use App\Servicos\Nfse\SefinNacional;
use App\Support\Atividades;
use App\Support\Documento;
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
            'federal' => ['cst' => ConfigNfse::CST_PIS_COFINS, 'com_base' => ConfigNfse::CST_COM_BASE],
        ]);
    }

    public function salvarConfig(Request $r): RedirectResponse
    {
        $r->merge(ConfigNfse::normalizar($r->only(['c_trib_nac', 'c_trib_mun', 'c_nbs', 'im', 'municipio_ibge'])));
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
            'descricao' => ['required', 'string', 'max:300'],
            'especificar_servicos' => ['boolean'],
            'pis_cofins_cst' => ['required', Rule::in(array_keys(ConfigNfse::CST_PIS_COFINS))],
            'p_aliq_pis' => [Rule::requiredIf(fn () => in_array($r->input('pis_cofins_cst'), ConfigNfse::CST_COM_BASE, true)), 'nullable', 'numeric', 'min:0', 'max:100'],
            'p_aliq_cofins' => [Rule::requiredIf(fn () => in_array($r->input('pis_cofins_cst'), ConfigNfse::CST_COM_BASE, true)), 'nullable', 'numeric', 'min:0', 'max:100'],
            'p_ret_csll' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'p_ret_irrf' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'p_ret_cp' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'ibs_cbs' => ['boolean'],
            'ibs_cbs_cst' => ['nullable', 'required_if:ibs_cbs,true,1', 'digits:3'],
            'ibs_cbs_class' => ['nullable', 'required_if:ibs_cbs,true,1', 'digits:6'],
        ], [
            'p_aliq_pis.required' => 'Essa situação tributária pede a alíquota do PIS.',
            'p_aliq_cofins.required' => 'Essa situação tributária pede a alíquota da COFINS.',
            'ibs_cbs_cst.digits' => 'O código de situação tributária do IBS/CBS tem 3 números.',
            'ibs_cbs_class.digits' => 'O código de classificação tributária tem 6 números.',
            'c_trib_nac.digits' => 'Copie do Emissor Nacional: 11.01.01 (6 números).',
            'c_trib_mun.digits' => 'Copie do Emissor Nacional: 11.01.01.004 (ou só os 3 últimos números).',
            'c_nbs.regex' => 'O item da NBS tem 9 números (ex.: 106043000).',
        ]);
        $d['serie'] = (string) $d['serie'];
        $d['especificar_servicos'] = $r->boolean('especificar_servicos');
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

    public function index(Request $r): Response
    {
        $de = $r->query('de') ?: now()->subDays(30)->format('Y-m-d');
        $ate = $r->query('ate') ?: now()->format('Y-m-d');
        $mun = ConfigNfse::ler()['municipio_ibge'];
        // escolhas da última nota de cada cliente (ISS retido, dados na nota): a próxima já vem igual
        $ultima = Nfse::whereIn('situacao', ['emitida', 'enviando'])->whereNotNull('cliente_id')->orderBy('id')->get(['cliente_id', 'iss_retido', 'tomador_identificado', 'ret_federal', 'ret_irrf', 'ret_cp'])->keyBy('cliente_id');
        $comNota = Nfse::whereIn('situacao', ['enviando', 'emitida'])->whereNotNull('pagamento_id')->pluck('pagamento_id');
        $aEmitir = Pagamento::with('cliente:id,nome,documento,cidade,cep,logradouro,bairro', 'forma:id,nome')->where('situacao', 'confirmado')->where('valor', '>', 0)
            ->whereBetween('recebido_em', [$de.' 00:00:00', $ate.' 23:59:59'])->whereNotIn('id', $comNota)
            ->orderByDesc('recebido_em')->limit(300)->get()
            ->map(fn (Pagamento $p) => ['id' => $p->id, 'recebido_em' => $p->recebido_em->format('d/m/Y H:i'), 'valor' => (float) $p->valor,
                'cliente' => $p->cliente?->nome, 'documento' => Documento::valido($p->cliente?->documento) ? Documento::formatar($p->cliente->documento) : null,
                'forma' => $p->forma?->nome, 'origem' => $p->origem, 'competencia' => Dps::competencia($p),
                'pode_identificar' => Documento::valido($p->cliente?->documento),
                'pode_reter' => Documento::valido($p->cliente?->documento) && Dps::municipioDoCliente($p->cliente) === $mun,
                'retido_antes' => (bool) $ultima->get($p->cliente_id)?->iss_retido,
                'identificar_antes' => $ultima->get($p->cliente_id)?->tomador_identificado ?? true,
                'federal_antes' => ['tipo' => $ultima->get($p->cliente_id)?->ret_federal ?? '0', 'irrf' => (bool) $ultima->get($p->cliente_id)?->ret_irrf, 'cp' => (bool) $ultima->get($p->cliente_id)?->ret_cp]]);
        $atual = now()->setTimezone('America/Sao_Paulo')->format('Y-m');
        $aliquotas = collect(ConfigNfse::ler()['aliquotas'])->map(fn ($a, $c) => ['competencia' => $c] + $a)->values();
        $notas = Nfse::with('pagamento:id,recebido_em', 'cliente:id,nome')->orderByDesc('id')->paginate(30)->withQueryString()
            ->through(fn (Nfse $n) => ['id' => $n->id, 'situacao' => $n->situacao, 'ambiente' => $n->ambiente, 'numero' => $n->numero_nfse, 'dps' => $n->serie.'-'.$n->numero_dps,
                'valor' => (float) $n->valor, 'tomador' => $n->tomador_nome, 'cliente' => $n->tomador_identificado ? null : $n->cliente?->nome, 'documento' => $n->tomador_documento ? Documento::formatar($n->tomador_documento) : null,
                'emitida_em' => $n->emitida_em?->format('d/m/Y H:i') ?? $n->criado_em?->format('d/m/Y H:i'), 'mensagem' => $n->mensagem, 'chave' => $n->chave_acesso,
                'iss_retido' => $n->iss_retido, 'valor_iss' => $n->valor_iss !== null ? (float) $n->valor_iss : null,
                'valor_ret_federal' => $n->valor_ret_federal !== null ? (float) $n->valor_ret_federal : null]);

        return Inertia::render('NotasFiscais/Index', [
            'aEmitir' => $aEmitir, 'notas' => $notas, 'filtros' => ['de' => $de, 'ate' => $ate],
            'faltando' => ConfigNfse::faltando(), 'ambiente' => ConfigNfse::ambiente(),
            'aliquotas' => $aliquotas, 'competenciaAtual' => $atual, 'federal' => self::federalParaEmissao(),
        ]);
    }

    /** opções de retenção federal e as alíquotas da configuração, para a escolha e a prévia na emissão */
    private static function federalParaEmissao(): array
    {
        $c = ConfigNfse::ler();
        $n = fn ($k) => (float) str_replace(',', '.', (string) $c[$k]);

        return [
            'tipos' => array_map(fn ($r) => ['nome' => $r[0], 'pis' => $r[1], 'cofins' => $r[2], 'csll' => $r[3]], ConfigNfse::RET_PIS_COFINS),
            'pct' => ['pis' => $n('p_aliq_pis'), 'cofins' => $n('p_aliq_cofins'), 'csll' => $n('p_ret_csll'), 'irrf' => $n('p_ret_irrf'), 'cp' => $n('p_ret_cp')],
        ];
    }

    /** alíquotas do mês (e-mail da contabilidade) */
    public function aliquotas(Request $r): RedirectResponse
    {
        $d = $r->validate([
            'competencia' => ['required', 'date_format:Y-m'],
            'iss' => ['required', 'numeric', 'min:0', 'max:5'],
            'tributos' => ['required', 'numeric', 'min:0', 'max:100'],
        ], ['iss.max' => 'O ISS vai até 5%.'], ['iss' => 'alíquota de ISS', 'tributos' => 'valor aproximado dos tributos']);
        $fmt = fn ($v) => number_format((float) $v, 2, '.', '');
        ConfigNfse::gravarAliquotas($d['competencia'], $fmt($d['iss']), $fmt($d['tributos']));
        $mes = Carbon::parse($d['competencia'].'-01')->format('m/Y');
        Atividades::registrar('nfse_aliquotas', "alíquotas da NFS-e de {$mes}: ISS {$fmt($d['iss'])}%, tributos {$fmt($d['tributos'])}%");

        return back()->with('sucesso', "Alíquotas de {$mes} salvas.");
    }

    public function emitir(Request $r, Pagamento $pagamento): RedirectResponse
    {
        try {
            $fed = ['tipo' => (string) $r->input('ret_federal', '0'), 'irrf' => $r->boolean('ret_irrf'), 'cp' => $r->boolean('ret_cp')];
            $n = Notas::emitir($pagamento, $r->boolean('retido'), $r->boolean('identificar', true), $fed);
        } catch (\RuntimeException $e) {
            return back()->with('erro', $e->getMessage());
        }

        return match ($n->situacao) {
            'emitida' => back()->with('sucesso', 'NFS-e '.($n->numero_nfse ?? '').' emitida'.($n->ambiente === 'restrita' ? ' (teste, sem valor fiscal).' : '.')),
            'enviando' => back()->with('aviso', $n->mensagem),
            default => back()->with('erro', 'Nota rejeitada: '.$n->mensagem),
        };
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
