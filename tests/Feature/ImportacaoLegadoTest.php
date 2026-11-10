<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\LogAtividade;
use App\Models\Nivel;
use App\Models\Usuario;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Importação do sistema atual, com um banco de teste no formato dele (tabelas em MAIÚSCULAS). */
class ImportacaoLegadoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $db = DB::connection('legado');
        foreach (['USUARIOS', 'NIVEIS_ACESSO', 'LOG_ATIVIDADES', 'CLIENTES', 'VEICULOS', 'ORDEM_SERVICOS'] as $t) {
            $db->statement("DROP TABLE IF EXISTS `{$t}`");
        }
        $db->statement('CREATE TABLE USUARIOS (usr_id SMALLINT AUTO_INCREMENT PRIMARY KEY, usr_full_name VARCHAR(40), usr_nickname VARCHAR(25), usr_password VARCHAR(255), is_online CHAR(1) DEFAULT 0, usr_admin TINYINT(1) DEFAULT 0, usr_niv_id INT NULL)');
        $db->statement('CREATE TABLE NIVEIS_ACESSO (niv_id INT AUTO_INCREMENT PRIMARY KEY, niv_nome VARCHAR(40), niv_permissoes TEXT, niv_fixo TINYINT(1) DEFAULT 0)');
        $db->statement('CREATE TABLE LOG_ATIVIDADES (log_id INT AUTO_INCREMENT PRIMARY KEY, log_quando DATETIME, log_usuario VARCHAR(25), log_ip VARCHAR(45), log_acao VARCHAR(40), log_detalhe VARCHAR(255))');
        $db->statement('CREATE TABLE CLIENTES (cli_id SMALLINT PRIMARY KEY, cli_nome VARCHAR(75))');
        $db->statement('CREATE TABLE VEICULOS (vcl_id SMALLINT PRIMARY KEY, vcl_placa VARCHAR(11))');
        $db->statement('CREATE TABLE ORDEM_SERVICOS (ord_id INT PRIMARY KEY, ord_cli_id INT, ord_srv_codigo VARCHAR(5), ord_valor DECIMAL(6,2), ord_dt_pagamento DATE NULL)');

        $db->table('NIVEIS_ACESSO')->insert([
            ['niv_id' => 1, 'niv_nome' => 'Administrador', 'niv_permissoes' => '', 'niv_fixo' => 1],
            ['niv_id' => 2, 'niv_nome' => 'Gerente', 'niv_permissoes' => 'desconto,aprovar,servidor_antigo', 'niv_fixo' => 0],
            ['niv_id' => 3, 'niv_nome' => 'Operador', 'niv_permissoes' => '', 'niv_fixo' => 0],
        ]);
        $db->table('USUARIOS')->insert([
            ['usr_id' => 1, 'usr_full_name' => 'CAUE', 'usr_nickname' => 'caue', 'usr_password' => password_hash('senha-boa', PASSWORD_BCRYPT), 'usr_admin' => 1, 'usr_niv_id' => 1],
            ['usr_id' => 2, 'usr_full_name' => 'GERENTE', 'usr_nickname' => 'gerente', 'usr_password' => sha1('antiga'), 'usr_admin' => 0, 'usr_niv_id' => 2],
            ['usr_id' => 3, 'usr_full_name' => 'OPERADOR', 'usr_nickname' => 'operador', 'usr_password' => password_hash('op123', PASSWORD_BCRYPT), 'usr_admin' => 0, 'usr_niv_id' => null],
        ]);
        $db->table('LOG_ATIVIDADES')->insert([
            ['log_quando' => '2026-11-01 10:00:00', 'log_usuario' => 'caue', 'log_ip' => '1.1.1.1', 'log_acao' => 'login', 'log_detalhe' => null],
            ['log_quando' => '2026-11-01 10:05:00', 'log_usuario' => 'gerente', 'log_ip' => '1.1.1.2', 'log_acao' => 'desconto', 'log_detalhe' => 'R$ 10,00'],
        ]);
    }

    private function importar(): void
    {
        $this->artisan('legado:importar', ['--empresa' => 'Local Estacionamento'])->assertSuccessful();
    }

    public function test_importa_niveis_usuarios_e_atividades(): void
    {
        $this->importar();
        $empresa = Empresa::firstOrFail();
        $this->assertSame('Local Estacionamento', $empresa->nome);
        app(EmpresaAtual::class)->definir($empresa->id);

        $this->assertSame(3, Nivel::count());
        $this->assertTrue(Nivel::where('legado_id', 1)->first()->fixo);
        $this->assertSame(['desconto', 'aprovar'], Nivel::where('legado_id', 2)->first()->permissoes); // descarta o que não existe mais

        $this->assertSame(3, Usuario::count());
        $this->assertSame('Gerente', Usuario::where('login', 'gerente')->first()->nivel->nome);
        $this->assertSame('Operador', Usuario::where('login', 'operador')->first()->nivel->nome); // sem nível: pelo usr_admin

        $this->assertSame(2, LogAtividade::count());
        $this->assertSame(Usuario::where('login', 'gerente')->value('id'), LogAtividade::where('acao', 'desconto')->value('usuario_id'));
    }

    public function test_senhas_atuais_continuam_valendo(): void
    {
        $this->importar();
        $this->post('/login', ['login' => 'caue', 'password' => 'senha-boa'])->assertRedirect('/');
        auth()->logout();
        $this->post('/login', ['login' => 'gerente', 'password' => 'antiga'])->assertRedirect('/');
    }

    public function test_pode_rodar_varias_vezes_sem_duplicar(): void
    {
        $this->importar();
        DB::connection('legado')->table('USUARIOS')->where('usr_id', 3)->update(['usr_full_name' => 'OPERADOR NOVO']);
        DB::connection('legado')->table('LOG_ATIVIDADES')->insert(['log_quando' => '2026-11-02 08:00:00', 'log_usuario' => 'operador', 'log_ip' => '1.1.1.3', 'log_acao' => 'login', 'log_detalhe' => null]);
        $this->importar();

        app(EmpresaAtual::class)->definir(Empresa::firstOrFail()->id);
        $this->assertSame(1, Empresa::count());
        $this->assertSame(3, Usuario::count());
        $this->assertSame(3, Nivel::count());
        $this->assertSame(3, LogAtividade::count());
        $this->assertSame('OPERADOR NOVO', Usuario::where('legado_id', 3)->value('nome'));
    }

    public function test_login_que_ja_existe_no_erp_e_ignorado(): void
    {
        $this->importar();
        app(EmpresaAtual::class)->definir(Empresa::firstOrFail()->id);
        $outro = new Usuario(['nome' => 'Criado no ERP', 'login' => 'novo', 'nivel_id' => Nivel::first()->id]);
        $outro->definirSenha('segredo1');
        $outro->save();
        DB::connection('legado')->table('USUARIOS')->insert(['usr_id' => 4, 'usr_full_name' => 'NOVO', 'usr_nickname' => 'novo', 'usr_password' => sha1('x'), 'usr_admin' => 0]);

        $this->importar();
        $this->assertSame('Criado no ERP', Usuario::where('login', 'novo')->value('nome'));
        $this->assertNull(Usuario::where('login', 'novo')->value('legado_id'));
    }

    public function test_comparacao_bate_depois_da_importacao(): void
    {
        $this->importar();
        $this->artisan('legado:comparar')->assertSuccessful();
    }
}
