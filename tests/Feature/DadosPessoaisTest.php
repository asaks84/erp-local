<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\FormaPagamento;
use App\Models\Nfse;
use App\Models\OrdemServico;
use App\Models\PagadorVinculado;
use App\Models\Veiculo;
use App\Servicos\Baixa;
use App\Servicos\DadosPessoais;
use App\Servicos\FormasPagamento;
use App\Servicos\Ordens;
use App\Support\Atividades;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** LGPD: exportar os dados do cliente e anonimizar, com os bloqueios (dívida, crédito, pátio, ativo, paralelo). */
class DadosPessoaisTest extends TestCase
{
    use RefreshDatabase;

    private object $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->criarEmpresa('a');
        app(EmpresaAtual::class)->definir($this->a->empresa->id);
        FormasPagamento::garantirPadrao();
    }

    private function cliente(): array
    {
        $c = Cliente::create(['nome' => 'MARIA DA SILVA', 'documento' => '52998224725', 'celular' => '13999990000', 'email' => 'maria@exemplo.com',
            'cep' => '11010000', 'logradouro' => 'RUA XV', 'numero' => '10', 'bairro' => 'CENTRO', 'cidade' => 'SANTOS', 'observacoes' => 'paga no dia 10']);
        $v = Veiculo::create(['cliente_id' => $c->id, 'tipo_veiculo_id' => $this->a->tipo->id, 'placa' => 'ABC1D23', 'marca' => 'GM', 'modelo' => 'ONIX', 'cor' => 'PRATA']);
        $o = Ordens::lancar(['tipo' => 'servico', 'cliente_id' => $c->id, 'descricao' => 'Lavagem', 'data' => '2026-11-05', 'vencimento' => '2026-11-05', 'valor' => 50]);
        $o->update(['placa' => 'ABC1D23', 'veiculo_descricao' => 'ONIX PRATA']);
        PagadorVinculado::create(['cliente_id' => $c->id, 'nome' => 'JOSE DA SILVA', 'documento' => '11144477735']);
        Atividades::registrar('cliente_alterado', 'cliente '.$c->id.' - MARIA DA SILVA', $this->a->admin);

        return [$c, $v, $o];
    }

    public function test_exportar_mostra_e_baixa_os_dados(): void
    {
        [$c] = $this->cliente();
        $adm = $this->actingAs($this->a->admin);
        $adm->get("/clientes/{$c->id}/dados-pessoais")->assertInertia(fn ($pg) => $pg->component('Clientes/DadosPessoais')
            ->where('dados.cadastro.documento', '529.982.247-25')->where('dados.veiculos.0.placa', 'ABC1D23')->has('dados.servicos', 1)->has('dados.pagadores_vinculados', 1));
        $adm->get("/clientes/{$c->id}/dados-pessoais?formato=json")->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="dados-cliente-'.$c->id.'.json"')
            ->assertJsonPath('cadastro.email', 'maria@exemplo.com');
        $this->assertSame(2, DB::table('log_atividades')->where('acao', 'cliente_dados_exportados')->count());
        $this->actingAs($this->a->operador)->get("/clientes/{$c->id}/dados-pessoais")->assertForbidden();
    }

    public function test_bloqueios_e_anonimizacao(): void
    {
        [$c, $v, $o] = $this->cliente();
        $adm = $this->actingAs($this->a->admin);

        // ativo e com conta em aberto: bloqueado
        $b = DadosPessoais::bloqueios($c);
        $this->assertCount(2, $b);
        $this->assertStringContainsString('ativo', $b[0]);
        $this->assertStringContainsString('conta(s) em aberto', $b[1]);
        $adm->get("/clientes/{$c->id}")->assertInertia(fn ($pg) => $pg->has('lgpd.bloqueios', 2));
        $adm->post("/clientes/{$c->id}/anonimizar", ['motivo' => 'pedido', 'confirmacao' => 'MARIA DA SILVA'])->assertSessionHasErrors('confirmacao');

        // paga e remove: libera
        Baixa::receberNaHora([$o], FormaPagamento::where('tipo', 'pix')->value('id'));
        $adm->post("/clientes/{$c->id}/remover");
        $c->refresh();
        $this->assertSame([], DadosPessoais::bloqueios($c));

        // cliente do sistema atual com o espelho ligado: espera a virada
        config(['erp.espelho_ativo' => true]);
        $c->forceFill(['legado_id' => 77])->save();
        $this->assertCount(1, DadosPessoais::bloqueios($c));
        config(['erp.espelho_ativo' => false]);

        // nota fiscal emitida fica como está (guarda legal)
        $nota = Nfse::create(['cliente_id' => $c->id, 'ambiente' => 'restrita', 'serie' => '1', 'numero_dps' => 1, 'id_dps' => 'DPS1', 'situacao' => 'emitida',
            'valor' => 50, 'tomador_documento' => '52998224725', 'tomador_nome' => 'MARIA DA SILVA', 'descricao' => 'Lavagem']);

        $adm->post("/clientes/{$c->id}/anonimizar", ['motivo' => 'pedido', 'confirmacao' => 'maria'])->assertSessionHasErrors('confirmacao');
        $adm->post("/clientes/{$c->id}/anonimizar", ['motivo' => 'pedido', 'confirmacao' => ' maria da silva '])->assertSessionHas('sucesso');

        $c->refresh();
        $this->assertSame("CLIENTE ANONIMIZADO {$c->id}", $c->nome);
        $this->assertNull($c->documento);
        $this->assertNull($c->celular);
        $this->assertNull($c->email);
        $this->assertNull($c->logradouro);
        $this->assertNull($c->observacoes);
        $this->assertTrue($c->nao_recebe_avisos);
        $this->assertNotNull($c->anonimizado_em);
        $this->assertNull($v->fresh()->placa);
        $this->assertNull($o->fresh()->placa);
        $this->assertSame('50.00', OrdemServico::find($o->id)->valor);                 // o dinheiro fica
        $this->assertSame('MARIA DA SILVA', $nota->fresh()->tomador_nome);             // a nota fica
        $this->assertSame(0, PagadorVinculado::where('cliente_id', $c->id)->count());
        $this->assertSame(0, DB::table('log_atividades')->where('detalhe', 'like', '%MARIA DA SILVA%')->count());
        $this->assertSame(1, DB::table('log_atividades')->where('acao', 'cliente_anonimizado')->count());

        // não tem volta
        $adm->get("/clientes/{$c->id}/editar")->assertForbidden();
        $adm->post("/clientes/{$c->id}/reativar")->assertForbidden();
        $adm->get("/clientes/{$c->id}/dados-pessoais")->assertForbidden();
        $adm->get("/clientes/{$c->id}")->assertInertia(fn ($pg) => $pg->where('cliente.anonimizado_em', now()->format('d/m/Y')));
        $this->actingAs($this->a->operador)->post("/clientes/{$c->id}/anonimizar", ['motivo' => 'pedido', 'confirmacao' => 'x'])->assertForbidden();
    }

    public function test_credito_e_patio_bloqueiam(): void
    {
        [$c, $v, $o] = $this->cliente();
        Baixa::receberNaHora([$o], FormaPagamento::where('tipo', 'pix')->value('id'));
        $this->actingAs($this->a->admin)->post("/clientes/{$c->id}/remover");
        Ordens::lancar(['tipo' => 'credito', 'cliente_id' => $c->id, 'descricao' => 'Crédito', 'data' => '2026-11-06', 'valor' => 20]);
        DB::table('estadias')->insert(['empresa_id' => $this->a->empresa->id, 'uuid' => (string) Str::uuid(), 'numero' => 1, 'cliente_id' => $c->id,
            'tipo_veiculo_id' => $this->a->tipo->id, 'entrada' => now(), 'situacao' => 'patio', 'criado_em' => now(), 'atualizado_em' => now()]);
        $b = DadosPessoais::bloqueios($c->fresh());
        $this->assertCount(2, $b);
        $this->assertStringContainsString('crédito', $b[0]);
        $this->assertStringContainsString('pátio', $b[1]);
    }
}
