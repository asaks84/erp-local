<?php

namespace Tests\Feature;

use App\Servicos\Backup\Cofre;
use App\Servicos\Backup\GoogleDrive;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Backup no Google Drive: dados criptografados, conexão OAuth, envio criptografado e limpeza dos antigos. */
class BackupDriveTest extends TestCase
{
    use RefreshDatabase;

    private object $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->criarEmpresa('a');
        app(EmpresaAtual::class)->definir($this->a->empresa->id);
        config(['erp.legado_conexao' => 'nao_existe']);   // só o banco do ERP
        Storage::fake('local');
    }

    private function salvar(): void
    {
        $this->actingAs($this->a->admin)->post('/configuracoes/backup/google', [
            'client_id' => '123-abc.apps.googleusercontent.com', 'client_secret' => 'segredo-do-cliente',
            'senha' => 'frase longa do backup', 'senha_confirmation' => 'frase longa do backup', 'dias' => 30,
        ])->assertSessionHas('sucesso');
    }

    public function test_dados_conexao_e_tela(): void
    {
        $adm = $this->actingAs($this->a->admin);
        $adm->post('/configuracoes/backup/google', ['client_id' => 'x', 'senha' => 'curta', 'senha_confirmation' => 'curta', 'dias' => 30])
            ->assertSessionHasErrors(['client_id', 'client_secret', 'senha']);
        $this->salvar();
        $i = GoogleDrive::integracao();
        $this->assertSame('frase longa do backup', $i->credencial('senha'));
        $this->assertStringNotContainsString('segredo-do-cliente', (string) $i->getRawOriginal('credenciais'));   // criptografado

        // conectar: vai para o Google com o estado guardado na sessão
        $r = $adm->post('/configuracoes/backup/google/conectar', [], ['X-Inertia' => 'true']);
        $r->assertStatus(409);
        $destino = $r->headers->get('X-Inertia-Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $destino);
        $this->assertStringContainsString('drive.file', urldecode($destino));
        parse_str(parse_url($destino, PHP_URL_QUERY), $q);

        $adm->get('/configuracoes/backup/google/retorno?state=errado&code=x')->assertSessionHas('erro');

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok', 'refresh_token' => 'ref']),
            'www.googleapis.com/drive/v3/files?fields=id' => Http::response(['id' => 'pasta1']),
            'www.googleapis.com/drive/v3/about*' => Http::response(['user' => ['emailAddress' => 'dono@gmail.com']]),
        ]);
        $adm->post('/configuracoes/backup/google/conectar', [], ['X-Inertia' => 'true']);
        $estado = session('backup_google_estado');
        $adm->get('/configuracoes/backup/google/retorno?code=abc&state='.$estado)->assertRedirect('/configuracoes/backup')->assertSessionHas('sucesso');
        $i->refresh();
        $this->assertTrue($i->ativo);
        $this->assertSame('ref', $i->credencial('refresh_token'));
        $this->assertSame('pasta1', $i->credencial('pasta'));
        $adm->get('/configuracoes/backup')->assertInertia(fn ($pg) => $pg->component('Configuracoes/Backup')->where('drive.ultimo_envio', null)->where('drive.conectado', true)->where('drive.conta', 'dono@gmail.com'));
        $this->actingAs($this->a->operador)->get('/configuracoes/backup')->assertForbidden();
    }

    public function test_backup_vai_criptografado_e_limpa_os_antigos(): void
    {
        $this->salvar();
        GoogleDrive::integracao()->forceFill(['ativo' => true, 'credenciais' => GoogleDrive::integracao()->credenciais + ['refresh_token' => 'ref', 'pasta' => 'pasta1']])->save();
        $enviado = null;
        Http::fake(function (Request $r) use (&$enviado) {
            $u = $r->url();

            return match (true) {
                str_contains($u, 'oauth2.googleapis.com/token') => Http::response(['access_token' => 'tok']),
                str_contains($u, 'drive/v3/files/pasta1') => Http::response(['id' => 'pasta1', 'trashed' => false]),
                str_contains($u, 'uploadType=resumable') => Http::response([], 200, ['Location' => 'https://www.googleapis.com/upload/sessao1']),
                str_contains($u, 'upload/sessao1') => (function () use ($r, &$enviado) {
                    $enviado = $r->body();

                    return Http::response(['id' => 'arq1']);
                })(),
                $r->method() === 'GET' && str_contains($u, 'drive/v3/files?') => Http::response(['files' => [
                    ['id' => 'n1', 'name' => 'a', 'createdTime' => now()->toIso8601String()],
                    ['id' => 'n2', 'name' => 'b', 'createdTime' => now()->subDays(40)->toIso8601String()],
                    ['id' => 'n3', 'name' => 'c', 'createdTime' => now()->subDays(41)->toIso8601String()],
                    ['id' => 'velho', 'name' => 'd', 'createdTime' => now()->subDays(60)->toIso8601String()],
                ]]),
                $r->method() === 'DELETE' => Http::response(null, 204),
                default => Http::response(['error' => ['message' => 'inesperado '.$u]], 500),
            };
        });

        $this->assertSame(0, Artisan::call('backup:banco'));
        $this->assertStringStartsWith('Salted__', (string) $enviado);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/files/velho'));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/files/n3'));   // os 3 mais novos ficam
        $i = GoogleDrive::integracao();
        $this->assertNotNull($i->testado_em);
        $this->assertNull($i->ultimo_erro);
        $this->assertSame(1, $i->credenciais['ultimo_envio']['apagados'] ?? null);
        $this->assertCount(0, glob(Storage::disk('local')->path('backups').'/*.enc'));   // o temporário some
        $this->actingAs($this->a->admin)->get('/configuracoes/backup')->assertInertia(fn ($pg) => $pg->has('drive.ultimo_envio.arquivos', 1)->has('locais', 1));

        // o que foi enviado abre com a senha e é o mesmo backup
        $cifrado = tempnam(sys_get_temp_dir(), 'bk');
        file_put_contents($cifrado, $enviado);
        Cofre::abrir($cifrado, $cifrado.'.gz', 'frase longa do backup');
        $local = Storage::disk('local')->files('backups')[0];
        $this->assertSame(Storage::disk('local')->get($local), file_get_contents($cifrado.'.gz'));
        @unlink($cifrado);
        @unlink($cifrado.'.gz');
    }

    public function test_falha_no_drive_fica_registrada_e_backup_local_fica(): void
    {
        $this->salvar();
        GoogleDrive::integracao()->forceFill(['ativo' => true, 'credenciais' => GoogleDrive::integracao()->credenciais + ['refresh_token' => 'ref']])->save();
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        $this->assertSame(1, Artisan::call('backup:banco'));
        $this->assertStringContainsString('invalid_grant', GoogleDrive::integracao()->ultimo_erro);
        $this->assertCount(1, Storage::disk('local')->files('backups'));
    }
}
