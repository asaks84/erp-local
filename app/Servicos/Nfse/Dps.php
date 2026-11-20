<?php

namespace App\Servicos\Nfse;

use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\OrdemServico;
use App\Support\Documento;
use App\Support\Placa;
use DOMDocument;
use DOMElement;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Monta a DPS (Declaração de Prestação de Serviço) do Sistema Nacional da NFS-e, leiaute 1.01, a partir dos
 * serviços (ordens) de um cliente. Ordem dos campos como no leiaute; o prestador é identificado só pelo CNPJ (nome e endereço vêm do
 * cadastro nacional, como pede a regra para tpEmit = 1). Ainda sem o grupo de IBS/CBS da reforma tributária
 * (no Emissor Nacional esses campos são opcionais por enquanto).
 *
 * Alíquotas do mês (e-mail da contabilidade, por competência):
 * - "Valor aproximado dos tributos" -> pTotTribSN ("Informar alíquota do Simples Nacional" no Emissor);
 * - "Alíquota de ISS" -> pAliq, só quando o ISS é retido pelo tomador (ME/EPP no Simples sem retenção não pode
 *   informar alíquota: regras E0625/E0631; com retenção é obrigatória, mínimo 1,8%: E0621/E0628).
 */
class Dps
{
    public const NS = 'http://www.sped.fazenda.gov.br/nfse';

    public const VERSAO = '1.01';

    /** municípios da Baixada para o endereço do tomador (o cadastro guarda o nome da cidade, não o código IBGE) */
    private const MUNICIPIOS = [
        'santos' => '3548500', 'sao vicente' => '3551009', 'guaruja' => '3518701', 'praia grande' => '3541000',
        'cubatao' => '3513504', 'bertioga' => '3506359', 'mongagua' => '3531100', 'itanhaem' => '3522109', 'peruibe' => '3537602',
        'sao paulo' => '3550308',
    ];

    /** Id da DPS: "DPS" + município(7) + tipo de inscrição(1: 2 = CNPJ) + CNPJ(14) + série(5) + número(15) */
    public static function id(string $municipio, string $cnpj, string $serie, int $numero): string
    {
        return 'DPS'.str_pad($municipio, 7, '0', STR_PAD_LEFT).'2'.str_pad($cnpj, 14, '0', STR_PAD_LEFT)
            .str_pad($serie, 5, '0', STR_PAD_LEFT).str_pad((string) $numero, 15, '0', STR_PAD_LEFT);
    }

    /**
     * Descrição do serviço: a descrição base (se houver, na primeira linha) e uma linha por serviço (ordem),
     * "MODELO - PLACA - SERVIÇO - VALOR"; estadia e serviço avulso levam a data na frente ("05/10 - POLO - TMA2G79 - R$ 60,00").
     * Sem "especificar serviços" (Configurações), só a mensalidade leva o nome do serviço; as outras contas saem
     * "PLACA - VEÍCULO - VALOR" (para quem não tem CNAE de lavagem, por exemplo, não citar o serviço na nota).
     */
    public static function descricao(Collection $ordens, array $cfg): string
    {
        $especificar = (bool) ($cfg['especificar_servicos'] ?? false);
        (new EloquentCollection($ordens->all()))->loadMissing('veiculo.tipo', 'tipoVeiculo');
        $linhas = $ordens->filter(fn (OrdemServico $o) => (float) $o->valor > 0)->sortBy([['data', 'asc'], ['id', 'asc']])
            ->map(function (OrdemServico $o) use ($especificar) {
                $placa = $o->placa ?: $o->veiculo?->placa;
                // modelo do veículo (como o Cauê escreve no Emissor: "POLO - TMA2G79"). Carro que não é do cadastro (ex.: o da
                // esposa, lançado na conta do cliente): o modelo digitado no lançamento e a placa da própria ordem; sem nada, o tipo
                $veiculo = trim((string) ($o->veiculo?->modelo ?: $o->veiculo?->marca ?: $o->veiculo_descricao ?: $o->veiculo?->tipo?->nome ?: $o->tipoVeiculo?->nome)) ?: null;
                $servico = match (true) {
                    $o->tipo === 'mensalidade' => (trim((string) $o->descricao) ?: 'Mensalidade').($o->competencia ? ' '.Carbon::parse($o->competencia.'-01')->format('m/Y') : ''),
                    ! $especificar => null,
                    $o->tipo === 'estadia' => 'Estadia',
                    default => trim((string) $o->descricao) ?: OrdemServico::TIPOS[$o->tipo] ?? null,
                };
                $data = $o->tipo !== 'mensalidade' && $o->data ? $o->data->format('d/m') : null;
                $partes = array_filter([$data, $veiculo, $placa ? Placa::formatar($placa) : null, $servico], fn ($x) => $x !== null && $x !== '');

                return implode(' - ', $partes ?: ['Serviço']).' - R$ '.number_format((float) $o->valor, 2, ',', '.');
            })->values();
        $base = trim((string) ($cfg['descricao'] ?? ''));
        $texto = implode("\n", array_filter([$base, $linhas->implode("\n")], fn ($x) => $x !== ''));

        return Str::limit($texto !== '' ? $texto : 'Serviço de estacionamento', 2000, '');
    }

