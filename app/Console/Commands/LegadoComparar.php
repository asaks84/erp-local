<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Models\LogAtividade;
use App\Models\Nivel;
use App\Models\Usuario;
use App\Support\EmpresaAtual;
use App\Support\Legado;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Compara os números do sistema atual com os do ERP e grava o relatório em Markdown
 * (storage/app/relatorios/). Antes da virada, qualquer diferença trava a virada.
 * O que ainda não é migrado aparece como base de referência para as próximas etapas.
 */
class LegadoComparar extends Command
{
    protected $signature = 'legado:comparar';

    protected $description = 'Compara os números do sistema atual com os do ERP';

    public function handle(Legado $legado, EmpresaAtual $empresaAtual): int
    {
        $empresa = Empresa::first();
        if (! $empresa) {
            $this->error('Nenhuma empresa no ERP. Rode antes: php artisan legado:importar');

            return self::FAILURE;
        }

        $linhas = $empresaAtual->como($empresa->id, fn () => $this->comparar($legado));

        $this->table(['Item', 'Sistema atual', 'ERP', 'Situação'], $linhas);

        $md = $this->markdown($empresa, $linhas);
        $arquivo = 'relatorios/comparacao-'.now()->format('Y-m-d-Hi').'.md';
        Storage::disk('local')->put($arquivo, $md);
        $this->info('Relatório gravado em storage/app/private/'.$arquivo);

        $diferencas = collect($linhas)->where(3, 'DIFERENTE')->count();
        if ($diferencas) {
            $this->error("{$diferencas} diferença(s) encontrada(s).");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function comparar(Legado $legado): array
    {
        $db = $legado->conexao();
        $linhas = [];
        $igual = fn ($a, $b) => (string) $a === (string) $b ? 'ok' : 'DIFERENTE';

        // migrado na etapa 1
        $niveis = $legado->contar('NIVEIS_ACESSO') ?? 2;
        $linhas[] = ['Níveis de acesso', $niveis, $n = Nivel::whereNotNull('legado_id')->count(), $igual($niveis, $n)];
        $usuarios = $legado->contar('USUARIOS');
        $linhas[] = ['Usuários', $usuarios, $n = Usuario::whereNotNull('legado_id')->count(), $igual($usuarios, $n)];
        $admins = $legado->tem('USUARIOS', 'usr_niv_id') && $legado->tabela('NIVEIS_ACESSO')
            ? (int) $db->selectOne('SELECT COUNT(*) n FROM `'.$legado->tabela('USUARIOS').'` u JOIN `'.$legado->tabela('NIVEIS_ACESSO').'` v ON v.niv_id = u.usr_niv_id WHERE v.niv_fixo = 1')->n
            : $legado->contar('USUARIOS', 'usr_admin = 1');
        $linhas[] = ['Usuários com acesso total', $admins,
            $n = Usuario::whereNotNull('legado_id')->whereHas('nivel', fn ($q) => $q->where('fixo', true))->count(), $igual($admins, $n)];
        if ($legado->tabela('LOG_ATIVIDADES')) {
            $log = $legado->contar('LOG_ATIVIDADES');
            $linhas[] = ['Registro de atividades', $log, $n = LogAtividade::whereNotNull('legado_id')->count(), $igual($log, $n)];
        }

        // base para as próximas etapas (ainda não migrado)
        $falta = 'etapa 2';
        $linhas[] = ['Clientes', $legado->contar('CLIENTES'), '—', $falta];
        $linhas[] = ['Veículos', $legado->contar('VEICULOS'), '—', $falta];

        $ordens = $legado->exige('ORDEM_SERVICOS');
        $aberto = $db->select("SELECT ord_cli_id, SUM(CASE WHEN ord_srv_codigo = 'CDT' THEN -ord_valor ELSE ord_valor END) total
            FROM `{$ordens}` WHERE ord_dt_pagamento IS NULL GROUP BY ord_cli_id HAVING total > 0");
        $linhas[] = ['Clientes com contas em aberto', count($aberto), '—', 'etapa 3'];
        $linhas[] = ['Total em aberto (R$)', $this->moeda(collect($aberto)->sum('total')), '—', 'etapa 3'];

        $meses = $db->select("SELECT DATE_FORMAT(ord_dt_pagamento, '%Y-%m') mes,
                SUM(CASE WHEN ord_srv_codigo = 'CDT' THEN -ord_valor ELSE ord_valor END) total
            FROM `{$ordens}` WHERE ord_dt_pagamento >= ? GROUP BY mes ORDER BY mes", [now()->subMonths(11)->startOfMonth()->toDateString()]);
        foreach ($meses as $m) {
            $linhas[] = ["Recebido em {$m->mes} (R$)", $this->moeda($m->total), '—', 'etapa 3'];
        }

        return $linhas;
    }

    private function moeda($valor): string
    {
        return number_format((float) $valor, 2, ',', '.');
    }

    private function markdown(Empresa $empresa, array $linhas): string
    {
        $md = "# Comparação sistema atual × ERP\n\n";
        $md .= '- Empresa: '.$empresa->nome."\n";
        $md .= '- Gerado em: '.now()->format('d/m/Y H:i')."\n\n";
        $md .= "| Item | Sistema atual | ERP | Situação |\n|---|---:|---:|---|\n";
        foreach ($linhas as $l) {
            $md .= '| '.implode(' | ', array_map(fn ($v) => str_replace('|', '/', (string) $v), $l))." |\n";
        }
        $md .= "\n\"etapa N\" = ainda não migrado; o número do sistema atual fica como referência.\n";

        return $md;
    }
}
