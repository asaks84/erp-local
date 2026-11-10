<?php

use App\Http\Controllers\AtividadeController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CadastroPublicoController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\ContratoController;
use App\Http\Controllers\ConvenioController;
use App\Http\Controllers\EmpresaController;
use App\Http\Controllers\InicioController;
use App\Http\Controllers\NivelController;
use App\Http\Controllers\PagadorController;
use App\Http\Controllers\PendenteController;
use App\Http\Controllers\ServicoController;
use App\Http\Controllers\TarifaController;
use App\Http\Controllers\TipoVeiculoController;
use App\Http\Controllers\UnidadeController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\VeiculoController;
use Illuminate\Support\Facades\Route;

// Toda rota declara a permissão que exige ("permissao:<chave>", ou "permissao:livre" para
// qualquer usuário logado) ou que é pública ("publica"). Rota sem isso é bloqueada.

Route::middleware(['publica', 'guest'])->group(function () {
    Route::get('/login', [LoginController::class, 'formulario'])->name('login');
    Route::post('/login', [LoginController::class, 'entrar'])->middleware('throttle:login');
});

// cadastro público de mensalista (QR Code no balcão)
Route::middleware('publica')->group(function () {
    Route::get('/cadastro', [CadastroPublicoController::class, 'inicio']);
    Route::get('/cadastro/{codigo}', [CadastroPublicoController::class, 'formulario']);
    Route::post('/cadastro/{codigo}', [CadastroPublicoController::class, 'enviar'])->middleware('throttle:10,60');
    Route::get('/cadastro/{codigo}/enviado', [CadastroPublicoController::class, 'enviado']);
});

