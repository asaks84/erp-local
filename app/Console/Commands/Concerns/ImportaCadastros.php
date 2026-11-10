<?php

namespace App\Console\Commands\Concerns;

use App\Models\CadastroPendente;
use App\Models\Cliente;
use App\Models\Convenio;
use App\Models\MensalidadeContrato;
use App\Models\PagadorVinculado;
use App\Models\Servico;
use App\Models\ServicoPreco;
use App\Models\Tarifa;
use App\Models\TipoVeiculo;
use App\Models\Unidade;
use App\Models\Veiculo;
use App\Support\Legado;
use App\Support\Placa;

/**
 * Etapa 2 da importação: tipos de veículo, tabelas de estadia, serviços e preços, clientes,
 * veículos, mensalidades, convênios, fila do cadastro público e pagadores vinculados.
 * Ligações que no sistema atual são pela placa passam a ser pelo id interno do veículo.
 */
trait ImportaCadastros
{
    /** preços e tabelas importados valem desde sempre (o sistema atual não tem vigência) */
    private string $desde = '2000-01-01';

    /** @var array<int,int> cliente antigo → novo */
    private array $mapaClientes = [];

    /** @var array<string,\App\Models\Veiculo> placa antiga → veículo novo */
    private array $mapaVeiculos = [];

