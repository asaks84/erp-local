<?php

namespace Tests\Feature;

use App\Models\Convenio;
use App\Models\Servico;
use App\Models\ServicoPreco;
use App\Models\Tarifa;
use App\Models\Unidade;
use App\Servicos\Precos;
use App\Support\EmpresaAtual;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Tipos, tabelas de estadia, serviços com preço por vigência e convênios. */
class ConfiguracoesTest extends TestCase
{
    use RefreshDatabase;

    private function tarifa(object $a, array $extra = []): array
    {
        return array_merge([
            'nome' => 'Carros', 'unidade_id' => null, 'tolerancia_min' => 10, 'fracao_min' => 5, 'restante' => 'faixas',
            'vigencia_inicio' => '2026-01-01', 'vigencia_fim' => null, 'tipos' => [$a->tipo->id],
            'faixas' => [['duracao_min' => 60, 'modo' => 'fixo', 'bloco_min' => 60, 'valor' => 10], ['duracao_min' => null, 'modo' => 'bloco', 'bloco_min' => 30, 'valor' => 5]],
            'pacotes' => [['nome' => 'Diária', 'duracao_min' => 720, 'valor' => 25]],
        ], $extra);
    }

    public function test_cria_tabela_e_tipo_nao_fica_em_duas_ao_mesmo_tempo(): void
    {
        $a = $this->criarEmpresa('a');
        $this->actingAs($a->admin)->post('/configuracoes/tarifas', $this->tarifa($a))->assertRedirect('/configuracoes/tarifas');
        $this->actingAs($a->admin)->post('/configuracoes/tarifas', $this->tarifa($a, ['nome' => 'Outra']))->assertSessionHasErrors('tipos');
        // numa unidade específica pode (exceção da unidade)
        $this->actingAs($a->admin)->post('/configuracoes/tarifas', $this->tarifa($a, ['nome' => 'Só da unidade', 'unidade_id' => $a->unidade->id]))->assertSessionHasNoErrors();

        app(EmpresaAtual::class)->definir($a->empresa->id);
        $this->assertSame('Só da unidade', Precos::tarifa($a->tipo->id, $a->unidade->id)->nome);
        $this->assertSame('Carros', Precos::tarifa($a->tipo->id)->nome);
        $this->assertNull(Precos::tarifa($a->moto->id));
    }

    public function test_nova_versao_encerra_a_anterior(): void
    {
        $a = $this->criarEmpresa('a');
        $this->actingAs($a->admin)->post('/configuracoes/tarifas', $this->tarifa($a));
        app(EmpresaAtual::class)->definir($a->empresa->id);
        $velha = Tarifa::firstOrFail();
        $this->actingAs($a->admin)->post('/configuracoes/tarifas', $this->tarifa($a, ['anterior_id' => $velha->id, 'vigencia_inicio' => '2027-01-01', 'nome' => 'Carros 2027']))->assertSessionHasNoErrors();
        $this->assertSame('2026-12-31', $velha->fresh()->vigencia_fim->toDateString());
        $this->assertSame('Carros', Precos::tarifa($a->tipo->id, null, Carbon::parse('2026-12-31'))->nome);
        $this->assertSame('Carros 2027', Precos::tarifa($a->tipo->id, null, Carbon::parse('2027-01-01'))->nome);
    }

    public function test_simulador(): void
    {
        $a = $this->criarEmpresa('a');
        $r = $this->actingAs($a->admin)->postJson('/configuracoes/tarifas/simular', [
            'entrada' => '2026-11-05 08:10', 'saida' => '2026-11-05 10:45', 'regras' => $this->tarifa($a),
        ])->assertOk()->json();
        $this->assertEquals(25, $r['valor']);
        $this->assertSame(155, $r['minutos']);
        $this->actingAs($a->operador)->postJson('/configuracoes/tarifas/simular', [])->assertForbidden();
    }