    /** competência (AAAA-MM) da nota: o mês da emissão (a data de competência é o dia em que a nota é emitida) */
    public static function competencia(?Carbon $agora = null): string
    {
        return ($agora ?? now())->copy()->setTimezone('America/Sao_Paulo')->format('Y-m');
    }

    /**
     * Monta a DPS de um conjunto de serviços (ordens) do mesmo cliente (ou avulsos, sem cliente).
     *
     * @param  array{iss: string, tributos: string}  $aliq  alíquotas da competência
     * @param  array  $op  retido (ISS retido pelo tomador), identificar (dados do cliente na nota; false = sem tomador),
     *                     fed (retenção federal: tipo, irrf, cp), avulso (documento e nome digitados: consumidor sem cadastro)
     * @return array{xml: string, id: string, tomador_documento: ?string, tomador_nome: ?string, descricao: string, valor_iss: ?float, valor_ret_federal: float, valor: float}
     */
    public static function montar(?Cliente $cliente, Collection $ordens, Empresa $e, array $cfg, string $ambiente, int $numero, array $aliq, array $op = [], ?Carbon $agora = null): array
    {
        $retido = (bool) ($op['retido'] ?? false);
        $identificar = (bool) ($op['identificar'] ?? true);
        $fed = (array) ($op['fed'] ?? []);
        $agora ??= now();
        $cnpj = preg_replace('/\D/', '', (string) $e->documento);
        $mun = (string) $cfg['municipio_ibge'];
        $serie = (string) (int) $cfg['serie'];
        $id = self::id($mun, $cnpj, $serie, $numero);
        $valor = round((float) $ordens->sum('valor'), 2);
        if ($valor <= 0) {
            throw ValidationException::withMessages(['nfse' => 'Sem valor: não há nota a emitir.']);
        }
        $descricao = self::descricao($ordens, $cfg);

        $d = new DOMDocument('1.0', 'UTF-8');
        $dps = $d->createElementNS(self::NS, 'DPS');
        $dps->setAttribute('versao', self::VERSAO);
        $d->appendChild($dps);
        $inf = self::filho($d, $dps, 'infDPS');
        $inf->setAttribute('Id', $id);
        self::filho($d, $inf, 'tpAmb', $ambiente === 'producao' ? '1' : '2');
        self::filho($d, $inf, 'dhEmi', $agora->copy()->setTimezone('America/Sao_Paulo')->format('Y-m-d\TH:i:sP'));
        self::filho($d, $inf, 'verAplic', 'ERP-Local-1.0');
        self::filho($d, $inf, 'serie', $serie);
        self::filho($d, $inf, 'nDPS', (string) $numero);
        self::filho($d, $inf, 'dCompet', $agora->copy()->setTimezone('America/Sao_Paulo')->format('Y-m-d'));   // competência = dia da emissão
        self::filho($d, $inf, 'tpEmit', '1');
        self::filho($d, $inf, 'cLocEmi', $mun);

        // prestador
        $prest = self::filho($d, $inf, 'prest');
        self::filho($d, $prest, 'CNPJ', $cnpj);
        // inscrição municipal do prestador não vai: o Emissor Nacional não manda (vem do cadastro nacional)
        $reg = self::filho($d, $prest, 'regTrib');
        self::filho($d, $reg, 'opSimpNac', (string) $cfg['op_simples']);
        if ($cfg['op_simples'] === '3') {
            self::filho($d, $reg, 'regApTribSN', (string) $cfg['reg_ap_trib_sn']);
        }
        self::filho($d, $reg, 'regEspTrib', (string) $cfg['reg_esp_trib']);

        // tomador: só com CPF/CNPJ válido (consumidor sem documento: nota sem tomador)
        [$tomDoc, $tomNome, $tomMun] = ! $identificar ? [null, null, null]
            : ($cliente ? self::tomador($d, $inf, $cliente) : self::tomadorAvulso($d, $inf, $op['avulso'] ?? []));
        if ($retido && ! $tomDoc) {
            throw ValidationException::withMessages(['nfse' => 'ISS retido precisa dos dados do cliente na nota (CPF/CNPJ válido no cadastro).']);
        }
        $fed = ['tipo' => (string) ($fed['tipo'] ?? '0'), 'irrf' => (bool) ($fed['irrf'] ?? false), 'cp' => (bool) ($fed['cp'] ?? false)];
        if (($fed['tipo'] !== '0' || $fed['irrf'] || $fed['cp']) && ! $tomDoc) {
            throw ValidationException::withMessages(['nfse' => 'A configuração tem retenção federal: a nota precisa dos dados do cliente.']);
        }
        if ($retido && $tomMun !== $mun) {
            throw ValidationException::withMessages(['nfse' => 'ISS retido: o cadastro do cliente precisa do endereço completo (CEP, rua, número, bairro) no município da prestação, como pede o Emissor Nacional.']);
        }

        // serviço
        $serv = self::filho($d, $inf, 'serv');
        $loc = self::filho($d, $serv, 'locPrest');
        self::filho($d, $loc, 'cLocPrestacao', $mun);
        $cs = self::filho($d, $serv, 'cServ');
        self::filho($d, $cs, 'cTribNac', (string) $cfg['c_trib_nac']);
        if (trim((string) $cfg['c_trib_mun']) !== '') {
            self::filho($d, $cs, 'cTribMun', trim((string) $cfg['c_trib_mun']));
        }
        self::filho($d, $cs, 'xDescServ', $descricao);
        if (trim((string) $cfg['c_nbs']) !== '') {
            self::filho($d, $cs, 'cNBS', preg_replace('/\D/', '', (string) $cfg['c_nbs']));
        }

        // valores
        $val = self::filho($d, $inf, 'valores');
        $vsp = self::filho($d, $val, 'vServPrest');
        self::filho($d, $vsp, 'vServ', number_format($valor, 2, '.', ''));
        $trib = self::filho($d, $val, 'trib');
        $tm = self::filho($d, $trib, 'tribMun');
        self::filho($d, $tm, 'tribISSQN', '1');            // operação tributável
        self::filho($d, $tm, 'tpRetISSQN', $retido ? '2' : '1');   // 2 = retido pelo tomador, 1 = não retido
        $valorIss = null;
        if ($retido) {
            $pAliq = round((float) str_replace(',', '.', (string) $aliq['iss']), 2);
            self::filho($d, $tm, 'pAliq', number_format($pAliq, 2, '.', ''));
            $valorIss = round($valor * $pAliq / 100, 2);
        }
        $valorFed = self::tribFed($d, $trib, $cfg, $valor, $fed);
        $tot = self::filho($d, $trib, 'totTrib');
        $pct = number_format((float) str_replace(',', '.', (string) $aliq['tributos']), 2, '.', '');
        if ($cfg['op_simples'] !== '1') {
            self::filho($d, $tot, 'pTotTribSN', $pct);
        } else {
            $pt = self::filho($d, $tot, 'pTotTrib');
            self::filho($d, $pt, 'pTotTribFed', '0.00');
            self::filho($d, $pt, 'pTotTribEst', '0.00');
            self::filho($d, $pt, 'pTotTribMun', $pct);
        }

        return ['xml' => $d->saveXML($dps), 'id' => $id, 'tomador_documento' => $tomDoc, 'tomador_nome' => $tomNome, 'descricao' => $descricao, 'valor_iss' => $valorIss, 'valor_ret_federal' => $valorFed, 'valor' => $valor];
    }

