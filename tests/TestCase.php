<?php

namespace Tests;

use App\Models\Empresa;
use App\Models\Nivel;
use App\Models\Unidade;
use App\Models\Usuario;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite(); // os testes não precisam do npm run build
    }

    /**
     * Cria uma empresa com unidade, níveis (Administrador, Gerente, Operador) e um usuário
     * de cada nível. Logins: <prefixo>-admin, <prefixo>-gerente, <prefixo>-operador; senha "segredo1".
     */
    protected function criarEmpresa(string $prefixo = 'a'): object
    {
        $empresa = Empresa::create(['nome' => "Empresa {$prefixo}"]);

        return app(EmpresaAtual::class)->como($empresa->id, function () use ($empresa, $prefixo) {
            $unidade = Unidade::create(['nome' => 'Principal']);
            $niveis = [
                'admin' => Nivel::create(['nome' => 'Administrador', 'fixo' => true, 'permissoes' => []]),
                'gerente' => Nivel::create(['nome' => 'Gerente', 'permissoes' => ['configuracoes', 'desconto']]),
                'operador' => Nivel::create(['nome' => 'Operador', 'permissoes' => []]),
            ];
            $usuarios = [];
            foreach ($niveis as $papel => $nivel) {
                $u = new Usuario(['nome' => ucfirst($papel)." {$prefixo}", 'login' => "{$prefixo}-{$papel}", 'nivel_id' => $nivel->id]);
                $u->definirSenha('segredo1');
                $u->save();
                $usuarios[$papel] = $u;
            }

            return (object) (['empresa' => $empresa, 'unidade' => $unidade, 'niveis' => (object) $niveis] + $usuarios);
        });
    }
}
