<?php

namespace Tests\Feature;

use App\Models\Conferencia;
use App\Models\Empresa;
use App\Support\EmpresaAtual;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BancoLegado;
use Tests\TestCase;

/** Etapa 5: espelho do sistema atual com conferência, tela da conferência, backup e agendamento. */
class ParaleloTest extends TestCase
{
    use BancoLegado;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->criarBancoLegado();
        DB::connection('legado')->table('USUARIOS')->insert([
            'usr_id' => 1, 'usr_full_name' => 'CAUE', 'usr_nickname' => 'caue', 'usr_password' => password_hash('senha-boa', PASSWORD_BCRYPT), 'usr_admin' => 1,
        ]);
    }

    public function test_espelhar_importa_compara_e_registra(): void
    {
        $this->artisan('legado:espelhar');   // primeira vez: cria a empresa pelo nome configurado
        $this->assertSame('Local Estacionamento', Empresa::firstOrFail()->nome);
        $c = Conferencia::latest('id')->firstOrFail();
        $this->assertContains($c->situacao, ['ok', 'diferente']);
        $this->assertNotEmpty($c->linhas);
        $this->assertNotNull($c->duracao_seg);

        // banco do sistema atual fora do ar: registra erro, sem estourar
        DB::connection('legado')->statement('DROP TABLE USUARIOS');
        $this->artisan('legado:espelhar')->assertFailed();
        $erro = Conferencia::latest('id')->first();
        $this->assertSame('erro', $erro->situacao);
        $this->assertStringContainsString('USUARIOS', $erro->mensagem);
    }

    public function test_tela_da_conferencia_e_aviso_do_inicio(): void
    {
        $a = $this->criarEmpresa('a');
        app(EmpresaAtual::class)->definir($a->empresa->id);
        Conferencia::create(['executada_em' => now()->subDay(), 'situacao' => 'diferente', 'diferencas' => 1, 'linhas' => [['Ordens', 10, 9, 'DIFERENTE']]]);
        Conferencia::create(['executada_em' => now()->subHour(), 'situacao' => 'ok', 'linhas' => [['Ordens', 10, 10, 'ok']]]);
        Conferencia::create(['executada_em' => now(), 'situacao' => 'ok', 'linhas' => [['Ordens', 11, 11, 'ok']]]);

        $this->actingAs($a->admin)->get('/conferencia')->assertOk()->assertInertia(fn ($p) => $p->component('Sistema/Conferencia')
            ->where('ultima.situacao', 'ok')->has('historico', 3)->where('dias_ok', 1));
        $this->actingAs($a->operador)->get('/conferencia')->assertForbidden();
        $this->actingAs($a->admin)->get('/')->assertInertia(fn ($p) => $p->where('conferencia.situacao', 'ok'));
    }

    public function test_aviso_quando_a_importacao_para(): void
    {
        $a = $this->criarEmpresa('a');
        app(EmpresaAtual::class)->definir($a->empresa->id);
        config(['erp.espelho_ativo' => true]);
        \Carbon\Carbon::setTestNow('2026-11-12 17:00:00');
        $c = Conferencia::create(['executada_em' => '2026-11-12 00:43:00', 'situacao' => 'ok', 'linhas' => []]);
        $this->actingAs($a->admin)->get('/conferencia')->assertInertia(fn ($p) => $p->where('atrasada', true));
        $this->actingAs($a->admin)->get('/')->assertInertia(fn ($p) => $p->where('conferencia.atrasada', true));
        $c->update(['executada_em' => '2026-11-12 16:00:00']);
        $this->actingAs($a->admin)->get('/conferencia')->assertInertia(fn ($p) => $p->where('atrasada', false));
        \Carbon\Carbon::setTestNow('2026-11-13 07:30:00');   // antes das 9h ainda não avisa (a 1ª importação é às 7h)
        $this->actingAs($a->admin)->get('/conferencia')->assertInertia(fn ($p) => $p->where('atrasada', false));
    }

    public function test_backup_dos_bancos(): void
    {
        Storage::fake('local');
        $a = $this->criarEmpresa('a');
        $this->criarCliente($a);
        $velho = 'backups/erp-2020-01-01-0310.sql.gz';
        Storage::disk('local')->put($velho, 'x');
        touch(Storage::disk('local')->path($velho), now()->subDays(30)->getTimestamp());

        // em PHP puro (a hospedagem não deixa rodar mysqldump)
        $this->artisan('backup:banco')->assertSuccessful();
        $arquivos = collect(Storage::disk('local')->files('backups'));
        $this->assertCount(2, $arquivos);   // erp + sistema atual; o de 30 dias saiu
        $this->assertNotContains($velho, $arquivos);
        $sql = gzdecode(Storage::disk('local')->get($arquivos->first(fn ($f) => str_starts_with(basename($f), 'erp-'))));
        $this->assertStringContainsString('CREATE TABLE `empresas`', $sql);
        $this->assertStringContainsString("'Empresa a'", $sql);
        $this->assertMatchesRegularExpression('/INSERT INTO `veiculos` \(([^)]*)\) VALUES/', $sql);
        preg_match('/INSERT INTO `veiculos` \(([^)]*)\) VALUES/', $sql, $m);
        $this->assertStringNotContainsString('placa_ativa', $m[1]);          // coluna gerada fica fora do INSERT

        // banco inacessível: comando falha e não deixa arquivo
        config(['database.connections.legado.database' => 'banco_que_nao_existe']);
        \DB::purge('legado');
        $this->artisan('backup:banco')->assertFailed();
        $this->assertCount(0, collect(Storage::disk('local')->files('backups'))->filter(fn ($f) => str_contains($f, 'sistema-atual') && filesize(Storage::disk('local')->path($f)) === 0));
    }

    public function test_agendamento(): void
    {
        // tarefas rodam no mesmo processo (sem proc_open): identificadas pelo nome
        $eventos = collect(app(Schedule::class)->events())->map(fn ($e) => $e->description);
        $this->assertTrue($eventos->contains(fn ($c) => str_contains($c, 'legado:espelhar')));
        $this->assertTrue($eventos->contains(fn ($c) => str_contains($c, 'backup:banco')));
    }
}
