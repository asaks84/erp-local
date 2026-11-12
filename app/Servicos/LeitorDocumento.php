<?php

namespace App\Servicos;

use Carbon\Carbon;
use Smalot\PdfParser\Parser;

/**
 * Lê boletos, guias (DAS, DARF, FGTS) e recibos de salário a partir do texto do PDF, do texto do
 * OCR (feito no navegador, quando o PDF é imagem ou foto) ou do código de barras colado.
 * O código de barras tem dígitos verificadores: quando ele aparece, o valor sai dele e é conferido.
 * Nada aqui grava: devolve sugestões que a pessoa confere antes de salvar.
 */
class LeitorDocumento
{
    /** marca que separa, no texto do OCR, a leitura normal da leitura com cores invertidas */
    public const INVERTIDO = '### INVERTIDO ###';

    private const MESES = ['janeiro' => 1, 'fevereiro' => 2, 'marco' => 3, 'março' => 3, 'abril' => 4, 'maio' => 5, 'junho' => 6,
        'julho' => 7, 'agosto' => 8, 'setembro' => 9, 'outubro' => 10, 'novembro' => 11, 'dezembro' => 12];

    /** texto do PDF; vazio quando o PDF é só imagem (impressão, digitalização) */
    public static function textoPdf(string $caminho): string
    {
        try {
            $texto = (new Parser)->parseFile($caminho)->getText();
        } catch (\Throwable) {
            return '';
        }

        return self::normalizar($texto);
    }

    public static function normalizar(string $t): string
    {
        $t = str_replace(["\u{00A0}", "\r"], [' ', ''], $t);
        $t = preg_replace('/[ \t]+/u', ' ', $t);

        return trim(preg_replace('/\n\s*\n+/', "\n", $t));
    }

    /** "1.234,56" -> 1234.56 */
    private static function numero(string $s): float
    {
        return round((float) str_replace(['.', ','], ['', '.'], $s), 2);
    }

    // ---------------------------------------------------------------- códigos de barras

    private static function mod10(string $num): int
    {
        $soma = 0;
        $peso = 2;
        for ($i = strlen($num) - 1; $i >= 0; $i--) {
            $p = (int) $num[$i] * $peso;
            $soma += intdiv($p, 10) + $p % 10;
            $peso = $peso === 2 ? 1 : 2;
        }

        return (10 - $soma % 10) % 10;
    }

    private static function mod11(string $num, bool $boleto): int
    {
        $soma = 0;
        $peso = 2;
        for ($i = strlen($num) - 1; $i >= 0; $i--) {
            $soma += (int) $num[$i] * $peso;
            $peso = $peso === 9 ? 2 : $peso + 1;
        }
        $resto = $soma % 11;
        if ($boleto) {
            $dv = 11 - $resto;

            return in_array($dv, [0, 10, 11], true) ? 1 : $dv;
        }

        return $resto <= 1 ? 0 : 11 - $resto;
    }

    /** vencimento pelo fator do boleto (a base mudou em 22/02/2025, quando o fator chegou a 9999) */
    private static function vencimentoFator(int $fator): ?string
    {
        if ($fator === 0) {
            return null;
        }
        $antiga = Carbon::create(1997, 10, 7)->addDays($fator);
        if ($antiga->lt(Carbon::create(2025, 2, 22))) {
            return Carbon::create(2025, 2, 22)->addDays($fator - 1000)->toDateString();
        }

        return $antiga->toDateString();
    }

