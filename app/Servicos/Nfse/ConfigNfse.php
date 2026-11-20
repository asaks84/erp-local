<?php

namespace App\Servicos\Nfse;

use App\Models\Empresa;
use App\Models\Integracao;

/**
 * Configuração da NFS-e (empresas.configuracoes.nfse) e o certificado A1 (integracoes tipo "nfse", criptografado).
 *
 * Regime (campos regTrib da DPS):
 * - op_simples: 1 não optante, 2 MEI, 3 ME/EPP no Simples Nacional;
 * - reg_ap_trib_sn (só ME/EPP): 1 tributos federais e ISS pelo Simples (DAS); 2 federais pelo Simples e ISS por
 *   fora, pela regra do município (o "híbrido"); 3 federais e ISS fora do Simples.
 */
class ConfigNfse
{
    public const PADRAO = [
        'ativo' => false,
        'municipio_ibge' => '3548500',   // Santos/SP
        'im' => '',
        'op_simples' => '3',
        'reg_ap_trib_sn' => '1',
        'reg_esp_trib' => '0',
        'c_trib_nac' => '110101',        // 11.01.01 - Guarda e estacionamento de veículos terrestres automotores
        'c_trib_mun' => '004',           // 11.01.01.004 - Estacionamento de veículos - outros veículos (Santos)
        'c_nbs' => '106043000',          // Serviços de estacionamento
        'serie' => '1',
        'descricao' => 'Serviço de estacionamento',
        'especificar_servicos' => false,  // nome do serviço (estadia, lavagem) em cada linha; mensalidade sempre vai
        // tributação federal (como no Emissor: "00 - Nenhum" e "0 - Não retidos" para quem está no Simples)
        'pis_cofins_cst' => '00',
        'tp_ret_pis_cofins' => '0',      // tipo de retenção quando o cliente retém (no Simples: 0, não há retenção)
        // alíquotas: valem para a base (CST 01 a 07) e para as retenções
        'p_aliq_pis' => '',              // % (lucro presumido 0,65 / real 1,65; retenção 0,65)
        'p_aliq_cofins' => '',           // % (presumido 3,00 / real 7,60; retenção 3,00)
        'p_ret_csll' => '1.00',          // % da CSLL quando retida
        'p_ret_irrf' => '',              // % do IRRF quando o cliente retém (em branco = não entra)
        'p_ret_cp' => '',                // % da previdenciária quando o cliente retém (em branco = não entra)
        // IBS/CBS ("Preencher as informações IBS/CBS?" no Emissor)
        'ibs_cbs' => false,
        'ibs_cbs_cst' => '',
        'ibs_cbs_class' => '',
        // alíquotas do mês, do e-mail da contabilidade: ['2026-11' => ['iss' => '2.01', 'tributos' => '17.62']]
        'aliquotas' => [],
    ];

    /** textos iguais aos do Emissor Nacional */
    public const OP_SIMPLES = [
        '1' => 'Não Optante',
        '2' => 'Optante - Microempreendedor Individual (MEI)',
        '3' => 'Optante - Microempresa ou Empresa de Pequeno Porte (ME/EPP)',
    ];

    public const REG_AP = [
        '1' => 'Regime de apuração dos tributos federais e municipal pelo Simples Nacional',
        '2' => 'Regime de apuração dos tributos federais pelo SN e o ISSQN pela NFS-e conforme respectiva legislação municipal do tributo',
        '3' => 'Regime de apuração dos tributos federais e municipal pela NFS-e conforme respectivas legislações federal e municipal de cada tributo',
    ];

    public const REG_ESP = ['0' => 'Nenhum', '1' => 'Ato Cooperado (Cooperativa)', '2' => 'Estimativa', '3' => 'Microempresa Municipal', '4' => 'Notário ou Registrador', '5' => 'Profissional Autônomo', '6' => 'Sociedade de Profissionais'];

