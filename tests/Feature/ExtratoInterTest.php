<?php

namespace Tests\Feature;

use App\Models\Boleto;
use App\Models\Despesa;
use App\Models\Estorno;
use App\Models\ExtratoLancamento;
use App\Models\Integracao;
use App\Servicos\Cobrancas;
use App\Servicos\FormasPagamento;
use App\Servicos\Inter\ExtratoInter;
use App\Servicos\Ordens;
use App\Servicos\PlanoContas;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExtratoInterTest extends TestCase
{
    use RefreshDatabase;

    private object $a;

    private object $x;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-14 10:00');
        Cache::flush();
        $this->a = $this->criarEmpresa('a');
        $this->x = $this->criarCliente($this->a);
        app(EmpresaAtual::class)->definir($this->a->empresa->id);
        Auth::login($this->a->admin);
        FormasPagamento::garantirPadrao();
        PlanoContas::garantirPadrao();
        Integracao::create(['tipo' => 'inter', 'ativo' => true, 'credenciais' => ['client_id' => 'id', 'client_secret' => 'segredo',
            'certificado' => "-----BEGIN CERTIFICATE-----\nX\n-----END CERTIFICATE-----", 'chave' => "-----BEGIN PRIVATE KEY-----\nY\n-----END PRIVATE KEY-----"]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** transações como o extrato completo do Inter devolve */
    private function transacoes(): array
    {
        return [
            ['idTransacao' => 'T1', 'dataEntrada' => '2026-11-12', 'tipoTransacao' => 'PIX', 'tipoOperacao' => 'C', 'valor' => '250.00', 'titulo' => 'Pix recebido',
                'descricao' => 'PIX RECEBIDO', 'detalhes' => ['nomePagador' => 'MARIA DA SILVA', 'cpfCnpjPagador' => '***.068.258-**', 'txId' => 'outro']],
            ['idTransacao' => 'T2', 'dataEntrada' => '2026-11-12', 'tipoTransacao' => 'BOLETO_COBRANCA', 'tipoOperacao' => 'C', 'valor' => '200.00', 'titulo' => 'Boleto recebido', 'detalhes' => []],
            ['idTransacao' => 'T3', 'dataEntrada' => '2026-11-13', 'tipoTransacao' => 'PIX', 'tipoOperacao' => 'C', 'valor' => '180.00', 'titulo' => 'Pix recebido',
                'detalhes' => ['nomePagador' => 'JOAO', 'txId' => 'TXBOLETO']],
            ['idTransacao' => 'T4', 'dataEntrada' => '2026-11-13', 'tipoTransacao' => 'PAGAMENTO', 'tipoOperacao' => 'D', 'valor' => '89.90', 'titulo' => 'Pagamento de conta', 'detalhes' => ['nomeDestinatario' => 'CPFL']],
            ['idTransacao' => 'T5', 'dataEntrada' => '2026-11-13', 'tipoTransacao' => 'PIX', 'tipoOperacao' => 'D', 'valor' => '60.00', 'titulo' => 'Pix enviado', 'detalhes' => ['nomeRecebedor' => 'CLIENTE']],
            ['idTransacao' => 'T6', 'dataEntrada' => '2026-11-13', 'tipoTransacao' => 'PIX', 'tipoOperacao' => 'D', 'valor' => '15.00', 'titulo' => 'Pix enviado', 'detalhes' => ['nomeRecebedor' => 'PADARIA']],
        ];
    }

    private function preparar(): void
    {
        // boleto pago pelo Pix (txid conhecido), conta paga de 89,90 e estorno de 60
        Ordens::lancar(['tipo' => 'mensalidade', 'cliente_id' => $this->x->cliente->id, 'valor' => 180, 'descricao' => 'Mensalidade', 'data' => '2026-11-01', 'vencimento' => '2026-11-20', 'competencia' => '2026-11']);
        $cob = Cobrancas::emitir($this->x->cliente, '2026-11', '2026-11-20');
        Boleto::create(['cobranca_id' => $cob->id, 'cliente_id' => $this->x->cliente->id, 'seu_numero' => '1-1', 'codigo_solicitacao' => 'abc', 'txid' => 'TXBOLETO',
            'valor' => 180, 'vencimento' => '2026-11-20', 'situacao' => 'recebido']);
        Despesa::create(['conta_id' => PlanoContas::lancaveis()->first()['id'], 'descricao' => 'Energia', 'competencia' => '2026-11-01', 'vencimento' => '2026-11-12',
            'valor' => 89.90, 'situacao' => 'paga', 'pago_em' => '2026-11-12', 'valor_pago' => 89.90]);
        $outro = $this->criarCliente($this->a, 'Outro Cliente', 'BBB2B22')->cliente;
        Ordens::lancarNaConta($outro, 'credito', 60, 'Crédito', '2026-11-10', 'x');
        \App\Servicos\Estornos::estornar($outro, ['valor' => 60, 'estornado_em' => '2026-11-13', 'motivo' => 'Boleto pago depois do cancelamento']);
    }

    public function test_monta_o_lote_sem_boletos_e_concilia_as_saidas(): void
    {
        $this->preparar();
        $r = ExtratoInter::buscar('2026-11-07', '2026-11-14', $this->transacoes());

        $this->assertCount(1, $r['entradas']); // só o Pix da Maria; os dois do boleto ficam de fora
        $this->assertSame(2, $r['boletos']);
        $e = $r['entradas'][0];
        $this->assertSame(['T1', '2026-11-12', 250.0, 'MARIA DA SILVA', '068258'], [$e['id'], $e['data'], $e['valor'], $e['nome'], $e['documento']]);

        $this->assertSame(3, $r['saidas']);
        $s = collect($r['lista_saidas'])->keyBy('id');
        $this->assertSame('despesa', $s['T4']['registro']['tipo']);
        $this->assertSame('estorno', $s['T5']['registro']['tipo']);
        $this->assertNull($s['T6']['registro']);
    }

    public function test_busca_pela_api_e_abre_a_conferencia(): void
    {
        $this->preparar();
        $pedidos = [];
        Http::fake(function (Request $req) use (&$pedidos) {
            $pedidos[] = $req;
            $url = $req->url();
            if (str_ends_with($url, '/oauth/v2/token')) {
                return Http::response(['access_token' => 'tok-'.$req['scope'], 'expires_in' => 3600]);
            }
            if (str_contains($url, '/banking/v2/extrato/completo')) {
                return Http::response(['transacoes' => $this->transacoes(), 'totalPaginas' => 1, 'ultimaPagina' => true]);
            }
            if (str_contains($url, '/banking/v2/saldo')) {
                return Http::response(['disponivel' => 1234.56]);
            }

            return Http::response([], 500);
        });

        $r = $this->actingAs($this->a->admin)->post('/extrato/inter', ['de' => '2026-11-07', 'ate' => '2026-11-14'])->assertRedirect();
        $this->actingAs($this->a->admin)->get($r->headers->get('Location'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Extrato/Conferir')->has('entradas', 1)->where('boletos', 2)->has('lista_saidas', 3)
                ->where('entradas.0.nome', 'MARIA DA SILVA'));
        // o token do extrato é pedido com a permissão de extrato, separado do boleto
        $this->assertTrue(collect($pedidos)->contains(fn ($q) => str_ends_with($q->url(), '/oauth/v2/token') && $q['scope'] === 'extrato.read'));
        $this->assertTrue(collect($pedidos)->contains(fn ($q) => str_contains($q->url(), '/extrato/completo') && $q->header('Authorization')[0] === 'Bearer tok-extrato.read'));
        $this->assertSame(1234.56, ExtratoInter::saldo());

        // futuro ou período grande: recusa
        $this->actingAs($this->a->admin)->post('/extrato/inter', ['de' => '2026-11-07', 'ate' => '2026-11-20'])->assertSessionHasErrors('ate');
        $this->actingAs($this->a->admin)->post('/extrato/inter', ['de' => '2026-07-01', 'ate' => '2026-11-14'])->assertSessionHasErrors('de');
    }

    public function test_pix_ja_baixado_pelo_csv_aparece_como_processado(): void
    {
        ExtratoLancamento::create(['identificador' => 'csv-123', 'data' => '2026-11-12', 'valor' => 250, 'nome' => 'MARIA DA SILVA', 'documento' => '068258',
            'cliente_id' => $this->x->cliente->id, 'processado_em' => now()]);
        $r = ExtratoInter::buscar('2026-11-07', '2026-11-14', $this->transacoes());
        $this->assertArrayHasKey('T1', ExtratoInter::parecidos($r['entradas']));
    }
}