    /**
     * Interpreta um código: linha digitável do boleto (47), código de barras (44) ou guia de
     * arrecadação (48, começa com 8). Devolve null se não for código válido.
     *
     * @return array{tipo:string,codigo:string,valor:?float,vencimento:?string,segmento:?int}|null
     */
    public static function codigo(string $entrada): ?array
    {
        $d = preg_replace('/\D/', '', $entrada);
        if (strlen($d) === 48 && $d[0] === '8') {
            $blocos = str_split($d, 12);
            $ref = (int) $d[2];
            $dez = in_array($ref, [6, 7], true);
            foreach ($blocos as $b) {
                $dv = $dez ? self::mod10(substr($b, 0, 11)) : self::mod11(substr($b, 0, 11), false);
                if ($dv !== (int) $b[11]) {
                    return null;
                }
            }
            $barras = implode('', array_map(fn ($b) => substr($b, 0, 11), $blocos));

            return self::arrecadacao($barras, $d);
        }
        if (strlen($d) === 44 && $d[0] === '8') {
            return self::arrecadacao($d, $d);
        }
        if (strlen($d) === 47) {
            $campos = [[substr($d, 0, 9), $d[9]], [substr($d, 10, 10), $d[20]], [substr($d, 21, 10), $d[31]]];
            foreach ($campos as [$n, $dv]) {
                if (self::mod10($n) !== (int) $dv) {
                    return null;
                }
            }
            $barras = substr($d, 0, 4).$d[32].substr($d, 33, 14).substr($d, 4, 5).substr($d, 10, 10).substr($d, 21, 10);

            return self::boletoBarras($barras, $d);
        }
        if (strlen($d) === 44) {
            return self::boletoBarras($d, $d);
        }

        return null;
    }

    private static function boletoBarras(string $b, string $codigo): ?array
    {
        if (self::mod11(substr($b, 0, 4).substr($b, 5), true) !== (int) $b[4]) {
            return null;
        }
        $valor = (int) substr($b, 9, 10) / 100;

        return ['tipo' => 'boleto', 'codigo' => $codigo, 'valor' => $valor > 0 ? $valor : null,
            'vencimento' => self::vencimentoFator((int) substr($b, 5, 4)), 'segmento' => null];
    }

    private static function arrecadacao(string $b, string $codigo): ?array
    {
        $ref = (int) $b[2];
        $semDv = substr($b, 0, 3).substr($b, 4);
        $dv = in_array($ref, [6, 7], true) ? self::mod10($semDv) : self::mod11($semDv, false);
        if ($dv !== (int) $b[3]) {
            return null;
        }
        // 6 e 8 = valor em reais; 7 e 9 = valor de referência (não é dinheiro)
        $valor = in_array($ref, [6, 8], true) ? (int) substr($b, 4, 11) / 100 : null;

        return ['tipo' => 'arrecadacao', 'codigo' => $codigo, 'valor' => $valor ?: null, 'vencimento' => null, 'segmento' => (int) $b[1]];
    }

