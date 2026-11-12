<?php

namespace Tests\Feature;

use App\Models\ContaFinanceira;
use App\Models\Despesa;
use App\Models\DespesaRecorrente;
use App\Models\FormaPagamento;
use App\Models\Fornecedor;
use App\Models\PlanoConta;
use App\Servicos\Despesas;
use App\Servicos\FormasPagamento;
use App\Servicos\PlanoContas;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Etapa 6a: plano de contas, fornecedores, contas a pagar (parcelas, pagamento, desfazer, cancelar), fixas. */
class ContasPagarTest extends TestCase
{
    use RefreshDatabase;

    private object $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->criarEmpresa('a');
        app(EmpresaAtual::class)->definir($this->a->empresa->id);
        PlanoContas::garantirPadrao();
        FormasPagamento::garantirPadrao();
    }

    private function conta(string $nome): PlanoConta
    {
        return PlanoConta::where('nome', $nome)->firstOrFail();
    }

    public function test_plano_de_contas_padrao_e_codigos(): void
    {
        $this->assertSame('1', PlanoContas::porChave('raiz_receita')->codigo);
        $this->assertSame('1.1', PlanoContas::porChave('receita_mensalidade')->codigo);
        $this->assertSame('2.1.1', PlanoContas::porChave('despesa_salarios')->codigo);
        $this->assertNotNull(PlanoContas::lancavel($this->conta('Energia')->id));
        $this->assertNull(PlanoContas::lancavel($this->conta('Utilidades')->id));   // tem filhas: não lança

        $adm = $this->actingAs($this->a->admin);
        $adm->get('/configuracoes/plano-contas')->assertOk()->assertInertia(fn ($p) => $p->component('Configuracoes/PlanoContas'));
        $adm->post('/configuracoes/plano-contas', ['pai_id' => $this->conta('Utilidades')->id, 'nome' => 'Gás'])->assertSessionHasNoErrors();
        $gas = $this->conta('Gás');
        $this->assertSame('2.3.4', $gas->codigo);
        $adm->post("/configuracoes/plano-contas/{$gas->id}/mover", ['direcao' => 'subir']);
        $this->assertSame('2.3.3', $gas->fresh()->codigo);
        $this->assertSame('2.3.4', $this->conta('Internet e telefone')->codigo);
        // conta do sistema não apaga nem desativa
        $sal = PlanoContas::porChave('despesa_salarios');
        $adm->delete("/configuracoes/plano-contas/{$sal->id}");
        $this->assertNotNull($sal->fresh());
        $adm->put("/configuracoes/plano-contas/{$sal->id}", ['nome' => 'Salários CLT', 'ativa' => false]);
        $this->assertTrue($sal->fresh()->ativa);
        $adm->delete("/configuracoes/plano-contas/{$gas->id}");
        $this->assertNull($gas->fresh());
        $this->actingAs($this->a->operador)->get('/configuracoes/plano-contas')->assertForbidden();
    }

    public function test_lancar_em_parcelas_pagar_desfazer_e_cancelar(): void
    {
        Storage::fake('local');
        $adm = $this->actingAs($this->a->admin);
        $adm->post('/fornecedores', ['nome' => 'loja de produtos', 'conta_padrao_id' => $this->conta('Produtos de lavagem')->id])->assertSessionHasNoErrors();
        $forn = Fornecedor::firstOrFail();
        $this->assertSame('LOJA DE PRODUTOS', $forn->nome);

        $adm->post('/pagar', ['descricao' => '', 'conta_id' => ''])->assertSessionHasErrors(['descricao', 'conta_id', 'valor', 'vencimento']);
        $adm->post('/pagar', ['descricao' => 'Shampoo', 'fornecedor_id' => $forn->id, 'conta_id' => $this->conta('Utilidades')->id,
            'competencia' => '2026-11', 'vencimento' => '2026-11-20', 'valor' => 100])->assertSessionHasErrors('conta_id');
        $adm->post('/pagar', ['descricao' => 'Shampoo', 'fornecedor_id' => $forn->id, 'conta_id' => $this->conta('Produtos de lavagem')->id,
            'competencia' => '2026-11', 'vencimento' => '2026-11-20', 'valor' => 100, 'parcelas' => 3,
            'comprovante' => UploadedFile::fake()->create('nota.pdf', 50, 'application/pdf')])->assertRedirect('/pagar');

        $p = Despesa::orderBy('vencimento')->get();
        $this->assertCount(3, $p);
        $this->assertSame([33.33, 33.33, 33.34], $p->map(fn ($x) => (float) $x->valor)->all());
        $this->assertSame(['2026-11-20', '2026-12-20', '2027-01-20'], $p->map(fn ($x) => $x->vencimento->toDateString())->all());
        $this->assertSame('2027-01', $p[2]->competencia->format('Y-m'));
        $this->assertSame('3/3', $p[2]->parcela.'/'.$p[2]->parcelas);
        Storage::disk('local')->assertExists($p[0]->comprovante);
        $adm->get("/pagar/{$p[0]->id}/comprovante")->assertOk();

        // pagar com juros, saindo do banco
        $pix = FormaPagamento::where('tipo', 'pix')->first();
        $banco = ContaFinanceira::where('tipo', 'banco')->first();
        $adm->post("/pagar/{$p[0]->id}/pagar", ['pago_em' => now()->toDateString(), 'forma_pagamento_id' => ''])->assertSessionHasErrors('forma_pagamento_id');
        $adm->post("/pagar/{$p[0]->id}/pagar", ['pago_em' => now()->addDay()->toDateString(), 'forma_pagamento_id' => $pix->id])->assertSessionHasErrors('pago_em');
        $adm->post("/pagar/{$p[0]->id}/pagar", ['pago_em' => now()->toDateString(), 'valor_pago' => 35.5, 'forma_pagamento_id' => $pix->id, 'conta_financeira_id' => $banco->id])->assertSessionHasNoErrors();
        $x = $p[0]->fresh();
        $this->assertSame(['paga', 35.5, $banco->id], [$x->situacao, (float) $x->valor_pago, $x->conta_financeira_id]);
        $adm->put("/pagar/{$x->id}", ['descricao' => 'X', 'conta_id' => $x->conta_id, 'competencia' => '2026-11', 'vencimento' => '2026-11-20', 'valor' => 1])->assertSessionHasErrors('valor');
        $adm->post("/pagar/{$x->id}/cancelar", ['motivo' => 'teste'])->assertSessionHasErrors('motivo');

        // desfazer volta para a pagar; depois cancela com motivo
        $adm->post("/pagar/{$x->id}/desfazer", ['motivo' => ''])->assertSessionHasErrors('motivo');
        $adm->post("/pagar/{$x->id}/desfazer", ['motivo' => 'paguei a conta errada']);
        $this->assertSame(['aberta', null], [$x->fresh()->situacao, $x->fresh()->valor_pago]);
        $adm->put("/pagar/{$x->id}", ['descricao' => 'Shampoo 5L', 'conta_id' => $x->conta_id, 'competencia' => '2026-11', 'vencimento' => '2026-11-25', 'valor' => 40])->assertSessionHasNoErrors();
        $this->assertSame(['Shampoo 5L', 40.0], [$x->fresh()->descricao, (float) $x->fresh()->valor]);
        $adm->post("/pagar/{$x->id}/cancelar", ['motivo' => 'devolvido']);
        $this->assertSame('cancelada', $x->fresh()->situacao);

        // lançar já paga
        $adm->post('/pagar', ['descricao' => 'Tarifa', 'conta_id' => $this->conta('Tarifas bancárias')->id, 'competencia' => '2026-11', 'vencimento' => now()->toDateString(),
            'valor' => 12.9, 'pago' => true, 'pago_em' => now()->toDateString(), 'forma_pagamento_id' => $pix->id])->assertSessionHasNoErrors();
        $t = Despesa::where('descricao', 'Tarifa')->first();
        $this->assertSame('paga', $t->situacao);
        $this->assertSame($pix->conta_financeira_id, $t->conta_financeira_id);   // de onde saiu: conta da forma

        // conta com lançamentos não ganha subconta (os lançamentos ficariam numa conta-mãe)
        $adm->post('/configuracoes/plano-contas', ['pai_id' => $x->conta_id, 'nome' => 'Shampoo'])->assertSessionHas('erro');
        $adm->get('/pagar')->assertOk()->assertInertia(fn ($p) => $p->component('Pagar/Index')->has('despesas.data', 2)->where('resumo.pago_mes', 12.9));
        $adm->get('/pagar?estado=canceladas')->assertInertia(fn ($p) => $p->has('despesas.data', 1));
        $this->actingAs($this->a->operador)->get('/pagar')->assertForbidden();
    }

    public function test_contas_fixas_geram_uma_por_mes(): void
    {
        $this->travelTo('2026-11-12 10:00');
        $adm = $this->actingAs($this->a->admin);
        $adm->post('/pagar/fixas', ['descricao' => 'Aluguel', 'conta_id' => $this->conta('Aluguel')->id, 'valor' => 2500, 'dia_vencimento' => 31, 'inicio' => '2026-11'])->assertSessionHasNoErrors();
        $this->assertSame(['2026-11-30', '2026-12-31'], Despesa::orderBy('vencimento')->pluck('vencimento')->map->toDateString()->all());
        $this->assertSame(0, Despesas::gerarRecorrentes());   // não repete

        $this->travelTo('2027-02-03 10:00');
        $this->assertSame(3, Despesas::gerarRecorrentes());   // janeiro, fevereiro (dia 28) e março
        $this->assertTrue(Despesa::where('vencimento', '2027-02-28')->exists());

        $r = DespesaRecorrente::first();
        $adm->put("/pagar/fixas/{$r->id}", ['descricao' => 'Aluguel', 'conta_id' => $r->conta_id, 'valor' => 2600, 'dia_vencimento' => 10, 'inicio' => '2026-11', 'fim' => '2027-03', 'ativa' => true]);
        $this->travelTo('2027-05-01 10:00');
        $this->artisan('pagar:gerar-fixas')->assertSuccessful();
        $this->assertSame(5, Despesa::count());   // terminou em março: abril e maio não geram
        $this->assertSame(2500.0, (float) Despesa::where('vencimento', '2027-03-31')->value('valor'));  // já gerada não muda
    }

    public function test_inicio_mostra_contas_atrasadas_e_isolamento(): void
    {
        Despesas::lancar(['descricao' => 'Energia', 'conta_id' => $this->conta('Energia')->id, 'competencia' => now()->format('Y-m'),
            'vencimento' => now()->subDay()->toDateString(), 'valor' => 300]);
        $this->actingAs($this->a->admin)->get('/')->assertInertia(fn ($p) => $p->where('pagar.atrasadas.qtd', 1)->where('pagar.atrasadas.valor', 300));
        $this->actingAs($this->a->operador)->get('/')->assertInertia(fn ($p) => $p->where('pagar', null));

        $x = Despesa::first();
        $b = $this->criarEmpresa('b');
        app(EmpresaAtual::class)->definir($b->empresa->id);
        $this->actingAs($b->admin)->post("/pagar/{$x->id}/cancelar", ['motivo' => 'x'])->assertNotFound();
    }
}
