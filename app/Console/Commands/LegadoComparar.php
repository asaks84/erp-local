<?php

namespace App\Console\Commands;

use App\Models\CadastroPendente;
use App\Models\Cliente;
use App\Models\Conferencia;
use App\Models\Convenio;
use App\Models\Empresa;
use App\Models\MensalidadeContrato;
use App\Models\OrdemServico;
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
use Illuminate\Support\Facades\DB;
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

    /** clientes com saldo em aberto diferente: [código, sistema atual, ERP] */
    private array $diferencasClientes = [];

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
        Conferencia::create([
            'executada_em' => now(), 'situacao' => $diferencas ? 'diferente' : 'ok',
            'diferencas' => $diferencas, 'linhas' => $linhas,
        ]);
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

        // migrado na etapa 3: dinheiro (o portão da etapa: em aberto e recebido iguais, centavo a centavo)
        $ordens = $legado->exige('ORDEM_SERVICOS');
        $cli = $legado->exige('CLIENTES');
        $linhas[] = ['Ordens de serviço', $x = $legado->contar('ORDEM_SERVICOS'), $n = OrdemServico::whereNotNull('legado_id')->count(), $igual($x, $n)];
        $x = $legado->contar('ORDEM_SERVICOS', 'ord_dt_pagamento IS NULL');
        $n = OrdemServico::whereNotNull('legado_id')->where('situacao', 'aberta')->count();
        $linhas[] = ['Ordens em aberto', $x, $n, $igual($x, $n)];

        // em aberto por cliente, com créditos abatendo (mesma conta do Início do sistema atual)
        $antigo = collect($db->select("SELECT o.ord_cli_id AS id, SUM(CASE WHEN o.ord_srv_codigo = 'CDT' THEN -o.ord_valor ELSE o.ord_valor END) AS total
            FROM `{$ordens}` o JOIN `{$cli}` c ON c.cli_id = o.ord_cli_id WHERE o.ord_dt_pagamento IS NULL GROUP BY o.ord_cli_id"))
            ->mapWithKeys(fn ($l) => [(int) $l->id => round((float) $l->total, 2)]);
        $novo = DB::table('ordens_servico as o')->join('clientes as c', 'c.id', '=', 'o.cliente_id')
            ->where('o.empresa_id', app(EmpresaAtual::class)->id())->where('o.situacao', 'aberta')
            ->groupBy('c.id', 'c.legado_id')->selectRaw('c.id, c.legado_id, SUM('.OrdemServico::SQL_VALOR.') AS total')->get()
            ->mapWithKeys(fn ($l) => [(int) ($l->legado_id ?? -$l->id) => round((float) $l->total, 2)]);
        $positivos = fn ($c) => $c->filter(fn ($v) => $v > 0);
        $linhas[] = ['Clientes com contas em aberto', $a = $positivos($antigo)->count(), $b = $positivos($novo)->count(), $igual($a, $b)];
        $linhas[] = ['Total em aberto (R$)', $a = $this->moeda($positivos($antigo)->sum()), $b = $this->moeda($positivos($novo)->sum()), $igual($a, $b)];
        $this->diferencasClientes = [];
        foreach ($antigo->keys()->merge($novo->keys())->unique() as $id) {
            $va = $antigo->get($id, 0.0);
            $vb = $novo->get($id, 0.0);
            if (abs($va - $vb) >= 0.01) {
                $this->diferencasClientes[] = [$id > 0 ? $id : 'só no ERP', $this->moeda($va), $this->moeda($vb)];
            }
        }
        if ($this->diferencasClientes) {
            $linhas[] = ['Clientes com saldo diferente', count($this->diferencasClientes).' cliente(s)', 'ver relatório', 'DIFERENTE'];
        }

        // recebido por mês: crédito usado entra negativo (dinheiro novo de verdade)
        $mesesAntigo = collect($db->select("SELECT DATE_FORMAT(ord_dt_pagamento, '%Y-%m') mes,
                SUM(CASE WHEN ord_srv_codigo = 'CDT' THEN -ord_valor ELSE ord_valor END) total
            FROM `{$ordens}` WHERE ord_dt_pagamento IS NOT NULL AND ord_dt_pagamento > '1000-01-01' GROUP BY mes"))->mapWithKeys(fn ($l) => [$l->mes => round((float) $l->total, 2)]);
        $mesesNovo = DB::table('pagamento_itens as i')->join('pagamentos as p', 'p.id', '=', 'i.pagamento_id')
            ->where('p.empresa_id', app(EmpresaAtual::class)->id())->where('p.situacao', 'confirmado')
            ->groupByRaw("DATE_FORMAT(p.recebido_em, '%Y-%m')")->selectRaw("DATE_FORMAT(p.recebido_em, '%Y-%m') mes, SUM(i.valor) total")->get()
            ->mapWithKeys(fn ($l) => [$l->mes => round((float) $l->total, 2)]);
        $todos = $mesesAntigo->keys()->merge($mesesNovo->keys())->unique()->sort()->values();
        $corte = now()->subMonths(23)->format('Y-m');
        $antes = $todos->filter(fn ($m) => $m < $corte);
        if ($antes->count()) {
            $a = $this->moeda($antes->sum(fn ($m) => $mesesAntigo->get($m, 0)));
            $b = $this->moeda($antes->sum(fn ($m) => $mesesNovo->get($m, 0)));
            $difMeses = $antes->filter(fn ($m) => abs($mesesAntigo->get($m, 0) - $mesesNovo->get($m, 0)) >= 0.01)->count();
            $linhas[] = ['Recebido até '.\Carbon\Carbon::parse($corte.'-01')->subMonth()->format('m/Y').' (R$)', $a, $b, $difMeses ? 'DIFERENTE' : $igual($a, $b)];
        }
        foreach ($todos->filter(fn ($m) => $m >= $corte) as $m) {
            $linhas[] = ['Recebido em '.\Carbon\Carbon::parse($m.'-01')->format('m/Y').' (R$)', $a = $this->moeda($mesesAntigo->get($m, 0)), $b = $this->moeda($mesesNovo->get($m, 0)), $igual($a, $b)];
        }

        if ($ext = $legado->tabela('EXTRATO_LANCAMENTOS')) {
            $linhas[] = ['Extrato importado (lançamentos)', $x = $legado->contar('EXTRATO_LANCAMENTOS'), $n = \App\Models\ExtratoLancamento::whereNotNull('legado_id')->count(), $igual($x, $n)];
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
        if ($this->diferencasClientes) {
            $md .= "\n## Clientes com saldo em aberto diferente\n\n| Código | Sistema atual | ERP |\n|---|---:|---:|\n";
            foreach (array_slice($this->diferencasClientes, 0, 200) as $d) {
                $md .= '| '.implode(' | ', $d)." |\n";
            }
        }
        $md .= "\nDinheiro: \"em aberto\" soma as ordens não pagas com os créditos abatendo; \"recebido\" soma o que foi baixado no mês (crédito usado entra negativo), igual ao sistema atual.\n";

        return $md;
    }
}
