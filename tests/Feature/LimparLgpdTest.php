<?php

namespace Tests\Feature;

use App\Models\CadastroPendente;
use App\Models\Cliente;
use App\Models\FormaPagamento;
use App\Servicos\Baixa;
use App\Servicos\FormasPagamento;
use App\Servicos\Ordens;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** LGPD: prazos de guarda (fila de espera 120 dias; ex-cliente sem movimento há 5 anos). */
class LimparLgpdTest extends TestCase
{
    use RefreshDatabase;

    private object $a;

    private int $cpf = 10000000001;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->criarEmpresa('a');
        app(EmpresaAtual::class)->definir($this->a->empresa->id);
        FormasPagamento::garantirPadrao();
        config(['erp.espelho_ativo' => true]);
    }

    private function pendente(int $dias, ?int $legado = null): CadastroPendente
    {
        return CadastroPendente::create(['recebido_em' => now()->subDays($dias), 'consentimento_em' => now()->subDays($dias), 'nome' => 'FULANO '.$dias, 'legado_id' => $legado]);
    }

    /** cliente inativo com um serviço pago (ou não) em uma data; cadastro "mexido" na mesma época */
    private function exCliente(string $nome, string $quando, bool $pago = true): Cliente
    {
        $c = Cliente::create(['nome' => $nome, 'documento' => $this->cpf++, 'celular' => '13999990000', 'ativo' => false]);
        $o = Ordens::lancar(['tipo' => 'servico', 'cliente_id' => $c->id, 'descricao' => 'Lavagem', 'data' => substr($quando, 0, 10), 'vencimento' => substr($quando, 0, 10), 'valor' => 50]);
        if ($pago) {
            Baixa::receberNaHora([$o], FormaPagamento::where('tipo', 'pix')->value('id'));
            DB::table('pagamentos')->where('cliente_id', $c->id)->update(['recebido_em' => $quando]);
        }
        DB::table('clientes')->where('id', $c->id)->update(['criado_em' => $quando, 'atualizado_em' => $quando]);

        return $c->fresh();
    }

    public function test_aplica_os_prazos(): void
    {
        $velho = $this->pendente(121);
        $novo = $this->pendente(100);
        $doLegado = $this->pendente(200, 55);
        $antigo = $this->exCliente('ANTIGO DA SILVA', now()->subYears(6)->toDateTimeString());
        $recente = $this->exCliente('RECENTE DA SILVA', now()->subYears(2)->toDateTimeString());
        $devedor = $this->exCliente('DEVEDOR DA SILVA', now()->subYears(7)->toDateTimeString(), false);
        $ativo = Cliente::create(['nome' => 'ATIVO DA SILVA']);
        DB::table('clientes')->where('id', $ativo->id)->update(['atualizado_em' => now()->subYears(9), 'criado_em' => now()->subYears(9)]);

        // simulação não muda nada
        Artisan::call('lgpd:limpar', ['--simular' => true]);
        $this->assertStringContainsString('fila de espera: 1 apagado(s); ex-clientes: 1 anonimizado(s), 1 com bloqueio', Artisan::output());
        $this->assertNotNull(CadastroPendente::find($velho->id));
        $this->assertNull($antigo->fresh()->anonimizado_em);

        $this->assertSame(0, Artisan::call('lgpd:limpar'));
        $this->assertNull(CadastroPendente::find($velho->id));
        $this->assertNotNull(CadastroPendente::find($novo->id));
        $this->assertNotNull(CadastroPendente::find($doLegado->id));       // espelho ligado: volta pela importação

        $this->assertNotNull($antigo->fresh()->anonimizado_em);
        $this->assertNull($antigo->fresh()->documento);
        $this->assertNull($recente->fresh()->anonimizado_em);
        $this->assertNull($devedor->fresh()->anonimizado_em);              // conta em aberto: a lei deixa guardar
        $this->assertNull($ativo->fresh()->anonimizado_em);
        $this->assertDatabaseHas('log_atividades', ['acao' => 'lgpd_limpeza']);

        // depois da virada, o do sistema atual também sai
        config(['erp.espelho_ativo' => false]);
        Artisan::call('lgpd:limpar');
        $this->assertNull(CadastroPendente::find($doLegado->id));
    }
}