    /**
     * Tributação federal (tribFed). PIS/COFINS: CST e tipo de retenção (configuração) sempre, como no Emissor; base, alíquotas e valores só com CST de 01 a 07. Retidos (NT 007): vRetCSLL leva a
     * soma de PIS, COFINS e CSLL retidos; IRRF e contribuição previdenciária quando configurados.
     *
     * @return float total retido de tributos federais
     */
    private static function tribFed(DOMDocument $d, DOMElement $trib, array $cfg, float $valor, array $fed): float
    {
        $num = fn ($v) => (float) str_replace(',', '.', (string) $v);
        $fmt = fn (float $v) => number_format($v, 2, '.', '');
        $vez = fn (float $p) => round($valor * $p / 100, 2);
        $cst = (string) ($cfg['pis_cofins_cst'] ?? '00');
        $ret = ConfigNfse::RET_PIS_COFINS[$fed['tipo']] ?? throw ValidationException::withMessages(['nfse' => 'Tipo de retenção federal inválido.']);
        $pPis = $num($cfg['p_aliq_pis'] ?? 0);
        $pCofins = $num($cfg['p_aliq_cofins'] ?? 0);
        $pCsll = $num($cfg['p_ret_csll'] ?? 0);
        $pIrrf = $num($cfg['p_ret_irrf'] ?? 0);
        $pCp = $num($cfg['p_ret_cp'] ?? 0);
        $falta = array_keys(array_filter([
            'PIS' => $ret[1] && $pPis <= 0, 'COFINS' => $ret[2] && $pCofins <= 0, 'CSLL' => $ret[3] && $pCsll <= 0,
            'IRRF' => $fed['irrf'] && $pIrrf <= 0, 'previdenciária' => $fed['cp'] && $pCp <= 0,
        ]));
        if ($falta) {
            throw ValidationException::withMessages(['nfse' => 'Para reter, falta a alíquota de '.implode(', ', $falta).' em Configurações › Nota fiscal (tributação federal).']);
        }

        $f = self::filho($d, $trib, 'tribFed');
        $pc = self::filho($d, $f, 'piscofins');
        self::filho($d, $pc, 'CST', $cst);
        if (in_array($cst, ConfigNfse::CST_COM_BASE, true)) {
            self::filho($d, $pc, 'vBCPisCofins', $fmt($valor));
            self::filho($d, $pc, 'pAliqPis', $fmt($pPis));
            self::filho($d, $pc, 'pAliqCofins', $fmt($pCofins));
            self::filho($d, $pc, 'vPis', $fmt($vez($pPis)));
            self::filho($d, $pc, 'vCofins', $fmt($vez($pCofins)));
        }
        self::filho($d, $pc, 'tpRetPisCofins', $fed['tipo']);

        $total = 0.0;
        if ($fed['cp']) {
            self::filho($d, $f, 'vRetCP', $fmt($vez($pCp)));
            $total += $vez($pCp);
        }
        if ($fed['irrf']) {
            self::filho($d, $f, 'vRetIRRF', $fmt($vez($pIrrf)));
            $total += $vez($pIrrf);
        }
        $pRet = ($ret[1] ? $pPis : 0) + ($ret[2] ? $pCofins : 0) + ($ret[3] ? $pCsll : 0);
        if ($pRet > 0) {
            self::filho($d, $f, 'vRetCSLL', $fmt($vez($pRet)));
            $total += $vez($pRet);
        }

        return round($total, 2);
    }

