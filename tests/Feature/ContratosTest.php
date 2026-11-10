<?php

namespace Tests\Feature;

use App\Models\MensalidadeContrato;
use App\Models\MensalidadePausa;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Mensalidade: reajuste por nova versão, encerramento agendado e pausa com taxa. */
class ContratosTest extends TestCase
{
    use RefreshDatabase;

    private function contrato(object $a, object $x, string $inicio = '2026-01-01'): MensalidadeContrato
    {
        return app(EmpresaAtual::class)->como($a->empresa->id, fn () => MensalidadeContrato::create([
            'unidade_id' => $a->unidade->id, 'cliente_id' => $x->cliente->id, 'veiculo_id' => $x->veiculo->id,
            'periodo' => 'Diurno', 'valor' => 200, 'inicio' => $inicio,
        ]));
    }

    public function test_reajuste_cria_nova_versao(): void
    {
        $a = $this->criarEmpresa('a');
        $x = $this->criarCliente($a);
        $c = $this->contrato($a, $x);
        $this->actingAs($a->admin)->post("/mensalidades/{$c->id}/reajustar", ['valor' => 230, 'a_partir' => '2027-01-01'])->assertSessionHasNoErrors();

        app(EmpresaAtual::class)->definir($a->empresa->id);
        $this->assertSame('2026-12-31', $c->fresh()->fim->toDateString());
        $novo = MensalidadeContrato::where('anterior_id', $c->id)->firstOrFail();
        $this->assertSame('230.00', $novo->valor);
        $this->assertSame('2027-01-01', $novo->inicio->toDateString());
        $this->assertNull($novo->fim);
        // no dia de hoje ainda vale o antigo; em 2027, o novo
        $this->assertSame($c->id, MensalidadeContrato::vigentes(now())->value('id'));
        $this->assertSame($novo->id, MensalidadeContrato::vigentes(\Carbon\Carbon::parse('2027-02-10'))->value('id'));
    }

    public function test_reajuste_exige_permissao(): void
    {
        $a = $this->criarEmpresa('a');
        $x = $this->criarCliente($a);
        $c = $this->contrato($a, $x);
        $this->actingAs($a->operador)->post("/mensalidades/{$c->id}/reajustar", ['valor' => 1, 'a_partir' => '2027-01-01'])->assertForbidden();
    }

    public function test_um_contrato_ativo_por_veiculo(): void
    {
        $a = $this->criarEmpresa('a');
        $x = $this->criarCliente($a);
        $this->contrato($a, $x);
        $this->actingAs($a->operador)->post("/veiculos/{$x->veiculo->id}/mensalidade", [
            'periodo' => 'Noturno', 'valor' => 100, 'inicio' => '2026-11-01', 'unidade_id' => $a->unidade->id,
        ])->assertSessionHasErrors('valor');
    }

    public function test_encerramento_agendado_e_desfazer(): void
    {
        $a = $this->criarEmpresa('a');
        $x = $this->criarCliente($a);
        $c = $this->contrato($a, $x);
        $data = now()->addMonths(2)->toDateString();
        $this->actingAs($a->operador)->post("/mensalidades/{$c->id}/encerrar", ['data' => $data, 'motivo' => 'Vai mudar de cidade'])->assertSessionHasNoErrors();
        $this->assertSame($data, $c->fresh()->fim->toDateString());
        $this->actingAs($a->operador)->post("/mensalidades/{$c->id}/desfazer-encerramento")->assertSessionHasNoErrors();
        $this->assertNull($c->fresh()->fim);
    }

    public function test_pausa_com_taxa_e_sem_sobrepor(): void
    {
        $a = $this->criarEmpresa('a');
        $x = $this->criarCliente($a);
        $c = $this->contrato($a, $x);
        $de = now()->addMonth()->format('Y-m');
        $ate = now()->addMonths(2)->format('Y-m');
        $this->actingAs($a->operador)->post("/mensalidades/{$c->id}/pausar", ['de' => $de, 'ate' => $ate, 'taxa_tipo' => 'pct', 'taxa_valor' => 30])->assertSessionHasNoErrors();
        app(EmpresaAtual::class)->definir($a->empresa->id);
        $p = MensalidadePausa::firstOrFail();
        $this->assertSame($de.'-01', $p->inicio->toDateString());
        $this->assertSame(60.0, $p->taxaSobre(200));
        $this->assertNotNull($c->fresh()->load('pausas')->pausaEm(now()->addMonth()));

        $this->actingAs($a->operador)->post("/mensalidades/{$c->id}/pausar", ['de' => $ate, 'ate' => $ate, 'taxa_tipo' => 'valor', 'taxa_valor' => 0])
            ->assertSessionHasErrors('de');
        $this->actingAs($a->operador)->delete("/pausas/{$p->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, MensalidadePausa::count());
    }
}