    public function test_preco_de_servico_com_reajuste_agendado(): void
    {
        $a = $this->criarEmpresa('a');
        $base = ['codigo' => 'lex', 'nome' => 'Lavagem Externa', 'papel' => 'principal', 'preco_modo' => 'fixo', 'ativo' => true, 'vale_a_partir' => '2026-01-01'];
        $this->actingAs($a->admin)->post('/configuracoes/servicos', $base + ['precos' => [$a->tipo->id => 40, $a->moto->id => '']])->assertRedirect('/configuracoes/servicos');
        app(EmpresaAtual::class)->definir($a->empresa->id);
        $s = Servico::firstOrFail();
        $this->assertSame('LEX', $s->codigo);
        $this->assertSame(1, ServicoPreco::count()); // moto sem preço

        // reajuste para 2027: até lá vale 40
        $this->actingAs($a->admin)->put("/configuracoes/servicos/{$s->id}", array_merge($base, ['vale_a_partir' => '2027-01-01', 'precos' => [$a->tipo->id => 45]]))->assertSessionHasNoErrors();
        $this->assertSame(40.0, Precos::servico($s->id, $a->tipo->id, null, Carbon::parse('2026-12-31')));
        $this->assertSame(45.0, Precos::servico($s->id, $a->tipo->id, null, Carbon::parse('2027-01-01')));
        $this->assertNull(Precos::servico($s->id, $a->moto->id));

        // exceção da unidade
        ServicoPreco::create(['servico_id' => $s->id, 'tipo_veiculo_id' => $a->tipo->id, 'unidade_id' => $a->unidade->id, 'valor' => 50, 'vigencia_inicio' => '2026-01-01']);
        $this->assertSame(50.0, Precos::servico($s->id, $a->tipo->id, $a->unidade->id, Carbon::parse('2026-06-01')));
        $outra = Unidade::create(['nome' => 'Outra']);
        $this->assertSame(40.0, Precos::servico($s->id, $a->tipo->id, $outra->id, Carbon::parse('2026-06-01')));
    }

    public function test_tipo_usado_nao_e_excluido(): void
    {
        $a = $this->criarEmpresa('a');
        $this->criarCliente($a);
        $this->actingAs($a->admin)->delete("/configuracoes/tipos/{$a->tipo->id}")->assertSessionHas('erro');
        $this->actingAs($a->admin)->delete("/configuracoes/tipos/{$a->moto->id}")->assertSessionHas('sucesso');
    }

    public function test_convenio(): void
    {
        $a = $this->criarEmpresa('a');
        $x = $this->criarCliente($a, 'CLINICA');
        app(EmpresaAtual::class)->definir($a->empresa->id);
        $lav = Servico::create(['codigo' => 'LAV', 'nome' => 'Lavagem simples']);
        $this->actingAs($a->operador)->get('/convenios')->assertForbidden();
        $base = ['cliente_id' => $x->cliente->id, 'fechamento' => 'mensalidade'];
        // nada marcado; limite de tempo em serviço; parcial sem valor
        $this->actingAs($a->admin)->post('/convenios', $base + ['regras' => []])->assertSessionHasErrors('regras');
        $this->actingAs($a->admin)->post('/convenios', $base + ['regras' => [['servico_id' => $lav->id, 'modo' => 'tempo', 'limite_min' => 60]]])->assertSessionHasErrors('regras.0.modo');
        $this->actingAs($a->admin)->post('/convenios', $base + ['regras' => [['servico_id' => null, 'modo' => 'parcial']]])->assertSessionHasErrors('regras.0.limite_valor');
        $this->actingAs($a->admin)->post('/convenios', ['cliente_id' => $x->cliente->id, 'fechamento' => 'semana', 'regras' => [['servico_id' => null, 'modo' => 'integral']]])->assertSessionHasErrors('fechamento_semana');

        $this->actingAs($a->admin)->post('/convenios', ['fechamento' => 'semana', 'fechamento_semana' => 2] + $base + ['regras' => [
            ['servico_id' => null, 'modo' => 'tempo', 'limite_min' => 120], ['servico_id' => $lav->id, 'modo' => 'parcial', 'limite_valor' => 10]]])->assertRedirect('/convenios');
        $c = Convenio::first();
        $this->assertSame('Estadia: paga até 2h · Lavagem simples: paga até R$ 10,00', $c->resumo());
        $this->assertSame(['Toda terça', true], [$c->resumoFechamento(), $c->regras_no_erp]);
        $this->actingAs($a->admin)->post('/convenios', $base + ['regras' => [['servico_id' => null, 'modo' => 'integral']]])->assertSessionHasErrors('cliente_id');
        $this->actingAs($a->admin)->get("/convenios/{$c->id}")->assertOk()->assertInertia(fn ($p) => $p->has('convenio.regras', 2)->has('opcoes', 2));
    }

    public function test_telas_de_configuracao_abrem(): void
    {
        $a = $this->criarEmpresa('a');
        foreach (['/configuracoes/tipos', '/configuracoes/tarifas', '/configuracoes/tarifas/nova', '/configuracoes/servicos', '/configuracoes/servicos/novo', '/convenios', '/convenios/novo', '/empresa', '/pendentes'] as $url) {
            $this->actingAs($a->admin)->get($url)->assertOk();
        }
        $this->actingAs($a->admin)->post('/empresa/link-cadastro')->assertSessionHas('sucesso');
        $this->assertNotNull($a->empresa->fresh()->codigo_publico);
        $this->actingAs($a->admin)->put('/empresa/config', ['dia_vencimento_padrao' => 10, 'periodos' => ['Diurno', '24 horas'], 'pausa_taxa_tipo' => 'pct', 'pausa_taxa_valor' => 30])->assertSessionHasNoErrors();
        $this->assertSame(['Diurno', '24 horas'], $a->empresa->fresh()->config('periodos'));
    }
}
