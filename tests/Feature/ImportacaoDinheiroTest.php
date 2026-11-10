<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\ExtratoLancamento;
use App\Models\FormaPagamento;
use App\Models\OrdemServico;
use App\Models\Pagamento;
use App\Servicos\Baixa;
use App\Servicos\Ordens;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BancoLegado;
use Tests\TestCase;

/** Etapa 3 da importação: ordens, pagamentos e extrato; o relatório de comparação precisa bater. */
class ImportacaoDinheiroTest extends TestCase
{
    use BancoLegado;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->criarBancoLegado();
        $db = DB::connection('legado');
        $db->table('NIVEIS_ACESSO')->insert(['niv_id' => 1, 'niv_nome' => 'Administrador', 'niv_permissoes' => '', 'niv_fixo' => 1]);
        $db->table('USUARIOS')->insert(['usr_full_name' => 'Admin', 'usr_nickname' => 'admin', 'usr_password' => password_hash('x', PASSWORD_BCRYPT), 'usr_admin' => 1, 'usr_niv_id' => 1]);
        $db->table('VEICULOS_TIPO')->insert(['vcltp_id' => 1, 'vcltp_descricao' => 'Carro']);
        $db->table('SERVICOS')->insert([
            ['srv_codigo' => 'MEN', 'srv_nome_servico' => 'Mensalidade', 'srv_papel' => 'sistema'],
            ['srv_codigo' => 'LS', 'srv_nome_servico' => 'Lavagem Simples', 'srv_papel' => 'principal'],
        ]);
        $db->table('CLIENTES')->insert([
            ['cli_id' => 5, 'cli_nome' => 'JOSE', 'cli_dia_vencimento' => 10, 'cli_cliend_id' => 0, 'cli_clitel_id' => 0],
            ['cli_id' => 6, 'cli_nome' => 'MARIA', 'cli_dia_vencimento' => 5, 'cli_cliend_id' => 0, 'cli_clitel_id' => 0],
        ]);
        $db->table('VEICULOS')->insert(['vcl_id' => 1, 'vcl_placa' => 'ABC1D23', 'vcl_cli_id' => 5, 'vcl_vcltp_tipo' => 1, 'vcl_modelo' => 'ONIX', 'vcl_marca' => 'GM', 'vcl_cor' => 'PRATA', 'vcl_periodo' => 'Diurno']);
        $db->table('VEICULOS_MENSALISTA')->insert(['vclms_id' => 1, 'vclms_vcl_placa' => 'ABC1D23', 'vclms_mensalidade' => 200]);
        $o = fn ($id, $cli, $placa, $cod, $desc, $ger, $venc, $valor, $pag = null, $hr = null) => ['ord_id' => $id, 'ord_cli_id' => $cli, 'ord_vcl_placa' => $placa, 'ord_vcl_desc' => $placa ? 'GM ONIX' : null,
            'ord_srv_codigo' => $cod, 'ord_srv_desc' => $desc, 'ord_dt_geracao' => $ger, 'ord_dt_vencimento' => $venc, 'ord_valor' => $valor, 'ord_dt_pagamento' => $pag, 'ord_hr_pagamento' => $hr];
        $db->table('ORDEM_SERVICOS')->insert([
            $o(100, 5, 'ABC1D23', 'MEN', 'Mensalidade', '2026-10-01', '2026-10-10', 200, '2026-10-05', '00:00:00'),
            $o(101, 5, 'ABC1D23', 'LS', 'Lavagem Simples', '2026-10-03', '2026-10-03', 40, '2026-10-05', '00:00:00'),
            $o(102, 5, null, 'CDT', 'Crédito', '2026-09-20', null, 10, '2026-10-05', '00:00:00'),
            $o(103, 5, 'ABC1D23', 'MEN', 'Mensalidade', '2026-11-01', '2026-11-10', 200),
            $o(104, 5, null, 'CDT', 'Crédito', '2026-10-05', null, 30),
            $o(105, 6, null, 'EST', 'Estadia Avulsa', '2026-11-02', null, 25),
            $o(106, 6, null, 'EST', 'Estadia Avulsa', '2026-08-02', null, 15, '2026-08-03', '14:30:00'),
        ]);
        $db->table('EXTRATO_LANCAMENTOS')->insert(['ext_identificador' => 'abc-123', 'ext_data' => '2026-10-05', 'ext_valor' => 230, 'ext_nome' => 'JOSE', 'ext_cli_id' => 5, 'ext_total_contas' => 230, 'ext_processado_em' => '2026-10-06 09:00:00', 'ext_usuario' => 'admin']);
    }

    private function importar(): void
    {
        $this->artisan('legado:importar', ['--empresa' => 'Local', '--sem-atividades' => true])->assertSuccessful();
        app(EmpresaAtual::class)->definir(Empresa::first()->id);
    }

    public function test_importa_ordens_pagamentos_e_bate_a_comparacao(): void
    {
        $this->importar();
        $this->assertSame(7, OrdemServico::count());
        $men = OrdemServico::where('numero', 103)->firstOrFail();
        $this->assertSame('mensalidade', $men->tipo);
        $this->assertSame('2026-11', $men->competencia);
        $this->assertNotNull($men->contrato_id);
        $this->assertSame('ABC1D23', $men->placa);
        $this->assertSame('servico', OrdemServico::where('numero', 101)->value('tipo'));
        $this->assertNotNull(OrdemServico::where('numero', 101)->value('servico_id'));

        // ordens pagas no mesmo dia e hora pelo mesmo cliente = um pagamento; crédito usado abate
        $p = Pagamento::where('cliente_id', Cliente::where('legado_id', 5)->value('id'))->firstOrFail();
        $this->assertSame('230.00', $p->valor);
        $this->assertSame(3, $p->itens()->count());
        $this->assertSame('nao_informada', $p->forma->tipo);
        $this->assertSame(2, Pagamento::count());
        $this->assertSame('2026-08-03 14:30', Pagamento::where('valor', 15)->first()->recebido_em->format('Y-m-d H:i'));

        // em aberto: José 200 - 30 = 170; Maria 25
        $this->assertSame(170.0, Baixa::totalAberto(Cliente::where('legado_id', 5)->first()));
        $this->assertSame($p->id, ExtratoLancamento::first()->pagamento_id);

        $this->artisan('legado:comparar')->assertSuccessful();

        // pagamento importado não é estornado no ERP (seria refeito na próxima importação)
        Auth::login(\App\Models\Usuario::first());
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        Baixa::estornar($p, 'teste');
    }

    public function test_data_de_pagamento_zerada_conta_como_paga(): void
    {
        // bancos antigos guardam "0000-00-00": o sistema atual não conta como em aberto (só NULL é em aberto)
        $db = DB::connection('legado');
        $db->statement("SET SESSION sql_mode = ''");
        $db->table('ORDEM_SERVICOS')->insert(['ord_id' => 120, 'ord_cli_id' => 5, 'ord_vcl_placa' => 'ABC1D23', 'ord_srv_codigo' => 'MEN',
            'ord_dt_geracao' => '2025-04-01', 'ord_dt_vencimento' => '2025-04-01', 'ord_valor' => 200, 'ord_dt_pagamento' => '0000-00-00']);
        $this->importar();
        $o = OrdemServico::where('numero', 120)->firstOrFail();
        $this->assertSame('paga', $o->situacao);
        $this->assertNull($o->pago_em);
        $this->assertSame(0, $o->itensPagamento()->count());
        $this->assertSame(170.0, Baixa::totalAberto(Cliente::where('legado_id', 5)->first()));
        $this->artisan('legado:comparar')->assertSuccessful();

        // o extrato da conta fecha com o mesmo em aberto (a ordem baixada sem data também sai do saldo)
        $jose = Cliente::where('legado_id', 5)->first();
        $this->actingAs(\App\Models\Usuario::first())->get("/clientes/{$jose->id}/extrato?de=2000-01-01")
            ->assertOk()->assertInertia(fn ($p) => $p->where('saldo_final', 170));
    }

    public function test_reimportar_nao_duplica_e_segue_o_sistema_atual(): void
    {
        $this->importar();
        $db = DB::connection('legado');
        $db->table('ORDEM_SERVICOS')->where('ord_id', 105)->delete();                                  // apagada lá
        $db->table('ORDEM_SERVICOS')->where('ord_id', 103)->update(['ord_dt_pagamento' => '2026-11-08', 'ord_hr_pagamento' => '10:00:00']); // paga lá
        $this->importar();

        $this->assertSame(6, OrdemServico::count());
        $this->assertSame(0, OrdemServico::where('numero', 105)->count());
        $this->assertSame('paga', OrdemServico::where('numero', 103)->value('situacao'));
        $this->assertSame(3, Pagamento::count());
        $this->assertSame(1, ExtratoLancamento::count());
        $this->artisan('legado:comparar')->assertSuccessful();
    }

    public function test_ordem_do_erp_com_numero_antigo_ganha_numero_novo(): void
    {
        $this->importar();
        Auth::login(\App\Models\Usuario::first());
        $nova = Ordens::lancar(['tipo' => 'estadia', 'cliente_id' => Cliente::first()->id, 'valor' => 12, 'descricao' => 'Estadia']);
        $this->assertSame(107, $nova->numero);   // continua depois do maior número antigo
        DB::connection('legado')->table('ORDEM_SERVICOS')->insert(['ord_id' => 107, 'ord_cli_id' => 6, 'ord_srv_codigo' => 'EST', 'ord_dt_geracao' => '2026-11-09', 'ord_valor' => 9]);
        $this->importar();
        $this->assertSame(108, $nova->fresh()->numero);
        $this->assertSame(9.0, (float) OrdemServico::where('numero', 107)->value('valor'));
    }

    public function test_recebido_no_erp_continua_pago_ao_reimportar(): void
    {
        $this->importar();
        Auth::login(\App\Models\Usuario::first());
        $maria = Cliente::where('legado_id', 6)->first();
        $est = OrdemServico::where('numero', 105)->first();
        Baixa::receber($maria, ['ordens' => [$est->id], 'valor' => 25, 'forma_pagamento_id' => FormaPagamento::where('tipo', 'dinheiro')->value('id')]);
        $this->importar();
        $this->assertSame('paga', $est->fresh()->situacao);
        $this->assertSame(1, Pagamento::where('origem', 'gestao')->count());

        // depois o sistema atual também dá baixa nela: não pode virar um segundo pagamento
        DB::connection('legado')->table('ORDEM_SERVICOS')->where('ord_id', 105)->update(['ord_dt_pagamento' => now()->toDateString(), 'ord_hr_pagamento' => '09:00:00']);
        $this->importar();
        $this->assertSame(1, \App\Models\PagamentoItem::where('ordem_id', $est->id)->count());
        $this->assertSame('paga', $est->fresh()->situacao);
    }
}