    private function texto($v, int $max = 255): ?string
    {
        $v = self::consertarAcentos(trim((string) $v));

        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    /**
     * Alguns registros do sistema atual têm acento gravado duas vezes ("GONÃ‡ALVES" em vez de
     * "GONÇALVES": texto UTF-8 guardado numa tabela latin1). Desfaz quando o resultado é válido.
     */
    public static function consertarAcentos(string $v): string
    {
        if (! preg_match('/[ÃÂ][\x{0080}-\x{00BF}\x{0152}\x{0153}\x{0160}\x{0161}\x{0178}\x{017D}\x{017E}\x{0192}\x{02C6}\x{02DC}\x{2013}-\x{203A}\x{20AC}\x{2122}]/u', $v)) {
            return $v;
        }
        $bytes = mb_convert_encoding($v, 'Windows-1252', 'UTF-8');

        return mb_check_encoding($bytes, 'UTF-8') ? $bytes : $v;
    }

    private function data($v): ?string
    {
        return ($v && $v !== '0000-00-00' && strtotime($v)) ? date('Y-m-d', strtotime($v)) : null;
    }

    private function contar(string $nome, int $novos, int $atualizados = 0, int $ignorados = 0): void
    {
        $this->resumo[] = [$nome, $novos, $atualizados, $ignorados];
    }

    private function importarCadastros(Legado $legado): void
    {
        $tipos = $this->importarTipos($legado);
        $this->importarTarifas($legado, $tipos);
        $this->importarServicos($legado, $tipos);
        $clientes = $this->importarClientes($legado);
        $veiculos = $this->importarVeiculos($legado, $clientes, $tipos);
        // usados pela importação das ordens (etapa 3)
        $this->mapaClientes = $clientes;
        $this->mapaVeiculos = $veiculos;
        $this->importarContratos($legado, $veiculos);
        $this->importarConvenios($legado, $clientes);
        $this->importarPendentes($legado, $tipos);
        $this->importarPagadores($legado, $clientes);
    }

    /** @return array<int,int> tipo antigo → novo */
    private function importarTipos(Legado $legado): array
    {
        $mapa = [];
        $n = $a = 0;
        foreach ($legado->conexao()->table($legado->exige('VEICULOS_TIPO'))->orderBy('vcltp_id')->get() as $i => $t) {
            $tipo = TipoVeiculo::where('legado_id', $t->vcltp_id)->first()
                ?? TipoVeiculo::whereNull('legado_id')->where('nome', trim($t->vcltp_descricao))->first();
            $tipo ? $a++ : $n++;
            $tipo ??= new TipoVeiculo;
            $tipo->fill(['nome' => $this->texto($t->vcltp_descricao, 30), 'detalhe' => $this->texto($t->vcltp_detalhe, 80), 'ordem' => $i]);
            $tipo->legado_id = $t->vcltp_id;
            $tipo->save();
            $mapa[(int) $t->vcltp_id] = $tipo->id;
        }
        $this->contar('Tipos de veículo', $n, $a);

        return $mapa;
    }

    private function importarTarifas(Legado $legado, array $tipos): void
    {
        if (! $legado->tabela('TARIFAS')) {
            return;
        }
        $db = $legado->conexao();
        $porTarifa = [];
        if ($tv = $legado->tabela('TARIFA_VEICULOS')) {
            foreach ($db->table($tv)->get() as $l) {
                if (isset($tipos[(int) $l->tv_vcltp_id])) {
                    $porTarifa[(int) $l->tv_tar_id][] = $tipos[(int) $l->tv_vcltp_id];
                }
            }
        }
        $n = $a = 0;
        foreach ($db->table($legado->tabela('TARIFAS'))->orderBy('tar_id')->get() as $t) {
            $tarifa = Tarifa::where('legado_id', $t->tar_id)->first();
            $tarifa ? $a++ : $n++;
            $tarifa ??= new Tarifa(['vigencia_inicio' => $this->desde]);
            $tarifa->fill([
                'nome' => $this->texto($t->tar_nome, 60),
                'tolerancia_min' => (int) $t->tar_tolerancia,
                'fracao_min' => (int) $t->tar_fracao,
                'restante' => $t->tar_restante === 'pacotes' ? 'pacotes' : 'faixas',
            ]);
            $tarifa->legado_id = $t->tar_id;
            $tarifa->save();
            $tarifa->faixas()->delete();
            $tarifa->pacotes()->delete();
            foreach ($db->table($legado->exige('TARIFA_FAIXAS'))->where('tf_tar_id', $t->tar_id)->orderBy('tf_ordem')->orderBy('tf_id')->get() as $i => $f) {
                $tarifa->faixas()->create([
                    'ordem' => $i, 'duracao_min' => $f->tf_duracao ?: null, 'modo' => $f->tf_modo === 'fixo' ? 'fixo' : 'bloco',
                    'bloco_min' => max(1, (int) $f->tf_bloco), 'valor' => $f->tf_valor,
                ]);
            }
            foreach ($db->table($legado->exige('TARIFA_PACOTES'))->where('tp_tar_id', $t->tar_id)->orderBy('tp_id')->get() as $p) {
                $tarifa->pacotes()->create([
                    'nome' => $this->texto($p->tp_nome, 40) ?? 'Diária', 'duracao_min' => $p->tp_duracao ?: null, 'valor' => $p->tp_valor,
                    'hora_inicio' => $this->texto($p->tp_hora_inicio, 5), 'hora_fim' => $this->texto($p->tp_hora_fim, 5),
                ]);
            }
            $tarifa->tipos()->sync($porTarifa[(int) $t->tar_id] ?? []);
        }
        $this->contar('Tabelas de estadia', $n, $a);
    }

    private function importarServicos(Legado $legado, array $tipos): void
    {
        $db = $legado->conexao();
        $mapa = [];
        $n = $a = 0;
        // MEN, EST, CDT e DBT são do sistema (viram tipos de ordem de serviço), não serviços vendidos
        foreach ($db->table($legado->exige('SERVICOS'))->where('srv_papel', '<>', 'sistema')->whereNotIn('srv_codigo', ['MEN', 'EST', 'CDT', 'DBT'])->get() as $s) {
            $servico = Servico::where('legado_codigo', $s->srv_codigo)->first();
            $servico ? $a++ : $n++;
            $servico ??= new Servico;
            $servico->fill([
                'codigo' => $servico->codigo ?? strtoupper($s->srv_codigo),
                'nome' => $this->texto($s->srv_nome_servico, 60) ?? $s->srv_codigo,
                'papel' => $s->srv_papel === 'adicional' ? 'adicional' : 'principal',
                'preco_modo' => ($s->srv_preco_modo ?? 'fixo') === 'ate' ? 'ate' : 'fixo',
                'detalhe' => $this->texto($s->srv_detalhe, 160),
            ]);
            $servico->legado_codigo = $s->srv_codigo;
            $servico->save();
            $mapa[$s->srv_codigo] = $servico;
        }
        foreach ($mapa as $codigo => $servico) {
            ServicoPreco::where('servico_id', $servico->id)->whereNull('unidade_id')->delete();
            foreach ($db->table($legado->exige('SERVICOS_PRECO'))->where('srvprc_srv_codigo', $codigo)->get() as $p) {
                if (isset($tipos[(int) $p->srvprc_vcltp_tipo])) {
                    ServicoPreco::create([
                        'servico_id' => $servico->id, 'tipo_veiculo_id' => $tipos[(int) $p->srvprc_vcltp_tipo],
                        'valor' => $p->srvprc_valor, 'vigencia_inicio' => $this->desde,
                    ]);
                }
            }
            $adicionais = [];
            if ($sa = $legado->tabela('SERVICOS_ADICIONAIS')) {
                foreach ($db->table($sa)->where('sa_principal', $codigo)->pluck('sa_adicional') as $ad) {
                    if (isset($mapa[$ad])) {
                        $adicionais[] = $mapa[$ad]->id;
                    }
                }
            }
            $servico->adicionais()->sync($adicionais);
        }
        $this->contar('Serviços e preços', $n, $a);
    }

    /** @return array<int,int> cliente antigo → novo */
    private function importarClientes(Legado $legado): array
    {
        $db = $legado->conexao();
        $end = $legado->tabela('CLIENTES_ENDERECO');
        $tel = $legado->tabela('CLIENTES_TELEFONE');
        $linhas = $db->table($legado->exige('CLIENTES').' as c')
            ->when($end, fn ($q) => $q->leftJoin($end.' as e', 'e.cliend_id', '=', 'c.cli_cliend_id'))
            ->when($tel, fn ($q) => $q->leftJoin($tel.' as t', 't.clitel_id', '=', 'c.cli_clitel_id'))
            ->orderBy('c.cli_id')->get();

        $mapa = [];
        $n = $a = 0;
        foreach ($linhas as $c) {
            $cliente = Cliente::where('legado_id', $c->cli_id)->first();
            $doc = preg_replace('/\D/', '', (string) $c->cli_cpf) ?: null;
            if ($doc && Cliente::where('documento', $doc)->when($cliente, fn ($q) => $q->where('id', '<>', $cliente->id))->exists()) {
                $this->warn("Cliente {$c->cli_id} ({$c->cli_nome}): CPF/CNPJ repetido; importado sem documento.");
                $doc = null;
            }
            $numero = isset($c->cliend_nr) ? (string) $c->cliend_nr : null;
            $novo = ! $cliente;
            $cliente ? $a++ : $n++;
            $cliente ??= new Cliente(['origem' => 'importacao']);
            // o código do cliente é o mesmo do sistema atual (é como a equipe conhece o cliente)
            if ($novo && ! Cliente::withoutGlobalScopes()->whereKey($c->cli_id)->exists()) {
                $cliente->id = (int) $c->cli_id;
            }
            $cliente->fill([
                'nome' => $this->texto($c->cli_nome, 100) ?? 'SEM NOME',
                'documento' => $doc,
                'telefone' => $this->texto(preg_replace('/\D/', '', (string) ($c->clitel_telefone_fixo ?? '')), 15),
                'celular' => $this->texto(preg_replace('/\D/', '', (string) ($c->clitel_telefone_celular ?? '')), 15),
                'cep' => $this->texto(preg_replace('/\D/', '', (string) ($c->cliend_cep ?? '')), 8),
                'logradouro' => $this->texto($c->cliend_logradouro ?? null, 100),
                'numero' => $numero === '0' ? 'S/N' : $this->texto($numero, 10),
                'complemento' => ($c->cliend_complemento ?? null) === '0' ? null : $this->texto($c->cliend_complemento ?? null, 80),
                'bairro' => $this->texto($c->cliend_bairro ?? null, 60),
                'cidade' => $this->texto($c->cliend_cidade ?? null, 60),
                'uf' => $this->texto(strtoupper((string) ($c->cliend_estado ?? '')), 2),
                'dia_vencimento' => min(31, max(1, (int) ($c->cli_dia_vencimento ?: 5))),
                'cliente_desde' => $this->data($c->cli_cadastrado_em ?? null),
            ]);
            $cliente->legado_id = $c->cli_id;
            $cliente->save();
            $mapa[(int) $c->cli_id] = $cliente->id;
        }
        $this->contar('Clientes', $n, $a);

        return $mapa;
    }

    /** @return array<string,Veiculo> placa antiga (como estava no sistema atual) → veículo novo */
    private function importarVeiculos(Legado $legado, array $clientes, array $tipos): array
    {
        $mapa = [];
        $n = $a = $sem = 0;
        $tipoPadrao = reset($tipos);
        foreach ($legado->conexao()->table($legado->exige('VEICULOS'))->orderBy('vcl_id')->get() as $v) {
            $original = trim((string) $v->vcl_placa);
            $placa = Placa::normalizar($original);
            $identificacao = null;
            // placa improvisada ("SEMPLC BICI"): vira veículo sem placa, com a identificação alternativa
            if (! $placa || str_starts_with($placa, 'SEM') || (preg_match('/\s/', $original) && ! Placa::brasileira($placa))) {
                $identificacao = mb_substr($original, 0, 60) ?: null;
                $placa = null;
                $sem++;
            }
            $veiculo = Veiculo::where('legado_id', $v->vcl_id)->first();
            if ($placa && Veiculo::where('placa', $placa)->where('ativo', true)->when($veiculo, fn ($q) => $q->where('id', '<>', $veiculo->id))->exists()) {
                $this->warn("Veículo {$v->vcl_id}: placa {$placa} repetida; importado sem placa.");
                $identificacao = $original;
                $placa = null;
            }
            $veiculo ? $a++ : $n++;
            $veiculo ??= new Veiculo;
            $veiculo->fill([
                'cliente_id' => $clientes[(int) $v->vcl_cli_id] ?? null,
                'tipo_veiculo_id' => $tipos[(int) $v->vcl_vcltp_tipo] ?? $tipoPadrao,
                'placa' => $placa,
                'identificacao' => $identificacao,
                'marca' => $this->texto($v->vcl_marca, 30),
                'modelo' => $this->texto($v->vcl_modelo, 40),
                'cor' => $this->texto($v->vcl_cor, 20),
                'ativo' => true,
            ]);
            $veiculo->legado_id = $v->vcl_id;
            $veiculo->save();
            $veiculo->periodo_legado = $this->texto($v->vcl_periodo, 30);
            $mapa[strtoupper($original)] = $veiculo;
        }
        $this->contar('Veículos ('.$sem.' sem placa)', $n, $a);

        return $mapa;
    }

    private function importarContratos(Legado $legado, array $veiculos): void
    {
        $db = $legado->conexao();
        $ordens = $legado->tabela('ORDEM_SERVICOS');
        $unidade = Unidade::orderBy('id')->value('id');
        $vistos = [];
        $n = $a = $ign = 0;
        foreach ($db->table($legado->exige('VEICULOS_MENSALISTA'))->orderBy('vclms_id')->get() as $m) {
            $veiculo = $veiculos[strtoupper(trim($m->vclms_vcl_placa))] ?? null;
            if (! $veiculo || ! $veiculo->cliente_id || (float) $m->vclms_mensalidade <= 0) {
                $ign++;

                continue;
            }
            $periodo = $veiculo->periodo_legado === '24Horas' ? '24 horas' : ($veiculo->periodo_legado ?? 'Diurno');
            $contrato = MensalidadeContrato::where('legado_id', $m->vclms_id)->first();
            $contrato ? $a++ : $n++;
            if (! $contrato) {
                // início: a primeira mensalidade gerada para a placa; senão "cliente desde"; senão hoje
                $primeira = $ordens ? $db->table($ordens)->where('ord_vcl_placa', $m->vclms_vcl_placa)->where('ord_srv_codigo', 'MEN')->min('ord_dt_geracao') : null;
                $inicio = $this->data($primeira) ?? $veiculo->cliente?->cliente_desde?->format('Y-m-d') ?? now()->toDateString();
                $contrato = new MensalidadeContrato(['inicio' => $inicio, 'unidade_id' => $unidade]);
            }
            $contrato->fill([
                'cliente_id' => $veiculo->cliente_id,
                'veiculo_id' => $veiculo->id,
                'periodo' => $periodo,
                'valor' => $m->vclms_mensalidade,
            ]);
            $contrato->legado_id = $m->vclms_id;
            $contrato->save();
            $vistos[] = $m->vclms_id;
        }
        // mensalista que deixou de ser no sistema atual: encerra aqui também
        $encerrados = MensalidadeContrato::whereNotNull('legado_id')->whereNotIn('legado_id', $vistos ?: [0])->whereNull('fim')
            ->update(['fim' => now()->toDateString(), 'motivo_fim' => 'Deixou de ser mensalista no sistema atual']);
        $this->contar('Mensalidades (contratos)', $n, $a, $ign);
        if ($encerrados) {
            $this->warn("{$encerrados} mensalidade(s) encerrada(s): não existem mais no sistema atual.");
        }
    }

    private function importarConvenios(Legado $legado, array $clientes): void
    {
        if (! $t = $legado->tabela('CONVENIOS')) {
            return;
        }
        $n = $a = 0;
        foreach ($legado->conexao()->table($t)->get() as $c) {
            if (! isset($clientes[(int) $c->cnv_cli_id])) {
                continue;
            }
            $conv = Convenio::where('cliente_id', $clientes[(int) $c->cnv_cli_id])->first();
            $conv ? $a++ : $n++;
            $conv ??= new Convenio(['cliente_id' => $clientes[(int) $c->cnv_cli_id]]);
            $modo = in_array($c->cnv_modo, ['tudo', 'tempo', 'desconto'], true) ? $c->cnv_modo : 'tudo';
            $conv->fill([
                'modo' => $modo,
                'limite_min' => $modo === 'tempo' ? $c->cnv_limite_min : null,
                'desconto_tipo' => $modo === 'desconto' ? ($c->cnv_desc_tipo === 'valor' ? 'valor' : 'pct') : null,
                'desconto_valor' => $modo === 'desconto' ? $c->cnv_desc_valor : null,
                'vale_estadia' => (bool) $c->cnv_estadia,
                'vale_servicos' => (bool) $c->cnv_servicos,
                'observacoes' => $this->texto($c->cnv_obs, 255),
                'ativo' => (bool) $c->cnv_ativo,
            ]);
            $conv->save();
        }
        $this->contar('Convênios', $n, $a);
    }

    private function importarPendentes(Legado $legado, array $tipos): void
    {
        if (! $t = $legado->tabela('CADASTROS_PENDENTES')) {
            return;
        }
        $vistos = [];
        $n = $a = 0;
        foreach ($legado->conexao()->table($t)->orderBy('cp_id')->get() as $p) {
            $cad = CadastroPendente::where('legado_id', $p->cp_id)->first();
            $cad ? $a++ : $n++;
            $cad ??= new CadastroPendente;
            $cad->fill([
                'recebido_em' => $p->cp_recebido_em, 'ip' => $p->cp_ip, 'consentimento_em' => $p->cp_consentimento,
                'nome' => $this->texto($p->cp_nome, 100) ?? 'SEM NOME', 'documento' => preg_replace('/\D/', '', (string) $p->cp_cpf) ?: null,
                'telefone' => $this->texto($p->cp_telefone, 15), 'celular' => $this->texto($p->cp_celular, 15),
                'cep' => $this->texto($p->cp_cep, 8), 'logradouro' => $this->texto($p->cp_logradouro, 100),
                'numero' => $this->texto((string) $p->cp_numero, 10), 'complemento' => $this->texto($p->cp_complemento, 80),
                'bairro' => $this->texto($p->cp_bairro, 60), 'cidade' => $this->texto($p->cp_cidade, 60), 'uf' => $this->texto($p->cp_estado, 2),
                'placa' => Placa::normalizar($p->cp_placa), 'marca' => $this->texto($p->cp_marca, 30), 'modelo' => $this->texto($p->cp_modelo, 40),
                'cor' => $this->texto($p->cp_cor, 20), 'tipo_veiculo_id' => $tipos[(int) $p->cp_tipo] ?? null,
            ]);
            $cad->legado_id = $p->cp_id;
            $cad->save();
            $vistos[] = $p->cp_id;
        }
        // aprovados ou recusados no sistema atual saem da fila daqui também
        CadastroPendente::whereNotNull('legado_id')->whereNotIn('legado_id', $vistos ?: [0])->delete();
        $this->contar('Fila de espera', $n, $a);
    }

    private function importarPagadores(Legado $legado, array $clientes): void
    {
        if (! $t = $legado->tabela('PAGADORES_VINCULADOS')) {
            return;
        }
        $n = $a = 0;
        foreach ($legado->conexao()->table($t)->orderBy('pv_id')->get() as $p) {
            if (! isset($clientes[(int) $p->pv_cli_id])) {
                continue;
            }
            $pag = PagadorVinculado::where('legado_id', $p->pv_id)->first();
            $pag ? $a++ : $n++;
            $pag ??= new PagadorVinculado;
            $pag->fill([
                'cliente_id' => $clientes[(int) $p->pv_cli_id], 'nome' => $this->texto($p->pv_nome, 100) ?? 'SEM NOME',
                'documento' => preg_replace('/\D/', '', (string) $p->pv_documento) ?: null, 'conta' => $this->texto($p->pv_conta, 60),
            ]);
            $pag->legado_id = $p->pv_id;
            $pag->save();
        }
        $this->contar('Pagadores vinculados', $n, $a);
    }
}
