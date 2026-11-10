<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/** Planilha CSV no formato do Excel brasileiro (ponto e vírgula, vírgula decimal, UTF-8 com BOM). */
class Csv
{
    public static function baixar(string $arquivo, array $cabecalho, iterable $linhas): StreamedResponse
    {
        return response()->streamDownload(function () use ($cabecalho, $linhas) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $cabecalho, ';', '"', '');
            foreach ($linhas as $l) {
                // evita fórmula no Excel em texto vindo de fora (=, +, -, @)
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) && ! is_numeric(str_replace(',', '.', $v)) ? "'".$v : $v, (array) $l), ';', '"', '');
            }
            fclose($out);
        }, $arquivo, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public static function numero($v): string
    {
        return number_format((float) $v, 2, ',', '');
    }
}
