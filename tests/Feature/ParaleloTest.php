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
        Process::fake(function ($p) {
            // simula o mysqldump gravando o arquivo
            foreach ($p->command as $arg) {
                if (str_starts_with($arg, '--result-file=')) {
                    file_put_contents(substr($arg, 14), "-- dump\nCREATE TABLE x (id int);\n");
                }
            }

            return Process::result('');
        });
        $velho = 'backups/erp-2020-01-01-0310.sql.gz';
        Storage::disk('local')->put($velho, 'x');
        touch(Storage::disk('local')->path($velho), now()->subDays(30)->getTimestamp());

        $this->artisan('backup:banco')->assertSuccessful();
        $arquivos = Storage::disk('local')->files('backups');
        $this->assertCount(2, $arquivos);   // erp + sistema atual; o de 30 dias saiu
        $this->assertNotContains($velho, $arquivos);
        $this->assertStringContainsString('CREATE TABLE', gzdecode(Storage::disk('local')->get($arquivos[0])));
        // senha vai pela variável de ambiente, nunca nos argumentos
        Process::assertRan(fn ($p) => is_array($p->command) && $p->command[0] === 'mysqldump'
            && array_key_exists('MYSQL_PWD', $p->environment) && ! collect($p->command)->contains(fn ($a) => str_starts_with($a, '-p') || str_starts_with($a, '--password')));

        // falha do mysqldump: comando falha e não deixa arquivo vazio
        Process::fake(['*' => Process::result('', 'acesso negado', 2)]);
        $this->artisan('backup:banco')->assertFailed();
    }

    public function test_agendamento(): void
    {
        $eventos = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command);
        $this->assertTrue($eventos->contains(fn ($c) => str_contains($c, 'legado:espelhar')));
        $this->assertTrue($eventos->contains(fn ($c) => str_contains($c, 'backup:banco')));
    }
}
