<?php

namespace App\Servicos\Pdv;

use App\Models\Empresa;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Modelos de impressão do PDV (8.8), editados em Configurações › Impressão do PDV. O desenho de cada papel
 * (linhas, ordem, formato) fica na configuração da empresa; o PDV monta o papel com resources/js/pdv/impressao/modelo.js.
 */
class ImpressaoModelos
{
    private const CABECALHO = ['logo', 'nome', 'cnpj', 'endereco', 'telefone'];

    /**
     * chaves aceitas em cada papel (as mesmas de CAMPOS_DO_PAPEL em modelo.js), além de espaço, linha e texto.
     * Três papéis: o ticket (estadia e lavagem), o recibo (todo pagamento) e o fechamento do caixa.
     */
    public const CAMPOS = [
        'ticket' => [...self::CABECALHO, 'numero', 'servico', 'placa', 'tamanho', 'entrada', 'pago_entrada', 'qr', 'link', 'carimbo'],
        'recibo' => [...self::CABECALHO, 'titulo', 'cliente', 'data', 'placa', 'tamanho', 'entrada', 'saida', 'tempo', 'itens', 'pago', 'na_conta'],
        'fechamento' => [...self::CABECALHO, 'titulo', 'periodo', 'por_servico', 'por_forma', 'dinheiro', 'movimentos', 'assinatura'],
    ];

    private const LIVRES = ['espaco', 'hr', 'texto'];

    /** o que o PDV e o editor recebem: modelos salvos (só os papéis editados) e o logo */
    public static function config(): array
    {
        $e = Empresa::atual();

        // só os papéis que existem (um modelo salvo de papel antigo é ignorado)
        $modelos = array_intersect_key($e->config('impressao_modelos') ?? [], self::CAMPOS);

        return ['modelos' => (object) $modelos, 'logo' => $e->config('impressao_logo')];
    }

    /** confere e limpa o modelo de um papel; lança erro de validação se vier algo fora do lugar */
    public static function limpar(string $papel, array $linhas): array
    {
        if (! isset(self::CAMPOS[$papel])) {
            throw ValidationException::withMessages(['modelos' => 'Papel desconhecido.']);
        }
        if (count($linhas) > 80) {
            throw ValidationException::withMessages(['modelos' => 'Linhas demais neste papel (máximo 80).']);
        }
        $aceitas = [...self::CAMPOS[$papel], ...self::LIVRES];
        $vistos = [];

        return collect($linhas)->map(function ($l) use ($aceitas, $papel, &$vistos) {
            $k = is_array($l) ? (string) ($l['k'] ?? '') : '';
            if (! in_array($k, $aceitas, true)) {
                throw ValidationException::withMessages(['modelos' => "Linha \"{$k}\" não existe em {$papel}."]);
            }
            // cada dado aparece uma vez; espaço, linha e texto podem repetir
            if (! in_array($k, self::LIVRES, true)) {
                if (isset($vistos[$k])) {
                    throw ValidationException::withMessages(['modelos' => "\"{$k}\" repetido em {$papel}."]);
                }
                $vistos[$k] = true;
            }
            $al = $l['al'] ?? 'e';
            $limpa = ['k' => $k, 'on' => (bool) ($l['on'] ?? true), 'al' => in_array($al, ['e', 'c', 'd'], true) ? $al : 'e',
                'neg' => (bool) ($l['neg'] ?? false), 'gra' => (bool) ($l['gra'] ?? false)];
            if ($k === 'texto') {
                $limpa['txt'] = mb_substr(trim((string) ($l['txt'] ?? '')), 0, 200);
            }
            if ($k === 'espaco') {
                $limpa['alt'] = max(1, min(6, (int) ($l['alt'] ?? 1)));
            }
            if ($k === 'qr') {
                $tam = $l['tam'] ?? 'M';
                $limpa['tam'] = in_array($tam, ['P', 'M', 'G'], true) ? $tam : 'M';
            }

            return $limpa;
        })->values()->all();
    }

    /** salva os papéis enviados; papel com null volta ao padrão */
    public static function salvar(array $modelos): void
    {
        $e = Empresa::atual();
        $atuais = array_intersect_key($e->config('impressao_modelos') ?? [], self::CAMPOS);
        foreach ($modelos as $papel => $linhas) {
            if ($linhas === null) {
                unset($atuais[$papel]);

                continue;
            }
            if (! is_array($linhas)) {
                throw ValidationException::withMessages(['modelos' => 'Modelo inválido.']);
            }
            $atuais[$papel] = self::limpar((string) $papel, $linhas);
        }
        $e->configuracoes = array_merge($e->configuracoes ?? [], ['impressao_modelos' => $atuais]);
        $e->save();
    }

    /**
     * logo para o papel térmico: até 384 pontos de largura (cabe na bobina de 58 e de 80 mm), em preto e branco puro,
     * guardado como PNG pequeno na configuração (o PDV recebe junto com os modelos).
     */
    public static function salvarLogo(UploadedFile $arquivo): string
    {
        $origem = @imagecreatefromstring((string) file_get_contents($arquivo->getRealPath()));
        if (! $origem) {
            throw ValidationException::withMessages(['logo' => 'Não consegui ler essa imagem. Use PNG ou JPG.']);
        }
        $w = imagesx($origem);
        $h = imagesy($origem);
        $nw = min(384, $w);
        $nh = (int) round($h * $nw / $w);
        if ($nh > 200) {
            $nh = 200;
            $nw = (int) round($w * $nh / $h);
        }
        $nw -= $nw % 8;
        $img = imagecreatetruecolor(max(8, $nw), max(1, $nh));
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imagecopyresampled($img, $origem, 0, 0, 0, 0, max(8, $nw), max(1, $nh), $w, $h);
        $bw = imagecreate(max(8, $nw), max(1, $nh));
        $branco = imagecolorallocate($bw, 255, 255, 255);
        $preto = imagecolorallocate($bw, 0, 0, 0);
        for ($y = 0; $y < imagesy($img); $y++) {
            for ($x = 0; $x < imagesx($img); $x++) {
                $c = imagecolorat($img, $x, $y);
                $lum = (($c >> 16) & 0xFF) * .299 + (($c >> 8) & 0xFF) * .587 + ($c & 0xFF) * .114;
                imagesetpixel($bw, $x, $y, $lum < 150 ? $preto : $branco);
            }
        }
        ob_start();
        imagepng($bw, null, 9);
        $png = (string) ob_get_clean();
        $url = 'data:image/png;base64,'.base64_encode($png);
        $e = Empresa::atual();
        $e->configuracoes = array_merge($e->configuracoes ?? [], ['impressao_logo' => $url]);
        $e->save();

        return $url;
    }

    public static function tirarLogo(): void
    {
        $e = Empresa::atual();
        $c = $e->configuracoes ?? [];
        unset($c['impressao_logo']);
        $e->configuracoes = $c;
        $e->save();
    }
}
