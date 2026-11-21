<?php

namespace Tests\Feature;

use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Política de privacidade pública: controlador identificado e canal de privacidade (Configurações › Empresa). */
class PrivacidadeTest extends TestCase
{
    use RefreshDatabase;

    public function test_politica_identifica_o_controlador_e_o_canal(): void
    {
        $a = $this->criarEmpresa('a');
        $a->empresa->update(['razao_social' => 'LOCAL ESTACIONAMENTO LTDA', 'documento' => '11222333000181', 'email' => 'contato@local.com.br', 'telefone' => '1332223333']);
        app(EmpresaAtual::class)->como($a->empresa->id, fn () => $a->unidade->update(['endereco' => 'Rua da Unidade, 1 - Santos/SP']));

        // sem endereço próprio: o da primeira unidade; sem canal próprio: só e-mail e telefone
        $this->get('/privacidade')->assertOk()->assertInertia(fn ($pg) => $pg->component('Privacidade')
            ->where('empresa_publica.razao_social', 'LOCAL ESTACIONAMENTO LTDA')->where('empresa_publica.cnpj', '11.222.333/0001-81')
            ->where('empresa_publica.endereco', 'Rua da Unidade, 1 - Santos/SP')->where('empresa_publica.contato_privacidade', null));

        app(EmpresaAtual::class)->definir($a->empresa->id);
        $this->actingAs($a->admin)->put('/empresa/privacidade', ['endereco' => ' Av. Ana Costa, 100 - Santos/SP ', 'contato' => 'privacidade@local.com.br'])
            ->assertSessionHas('sucesso');
        $this->actingAs($a->admin)->get('/empresa')->assertInertia(fn ($pg) => $pg->where('privacidade.contato', 'privacidade@local.com.br'));
        $this->actingAs($a->operador)->put('/empresa/privacidade', ['contato' => 'x'])->assertForbidden();

        auth()->logout();
        $this->get('/privacidade')->assertInertia(fn ($pg) => $pg->where('empresa_publica.endereco', 'Av. Ana Costa, 100 - Santos/SP')
            ->where('empresa_publica.contato_privacidade', 'privacidade@local.com.br'));
    }
}
