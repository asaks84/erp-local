<?php

namespace Database\Seeders;

use App\Models\Empresa;
use App\Models\Nivel;
use App\Models\Unidade;
use App\Models\Usuario;
use App\Support\EmpresaAtual;
use Illuminate\Database\Seeder;

/**
 * Dados de exemplo para desenvolvimento (php artisan migrate --seed).
 * Para o banco de verdade use: php artisan legado:importar (ou erp:instalar num banco novo).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $empresa = Empresa::create(['nome' => 'Estacionamento Exemplo']);
        app(EmpresaAtual::class)->como($empresa->id, function () {
            Unidade::create(['nome' => 'Principal']);
            $admin = Nivel::create(['nome' => 'Administrador', 'fixo' => true, 'permissoes' => []]);
            Nivel::create(['nome' => 'Gerente', 'permissoes' => ['contas_editar', 'desconto', 'aprovar', 'contas_aberto', 'rel_mensalistas']]);
            Nivel::create(['nome' => 'Operador', 'permissoes' => []]);
            $u = new Usuario(['nome' => 'Administrador Exemplo', 'login' => 'admin', 'nivel_id' => $admin->id]);
            $u->definirSenha('trocar123');
            $u->save();
        });
    }
}