Route::middleware('auth')->group(function () {
    // dia a dia: qualquer usuário logado
    Route::middleware('permissao:livre')->group(function () {
        Route::get('/', InicioController::class);
        Route::post('/sair', [LoginController::class, 'sair']);
        Route::get('/sessao/ping', [LoginController::class, 'ping']);
        Route::get('/busca/clientes', [ClienteController::class, 'buscar']);

        Route::get('/clientes', [ClienteController::class, 'index']);
        Route::get('/clientes/novo', [ClienteController::class, 'create']);
        Route::post('/clientes', [ClienteController::class, 'store']);
        Route::get('/clientes/{cliente}', [ClienteController::class, 'show']);
        Route::get('/clientes/{cliente}/editar', [ClienteController::class, 'edit']);
        Route::put('/clientes/{cliente}', [ClienteController::class, 'update']);

        Route::get('/clientes/{cliente}/veiculos/novo', [VeiculoController::class, 'create']);
        Route::post('/clientes/{cliente}/veiculos', [VeiculoController::class, 'store']);
        Route::get('/veiculos/{veiculo}/editar', [VeiculoController::class, 'edit']);
        Route::put('/veiculos/{veiculo}', [VeiculoController::class, 'update']);

        Route::post('/veiculos/{veiculo}/mensalidade', [ContratoController::class, 'store']);
        Route::post('/mensalidades/{contrato}/encerrar', [ContratoController::class, 'encerrar']);
        Route::post('/mensalidades/{contrato}/desfazer-encerramento', [ContratoController::class, 'desfazerEncerramento']);
        Route::post('/mensalidades/{contrato}/pausar', [ContratoController::class, 'pausar']);
        Route::delete('/pausas/{pausa}', [ContratoController::class, 'removerPausa']);

        Route::delete('/pagadores/{pagador}', [PagadorController::class, 'destroy']);
    });

    Route::middleware('permissao:mensalidade_valor')->group(function () {
        Route::post('/mensalidades/{contrato}/reajustar', [ContratoController::class, 'reajustar']);
    });

    Route::middleware('permissao:excluir_cadastro')->group(function () {
        Route::post('/clientes/{cliente}/remover', [ClienteController::class, 'desativar']);
        Route::post('/clientes/{cliente}/reativar', [ClienteController::class, 'reativar']);
        Route::post('/veiculos/{veiculo}/remover', [VeiculoController::class, 'desativar']);
        Route::post('/veiculos/{veiculo}/reativar', [VeiculoController::class, 'reativar']);
    });

    Route::middleware('permissao:aprovar')->group(function () {
        Route::get('/pendentes', [PendenteController::class, 'index']);
        Route::get('/pendentes/{pendente}', [PendenteController::class, 'show']);
        Route::post('/pendentes/{pendente}/aprovar', [PendenteController::class, 'aprovar']);
        Route::post('/pendentes/{pendente}/recusar', [PendenteController::class, 'recusar']);
    });

    Route::middleware('permissao:convenios')->group(function () {
        Route::get('/convenios', [ConvenioController::class, 'index']);
        Route::get('/convenios/novo', [ConvenioController::class, 'create']);
        Route::post('/convenios', [ConvenioController::class, 'store']);
        Route::get('/convenios/{convenio}', [ConvenioController::class, 'edit']);
        Route::put('/convenios/{convenio}', [ConvenioController::class, 'update']);
    });

    Route::middleware('permissao:configuracoes')->group(function () {
        Route::get('/empresa', [EmpresaController::class, 'edit']);
        Route::put('/empresa', [EmpresaController::class, 'update']);
        Route::put('/empresa/config', [EmpresaController::class, 'config']);
        Route::post('/empresa/link-cadastro', [EmpresaController::class, 'gerarLink']);

        Route::get('/unidades/nova', [UnidadeController::class, 'create']);
        Route::post('/unidades', [UnidadeController::class, 'store']);
        Route::get('/unidades/{unidade}', [UnidadeController::class, 'edit']);
        Route::put('/unidades/{unidade}', [UnidadeController::class, 'update']);

        Route::get('/niveis', [NivelController::class, 'index']);
        Route::get('/niveis/novo', [NivelController::class, 'create']);
        Route::post('/niveis', [NivelController::class, 'store']);
        Route::get('/niveis/{nivel}', [NivelController::class, 'edit']);
        Route::put('/niveis/{nivel}', [NivelController::class, 'update']);
        Route::delete('/niveis/{nivel}', [NivelController::class, 'destroy']);

        Route::get('/configuracoes/tipos', [TipoVeiculoController::class, 'index']);
        Route::post('/configuracoes/tipos', [TipoVeiculoController::class, 'store']);
        Route::put('/configuracoes/tipos/{tipo}', [TipoVeiculoController::class, 'update']);
        Route::delete('/configuracoes/tipos/{tipo}', [TipoVeiculoController::class, 'destroy']);

        Route::get('/configuracoes/tarifas', [TarifaController::class, 'index']);
        Route::get('/configuracoes/tarifas/nova', [TarifaController::class, 'create']);
        Route::post('/configuracoes/tarifas', [TarifaController::class, 'store']);
        Route::post('/configuracoes/tarifas/simular', [TarifaController::class, 'simular']);
        Route::get('/configuracoes/tarifas/{tarifa}', [TarifaController::class, 'edit']);
        Route::put('/configuracoes/tarifas/{tarifa}', [TarifaController::class, 'update']);
        Route::delete('/configuracoes/tarifas/{tarifa}', [TarifaController::class, 'destroy']);

        Route::get('/configuracoes/servicos', [ServicoController::class, 'index']);
        Route::get('/configuracoes/servicos/novo', [ServicoController::class, 'create']);
        Route::post('/configuracoes/servicos', [ServicoController::class, 'store']);
        Route::get('/configuracoes/servicos/{servico}', [ServicoController::class, 'edit']);
        Route::put('/configuracoes/servicos/{servico}', [ServicoController::class, 'update']);
    });

    Route::middleware('permissao:usuarios')->group(function () {
        Route::get('/usuarios', [UsuarioController::class, 'index']);
        Route::get('/usuarios/novo', [UsuarioController::class, 'create']);
        Route::post('/usuarios', [UsuarioController::class, 'store']);
        Route::get('/usuarios/{usuario}', [UsuarioController::class, 'edit']);
        Route::put('/usuarios/{usuario}', [UsuarioController::class, 'update']);

        Route::get('/atividades', AtividadeController::class);
    });
});
