<?php

namespace Tests\Feature;

use App\Models\Despesa;
use App\Models\Diaria;
use App\Models\FormaPagamento;
use App\Models\Funcionario;
use App\Models\Holerite;
use App\Servicos\Despesas;
use App\Servicos\FormasPagamento;
use App\Servicos\LeitorDocumento;
use App\Servicos\PlanoContas;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Etapa 6b: leitura de boleto/guia/recibo, funcionários, recibos de salário e diárias. Dados fictícios. */
class FolhaTest extends TestCase
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

    private static function mod10(string $n): int
    {
        $s = 0;
        $p = 2;
        for ($i = strlen($n) - 1; $i >= 0; $i--) {
            $x = (int) $n[$i] * $p;
            $s += intdiv($x, 10) + $x % 10;
            $p = $p === 2 ? 1 : 2;
        }

        return (10 - $s % 10) % 10;
    }

    private static function mod11(string $n): int
    {
        $s = 0;
        $p = 2;
        for ($i = strlen($n) - 1; $i >= 0; $i--) {
            $s += (int) $n[$i] * $p;
            $p = $p === 9 ? 2 : $p + 1;
        }

        return $s % 11;
    }

    /** linha digitável de um boleto fictício: banco 341, fator, valor em centavos */
    private static function linhaBoleto(int $fator, int $centavos): string
    {
        $livre = '1571234567812345678901234';
        $semDv = '3419'.sprintf('%04d', $fator).sprintf('%010d', $centavos).$livre;
        $dv = 11 - self::mod11($semDv);
        $dv = in_array($dv, [0, 10, 11], true) ? 1 : $dv;
        $barras = substr($semDv, 0, 4).$dv.substr($semDv, 4);
        $c1 = substr($barras, 0, 4).substr($barras, 19, 5);
        $c2 = substr($barras, 24, 10);
        $c3 = substr($barras, 34, 10);

        return substr($c1, 0, 5).'.'.substr($c1, 5).self::mod10($c1).' '.substr($c2, 0, 5).'.'.substr($c2, 5).self::mod10($c2).' '
            .substr($c3, 0, 5).'.'.substr($c3, 5).self::mod10($c3).' '.$dv.' '.sprintf('%04d', $fator).sprintf('%010d', $centavos);
    }

    /** guia de arrecadação fictícia (DARF/DAS: segmento 5, valor efetivo, módulo 11) */
    private static function guia(int $centavos): string
    {
        $semDv = '858'.sprintf('%011d', $centavos).'03282628640720262590897103361';
        $r = self::mod11($semDv);
        $barras = substr($semDv, 0, 3).($r <= 1 ? 0 : 11 - $r).substr($semDv, 3);
        $blocos = [];
        foreach (str_split($barras, 11) as $b) {
            $rb = self::mod11($b);
            $blocos[] = $b.' '.($rb <= 1 ? 0 : 11 - $rb);
        }

        return implode(' ', $blocos);
    }

    public function test_le_boleto_e_guias(): void
    {
        $boleto = LeitorDocumento::interpretar("Banco X\nBeneficiário EMPRESA FICTICIA\n".self::linhaBoleto(1626, 600000)."\nREF.: ALUGUEL DO PATIO");
        $this->assertSame('boleto', $boleto['tipo']);
        $this->assertEquals(6000.0, $boleto['valor']);
        $this->assertSame('2026-11-10', $boleto['vencimento']);   // fator 1626 na base nova (22/02/2025 = 1000)
        $this->assertTrue($boleto['conferido']);
        $this->assertSame('ALUGUEL DO PATIO', $boleto['descricao']);

        // um número errado no código: não confere, e o valor não vem do código
        $certa = self::linhaBoleto(1626, 600000);
        $errado = substr_replace($certa, (string) (((int) $certa[7] + 1) % 10), 7, 1);
        $this->assertNull(LeitorDocumento::codigo($errado));

        $das = LeitorDocumento::interpretar("Documento de Arrecadação do Simples Nacional\nPeríodo de Apuração agosto/2026\nPagar este documento até\n21/09/2026\nValor Total do Documento 621,72\n".self::guia(62172));
        $this->assertSame(['das', 621.72, '2026-09-21', '2026-08', 'despesa_simples', true],
            [$das['tipo'], $das['valor'], $das['vencimento'], $das['competencia'], $das['conta_chave'], $das['conferido']]);

        // OCR: espaços no meio da data, rótulos tortos; o valor do código prevalece sobre o texto mal lido
        $darf = LeitorDocumento::interpretar("Documento de Arrecadação\nde Receitas Federais\nCONTR PREV DESCONTA SEGURADO\nPA:08/2026\nPagar até: 1 8/09/ 2026\nValor: 501,18\n".self::guia(50113));
        $this->assertSame(['darf', 501.13, '2026-09-18', '2026-08', 'despesa_inss'], [$darf['tipo'], $darf['valor'], $darf['vencimento'], $darf['competencia'], $darf['conta_chave']]);
        $this->assertNotEmpty($darf['avisos']);

        // FGTS: sem código de barras; confere pela soma; vencimento na leitura invertida
        $fgts = LeitorDocumento::interpretar("GFD - Guia do FGTS Digital\nTotal FGTS: 482,64 0,00 482,64\nTotal Consignado: 288,95 0,00 288,95\nTotal da Guia: 771,59\nData de geração da Guia: 16/09/2026\n08/2026\n"
            .LeitorDocumento::INVERTIDO."\n1 8 / 09 / 2026\n");
        $this->assertSame(['fgts', 771.59, '2026-09-18', true, 'despesa_fgts'], [$fgts['tipo'], $fgts['valor'], $fgts['vencimento'], $fgts['conferido'], $fgts['conta_chave']]);
    }

    public function test_ler_pela_tela_acha_fornecedor_pelo_documento(): void
    {
        $f = \App\Models\Fornecedor::create(['nome' => 'IMOBILIARIA FICTICIA', 'documento' => '11222333000181', 'conta_padrao_id' => PlanoContas::lancaveis()->firstWhere('nome', 'Aluguel')['id']]);
        $r = $this->actingAs($this->a->admin)->postJson('/pagar/ler', ['texto' => "Beneficiário IMOBILIARIA CNPJ 11.222.333/0001-81\n".self::linhaBoleto(1626, 123450)])->assertOk()->json();
        $this->assertSame($f->id, $r['fornecedor_id']);
        $this->assertSame($f->conta_padrao_id, $r['conta_id']);
        $this->assertEquals(1234.5, $r['valor']);
        // PDF que é só imagem: pede o OCR do navegador
        $this->actingAs($this->a->admin)->post('/pagar/ler', ['arquivo' => UploadedFile::fake()->create('guia.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertOk()->assertJson(['precisa_ocr' => true]);
    }

    private function textoRecibos(): string
    {
        $um = fn ($reg, $nome, $funcao, $base, $venc, $desc, $liq, $fgts) => "RECIBO DE PAGAMENTO DE SALÁRIO MENSAL\nCompetência\n09/2026\nEmpresa\tDivisão R.H.\n"
            ."0001 EMPRESA FICTICIA LTDA\nRua Tal, 1 - CENTRO\tFunção\n{$funcao}Santos\tSP\tCNPJ: 11.222.333/0001-81\nNº Reg.Chapa Nome\n{$reg} {$nome}\n"
            ."Cód.\tDescrição\tReferência\tVencimentos\tDescontos\n11000-000\n001 SALARIO NORMAL\t30,00\t{$base}\n101 I.N.S.S\t7,50\t100,00\nRESUMO DO\nSALÁRIO\n"
            ."Salário Base\tSal. Contribuição\tTotal de Vencimentos\tTotal de Descontos\n{$base}\t{$venc}\t{$venc}\t{$desc}\n"
            ."Base Cál. F.G.T.S\tF.G.T.S do Mês\tBase Cál. I.R.\tL Í Q U I D O\nA R E C E B E R{$venc}\t{$fgts}\t1.000,00\t{$liq}\n1º via/Funcionário\n";

        return $um('01001', 'FULANO DE TAL', 'Vigia', '1.500,00', '2.000,00', '300,00', '1.700,00', '160,00')
            .$um('01001', 'FULANO DE TAL', 'Vigia', '1.500,00', '2.000,00', '300,00', '1.700,00', '160,00')   // 2ª via
            .$um('01002', 'BELTRANO SILVA', 'MANOBRISTA', '2.200,00', '2.500,00', '250,00', '2.250,00', '200,00');
    }

    public function test_le_recibos_e_importa_como_contas_a_pagar(): void
    {
        $lidos = LeitorDocumento::holerites($this->textoRecibos());
        $this->assertCount(2, $lidos);
        $this->assertSame(['1001', 'FULANO DE TAL', 'Vigia', '2026-09', 2000.0, 300.0, 1700.0, 160.0, true],
            [$lidos[0]['matricula'], $lidos[0]['nome'], $lidos[0]['funcao'], $lidos[0]['competencia'], $lidos[0]['vencimentos'], $lidos[0]['descontos'], $lidos[0]['liquido'], $lidos[0]['fgts'], $lidos[0]['conferido']]);
        $this->assertSame('MANOBRISTA', $lidos[1]['funcao']);
        $this->assertSame('2026-10-06', LeitorDocumento::quintoDiaUtil('2026-10')); // 1 a 6/10, domingo 4 não conta

        $adm = $this->actingAs($this->a->admin);
        $j = $adm->postJson('/funcionarios/recibos/ler', ['texto' => $this->textoRecibos()])->assertOk()->json('recibos');
        $this->assertSame('2026-10-06', $j[0]['vencimento']);
        $this->assertNull($j[0]['funcionario_id']);

        Storage::fake('local');
        $itens = array_map(fn ($r) => array_diff_key($r, ['conferido' => 1, 'ja_importado' => 1]), $j);
        $adm->post('/funcionarios/recibos', ['arquivo' => UploadedFile::fake()->create('recibos.pdf', 20, 'application/pdf'), 'itens' => $itens])->assertSessionHasNoErrors();
        $this->assertSame(2, Funcionario::where('tipo', 'registrado')->count());
        $d = Despesa::where('descricao', 'Salário FULANO DE TAL 09/2026')->firstOrFail();
        $this->assertEquals(1700, $d->valor);
        $this->assertSame(PlanoContas::porChave('despesa_salarios')->id, $d->conta_id);
        $this->assertSame('2026-10-06', $d->vencimento->toDateString());
        $this->assertNotNull($d->comprovante);

        // de novo: já importado
        $adm->post('/funcionarios/recibos', ['itens' => $itens])->assertSessionHasErrors();
        $this->assertTrue($adm->postJson('/funcionarios/recibos/ler', ['texto' => $this->textoRecibos()])->json('recibos.0.ja_importado'));
        // conta cancelada: o recibo pode ser importado de novo
        Despesas::cancelar($d, 'valor errado no recibo');
        $this->assertFalse($adm->postJson('/funcionarios/recibos/ler', ['texto' => $this->textoRecibos()])->json('recibos.0.ja_importado'));
        $adm->get('/funcionarios/recibos?mes=2026-09')->assertOk()->assertInertia(fn ($p) => $p->component('Funcionarios/Recibos')->has('recibos', 2));
    }

    public function test_diarias_de_avulso_viram_pagamento(): void
    {
        $adm = $this->actingAs($this->a->admin);
        $adm->post('/funcionarios', ['nome' => 'avulso um', 'tipo' => 'avulso', 'valor_diaria' => 120])->assertSessionHasNoErrors();
        $f = Funcionario::where('nome', 'AVULSO UM')->firstOrFail();
        $adm->post('/funcionarios/diarias', ['funcionario_id' => $f->id, 'datas' => ['2026-11-07', '2026-11-08', '2026-11-07'], 'valor' => 120])->assertSessionHasNoErrors();
        $this->assertSame(2, Diaria::count());
        $adm->post('/funcionarios/diarias', ['funcionario_id' => $f->id, 'datas' => [now()->addDay()->toDateString()], 'valor' => 120])->assertSessionHasErrors('datas.0');

        $pix = FormaPagamento::where('tipo', 'pix')->first() ?? FormaPagamento::first();
        $adm->post('/funcionarios/diarias/pagar', ['funcionario_id' => $f->id, 'ids' => Diaria::pluck('id')->all(), 'pago' => true,
            'pago_em' => '2026-11-08', 'forma_pagamento_id' => $pix->id])->assertSessionHasNoErrors();
        $d = Despesa::where('descricao', 'like', 'Diárias AVULSO UM%')->firstOrFail();
        $this->assertSame(['paga', 240.0, 'Diárias AVULSO UM 07/11 a 08/11 (2 dias)'], [$d->situacao, (float) $d->valor, $d->descricao]);
        $this->assertSame(0, \App\Servicos\Folha::diariasAPagar()->count());

        // diária paga não sai; pagamento cancelado devolve as diárias para "a pagar"
        $adm->delete('/funcionarios/diarias/'.Diaria::first()->id)->assertSessionHasErrors('diaria');
        Despesas::desfazerPagamento($d, 'teste');
        Despesas::cancelar($d->fresh(), 'pagou errado');
        $this->assertSame(2, \App\Servicos\Folha::diariasAPagar()->count());
        $adm->delete('/funcionarios/diarias/'.Diaria::first()->id)->assertSessionHasNoErrors();
        $this->assertSame(1, Diaria::count());
        $adm->get('/funcionarios/diarias')->assertOk()->assertInertia(fn ($p) => $p->component('Funcionarios/Diarias')->has('abertas', 1));
    }

    public function test_funcionarios_sao_da_empresa_e_pedem_permissao(): void
    {
        Funcionario::create(['nome' => 'DA EMPRESA A', 'tipo' => 'registrado', 'matricula' => '5']);
        $b = $this->criarEmpresa('b');
        $this->actingAs($b->admin)->get('/funcionarios')->assertOk()->assertInertia(fn ($p) => $p->has('funcionarios', 0));
        $this->actingAs($this->a->admin)->post('/funcionarios', ['nome' => 'outro', 'tipo' => 'registrado', 'matricula' => '005'])->assertSessionHasErrors('matricula');
    }
}
