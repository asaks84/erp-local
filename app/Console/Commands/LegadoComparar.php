<?php

namespace App\Console\Commands;

use App\Models\CadastroPendente;
use App\Models\Cliente;
use App\Models\Convenio;
use App\Models\Empresa;
use App\Models\MensalidadeContrato;
use App\Models\PagadorVinculado;
use App\Models\Servico;
use App\Models\Tarifa;
use App\Models\TipoVeiculo;
use App\Models\Veiculo;
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

        // migrado na etapa 2
        $linhas[] = ['Tipos de veículo', $x = $legado->contar('VEICULOS_TIPO'), $n = TipoVeiculo::whereNotNull('legado_id')->count(), $igual($x, $n)];
        if ($legado->tabela('TARIFAS')) {
            $linhas[] = ['Tabelas de estadia', $x = $legado->contar('TARIFAS'), $n = Tarifa::whereNotNull('legado_id')->count(), $igual($x, $n)];
        }
        $srv = $legado->exige('SERVICOS');
        $x = (int) $db->selectOne("SELECT COUNT(*) n FROM `{$srv}` WHERE srv_papel <> 'sistema' AND srv_codigo NOT IN ('MEN','EST','CDT','DBT')")->n;
        $linhas[] = ['Serviços', $x, $n = Servico::whereNotNull('legado_codigo')->count(), $igual($x, $n)];
        $linhas[] = ['Clientes', $x = $legado->contar('CLIENTES'), $n = Cliente::whereNotNull('legado_id')->count(), $igual($x, $n)];
        $linhas[] = ['Veículos', $x = $legado->contar('VEICULOS'), $n = Veiculo::whereNotNull('legado_id')->count(), $igual($x, $n)];
        $vm = $legado->exige('VEICULOS_MENSALISTA');
        $vc = $legado->exige('VEICULOS');
        $mens = $db->selectOne("SELECT COUNT(*) n, COALESCE(SUM(m.vclms_mensalidade),0) total FROM `{$vm}` m JOIN `{$vc}` v ON v.vcl_placa = m.vclms_vcl_placa WHERE m.vclms_mensalidade > 0");
        $ativos = MensalidadeContrato::whereNotNull('legado_id')->ativos()->get();
        $linhas[] = ['Mensalistas (veículos)', (int) $mens->n, $ativos->count(), $igual((int) $mens->n, $ativos->count())];
        $linhas[] = ['Soma das mensalidades (R$)', $this->moeda($mens->total), $this->moeda($ativos->sum('valor')), $igual($this->moeda($mens->total), $this->moeda($ativos->sum('valor')))];
        if ($legado->tabela('CONVENIOS')) {
            $linhas[] = ['Convênios', $x = $legado->contar('CONVENIOS'), $n = Convenio::count(), $igual($x, $n)];
        }
        if ($legado->tabela('CADASTROS_PENDENTES')) {
            $linhas[] = ['Fila de espera', $x = $legado->contar('CADASTROS_PENDENTES'), $n = CadastroPendente::whereNotNull('legado_id')->count(), $igual($x, $n)];
        }
        if ($legado->tabela('PAGADORES_VINCULADOS')) {
            $linhas[] = ['Pagadores vinculados', $x = $legado->contar('PAGADORES_VINCULADOS'), $n = PagadorVinculado::whereNotNull('legado_id')->count(), $igual($x, $n)];
        }

        // base para a próxima etapa (ainda não migrado)
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
