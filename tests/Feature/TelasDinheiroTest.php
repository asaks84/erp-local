<?php

namespace Tests\Feature;

use App\Models\Cobranca;
use App\Models\ExtratoLancamento;
use App\Models\FormaPagamento;
use App\Models\MensalidadeContrato;
use App\Models\OrdemServico;
use App\Models\Pagamento;
use App\Servicos\FormasPagamento;
use App\Servicos\Ordens;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/** Telas do dinheiro: abrem, respeitam as permissões e fazem o caminho completo. */
class TelasDinheiroTest extends TestCase
{
    use RefreshDatabase;

    private object $a;

    private object $x;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->criarEmpresa('a');
        $this->x = $this->criarCliente($this->a);
        app(EmpresaAtual::class)->definir($this->a->empresa->id);
        Auth::login($this->a->admin);
        FormasPagamento::garantirPadrao();
    }

    private function ordem(float $valor = 200, string $tipo = 'mensalidade'): OrdemServico
    {
        return Ordens::lancar(['tipo' => $tipo, 'cliente_id' => $this->x->cliente->id, 'valor' => $valor, 'descricao' => 'Mensalidade', 'data' => now()->toDateString(), 'vencimento' => now()->toDateString(), 'competencia' => now()->format('Y-m')]);
    }

    public function test_telas_abrem_para_quem_tem_acesso(): void
    {
        $o = $this->ordem();
        $cob = \App\Servicos\Cobrancas::emitir($this->x->cliente, now()->format('Y-m'));
        $c = $this->x->cliente->id;
        foreach (['/', "/clientes/{$c}", "/clientes/{$c}/receber", "/clientes/{$c}/lancar", "/clientes/{$c}/extrato", '/ordens', '/mensalidades/gerar',
            '/cobrancas', '/cobrancas/gerar', "/cobrancas/{$cob->id}", "/cobrancas/imprimir?ids[]={$cob->id}", '/contas/abertas', '/relatorios/recebimentos',
            '/extrato', '/configuracoes/pagamentos', '/empresa'] as $url) {
            $this->actingAs($this->a->admin)->get($url)->assertOk();
        }
        $this->actingAs($this->a->admin)->get('/contas/abertas?csv=1')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_operador_recebe_mas_nao_ve_gestao(): void
    {
        $this->ordem();
        $c = $this->x->cliente->id;
        $this->actingAs($this->a->operador)->get("/clientes/{$c}/receber")->assertOk();
        $this->actingAs($this->a->operador)->get('/mensalidades/gerar')->assertOk();
        foreach (['/contas/abertas', '/relatorios/recebimentos', '/extrato', '/configuracoes/pagamentos'] as $url) {
            $this->actingAs($this->a->operador)->get($url)->assertForbidden();
        }
        // débito/crédito manual só com "alterar contas"
        $this->actingAs($this->a->operador)->post("/clientes/{$c}/lancar", ['tipo' => 'credito', 'valor' => 10, 'data' => now()->toDateString(), 'motivo' => 'x'])->assertForbidden();
        $this->actingAs($this->a->operador)->post("/clientes/{$c}/lancar", ['tipo' => 'estadia', 'valor' => 15, 'data' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame(1, OrdemServico::where('tipo', 'estadia')->count());
    }

    public function test_gerar_mensalidades_e_emitir_cobrancas_pela_tela(): void
    {
        $c = MensalidadeContrato::create(['unidade_id' => $this->a->unidade->id, 'cliente_id' => $this->x->cliente->id, 'veiculo_id' => $this->x->veiculo->id,
            'periodo' => 'Diurno', 'valor' => 180, 'inicio' => '2026-01-01']);
        $mes = now()->addMonthNoOverflow()->format('Y-m');
        $this->actingAs($this->a->operador)->post('/mensalidades/gerar', ['mes' => $mes, 'contratos' => [$c->id]])->assertSessionHasNoErrors();
        $this->assertSame(1, OrdemServico::where('competencia', $mes)->count());

        $this->actingAs($this->a->operador)->post('/cobrancas/gerar', ['mes' => $mes, 'clientes' => [$this->x->cliente->id]])
            ->assertSessionHasNoErrors()->assertSessionHas('imprimir');
        $this->assertSame('180.00', Cobranca::first()->total);
        // operador não cancela cobrança
        $this->actingAs($this->a->operador)->post('/cobrancas/'.Cobranca::first()->id.'/cancelar', ['motivo' => 'x'])->assertForbidden();
    }

    public function test_extrato_do_csv_ate_a_baixa(): void
    {
        $this->x->cliente->update(['nome' => 'JOAO DA SILVA', 'documento' => '52998224725']);
        $this->ordem(150);
        $csv = "Data;Descrição;Valor;Saldo;Identificador\n"
            .now()->format('d/m/Y').";Transferência recebida pelo Pix - JOAO DA SILVA - •••.982.247-•• - BANCO (0001) Agência: 1 Conta: 123-4;150,00;1000,00;PIX-001\n"
            .now()->format('d/m/Y').";Pagamento de boleto;-50,00;850,00;SAIDA-1\n";
        $arquivo = UploadedFile::fake()->createWithContent('extrato.csv', $csv);

        $r = $this->actingAs($this->a->admin)->post('/extrato', ['arquivo' => $arquivo]);
        $r->assertSessionHasNoErrors();
        $url = $r->headers->get('Location');
        $this->assertStringContainsString('/extrato/conferir/', $url);
        $token = basename($url);
        $this->actingAs($this->a->admin)->get("/extrato/conferir/{$token}")->assertOk()
            ->assertInertia(fn ($p) => $p->where('entradas.0.cliente_id', $this->x->cliente->id)->where('entradas.0.certeza', 'alta')->where('saidas', 1));

        $this->actingAs($this->a->admin)->post('/extrato/confirmar', [
            'token' => $token, 'forma_pagamento_id' => FormaPagamento::where('tipo', 'pix')->value('id'),
            'linhas' => [['indice' => 0, 'cliente_id' => $this->x->cliente->id, 'modo' => 'tudo', 'lembrar' => true]],
        ])->assertRedirect("/extrato/resultado/{$token}");
        $this->assertSame(0, OrdemServico::abertas()->count());
        $p = Pagamento::firstOrFail();
        $this->assertSame('extrato', $p->origem);
        $this->assertSame($p->id, ExtratoLancamento::first()->pagamento_id);

        // o mesmo PIX não baixa duas vezes
        $this->actingAs($this->a->admin)->post('/extrato/confirmar', [
            'token' => $token, 'forma_pagamento_id' => FormaPagamento::where('tipo', 'pix')->value('id'),
            'linhas' => [['indice' => 0, 'cliente_id' => $this->x->cliente->id, 'modo' => 'tudo', 'sobra' => 'credito']],
        ]);
        $this->assertSame(1, Pagamento::count());
    }

    public function test_formas_de_pagamento_nas_configuracoes(): void
    {
        $this->actingAs($this->a->admin)->post('/configuracoes/formas', [
            'nome' => 'Crédito 3x', 'tipo' => 'credito', 'conta_financeira_id' => null, 'taxa_pct' => 3.5, 'taxa_fixa' => 0,
            'parcelas_max' => 3, 'taxas_parcelas' => ['2' => 4.2, '3' => '', '9' => 1], 'prazo_dias' => 30, 'ativa' => true,
        ])->assertSessionHasNoErrors();
        $f = FormaPagamento::where('nome', 'Crédito 3x')->firstOrFail();
        $this->assertSame(['2' => 4.2], $f->taxas_parcelas);
        $this->assertSame(8.4, $f->taxaSobre(200, 2));
    }
}
