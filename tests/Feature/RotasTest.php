<?php

namespace Tests\Feature;

use App\Http\Middleware\PermissaoDeclarada;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Bloqueio por padrão: toda rota precisa declarar a permissão (ou ser pública). */
class RotasTest extends TestCase
{
    public function test_toda_rota_declara_permissao(): void
    {
        $semPermissao = [];
        foreach (Route::getRoutes() as $rota) {
            if (str_starts_with($rota->uri(), '_') || str_starts_with($rota->uri(), 'storage/')) {
                continue; // rotas internas do framework
            }
            if (! PermissaoDeclarada::declarou($rota->gatherMiddleware())) {
                $semPermissao[] = implode('|', $rota->methods()).' '.$rota->uri();
            }
        }
        $this->assertSame([], $semPermissao, 'Rotas sem permissão declarada');
    }

    public function test_rota_sem_permissao_e_bloqueada(): void
    {
        Route::middleware('web')->get('/rota-esquecida', fn () => 'aberta');
        $this->get('/rota-esquecida')->assertForbidden();
    }

    public function test_permissoes_usadas_nas_rotas_existem_no_catalogo(): void
    {
        $catalogo = array_merge(['livre'], \App\Models\Nivel::catalogoChaves());
        foreach (Route::getRoutes() as $rota) {
            foreach ($rota->gatherMiddleware() as $m) {
                if (is_string($m) && str_starts_with($m, 'permissao:')) {
                    foreach (explode(',', substr($m, 10)) as $chave) {
                        $this->assertContains($chave, $catalogo, "Permissão desconhecida na rota {$rota->uri()}");
                    }
                }
            }
        }
    }
}
