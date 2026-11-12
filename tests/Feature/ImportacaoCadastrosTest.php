<?php

namespace Tests\Feature;

use App\Models\CadastroPendente;
use App\Models\Cliente;
use App\Models\Convenio;
use App\Models\Empresa;
use App\Models\MensalidadeContrato;
use App\Models\Servico;
use App\Models\Tarifa;
use App\Models\TipoVeiculo;
use App\Models\Veiculo;
use App\Servicos\Precos;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BancoLegado;
use Tests\TestCase;

/** Etapa 2 da importação, com dados no formato do sistema atual. */
class ImportacaoCadastrosTest extends TestCase
{
    use BancoLegado;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->criarBancoLegado();
        $db = DB::connection('legado');
        $db->table('VEICULOS_TIPO')->insert([['vcltp_id' => 1, 'vcltp_descricao' => 'Pequeno', 'vcltp_detalhe' => 'Hatch'], ['vcltp_id' => 7, 'vcltp_descricao' => 'Moto', 'vcltp_detalhe' => null]]);
        $db->table('TARIFAS')->insert(['tar_id' => 3, 'tar_nome' => 'Estadia de carro', 'tar_restante' => 'regras']);
        $db->table('TARIFA_FAIXAS')->insert([['tf_tar_id' => 3, 'tf_ordem' => 0, 'tf_duracao' => 60, 'tf_modo' => 'fixo', 'tf_bloco' => 60, 'tf_valor' => 10], ['tf_tar_id' => 3, 'tf_ordem' => 1, 'tf_duracao' => null, 'tf_modo' => 'bloco', 'tf_bloco' => 30, 'tf_valor' => 5]]);
        $db->table('TARIFA_PACOTES')->insert(['tp_tar_id' => 3, 'tp_nome' => 'Diária 12h', 'tp_duracao' => 720, 'tp_valor' => 25]);
        $db->table('TARIFA_VEICULOS')->insert(['tv_vcltp_id' => 1, 'tv_tar_id' => 3]);
        $db->table('SERVICOS')->insert([
            ['srv_codigo' => 'MEN', 'srv_nome_servico' => 'Mensalidade', 'srv_papel' => 'sistema', 'srv_preco_modo' => 'fixo', 'srv_detalhe' => null],
            ['srv_codigo' => 'LEX', 'srv_nome_servico' => 'Lavagem Externa', 'srv_papel' => 'principal', 'srv_preco_modo' => 'fixo', 'srv_detalhe' => null],
            ['srv_codigo' => 'ACR', 'srv_nome_servico' => 'Acréscimo', 'srv_papel' => 'adicional', 'srv_preco_modo' => 'ate', 'srv_detalhe' => null],
        ]);
        $db->table('SERVICOS_ADICIONAIS')->insert(['sa_principal' => 'LEX', 'sa_adicional' => 'ACR']);
        $db->table('SERVICOS_PRECO')->insert([['srvprc_srv_codigo' => 'LEX', 'srvprc_vcltp_tipo' => 1, 'srvprc_valor' => 42.5], ['srvprc_srv_codigo' => 'ACR', 'srvprc_vcltp_tipo' => 1, 'srvprc_valor' => 20]]);
        $db->table('CLIENTES_ENDERECO')->insert([['cliend_id' => 1, 'cliend_logradouro' => 'RUA A', 'cliend_nr' => 0, 'cliend_bairro' => 'CENTRO', 'cliend_cidade' => 'SANTOS', 'cliend_estado' => 'SP', 'cliend_complemento' => '0', 'cliend_cep' => '11000000']]);
        $db->table('CLIENTES_TELEFONE')->insert([['clitel_id' => 1, 'clitel_telefone_fixo' => '', 'clitel_telefone_celular' => '13988887777']]);
        $db->table('CLIENTES')->insert([
            ['cli_id' => 5, 'cli_nome' => 'JOSÉ DA CONCEIÇÃO', 'cli_cpf' => '52998224725', 'cli_cadastrado_em' => '2025-03-01', 'cli_dia_vencimento' => 31, 'cli_cliend_id' => 1, 'cli_clitel_id' => 1],
            ['cli_id' => 6, 'cli_nome' => 'CLINICA SORRISO', 'cli_cpf' => null, 'cli_cadastrado_em' => null, 'cli_dia_vencimento' => 5, 'cli_cliend_id' => 99, 'cli_clitel_id' => 99],
        ]);
        $db->table('VEICULOS')->insert([
            ['vcl_id' => 1, 'vcl_placa' => 'ABC1D23', 'vcl_cli_id' => 5, 'vcl_vcltp_tipo' => 1, 'vcl_modelo' => 'ONIX', 'vcl_marca' => 'GM', 'vcl_cor' => 'PRATA', 'vcl_periodo' => '24Horas'],
            ['vcl_id' => 2, 'vcl_placa' => 'SEMPLC BICI', 'vcl_cli_id' => 5, 'vcl_vcltp_tipo' => 7, 'vcl_modelo' => 'BIKE', 'vcl_marca' => '', 'vcl_cor' => '', 'vcl_periodo' => 'Diurno'],
        ]);
        $db->table('VEICULOS_MENSALISTA')->insert([['vclms_id' => 10, 'vclms_vcl_placa' => 'ABC1D23', 'vclms_mensalidade' => 250], ['vclms_id' => 11, 'vclms_vcl_placa' => 'SEMPLC BICI', 'vclms_mensalidade' => 80]]);
        $db->table('ORDEM_SERVICOS')->insert(['ord_cli_id' => 5, 'ord_vcl_placa' => 'ABC1D23', 'ord_srv_codigo' => 'MEN', 'ord_dt_geracao' => '2025-03-05', 'ord_valor' => 250]);
        $db->table('CONVENIOS')->insert(['cnv_cli_id' => 6, 'cnv_modo' => 'desconto', 'cnv_desc_tipo' => 'pct', 'cnv_desc_valor' => 15, 'cnv_estadia' => 1, 'cnv_servicos' => 1]);
        $db->table('CADASTROS_PENDENTES')->insert(['cp_recebido_em' => '2026-11-01 10:00:00', 'cp_ip' => '1.1.1.1', 'cp_consentimento' => '2026-11-01 10:00:00', 'cp_nome' => 'NOVO', 'cp_cep' => '11000000', 'cp_logradouro' => 'RUA B', 'cp_numero' => 5, 'cp_bairro' => 'X', 'cp_cidade' => 'SANTOS', 'cp_estado' => 'SP', 'cp_placa' => 'QQQ1Q11', 'cp_tipo' => 1]);
        $db->table('PAGADORES_VINCULADOS')->insert(['pv_nome' => 'MARIA (ESPOSA)', 'pv_documento' => '', 'pv_cli_id' => 5, 'pv_criado_em' => '2026-11-01 10:00:00']);
    }

    private function importar(): void
    {
        $this->artisan('legado:importar', ['--empresa' => 'Local Estacionamento'])->assertSuccessful();
        app(EmpresaAtual::class)->definir(Empresa::firstOrFail()->id);
    }

    public function test_importa_todos_os_cadastros(): void
    {
        $this->importar();
        $this->assertSame(2, TipoVeiculo::count());
        $pequeno = TipoVeiculo::where('legado_id', 1)->firstOrFail();

        // tabela de estadia com as mesmas regras (o tarifador dá o mesmo valor)
        $t = Precos::tarifa($pequeno->id);
        $this->assertSame('Estadia de carro', $t->nome);
        $this->assertSame(25.0, (new \App\Servicos\Tarifador($t->regras()))->calcular('2026-11-05 08:10', '2026-11-05 10:45')['valor']);

        // serviços (sem os do sistema), adicional e preço
        $this->assertSame(['ACR', 'LEX'], Servico::orderBy('codigo')->pluck('codigo')->all());
        $lex = Servico::where('codigo', 'LEX')->first();
        $this->assertSame(['ACR'], $lex->adicionais()->pluck('codigo')->all());
        $this->assertSame(42.5, Precos::servico($lex->id, $pequeno->id));
        $this->assertSame('ate', Servico::where('codigo', 'ACR')->value('preco_modo'));

        // cliente com endereço, telefone e acentos
        $c = Cliente::where('legado_id', 5)->firstOrFail();
        $this->assertSame('JOSÉ DA CONCEIÇÃO', $c->nome);
        $this->assertSame(5, $c->id); // mesmo código do sistema atual
        $this->assertSame('S/N', $c->numero);
        $this->assertNull($c->complemento);
        $this->assertSame('13988887777', $c->celular);
        $this->assertSame(31, $c->dia_vencimento);
        $this->assertSame('2025-03-01', $c->cliente_desde->toDateString());

        // veículos: placa improvisada vira "sem placa"
        $this->assertSame('ABC1D23', Veiculo::where('legado_id', 1)->value('placa'));
        $bici = Veiculo::where('legado_id', 2)->first();
        $this->assertNull($bici->placa);
        $this->assertSame('SEMPLC BICI', $bici->identificacao);

        // mensalidades ligadas pelo id do veículo; início = primeira mensalidade gerada
        $m = MensalidadeContrato::where('legado_id', 10)->firstOrFail();
        $this->assertSame(Veiculo::where('legado_id', 1)->value('id'), $m->veiculo_id);
        $this->assertSame('24 horas', $m->periodo);
        $this->assertSame('2025-03-05', $m->inicio->toDateString());
        $this->assertSame($bici->id, MensalidadeContrato::where('legado_id', 11)->value('veiculo_id'));

        $this->assertSame('Desconto de 15%', Convenio::firstOrFail()->resumo());
        $this->assertSame(1, CadastroPendente::count());
        $this->assertSame('MARIA (ESPOSA)', $c->pagadores()->value('nome'));
    }

    public function test_reimportar_atualiza_e_encerra_o_que_saiu(): void
    {
        $this->importar();
        $db = DB::connection('legado');
        $db->table('VEICULOS_MENSALISTA')->where('vclms_id', 10)->update(['vclms_mensalidade' => 270]);
        $db->table('VEICULOS_MENSALISTA')->where('vclms_id', 11)->delete();
        $db->table('CADASTROS_PENDENTES')->delete();
        $this->importar();

        $this->assertSame(2, Cliente::count());
        $this->assertSame(2, Veiculo::count());
        $this->assertSame('270.00', MensalidadeContrato::where('legado_id', 10)->value('valor'));
        $this->assertNotNull(MensalidadeContrato::where('legado_id', 11)->value('fim'));
        $this->assertSame(0, CadastroPendente::count());
        $this->assertSame(1, Tarifa::count());
    }

    public function test_exclusoes_e_mensalidade_zero_no_sistema_atual(): void
    {
        $this->importar();
        $db = DB::connection('legado');
        $db->table('CONVENIOS')->delete();
        $db->table('CLIENTES')->where('cli_id', 6)->delete();                         // cliente excluído
        $db->table('VEICULOS_MENSALISTA')->where('vclms_id', 11)->delete();
        $db->table('VEICULOS')->where('vcl_id', 2)->delete();                          // veículo excluído
        $db->table('VEICULOS_MENSALISTA')->where('vclms_id', 10)->update(['vclms_mensalidade' => 0]);   // licença: mensalidade 0
        $this->importar();

        $this->assertFalse(Cliente::where('legado_id', 6)->value('ativo'));
        $this->assertTrue(Cliente::where('legado_id', 5)->value('ativo'));
        $this->assertFalse(Veiculo::where('legado_id', 2)->value('ativo'));
        // sem a mensalidade deste mês: termina no mês passado (não volta para "Gerar mensalidades")
        $this->assertSame(now()->startOfMonth()->subDay()->toDateString(), MensalidadeContrato::where('legado_id', 10)->first()->fim->toDateString());
        $this->assertFalse(\App\Servicos\Mensalidades::previa(now()->format('Y-m'))->contains('contrato_id', MensalidadeContrato::where('legado_id', 11)->value('id')));
        \Artisan::call('legado:comparar');
        $saida = \Artisan::output();
        $this->assertStringNotContainsString('DIFERENTE', $saida, $saida);   // excluídos e encerrados hoje não contam
    }

    public function test_comparacao_bate(): void
    {
        $this->importar();
        $this->artisan('legado:comparar')->assertSuccessful();
    }
}
