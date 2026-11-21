<?php

namespace Tests\Feature;

use App\Models\Incidente;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** LGPD: registro de incidentes, prazo de 6 dias úteis (pequeno porte) e encerramento com as exigências. */
class IncidentesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_prazo_em_dias_uteis_pula_fim_de_semana(): void
    {
        // sexta 20/11/2026 + 6 dias úteis = segunda 30/11
        $this->assertSame('2026-11-30', Incidente::somarDiasUteis(Carbon::parse('2026-11-20 18:00'), 6)->format('Y-m-d'));
        $this->assertSame('2026-11-24', Incidente::somarDiasUteis(Carbon::parse('2026-11-21 09:00'), 2)->format('Y-m-d'));
    }

    public function test_registrar_avaliar_comunicar_e_encerrar(): void
    {
        Carbon::setTestNow('2026-11-20 15:00');
        $a = $this->criarEmpresa('a');
        app(EmpresaAtual::class)->definir($a->empresa->id);
        $adm = $this->actingAs($a->admin);

        $adm->post('/incidentes', ['titulo' => 'Celular do balcão roubado', 'descoberto_em' => '2026-11-20 10:00', 'descricao' => 'Levaram o celular do caixa.'])
            ->assertSessionHas('sucesso');
        $inc = Incidente::first();
        $this->assertSame(1, $inc->numero);
        $this->assertSame('avaliar', $inc->etapa());
        $adm->get('/incidentes')->assertInertia(fn ($pg) => $pg->component('Sistema/Incidentes')->has('incidentes', 1)
            ->where('incidentes.0.prazo', '2026-11-30')->where('incidentes.0.atrasado', false)->where('dias_uteis', 6));

        // futuro não vale; encerrar sem decidir o risco não vale
        $adm->post('/incidentes', ['titulo' => 'X', 'descoberto_em' => '2026-11-25 10:00', 'descricao' => 'x'])->assertSessionHasErrors('descoberto_em');
        $adm->post("/incidentes/{$inc->id}/encerrar")->assertSessionHasErrors('incidente');

        // relevante: só encerra depois de comunicar ANPD e clientes
        $dados = ['titulo' => 'Celular do balcão roubado', 'descoberto_em' => '2026-11-20 10:00', 'descricao' => 'Levaram o celular do caixa.',
            'dados_afetados' => 'nome e placa dos mensalistas', 'titulares' => 80, 'contencao' => 'Aparelho desativado às 10:15.',
            'risco' => 'relevante', 'justificativa' => 'Lista de mensalistas exposta.'];
        $adm->put("/incidentes/{$inc->id}", $dados)->assertSessionHas('sucesso');
        $this->assertSame('comunicar', $inc->fresh()->etapa());
        $adm->post("/incidentes/{$inc->id}/encerrar")->assertSessionHasErrors('incidente');

        // prazo vencido aparece
        Carbon::setTestNow('2026-12-01 09:00');
        $adm->get('/incidentes')->assertInertia(fn ($pg) => $pg->where('incidentes.0.atrasado', true));

        $adm->put("/incidentes/{$inc->id}", $dados + ['comunicado_anpd_em' => '2026-11-27', 'protocolo_anpd' => 'ANPD-123', 'comunicado_titulares_em' => '2026-11-27']);
        $this->assertSame('pronto', $inc->fresh()->etapa());
        $adm->post("/incidentes/{$inc->id}/encerrar")->assertSessionHas('sucesso');
        $this->assertNotNull($inc->fresh()->encerrado_em);

        // encerrado não muda; operador não acessa
        $adm->put("/incidentes/{$inc->id}", $dados)->assertForbidden();
        $this->actingAs($a->operador)->get('/incidentes')->assertForbidden();
        $this->assertDatabaseHas('log_atividades', ['acao' => 'incidente_encerrado']);
    }
}