    /** procura linha digitável ou código de guia dentro de um texto (PDF ou OCR) */
    public static function acharCodigo(string $texto): ?array
    {
        $sep = '[\s.|\[\]\-]*';
        // guia de arrecadação: 4 blocos de 11 + DV
        if (preg_match_all('/8\d{10}'.$sep.'\d(?:'.$sep.'\d{11}'.$sep.'\d){3}/', $texto, $m)) {
            foreach ($m[0] as $c) {
                if ($r = self::codigo($c)) {
                    return $r;
                }
            }
        }
        // boleto: 5.5 5.6 5.6 1 14
        if (preg_match_all('/\d{5}\.?\d{5}\s+\d{5}\.?\d{6}\s+\d{5}\.?\d{6}\s+\d\s+\d{14}/', $texto, $m)) {
            foreach ($m[0] as $c) {
                if ($r = self::codigo($c)) {
                    return $r;
                }
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- guias e boletos

    /** data dd/mm/aaaa, aceitando os espaços que o OCR põe entre os números ("1 8 / 09 / 2026") */
    private const DATA = '(\d)\s?(\d)\s*\/\s*(\d)\s?(\d)\s*\/\s*(\d)\s?(\d)\s?(\d)\s?(\d)';

    private static function montarData(array $m): ?string
    {
        [$d, $mes, $a] = [(int) ($m[1].$m[2]), (int) ($m[3].$m[4]), (int) ($m[5].$m[6].$m[7].$m[8])];

        return $a >= 2000 && checkdate($mes, $d, $a) ? sprintf('%04d-%02d-%02d', $a, $mes, $d) : null;
    }

    private static function data(string $texto, array $rotulos): ?string
    {
        foreach ($rotulos as $r) {
            if (preg_match_all('/'.$r.'[^0-9]{0,100}?'.self::DATA.'/iu', $texto, $ms, PREG_SET_ORDER)) {
                foreach ($ms as $m) {
                    if ($data = self::montarData($m)) {
                        return $data;
                    }
                }
            }
        }

        return null;
    }

    private static function valor(string $texto, array $rotulos): ?float
    {
        foreach ($rotulos as $r) {
            if (preg_match('/'.$r.'[^0-9]{0,40}?(\d{1,3}(?:\.?\d{3})*,\d{2})/iu', $texto, $m)) {
                return self::numero($m[1]);
            }
        }

        return null;
    }

    private static function competencia(string $texto): ?string
    {
        if (preg_match('/(?:PA|Compet[eê]ncia|Per[ií]odo de Apura[cç][aã]o)\s*:?\s*(0[1-9]|1[0-2])\/(20\d{2})/iu', $texto, $m)) {
            return "{$m[2]}-{$m[1]}";
        }
        if (preg_match('/\b(janeiro|fevereiro|mar[cç]o|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro)\s*\/\s*(20\d{2})/iu', $texto, $m)) {
            return $m[2].'-'.str_pad((string) self::MESES[mb_strtolower($m[1])], 2, '0', STR_PAD_LEFT);
        }
        if (preg_match('/(?<![\d\/])(0[1-9]|1[0-2])\/(20\d{2})(?![\d\/])/', $texto, $m)) {
            return "{$m[2]}-{$m[1]}";
        }

        return null;
    }

    /** CPFs e CNPJs escritos no texto, só números */
    private static function documentos(string $texto): array
    {
        preg_match_all('/\b\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}\b|\b\d{3}\.\d{3}\.\d{3}-\d{2}\b/', $texto, $m);

        return array_values(array_unique(array_map(fn ($x) => preg_replace('/\D/', '', $x), $m[0])));
    }

    /**
     * Lê um texto (do PDF ou do OCR) ou um código colado.
     *
     * @return array{tipo:string,rotulo:string,valor:?float,vencimento:?string,competencia:?string,descricao:?string,
     *   conta_chave:?string,documentos:array,beneficiario:?string,codigo:?string,conferido:bool,avisos:array}
     */
    public static function interpretar(string $texto): array
    {
        $texto = self::normalizar($texto);
        $r = ['tipo' => 'desconhecido', 'rotulo' => 'Documento', 'valor' => null, 'vencimento' => null, 'competencia' => null, 'descricao' => null,
            'conta_chave' => null, 'documentos' => self::documentos($texto), 'beneficiario' => null, 'codigo' => null, 'conferido' => false, 'avisos' => []];

        if (preg_match('/RECIBO DE PAGAMENTO DE SAL[AÁ]RIO/iu', $texto)) {
            $r['tipo'] = 'holerite';
            $r['rotulo'] = 'Recibo de salário';
            $r['avisos'][] = 'Isto é um recibo de salário: importe em Funcionários › Recibos de salário.';

            return $r;
        }

        $codigo = self::codigo($texto) ?? self::acharCodigo($texto);
        if ($codigo) {
            $r['codigo'] = $codigo['codigo'];
            $r['valor'] = $codigo['valor'];
            $r['vencimento'] = $codigo['vencimento'];
            $r['conferido'] = $codigo['valor'] !== null;
            $r['tipo'] = $codigo['tipo'];
            $r['rotulo'] = $codigo['tipo'] === 'boleto' ? 'Boleto' : 'Guia de arrecadação';
        }

        $comp = self::competencia($texto);
        $mesAno = $comp ? substr($comp, 5, 2).'/'.substr($comp, 0, 4) : '';
        $venc = self::data($texto, ['Pagar este documento at[eé]', 'Pagar at[eé]', 'Data de Vencimento', 'Vencimento da Guia', 'Vencimento']);
        $valorTexto = self::valor($texto, ['Valor Total do Documento', 'Total da Guia', 'Valor a recolher', '\(=\)\s*Valor do Documento', 'Valor do Documento', 'Valor Cobrado', 'Valor\s*:', 'Total a pagar']);

        if (preg_match('/Simples Nacional/iu', $texto) && preg_match('/Documento de Arrecada/iu', $texto)) {
            $r = array_merge($r, ['tipo' => 'das', 'rotulo' => 'DAS (Simples Nacional)', 'conta_chave' => 'despesa_simples', 'descricao' => trim('DAS '.$mesAno)]);
        } elseif (preg_match('/Receitas? Federa(?:l|is)/iu', $texto) && preg_match('/Documento de Arrecada|DARF/iu', $texto)) {
            $inss = preg_match('/PREV|INSS|CP SEGURADOS|PREVIDENCI/iu', $texto);
            $r = array_merge($r, ['tipo' => 'darf', 'rotulo' => 'DARF (Receita Federal)', 'conta_chave' => $inss ? 'despesa_inss' : null,
                'descricao' => trim(($inss ? 'INSS (DARF) ' : 'DARF ').$mesAno)]);
        } elseif (preg_match('/FGTS/u', $texto) && preg_match('/Guia do FGTS|GFD|FGTS Digital|fgtsdigital|Total FGTS|FUNDO DE GARANTIA/iu', $texto)) {
            $r = array_merge($r, ['tipo' => 'fgts', 'rotulo' => 'Guia do FGTS', 'conta_chave' => 'despesa_fgts', 'descricao' => trim('FGTS '.$mesAno)]);
            $fgts = self::valor($texto, ['Total FGTS\s*:']);
            $consig = self::valor($texto, ['Total Consignado\s*:']);
            $total = self::valor($texto, ['Total da Guia\s*:']) ?? $valorTexto;
            if ($total !== null && $fgts !== null && abs($fgts + ($consig ?? 0) - $total) < 0.01) {
                $r['valor'] = $total;
                $r['conferido'] = true;
            }
            if ($consig) {
                $r['avisos'][] = 'A guia inclui R$ '.number_format($consig, 2, ',', '.').' de empréstimo consignado descontado do salário.';
            }
            // o vencimento da guia do FGTS fica numa caixa escura: o OCR do navegador faz uma 2ª leitura
            // com as cores invertidas (depois da marca), e a 1ª data dela é a da caixa
            if (! $venc && ($p = mb_strpos($texto, self::INVERTIDO)) !== false
                && preg_match('/'.self::DATA.'/', mb_substr($texto, $p), $m)) {
                $venc = self::montarData($m);
            }
        } elseif ($codigo && $codigo['tipo'] === 'boleto') {
            if (preg_match('/(?:Cedente|Benefici[aá]rio)\s+([A-ZÀ-Ú][A-ZÀ-Ú0-9 .&\/-]{3,60}?)\s+(?:CPF|CNPJ)/u', $texto, $m)) {
                $r['beneficiario'] = trim($m[1]);
            }
            if (preg_match('/REF(?:ERENTE)?\.?\s*:?\s*([^\n]{4,80})/u', $texto, $m)) {
                $r['descricao'] = trim($m[1]);
            }
        }

        $r['competencia'] = $comp;
        $r['vencimento'] ??= $venc;
        if ($r['valor'] === null) {
            $r['valor'] = $valorTexto;
        } elseif ($valorTexto !== null && abs($valorTexto - $r['valor']) >= 0.01 && $codigo) {
            $r['avisos'][] = 'O valor escrito no documento (R$ '.number_format($valorTexto, 2, ',', '.').') é diferente do código de barras: usei o do código.';
        }
        if ($r['valor'] !== null && ! $r['conferido']) {
            $r['avisos'][] = 'Confira o valor: ele foi lido do texto, sem código de barras para conferir.';
        }
        if (! $r['vencimento']) {
            $r['avisos'][] = 'Não achei o vencimento: preencha.';
        }
        if ($r['tipo'] === 'desconhecido' && $r['valor'] === null) {
            $r['avisos'] = ['Não reconheci este documento. Preencha os campos à mão.'];
        }

        return $r;
    }

    // ---------------------------------------------------------------- recibos de salário

    /**
     * Recibos de salário do PDF do contador (um por funcionário; as 2 vias contam uma vez).
     *
     * @return array<int, array{matricula:string,nome:string,funcao:?string,competencia:string,salario_base:?float,
     *   vencimentos:float,descontos:float,liquido:float,fgts:?float,itens:array,conferido:bool}>
     */
    public static function holerites(string $texto, ?string $cidade = null): array
    {
        $texto = self::normalizar($texto);
        $blocos = preg_split('/RECIBO DE PAGAMENTO DE SAL[AÁ]RIO/iu', $texto);
        array_shift($blocos);
        $num = '(\d{1,3}(?:\.?\d{3})*,\d{2})';
        $lista = [];
        foreach ($blocos as $b) {
            if (! preg_match('/Compet[eê]ncia\s*(0[1-9]|1[0-2])\/(20\d{2})/iu', $b, $c)
                || ! preg_match('/Reg\.?\s*Chapa\s*Nome\s*(\d{1,10})\s+([^\n]+)/iu', $b, $p)
                || ! preg_match('/Total\s+de\s+Descontos\s*'.$num.'\s+'.$num.'\s+'.$num.'\s+'.$num.'/iu', $b, $t)) {
                continue;
            }
            $matricula = ltrim($p[1], '0') ?: '0';
            $comp = "{$c[2]}-{$c[1]}";
            if (isset($lista[$matricula.$comp])) {
                continue; // 2ª via
            }
            $liquido = null;
            $fgts = null;
            if (preg_match('/A\s*R\s*E\s*C\s*E\s*B\s*E\s*R\s*'.$num.'\s+'.$num.'\s+'.$num.'\s+'.$num.'/iu', $b, $l)) {
                $fgts = self::numero($l[2]);
                $liquido = self::numero($l[4]);
            }
            $venc = self::numero($t[3]);
            $desc = self::numero($t[4]);
            $liquido ??= round($venc - $desc, 2);
            $funcao = null;
            if (preg_match('/Fun[cç][aã]o\s*\n([^\n]+?)\s+[A-Z]{2}\s+CNPJ/u', $b, $f)) {
                $funcao = trim($f[1]);
                if ($cidade && str_ends_with(mb_strtolower($funcao), mb_strtolower($cidade))) {
                    $funcao = trim(mb_substr($funcao, 0, mb_strlen($funcao) - mb_strlen($cidade)));
                } elseif (preg_match('/^(.+?)([A-ZÀ-Ú][a-zà-ú]+(?:\s+(?:d[aeo]s?\s+)?[A-ZÀ-Ú][a-zà-ú]+)*)$/u', $funcao, $x)) {
                    $funcao = trim($x[1]);
                }
            }
            $itens = [];
            if (preg_match('/Descontos\s*\n(.*?)RESUMO/isu', $b, $corpo)) {
                foreach (explode("\n", $corpo[1]) as $linha) {
                    if (preg_match('/^(\d{3})\s+(.+?)((?:\s+\d{1,3}(?:\.?\d{3})*,\d{2})+)\s*$/u', trim($linha), $i)) {
                        preg_match_all('/\d{1,3}(?:\.?\d{3})*,\d{2}/', $i[3], $vs);
                        $itens[] = ['cod' => $i[1], 'descricao' => trim($i[2]), 'valores' => array_map(fn ($v) => self::numero($v), $vs[0])];
                    }
                }
            }
            $lista[$matricula.$comp] = [
                'matricula' => $matricula,
                'nome' => mb_strtoupper(trim(preg_replace('/\s+/', ' ', $p[2]))),
                'funcao' => $funcao ? mb_substr($funcao, 0, 60) : null,
                'competencia' => $comp,
                'salario_base' => self::numero($t[1]),
                'vencimentos' => $venc,
                'descontos' => $desc,
                'liquido' => $liquido,
                'fgts' => $fgts,
                'itens' => $itens,
                'conferido' => abs($venc - $desc - $liquido) < 0.01,
            ];
        }

        return array_values($lista);
    }

    /** 5º dia útil do mês (sábado conta, domingo e feriado nacional fixo não) — data de pagamento do salário */
    public static function quintoDiaUtil(string $mesAno): string
    {
        $d = Carbon::createFromFormat('Y-m-d', $mesAno.'-01');
        $feriados = ['01-01', '04-21', '05-01', '09-07', '10-12', '11-02', '11-15', '11-20', '12-25'];
        $uteis = 0;
        while (true) {
            if (! $d->isSunday() && ! in_array($d->format('m-d'), $feriados, true)) {
                $uteis++;
                if ($uteis === 5) {
                    return $d->toDateString();
                }
            }
            $d->addDay();
        }
    }
}
