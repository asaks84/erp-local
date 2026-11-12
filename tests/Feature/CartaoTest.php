<?php

namespace Tests\Feature;

use App\Models\Cartao;
use App\Models\CartaoRegra;
use App\Models\Despesa;
use App\Models\FaturaCartao;
use App\Servicos\Cartoes;
use App\Servicos\Despesas;
use App\Servicos\FormasPagamento;
use App\Servicos\PlanoContas;
use App\Servicos\Resultado;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Etapa 6d: fatura de cartão (OFX e CSV do Nubank), classificação, regras, resultado e cartão pessoal. Dados fictícios. */
class CartaoTest extends TestCase
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
        Storage::fake('local');
        \Carbon\Carbon::setTestNow('2026-11-12 10:00:00');
    }

    /** OFX no formato do Nubank, com as compras [memo, valor (negativo = compra)] */
    private function ofx(string $inicio, string $fim, array $compras, ?float $saldo = null, string $conta = 'conta-ficticia-1'): string
    {
        $t = '';
        foreach ($compras as $n => [$memo, $valor]) {
            $tipo = $valor < 0 ? 'DEBIT' : 'CREDIT';
            $t .= "<STMTTRN>\n<TRNTYPE>{$tipo}</TRNTYPE>\n<DTPOSTED>{$inicio}000000[-3:BRT]</DTPOSTED>\n<TRNAMT>{$valor}</TRNAMT>\n<FITID>id-{$fim}-{$n}</FITID>\n<MEMO>{$memo}</MEMO>\n</STMTTRN>\n";
        }
        $saldo ??= array_sum(array_map(fn ($c) => $c[1] < 0 || ! str_starts_with($c[0], 'Pagamento') ? $c[1] : 0, $compras));

        return "OFXHEADER:100\nDATA:OFXSGML\n<OFX>\n<CREDITCARDMSGSRSV1><CCSTMTTRNRS><CCSTMTRS>\n<CCACCTFROM>\n<ACCTID>{$conta}</ACCTID>\n</CCACCTFROM>\n<BANKTRANLIST>\n"
            ."<DTSTART>{$inicio}000000[-3:BRT]</DTSTART>\n<DTEND>{$fim}000000[-3:BRT]</DTEND>\n{$t}</BANKTRANLIST>\n<LEDGERBAL>\n<BALAMT>{$saldo}</BALAMT>\n</LEDGERBAL>\n</CCSTMTRS></CCSTMTTRNRS></CREDITCARDMSGSRSV1>\n</OFX>";
    }

    private function arquivo(string $conteudo, string $nome): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nome, $conteudo);
    }

    private function conta(string $nome): int
    {
        return PlanoContas::lancaveis()->firstWhere('nome', $nome)['id'];
    }

    public function test_le_ofx_e_csv(): void
    {
        $r = Cartoes::lerArquivo($this->ofx('20260916', '20261016', [['Pagamento recebido', 100.00], ['Mercadolivre*Lojax - Parcela 2/10', -38.03], ['Posto Bom', -150.00], ['Estorno Posto Bom', 20.00]], -168.02), 'Nubank_2026-10-23.ofx');
        $this->assertSame(['conta-ficticia-1', '2026-09-16', '2026-10-16', '2026-10-23', 168.02], [$r['identificador'], $r['inicio'], $r['fechamento'], $r['vencimento'], $r['saldo']]);
        $this->assertSame([38.03, 150.0, -20.0], array_column($r['itens'], 'valor'));   // pagamento sai; estorno abate
        $this->assertSame(['mercadolivre*lojax', 2, 10], Cartoes::estabelecimento('Mercadolivre*Lojax - Parcela 2/10'));
        $this->assertTrue(Cartoes::marketplace('mercadolivre*lojax'));
        $this->assertFalse(Cartoes::marketplace('posto bom'));

        $csv = Cartoes::lerArquivo("date,title,amount\n2026-10-01,Posto Bom,150.00\n2026-10-05,Pagamento recebido,-300.00\n2026-10-09,Amazon - Parcela 1/3,41.50\n", 'Nubank_2026-11-23.csv');
        $this->assertSame([150.0, 41.5], array_column($csv['itens'], 'valor'));
        $this->assertSame(['2026-10-09', '2026-11-23'], [$csv['fechamento'], $csv['vencimento']]);
    }

    public function test_importa_classifica_aprende_e_entra_no_resultado(): void
    {
        $adm = $this->actingAs($this->a->admin);
        $adm->post('/pagar/cartoes', ['nome' => 'Nubank da empresa', 'tipo' => 'empresa', 'dia_vencimento' => 23])->assertSessionHasNoErrors();
        $cartao = Cartao::firstOrFail();
        $ofx = $this->ofx('20260916', '20261016', [['Posto Bom', -150.00], ['Mercadolivre*Lojax - Parcela 1/10', -38.03], ['Mercadolivre*Lojay', -60.00], ['Padaria', -12.00]], -260.02);
        $adm->post('/pagar/cartoes/importar', ['cartao_id' => $cartao->id, 'arquivo' => $this->arquivo($ofx, 'Nubank_2026-10-23.ofx')])->assertSessionHasNoErrors();
        $f = FaturaCartao::firstOrFail();
        $d = Despesa::findOrFail($f->despesa_id);
        $this->assertSame(['aberta', 260.02, '2026-10-23', '2026-10', PlanoContas::porChave('despesa_cartao')->id],
            [$d->situacao, (float) $d->valor, $d->vencimento->toDateString(), $d->competencia->format('Y-m'), $d->conta_id]);   // o que o banco cobra
        $this->assertSame('conta-ficticia-1', $cartao->fresh()->identificador);
        // mesma fatura de novo: não entra
        $adm->post('/pagar/cartoes/importar', ['cartao_id' => $cartao->id, 'arquivo' => $this->arquivo($ofx, 'Nubank_2026-10-23.ofx')])->assertSessionHasErrors('arquivo');
        // alterar a conta da fatura por Contas a pagar não pode: vem das compras
        $adm->put("/pagar/{$d->id}", ['descricao' => 'x', 'conta_id' => $this->conta('Energia'), 'competencia' => '2026-10', 'vencimento' => '2026-10-23', 'valor' => 1])->assertSessionHasErrors('valor');

        $itens = $f->itens()->orderBy('id')->get();
        $adm->get("/pagar/faturas/{$f->id}")->assertOk()->assertInertia(fn ($p) => $p->component('Pagar/Fatura')->has('itens', 4)->where('itens.1.perguntar', true));
        $adm->put("/pagar/faturas/{$f->id}", ['itens' => [
            $itens[0]->id => ['conta_id' => $this->conta('Manutenção do pátio'), 'descricao' => '', 'da_empresa' => true],
            $itens[1]->id => ['conta_id' => $this->conta('Investimentos e equipamentos'), 'descricao' => 'Tela de proteção', 'da_empresa' => true],
            $itens[2]->id => ['conta_id' => $this->conta('Produtos de lavagem'), 'descricao' => 'Shampoo automotivo', 'da_empresa' => true],
            $itens[3]->id => ['conta_id' => null, 'descricao' => '', 'da_empresa' => false],
        ]])->assertSessionHasNoErrors();
        $this->assertSame('fixo', CartaoRegra::where('estabelecimento', 'posto bom')->value('modo'));
        $this->assertSame('perguntar', CartaoRegra::where('estabelecimento', 'mercadolivre*lojay')->value('modo'));

        // resultado de outubro: compras nas suas contas; a conta da fatura não entra; investimento e "não é da empresa" à parte
        $r = Resultado::mes('2026-10');
        $this->assertSame(210.0, $r['total_despesas']);           // 150 + 60
        $this->assertSame(38.03, $r['total_investimentos']);
        $this->assertSame(12.0, $r['fora_empresa']);
        $this->assertSame(round(-210 - 38.03 - 12, 2), $r['resultado_final']);
        $this->assertNotContains('Cartão de crédito (a classificar)', array_column($r['despesas'], 'nome'));

        // próxima fatura: parcela 2/10 herda a classificação; posto vem pela regra; posto classificado diferente vira "perguntar"
        $ofx2 = $this->ofx('20261016', '20261116', [['Posto Bom', -90.00], ['Mercadolivre*Lojax - Parcela 2/10', -38.03], ['Mercadolivre*Lojaz', -20.00]], -148.03);
        $adm->post('/pagar/cartoes/importar', ['cartao_id' => $cartao->id, 'arquivo' => $this->arquivo($ofx2, 'Nubank_2026-11-23.ofx')])->assertSessionHasNoErrors();
        $f2 = FaturaCartao::where('fechamento', '2026-11-16')->firstOrFail();
        $i2 = $f2->itens()->orderBy('id')->get();
        $this->assertSame($this->conta('Manutenção do pátio'), $i2[0]->conta_id);
        $this->assertSame([$this->conta('Investimentos e equipamentos'), 'Tela de proteção'], [$i2[1]->conta_id, $i2[1]->descricao]);
        $this->assertNull($i2[2]->conta_id);
        $adm->put("/pagar/faturas/{$f2->id}", ['itens' => [$i2[0]->id => ['conta_id' => $this->conta('Energia'), 'descricao' => '', 'da_empresa' => true]]])->assertSessionHasNoErrors();
        $this->assertSame('perguntar', CartaoRegra::where('estabelecimento', 'posto bom')->value('modo'));
        // compra sem conta aparece como "a classificar"
        $this->assertContains('Cartão de crédito (a classificar)', array_column(Resultado::mes('2026-11')['despesas'], 'nome'));

        // pelo dinheiro: conta no mês em que a fatura foi paga
        $pix = \App\Models\FormaPagamento::where('tipo', 'pix')->value('id');
        Despesas::pagar($d->fresh(), ['pago_em' => '2026-10-23', 'forma_pagamento_id' => $pix]);
        $this->assertSame(210.0, Resultado::mes('2026-10', 'caixa')['total_despesas']);
        $fluxo = collect(Resultado::fluxo('2026-10-01', '2026-10-31')['dias'])->keyBy('data');
        $this->assertSame(260.02, $fluxo['2026-10-23']['saidas']);
    }

    public function test_cartao_pessoal_so_conta_o_que_e_da_empresa_e_nao_sai_do_caixa(): void
    {
        $adm = $this->actingAs($this->a->admin);
        $cartao = Cartao::create(['nome' => 'ML do sócio', 'tipo' => 'pessoal']);
        $ofx = $this->ofx('20260920', '20261020', [['Mercadolivre*Loja1', -300.00], ['Cinema', -50.00]], -350.00, 'conta-pessoal');
        $adm->post('/pagar/cartoes/importar', ['cartao_id' => $cartao->id, 'arquivo' => $this->arquivo($ofx, 'fatura.ofx'), 'vencimento' => '2026-10-28'])->assertSessionHasNoErrors();
        $f = FaturaCartao::firstOrFail();
        $this->assertNull($f->despesa_id);                         // nada marcado como da empresa ainda
        $i = $f->itens()->orderBy('id')->get();
        $this->assertFalse($i[0]->da_empresa);
        $adm->put("/pagar/faturas/{$f->id}", ['itens' => [$i[0]->id => ['conta_id' => $this->conta('Manutenção do pátio'), 'descricao' => 'Telhas', 'da_empresa' => true]]])->assertSessionHasNoErrors();
        $d = Despesa::with('forma')->findOrFail($f->fresh()->despesa_id);
        $this->assertSame(['paga', 300.0, 'socio'], [$d->situacao, (float) $d->valor, $d->forma->tipo]);
        $this->assertSame(300.0, Resultado::mes('2026-10')['total_despesas']);
        $this->assertSame(0.0, Resultado::mes('2026-10')['fora_empresa']);   // o resto do cartão pessoal não é assunto da empresa
        $this->assertSame(0.0, Resultado::fluxo('2026-10-01', '2026-10-31')['realizado']['saidas']);   // não saiu do caixa

        // desmarcar tudo: a conta é cancelada
        $adm->put("/pagar/faturas/{$f->id}", ['itens' => [$i[0]->id => ['conta_id' => null, 'descricao' => '', 'da_empresa' => false]]])->assertSessionHasNoErrors();
        $this->assertSame('cancelada', $d->fresh()->situacao);
        // arquivo de um cartão no outro: recusa
        $outro = Cartao::create(['nome' => 'Nubank', 'tipo' => 'empresa']);
        $adm->post('/pagar/cartoes/importar', ['cartao_id' => $outro->id, 'arquivo' => $this->arquivo($this->ofx('20261020', '20261120', [['X', -1.0]], null, 'conta-pessoal'), 'f.ofx')])
            ->assertSessionHasErrors('arquivo');
    }

    public function test_desfazer_importacao(): void
    {
        $adm = $this->actingAs($this->a->admin);
        $cartao = Cartao::create(['nome' => 'Nubank', 'tipo' => 'empresa']);
        $adm->post('/pagar/cartoes/importar', ['cartao_id' => $cartao->id, 'arquivo' => $this->arquivo($this->ofx('20260916', '20261016', [['Posto', -10.0]]), 'Nubank_2026-10-23.ofx')]);
        $f = FaturaCartao::firstOrFail();
        $d = Despesa::findOrFail($f->despesa_id);
        $adm->post("/pagar/faturas/{$f->id}/desfazer", ['motivo' => 'arquivo errado'])->assertRedirect('/pagar/cartoes');
        $this->assertSame(0, FaturaCartao::count());
        $this->assertSame(['cancelada', null], [$d->fresh()->situacao, $d->fresh()->fatura_id]);
        $adm->get('/pagar/cartoes')->assertOk()->assertInertia(fn ($p) => $p->component('Pagar/Cartoes')->has('faturas', 0));
    }
}