    /**
     * Código IBGE da cidade do cliente, se o endereço estiver completo (CEP, rua e bairro). Ordem: o já guardado para
     * esse CEP; a lista da Baixada (pelo nome da cidade); a consulta do CEP no ViaCEP. O que achar fica guardado no
     * cliente (cidade_ibge + o CEP usado).
     */
    public static function municipioDoCliente(?Cliente $c): ?string
    {
        if (! $c || ! $c->logradouro || ! $c->bairro) {
            return null;
        }
        $cep = preg_replace('/\D/', '', (string) $c->cep);
        if (strlen($cep) !== 8) {
            return null;
        }
        if ($c->cidade_ibge && $c->cidade_ibge_cep === $cep) {
            return $c->cidade_ibge;
        }
        $mun = self::MUNICIPIOS[Str::of((string) $c->cidade)->ascii()->lower()->trim()->value()] ?? self::ibgeDoCep($cep);
        if ($mun && $c->exists) {
            $c->forceFill(['cidade_ibge' => $mun, 'cidade_ibge_cep' => $cep])->saveQuietly();
        }

        return $mun;
    }

    /** código IBGE da cidade pelo CEP (ViaCEP); null se não achar ou sem conexão */
    public static function ibgeDoCep(string $cep): ?string
    {
        try {
            $r = Http::timeout(5)->acceptJson()->get("https://viacep.com.br/ws/{$cep}/json/");
            $ibge = (string) ($r->json('ibge') ?? '');

            return $r->ok() && preg_match('/^\d{7}$/', $ibge) ? $ibge : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** tomador digitado na emissão avulsa (consumidor sem cadastro): CPF/CNPJ e nome, sem endereço */
    private static function tomadorAvulso(DOMDocument $d, DOMElement $inf, array $a): array
    {
        $doc = Documento::limpar($a['documento'] ?? null);
        if (! $doc) {
            return [null, null, null];
        }
        if (! Documento::valido($doc) || trim((string) ($a['nome'] ?? '')) === '') {
            throw ValidationException::withMessages(['nfse' => 'Para a nota com tomador, informe CPF/CNPJ válido e o nome.']);
        }
        $t = self::filho($d, $inf, 'toma');
        self::filho($d, $t, strlen($doc) === 14 ? 'CNPJ' : 'CPF', $doc);
        self::filho($d, $t, 'xNome', Str::limit(trim($a['nome']), 150, ''));

        return [$doc, trim($a['nome']), null];
    }

    private static function tomador(DOMDocument $d, DOMElement $inf, ?Cliente $c): array
    {
        $doc = Documento::limpar($c?->documento);
        if (! $c || ! $doc || ! Documento::valido($doc)) {
            return [null, $c?->nome, null];
        }
        $t = self::filho($d, $inf, 'toma');
        self::filho($d, $t, strlen($doc) === 14 ? 'CNPJ' : 'CPF', $doc);
        self::filho($d, $t, 'xNome', Str::limit(trim($c->nome), 150, ''));
        $mun = self::municipioDoCliente($c);
        $cep = preg_replace('/\D/', '', (string) $c->cep);
        $temEndereco = $mun !== null;   // código IBGE achado (lista, guardado ou pelo CEP)
        if ($temEndereco) {
            $end = self::filho($d, $t, 'end');
            $nac = self::filho($d, $end, 'endNac');
            self::filho($d, $nac, 'cMun', $mun);
            self::filho($d, $nac, 'CEP', $cep);
            self::filho($d, $end, 'xLgr', Str::limit($c->logradouro, 255, ''));
            self::filho($d, $end, 'nro', Str::limit($c->numero ?: 'S/N', 60, ''));
            if ($c->complemento) {
                self::filho($d, $end, 'xCpl', Str::limit($c->complemento, 156, ''));
            }
            self::filho($d, $end, 'xBairro', Str::limit($c->bairro, 60, ''));
        }
        if ($c->email && filter_var($c->email, FILTER_VALIDATE_EMAIL)) {
            self::filho($d, $t, 'email', $c->email);
        }

        return [$doc, $c->nome, $temEndereco ? $mun : null];
    }

    private static function filho(DOMDocument $d, DOMElement $pai, string $nome, ?string $texto = null): DOMElement
    {
        $e = $d->createElementNS(self::NS, $nome);
        if ($texto !== null) {
            $e->appendChild($d->createTextNode($texto));
        }
        $pai->appendChild($e);

        return $e;
    }
}