    /** Situação Tributária do PIS/COFINS (textos do Emissor) */
    public const CST_PIS_COFINS = [
        '00' => '00 - Nenhum',
        '01' => '01 - Operação Tributável com Alíquota Básica',
        '02' => '02 - Operação Tributável com Alíquota Diferenciada',
        '03' => '03 - Operação Tributável com Alíquota por Unidade de Medida de Produto',
        '04' => '04 - Operação Tributável monofásica - Revenda a Alíquota Zero',
        '05' => '05 - Operação Tributável por Substituição Tributária',
        '06' => '06 - Operação Tributável a Alíquota Zero',
        '07' => '07 - Operação Isenta da Contribuição',
        '08' => '08 - Operação sem Incidência da Contribuição',
        '09' => '09 - Operação com Suspensão da Contribuição',
    ];

    /** CST com base de cálculo e alíquotas (00, 08 e 09 não levam base) */
    public const CST_COM_BASE = ['01', '02', '03', '04', '05', '06', '07'];

    /** Tipo de retenção do PIS/COFINS/CSLL: código => [texto, retém PIS, COFINS, CSLL] */
    public const RET_PIS_COFINS = [
        '0' => ['0 - PIS/COFINS/CSLL Não Retidos', false, false, false],
        '3' => ['3 - PIS/COFINS/CSLL Retidos', true, true, true],
        '4' => ['4 - PIS/COFINS Retidos, CSLL Não Retido', true, true, false],
        '5' => ['5 - PIS Retido, COFINS/CSLL Não Retido', true, false, false],
        '6' => ['6 - COFINS Retido, PIS/CSLL Não Retido', false, true, false],
        '7' => ['7 - COFINS/CSLL Retidos, PIS Não Retido', false, true, true],
        '8' => ['8 - CSLL Retido, PIS/COFINS Não Retido', false, false, true],
        '9' => ['9 - PIS/CSLL Retidos, COFINS Não Retido', true, false, true],
    ];

    /** retenção federal da configuração: tipo do PIS/COFINS/CSLL e se retém IRRF e previdenciária (percentual preenchido) */
    public static function retencaoConfigurada(array $c): array
    {
        $n = fn ($k) => (float) str_replace(',', '.', (string) ($c[$k] ?? 0));

        return ['tipo' => (string) ($c['tp_ret_pis_cofins'] ?? '0'), 'irrf' => $n('p_ret_irrf') > 0, 'cp' => $n('p_ret_cp') > 0];
    }

    /** % do valor retido em tributos federais, para a prévia */
    public static function retencaoFederal(array $c): float
    {
        $n = fn ($k) => (float) str_replace(',', '.', (string) ($c[$k] ?? 0));
        $r = self::RET_PIS_COFINS[(string) ($c['tp_ret_pis_cofins'] ?? '0')] ?? self::RET_PIS_COFINS['0'];

        return round(($r[1] ? $n('p_aliq_pis') : 0) + ($r[2] ? $n('p_aliq_cofins') : 0) + ($r[3] ? $n('p_ret_csll') : 0) + $n('p_ret_irrf') + $n('p_ret_cp'), 2);
    }

    /** nomes dos códigos mais prováveis, para mostrar ao lado do número */
    public const NOMES = [
        'c_trib_nac' => ['110101' => 'Guarda e estacionamento de veículos terrestres automotores', '110102' => 'Guarda e estacionamento de aeronaves e de embarcações'],
        'c_trib_mun' => ['001' => 'Garagista', '002' => 'Guardador de veículos', '003' => 'Estacionamento de veículos - ônibus, caminhões, carretas e outros veículos pesados', '004' => 'Estacionamento de veículos - outros veículos, não especificados anteriormente'],
        'c_nbs' => ['106043000' => 'Serviços de estacionamento'],
    ];

