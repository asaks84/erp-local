<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Models\Nivel;
use App\Models\Unidade;
use App\Models\Usuario;
use App\Support\EmpresaAtual;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cria a primeira empresa, a unidade principal, o nível de acesso total e o primeiro
 * administrador. Usado num banco novo, sem importar do sistema atual.
 */
class ErpInstalar extends Command
{
    protected $signature = 'erp:instalar
        {--empresa= : Nome da empresa}
        {--unidade= : Nome da primeira unidade}
        {--nome= : Nome do administrador}
        {--login= : Login do administrador}
        {--senha= : Senha do administrador (se vazio, é perguntada)}';

    protected $description = 'Cria a empresa, a primeira unidade e o primeiro administrador';

    public function handle(EmpresaAtual $empresaAtual): int
    {
        if (Empresa::exists()) {
            $this->error('Já existe empresa cadastrada. Este comando é só para um banco novo.');

            return self::FAILURE;
        }

        $nomeEmpresa = $this->option('empresa') ?: $this->ask('Nome da empresa');
        $nomeUnidade = $this->option('unidade') ?: $this->ask('Nome da primeira unidade', 'Principal');
        $nome = $this->option('nome') ?: $this->ask('Nome do administrador');
        $login = $this->option('login') ?: $this->ask('Login do administrador');
        $senha = $this->option('senha') ?: $this->secret('Senha do administrador (mínimo 6)');

        if (! $nomeEmpresa || ! $nome || ! $login || strlen((string) $senha) < 6) {
            $this->error('Preencha todos os dados (senha com no mínimo 6 caracteres).');

            return self::FAILURE;
        }

        DB::transaction(function () use ($empresaAtual, $nomeEmpresa, $nomeUnidade, $nome, $login, $senha) {
            $empresa = Empresa::create(['nome' => $nomeEmpresa]);
            $empresaAtual->como($empresa->id, function () use ($nomeUnidade, $nome, $login, $senha) {
                Unidade::create(['nome' => $nomeUnidade]);
                $total = Nivel::create(['nome' => 'Administrador', 'fixo' => true, 'permissoes' => []]);
                Nivel::create(['nome' => 'Operador', 'fixo' => false, 'permissoes' => []]);
                $usuario = new Usuario(['nome' => $nome, 'login' => $login, 'nivel_id' => $total->id]);
                $usuario->definirSenha($senha);
                $usuario->save();
            });
        });

        $this->info("Pronto. Entre com o login \"{$login}\".");

        return self::SUCCESS;
    }
}
