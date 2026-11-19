<?php

namespace Tests\Feature;

use App\Models\Aparelho;
use App\Models\Empresa;
use App\Servicos\Pdv\Aparelhos;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** 8.8: editor de impressão do PDV (modelos por papel e logo) */
class ImpressaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_salva_modelo_valida_e_chega_ao_pdv(): void
    {
        $a = $this->criarEmpresa('a');
        app(EmpresaAtual::class)->definir($a->empresa->id);
        $this->actingAs($a->admin)->get('/configuracoes/impressao')->assertOk();

        $ticket = [
            ['k' => 'nome', 'on' => true, 'al' => 'c', 'neg' => true],
            ['k' => 'espaco', 'alt' => 9],
            ['k' => 'numero', 'gra' => true, 'al' => 'x'],
            ['k' => 'texto', 'txt' => str_repeat('a', 300)],
            ['k' => 'texto', 'txt' => 'Outro'],
            ['k' => 'qr', 'tam' => 'G', 'extra' => 'ignorado'],
        ];
        $this->actingAs($a->admin)->put('/configuracoes/impressao', ['modelos' => ['ticket' => $ticket]])->assertRedirect()->assertSessionHasNoErrors();
        $salvo = Empresa::atual()->fresh()->config('impressao_modelos')['ticket'];
        $this->assertSame(6, $salvo[1]['alt']);
        $this->assertSame('e', $salvo[2]['al']);
        $this->assertSame(200, mb_strlen($salvo[3]['txt']));
        // o MySQL reordena as chaves do JSON: compara sem depender da ordem
        $qr = $salvo[5];
        ksort($qr);
        $this->assertSame(['al' => 'e', 'gra' => false, 'k' => 'qr', 'neg' => false, 'on' => true, 'tam' => 'G'], $qr);

        // chave de outro papel, dado repetido e papel desconhecido: recusados
        $this->actingAs($a->admin)->put('/configuracoes/impressao', ['modelos' => ['ticket' => [['k' => 'por_forma']]]])->assertSessionHasErrors('modelos');
        $this->actingAs($a->admin)->put('/configuracoes/impressao', ['modelos' => ['ticket' => [['k' => 'placa'], ['k' => 'placa']]]])->assertSessionHasErrors('modelos');
        $this->actingAs($a->admin)->put('/configuracoes/impressao', ['modelos' => ['xpto' => [['k' => 'nome']]]])->assertSessionHasErrors('modelos');

        // voltar ao padrão (null) tira o papel
        $this->actingAs($a->admin)->put('/configuracoes/impressao', ['modelos' => ['ticket' => null, 'recibo' => [['k' => 'titulo']]]])->assertSessionHasNoErrors();
        $this->assertSame(['recibo'], array_keys(Empresa::atual()->fresh()->config('impressao_modelos')));

        // o PDV recebe os modelos e o logo junto com os dados do aparelho
        $this->actingAs($a->admin)->post('/configuracoes/impressao/logo', ['logo' => UploadedFile::fake()->image('logo.png', 800, 300)])->assertSessionHasNoErrors();
        $logo = Empresa::atual()->fresh()->config('impressao_logo');
        $this->assertStringStartsWith('data:image/png;base64,', $logo);
        $img = imagecreatefromstring(base64_decode(substr($logo, 22)));
        $this->assertSame(384, imagesx($img));
        $this->assertSame(144, imagesy($img));

        $ap = Aparelhos::novoCodigo(Aparelho::create(['nome' => 'Balcão']));
        $token = $this->postJson('/api/pdv/parear', ['codigo' => $ap])->assertOk()->json('token');
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/pdv/eu')
            ->assertJsonPath('impressao.modelos.recibo.0.k', 'titulo')->assertJsonPath('impressao.logo', $logo);

        $this->actingAs($a->admin)->delete('/configuracoes/impressao/logo')->assertSessionHasNoErrors();
        $this->assertNull(Empresa::atual()->fresh()->config('impressao_logo'));
    }

    public function test_operador_sem_permissao_nao_edita(): void
    {
        $a = $this->criarEmpresa('a');
        app(EmpresaAtual::class)->definir($a->empresa->id);
        $this->actingAs($a->operador)->get('/configuracoes/impressao')->assertForbidden();
        $this->actingAs($a->operador)->put('/configuracoes/impressao', ['modelos' => ['ticket' => [['k' => 'nome']]]])->assertForbidden();
    }
}