    /** "11.01.01" -> "110101"; "11.01.01.004" -> "004" (o Emissor mostra o código completo; na DPS vão só os 3 últimos) */
    public static function normalizar(array $d): array
    {
        foreach (['c_trib_nac', 'c_nbs', 'im', 'municipio_ibge'] as $k) {
            if (isset($d[$k])) {
                $d[$k] = preg_replace('/\D/', '', (string) $d[$k]);
            }
        }
        if (isset($d['c_trib_mun'])) {
            $n = preg_replace('/\D/', '', (string) $d['c_trib_mun']);
            $d['c_trib_mun'] = strlen($n) > 3 ? substr($n, -3) : $n;
        }

        return $d;
    }

    /** alíquotas da competência (AAAA-MM) ou null se a contabilidade ainda não informou */
    public static function aliquotas(string $competencia, ?Empresa $e = null): ?array
    {
        $a = self::ler($e)['aliquotas'][$competencia] ?? null;

        return $a && ($a['iss'] ?? '') !== '' && ($a['tributos'] ?? '') !== '' ? $a : null;
    }

    public static function gravarAliquotas(string $competencia, string $iss, string $tributos, ?Empresa $e = null): void
    {
        $todas = self::ler($e)['aliquotas'];
        $todas[$competencia] = ['iss' => $iss, 'tributos' => $tributos];
        krsort($todas);
        self::gravar(['aliquotas' => array_slice($todas, 0, 24, true)], $e);   // guarda 2 anos
    }

    public static function ler(?Empresa $e = null): array
    {
        $e ??= Empresa::atual();

        $salvo = (array) (($e->configuracoes ?? [])['nfse'] ?? []);
        // códigos em branco (gravados antes de existir o padrão) ficam com o do estacionamento
        foreach (['c_trib_nac', 'c_trib_mun', 'c_nbs'] as $k) {
            if (($salvo[$k] ?? null) === '') {
                unset($salvo[$k]);
            }
        }

        return array_merge(self::PADRAO, $salvo);
    }

    public static function gravar(array $dados, ?Empresa $e = null): void
    {
        $e ??= Empresa::atual();
        $c = $e->configuracoes ?? [];
        $c['nfse'] = array_merge(self::ler($e), array_intersect_key($dados, self::PADRAO));
        $e->configuracoes = $c;
        $e->save();
    }

    public static function integracao(): ?Integracao
    {
        return Integracao::where('tipo', 'nfse')->first();
    }

    /** o que falta para emitir (lista vazia = pronto) */
    public static function faltando(?Empresa $e = null): array
    {
        $e ??= Empresa::atual();
        $c = self::ler($e);
        $i = self::integracao();
        $f = [];
        if (strlen(preg_replace('/\D/', '', (string) $e->documento)) !== 14) {
            $f[] = 'CNPJ da empresa (Empresa e unidades)';
        }
        if (! preg_match('/^\d{6}$/', (string) $c['c_trib_nac'])) {
            $f[] = 'código de tributação nacional';
        }
        if (! preg_match('/^\d{3}$/', (string) $c['c_trib_mun'])) {
            $f[] = 'código complementar municipal';
        }
        if (! preg_match('/^\d{9}$/', (string) $c['c_nbs'])) {
            $f[] = 'item da NBS';
        }
        if ($c['ibs_cbs'] && (! preg_match('/^\d{3}$/', (string) $c['ibs_cbs_cst']) || ! preg_match('/^\d{6}$/', (string) $c['ibs_cbs_class']))) {
            $f[] = 'códigos de IBS/CBS';
        }
        if (! $i?->credencial('certificado') || ! $i?->credencial('chave')) {
            $f[] = 'certificado digital A1';
        } elseif ($i->certificado_validade && $i->certificado_validade->isPast()) {
            $f[] = 'certificado digital A1 válido (o atual venceu)';
        }

        return $f;
    }

    public static function ambiente(): string
    {
        return self::integracao()?->ambiente === 'producao' ? 'producao' : 'restrita';
    }
}
