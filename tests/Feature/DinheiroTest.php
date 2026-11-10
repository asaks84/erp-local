<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\FormaPagamento;
use App\Models\MensalidadeContrato;
use App\Models\MensalidadePausa;
use App\Models\OrdemServico;
use App\Models\Pagamento;
use App\Models\Servico;
use App\Models\ServicoPreco;
use App\Servicos\Baixa;
use App\Servicos\Cobrancas;
use App\Servicos\FormasPagamento;
use App\Servicos\Mensalidades;
use App\Servicos\Ordens;
use App\Support\EmpresaAtual;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Regras do dinheiro: mensalidades, primeira cobrança, baixa, estorno e cobranças. */
class DinheiroTest extends TestCase
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function contrato(float $valor = 200, string $inicio = '2026-01-01', ?object $x = null): MensalidadeContrato
    {
        $x ??= $this->x;

        return MensalidadeContrato::create(['unidade_id' => $this->a->unidade->id, 'cliente_id' => $x->cliente->id,
            'veiculo_id' => $x->veiculo->id, 'periodo' => 'Diurno', 'valor' => $valor, 'inicio' => $inicio]);
    }

    private function pix(): int
    {
        return FormaPagamento::where('tipo', 'pix')->value('id');
    }

    private function ordem(string $tipo, float $valor, string $data = '2026-11-01'): OrdemServico
    {
        return Ordens::lancar(['tipo' => $tipo, 'cliente_id' => $this->x->cliente->id, 'valor' => $valor, 'descricao' => ucfirst($tipo), 'data' => $data, 'vencimento' => $tipo === 'credito' ? null : $data]);
    }

    // ---------------------------------------------------------------- numeração e lançamentos

    public function test_numeracao_continua_do_maior_numero(): void
    {
        $o1 = $this->ordem('estadia', 10);
        $o1->update(['numero' => 500]);       // como uma ordem importada com número antigo
        \DB::table('numeracoes')->delete();
        $o2 = $this->ordem('estadia', 10);
        $o3 = $this->ordem('estadia', 10);
        $this->assertSame(501, $o2->numero);
        $this->assertSame(502, $o3->numero);
    }

    public function test_lavagem_com_adicional_usa_preco_pelo_tipo(): void
    {
        $lav = Servico::create(['codigo' => 'LS', 'nome' => 'Lavagem simples']);
        $cera = Servico::create(['codigo' => 'CE', 'nome' => 'Cera', 'papel' => 'adicional']);
        $lav->adicionais()->attach($cera->id);
        ServicoPreco::create(['servico_id' => $lav->id, 'tipo_veiculo_id' => $this->a->tipo->id, 'valor' => 40, 'vigencia_inicio' => '2000-01-01']);
        ServicoPreco::create(['servico_id' => $cera->id, 'tipo_veiculo_id' => $this->a->tipo->id, 'valor' => 15, 'vigencia_inicio' => '2000-01-01']);

        [$p, $ad] = Ordens::lancarServico($this->x->veiculo, $lav, [$cera]);
        $this->assertSame('40.00', $p->valor);
        $this->assertSame('15.00', $ad->valor);
        $this->assertSame($p->id, $ad->principal_id);
        $this->assertSame($this->x->cliente->id, $ad->cliente_id);
        $this->assertSame('ABC1D23', $ad->placa);
    }

    // ---------------------------------------------------------------- mensalidades

    public function test_proporcional_do_mes_comercial(): void
    {
        // entrada no dia 20 = dias 20 a 30 = 11 dias
        $this->assertSame(73.33, Mensalidades::proporcional(200, Carbon::parse('2026-11-20'))[0]);
        // dia 31 conta como 30: 1 dia
        $this->assertSame(6.67, Mensalidades::proporcional(200, Carbon::parse('2026-12-31'))[0]);
        $this->assertSame('2027-02-28', Mensalidades::vencimentoNoMes(31, '2027-02')->toDateString());
    }

    public function test_geracao_do_mes_sem_duplicar(): void
    {
        $c = $this->contrato();
        $l = Mensalidades::previa('2026-12')->first();
        $this->assertSame('gerar', $l['situacao']);
        $this->assertSame('2026-12-10', $l['vencimento']);   // dia de vencimento do cliente

        [$qtd, $total] = Mensalidades::gerar('2026-12', [$c->id]);
        $this->assertSame([1, 200.0], [$qtd, $total]);
        [$qtd] = Mensalidades::gerar('2026-12', [$c->id]);
        $this->assertSame(0, $qtd);
        $this->assertSame('ja_gerada', Mensalidades::previa('2026-12')->first()['situacao']);
        $this->assertSame(1, OrdemServico::where('competencia', '2026-12')->count());
    }

    public function test_geracao_usa_o_valor_reajustado_e_a_taxa_da_pausa(): void
    {
        $c = $this->contrato(200, '2026-01-01');
        $novo = \App\Servicos\Contratos::reajustar($c, 250, '2027-01-01');
        MensalidadePausa::create(['contrato_id' => $novo->id, 'inicio' => '2027-03-01', 'fim' => '2027-03-31', 'taxa_tipo' => 'pct', 'taxa_valor' => 20]);
        Carbon::setTestNow('2026-12-15');

        $this->assertSame(250.0, Mensalidades::previa('2027-01')->first()['valor']);
        $marco = Mensalidades::previa('2027-03')->first();
        $this->assertSame('pausa', $marco['situacao']);
        $this->assertSame(50.0, $marco['valor']);
        Mensalidades::gerar('2027-03', [$marco['contrato_id']]);
        $this->assertSame('Mensalidade (reserva de vaga em pausa)', OrdemServico::where('competencia', '2027-03')->value('descricao'));
    }

    public function test_quem_comecou_no_mes_vem_desmarcado(): void
    {
        Carbon::setTestNow('2026-11-20');
        $this->contrato(200, '2026-11-20');
        $l = Mensalidades::previa('2026-11')->first();
        $this->assertSame('inicio_no_mes', $l['situacao']);
        $this->assertFalse($l['marcar']);
        $this->assertTrue($l['selecionavel']);
    }

    public function test_primeira_cobranca_proporcional_e_proximo_mes(): void
    {
        Carbon::setTestNow('2026-11-20 10:00');
        $c = $this->contrato(200, '2026-11-20');
        $geradas = Mensalidades::primeiraCobranca($c, 'prop_proximo');
        $this->assertCount(2, $geradas);
        $this->assertSame('73.33', $geradas[0]->valor);
        $this->assertSame('2026-11', $geradas[0]->competencia);
        $this->assertSame('Mensalidade proporcional 20/11 a 30/11', $geradas[0]->descricao);
        $this->assertSame('2026-12-10', $geradas[1]->vencimento->toDateString());
        // de novo: não duplica
        $this->assertCount(0, Mensalidades::primeiraCobranca($c, 'prop_proximo'));
        // dezembro já gerado pela primeira cobrança: a geração de dezembro mostra "já gerada"
        $this->assertSame('ja_gerada', Mensalidades::previa('2026-12')->first()['situacao']);
    }

    public function test_cortesia_cobre_o_mes_atual(): void
    {
        Carbon::setTestNow('2026-11-20 10:00');
        $c = $this->contrato(200, '2026-11-20');
        Mensalidades::primeiraCobranca($c, 'proximo');
        $this->assertSame('coberta', Mensalidades::previa('2026-11')->first()['situacao']);
    }

    public function test_primeira_cobranca_pela_tela_respeita_permissao(): void
    {
        Carbon::setTestNow('2026-11-20 10:00');
        $c = $this->contrato(200, '2026-11-20');
        $this->actingAs($this->a->operador)->post("/mensalidades/{$c->id}/primeira-cobranca", ['opcao' => 'proximo'])->assertSessionHasErrors('opcao');
        $this->actingAs($this->a->operador)->post("/mensalidades/{$c->id}/primeira-cobranca", ['opcao' => 'proporcional', 'valor' => 10])->assertSessionHasNoErrors();
        // operador não muda o valor do proporcional
        $this->assertSame('73.33', OrdemServico::where('contrato_id', $c->id)->value('valor'));
    }

    // ---------------------------------------------------------------- baixa

    public function test_baixa_total_com_credito_abatendo(): void
    {
        $m = $this->ordem('mensalidade', 200);
        $l = $this->ordem('servico', 40);
        $cr = $this->ordem('credito', 30);
        $this->assertSame(210.0, Baixa::totalAberto($this->x->cliente));

        [$p] = Baixa::receber($this->x->cliente, ['ordens' => [$m->id, $l->id], 'valor' => 210, 'forma_pagamento_id' => $this->pix()]);
        $this->assertSame('210.00', $p->valor);
        $this->assertSame(3, $p->itens()->count());
        $this->assertSame(210.0, round((float) $p->itens()->sum('valor'), 2));
        $this->assertSame('paga', $cr->fresh()->situacao);
        $this->assertSame(0.0, Baixa::totalAberto($this->x->cliente));
    }

    public function test_sobra_vira_credito_e_falta_vira_debito(): void
    {
        $m = $this->ordem('mensalidade', 200);
        Baixa::receber($this->x->cliente, ['ordens' => [$m->id], 'valor' => 250, 'forma_pagamento_id' => $this->pix(), 'sobra' => 'credito']);
        $this->assertSame(-50.0, Baixa::totalAberto($this->x->cliente));

        $m2 = $this->ordem('mensalidade', 200, '2026-12-01');
        // deve 150 (200 - 50 de crédito); pagou 100 → débito de 50
        Baixa::receber($this->x->cliente, ['ordens' => [$m2->id], 'valor' => 100, 'forma_pagamento_id' => $this->pix(), 'falta' => 'debito']);
        $this->assertSame(50.0, Baixa::totalAberto($this->x->cliente));
        $this->assertSame(1, OrdemServico::abertas()->where('tipo', 'debito')->count());
    }

    public function test_sobra_como_estadia_feita_na_hora(): void
    {
        $m = $this->ordem('mensalidade', 200);
        [$p] = Baixa::receber($this->x->cliente, ['ordens' => [$m->id], 'valor' => 225, 'forma_pagamento_id' => $this->pix(), 'sobra' => 'servico', 'servico_id' => 'estadia']);
        $e = OrdemServico::where('tipo', 'estadia')->firstOrFail();
        $this->assertSame('paga', $e->situacao);
        $this->assertSame('25.00', $e->valor);
        $this->assertSame(225.0, round((float) $p->itens()->sum('valor'), 2));
        $this->assertSame(0.0, Baixa::totalAberto($this->x->cliente));
    }

    public function test_diferenca_sem_escolha_e_desconto_sem_permissao(): void
    {
        $m = $this->ordem('mensalidade', 200);
        try {
            Baixa::receber($this->x->cliente, ['ordens' => [$m->id], 'valor' => 180, 'forma_pagamento_id' => $this->pix()]);
            $this->fail('deveria pedir a escolha');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('falta', $e->errors());
        }
        Auth::login($this->a->operador);
        $this->expectException(ValidationException::class);
        Baixa::receber($this->x->cliente, ['ordens' => [$m->id], 'valor' => 180, 'forma_pagamento_id' => $this->pix(), 'falta' => 'desconto']);
    }

    public function test_baixa_parcial_deixa_o_resto_em_aberto(): void
    {
        $l1 = $this->ordem('servico', 40, '2026-10-05');
        $l2 = $this->ordem('servico', 40, '2026-10-12');
        $m = $this->ordem('mensalidade', 200, '2026-11-10');
        Baixa::receber($this->x->cliente, ['ordens' => [$l1->id, $l2->id], 'valor' => 80, 'forma_pagamento_id' => $this->pix()]);
        $this->assertSame('aberta', $m->fresh()->situacao);
        $this->assertSame(200.0, Baixa::totalAberto($this->x->cliente));
    }

    public function test_taxa_e_previsao_da_forma(): void
    {
        $cred = FormaPagamento::where('tipo', 'credito')->first();
        $cred->update(['taxa_pct' => 3, 'prazo_dias' => 30, 'parcelas_max' => 3, 'taxas_parcelas' => ['2' => 5]]);
        $m = $this->ordem('mensalidade', 200);
        Carbon::setTestNow('2026-11-10 09:00');
        [$p] = Baixa::receber($this->x->cliente, ['ordens' => [$m->id], 'valor' => 200, 'forma_pagamento_id' => $cred->id, 'parcelas' => 2]);
        $this->assertSame('10.00', $p->taxa);
        $this->assertSame('190.00', $p->liquido);
        $this->assertSame('2026-12-10', $p->previsto_para->toDateString());
    }

    public function test_estorno_reabre_as_ordens_e_cancela_o_credito(): void
    {
        $m = $this->ordem('mensalidade', 200);
        [$p] = Baixa::receber($this->x->cliente, ['ordens' => [$m->id], 'valor' => 250, 'forma_pagamento_id' => $this->pix(), 'sobra' => 'credito']);
        Baixa::estornar($p, 'Pix devolvido');
        $this->assertSame('aberta', $m->fresh()->situacao);
        $this->assertSame('estornado', $p->fresh()->situacao);
        $this->assertSame(200.0, Baixa::totalAberto($this->x->cliente));
    }

    public function test_estorno_bloqueado_se_o_credito_ja_foi_usado(): void
    {
        $m = $this->ordem('mensalidade', 200);
        [$p] = Baixa::receber($this->x->cliente, ['ordens' => [$m->id], 'valor' => 250, 'forma_pagamento_id' => $this->pix(), 'sobra' => 'credito']);
        $m2 = $this->ordem('mensalidade', 200, '2026-12-01');
        Baixa::receber($this->x->cliente, ['ordens' => [$m2->id], 'valor' => 150, 'forma_pagamento_id' => $this->pix()]);
        $this->expectException(ValidationException::class);
        Baixa::estornar($p, 'engano');
    }

    public function test_receber_pela_tela(): void
    {
        $m = $this->ordem('mensalidade', 200);
        $this->actingAs($this->a->operador)->post("/clientes/{$this->x->cliente->id}/receber", [
            'ordens' => [$m->id], 'valor' => '200', 'forma_pagamento_id' => $this->pix(), 'recebido_em' => now()->toDateString(),
        ])->assertSessionHasNoErrors()->assertRedirect("/clientes/{$this->x->cliente->id}");
        $this->assertSame('paga', $m->fresh()->situacao);
        $this->assertSame(1, Pagamento::count());
    }

    // ---------------------------------------------------------------- cobranças

    public function test_cobranca_imutavel_com_saldo_anterior(): void
    {
        $l = $this->ordem('servico', 40, '2026-10-05');
        $m = $this->ordem('mensalidade', 200, '2026-10-10');
        $out = Cobrancas::emitir($this->x->cliente, '2026-10');
        $this->assertSame('240.00', $out->total);
        $this->assertSame(2, $out->itens()->count());

        // pagou só a lavagem; a mensalidade fica em aberto
        Baixa::receber($this->x->cliente, ['ordens' => [$l->id], 'valor' => 40, 'forma_pagamento_id' => $this->pix()]);
        $this->assertSame('parcial', $out->fresh()->situacao);

        $m2 = $this->ordem('mensalidade', 200, '2026-11-10');
        $nov = Cobrancas::emitir($this->x->cliente, '2026-11');
        $this->assertSame('400.00', $nov->total);
        $this->assertSame(['saldo_anterior', 'atual'], $nov->itens->pluck('grupo')->all());
        $this->assertSame('parcial', $out->fresh()->situacao);
        // a cópia de outubro não mudou
        $this->assertSame('240.00', $out->fresh()->total);
        $this->assertSame(2, $out->itens()->count());

        Baixa::receber($this->x->cliente, ['ordens' => [$m->id, $m2->id], 'valor' => 400, 'forma_pagamento_id' => $this->pix()]);
        $this->assertSame('paga', $nov->fresh()->situacao);
        $this->assertSame('parcial', $out->fresh()->situacao);
    }

    public function test_cobranca_nao_paga_fica_levada_e_cancelar_devolve(): void
    {
        $m = $this->ordem('mensalidade', 200, '2026-10-10');
        $out = Cobrancas::emitir($this->x->cliente, '2026-10');
        $nov = Cobrancas::emitir($this->x->cliente, '2026-11');
        $this->assertSame('levada', $out->fresh()->situacao);

        Cobrancas::cancelar($nov, 'emitida por engano');
        $this->assertSame('cancelada', $nov->fresh()->situacao);
        $this->assertSame($out->id, $m->fresh()->cobranca_id);
        $this->assertSame('aberta', $out->fresh()->situacao);
        // depois de cancelar dá para emitir de novo
        $this->assertNotNull(Cobrancas::emitir($this->x->cliente, '2026-11'));
    }

    public function test_cobranca_duplicada_no_mes_e_sem_contas(): void
    {
        $this->ordem('mensalidade', 200);
        Cobrancas::emitir($this->x->cliente, '2026-11');
        $this->expectException(ValidationException::class);
        Cobrancas::emitir($this->x->cliente, '2026-11');
    }

    public function test_cancelar_ordem_exige_permissao_e_motivo(): void
    {
        $m = $this->ordem('mensalidade', 200);
        $this->actingAs($this->a->operador)->post("/ordens/{$m->id}/cancelar", ['motivo' => 'saiu'])->assertForbidden();
        $this->actingAs($this->a->admin)->post("/ordens/{$m->id}/cancelar", ['motivo' => ''])->assertSessionHasErrors('motivo');
        $this->actingAs($this->a->admin)->post("/ordens/{$m->id}/cancelar", ['motivo' => 'Deixou de ser mensalista'])->assertSessionHasNoErrors();
        $this->assertSame('cancelada', $m->fresh()->situacao);
    }

    public function test_dinheiro_isolado_por_empresa(): void
    {
        $this->ordem('mensalidade', 200);
        $b = $this->criarEmpresa('b');
        app(EmpresaAtual::class)->definir($b->empresa->id);
        $this->assertSame(0, OrdemServico::count());
        $this->actingAs($b->admin)->get('/clientes/'.$this->x->cliente->id.'/receber')->assertNotFound();
    }
}
