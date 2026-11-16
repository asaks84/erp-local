<?php

namespace App\Servicos\WhatsApp;

/**
 * Modelos de mensagem da régua (aprovados pela Meta, categoria utilidade). O mesmo texto vale no envio
 * manual (wa.me). Variáveis: {{1}} primeiro nome, {{2}} empresa, {{3}} valor, {{4}} vencimento.
 * O botão "Ver cobrança" abre o link público da cobrança.
 */
class Modelos
{
    public const IDIOMA = 'pt_BR';

    public const TEXTOS = [
        'cobranca_lembrete' => 'Olá, {{1}}! Aqui é do {{2}}. Sua cobrança de {{3}} vence em {{4}}. Para ver os detalhes e as formas de pagamento, toque no botão abaixo.',
        'cobranca_vence_hoje' => 'Olá, {{1}}! Aqui é do {{2}}. Sua cobrança de {{3}} vence hoje, {{4}}. Para ver os detalhes e as formas de pagamento, toque no botão abaixo.',
        'cobranca_em_atraso' => 'Olá, {{1}}! Aqui é do {{2}}. Sua cobrança de {{3}} venceu em {{4}} e ainda está em aberto. Para ver os detalhes e pagar, toque no botão abaixo. Se já pagou, desconsidere este aviso.',
    ];

    public const ROTULOS = ['cobranca_lembrete' => 'Lembrete (antes do vencimento)', 'cobranca_vence_hoje' => 'Vence hoje', 'cobranca_em_atraso' => 'Em atraso'];

    /** modelo usado para um passo da régua (dias em relação ao vencimento) */
    public static function doPasso(int $dias): string
    {
        return $dias < 0 ? 'cobranca_lembrete' : ($dias === 0 ? 'cobranca_vence_hoje' : 'cobranca_em_atraso');
    }

    /** texto pronto (para o envio manual e para guardar no aviso) */
    public static function texto(string $modelo, array $valores, ?string $link = null): string
    {
        $t = self::TEXTOS[$modelo];
        foreach (array_values($valores) as $i => $v) {
            $t = str_replace('{{'.($i + 1).'}}', (string) $v, $t);
        }
        if ($link) {
            $t = str_replace(['toque no botão abaixo.', 'toque no botão abaixo'], 'acesse: '.$link, $t);
        }

        return $t;
    }

    /** definição do modelo para criar na Meta */
    public static function definicao(string $modelo, string $baseLink): array
    {
        return [
            'name' => $modelo, 'language' => self::IDIOMA, 'category' => 'UTILITY',
            'components' => [
                ['type' => 'BODY', 'text' => self::TEXTOS[$modelo], 'example' => ['body_text' => [['Maria', 'Local Estacionamento', 'R$ 250,00', '10/12/2026']]]],
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'Ver cobrança', 'url' => rtrim($baseLink, '/').'/c/{{1}}', 'example' => [rtrim($baseLink, '/').'/c/exemplo']]]],
            ],
        ];
    }
}
