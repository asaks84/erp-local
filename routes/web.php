<?php

use App\Http\Controllers\AtividadeController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CadastroPublicoController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\CobrancaController;
use App\Http\Controllers\ConferenciaController;
use App\Http\Controllers\ContaController;
use App\Http\Controllers\ContratoController;
use App\Http\Controllers\DespesaController;
use App\Http\Controllers\FornecedorController;
use App\Http\Controllers\FuncionarioController;
use App\Http\Controllers\PlanoContaController;
use App\Http\Controllers\RecorrenteController;
use App\Http\Controllers\ConvenioController;
use App\Http\Controllers\EmpresaController;
use App\Http\Controllers\EstadiaController;
use App\Http\Controllers\EtapaLavagemController;
use App\Http\Controllers\ExtratoController;
use App\Http\Controllers\FormaPagamentoController;
use App\Http\Controllers\AnotacaoController;
use App\Http\Controllers\InicioController;
use App\Http\Controllers\LavagemController;
use App\Http\Controllers\MensalidadeController;
use App\Http\Controllers\NivelController;
use App\Http\Controllers\OrdemController;
use App\Http\Controllers\PagadorController;
use App\Http\Controllers\PendenteController;
use App\Http\Controllers\RelatorioController;
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
        Route::post('/anotacoes', [AnotacaoController::class, 'store']);
        Route::put('/anotacoes/{anotacao}', [AnotacaoController::class, 'update']);
        Route::post('/anotacoes/{anotacao}/alternar', [AnotacaoController::class, 'alternar']);
        Route::delete('/anotacoes/{anotacao}', [AnotacaoController::class, 'destroy']);
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

        // dinheiro do dia a dia: receber, lançar, gerar mensalidades, emitir e imprimir cobranças
        Route::get('/clientes/{cliente}/extrato', [ClienteController::class, 'extrato']);
        Route::get('/clientes/{cliente}/receber', [ContaController::class, 'receber']);
        Route::post('/clientes/{cliente}/receber', [ContaController::class, 'confirmarRecebimento']);
        Route::get('/clientes/{cliente}/lancar', [OrdemController::class, 'create']);
        Route::post('/clientes/{cliente}/lancar', [OrdemController::class, 'store']); // débito/crédito conferem "alterar contas"
        Route::post('/clientes/{cliente}/cobrancas', [CobrancaController::class, 'emitir']);
        Route::get('/ordens', [OrdemController::class, 'index']);

        Route::get('/mensalidades/gerar', [MensalidadeController::class, 'gerar']);
        Route::post('/mensalidades/gerar', [MensalidadeController::class, 'confirmarGeracao']);
        Route::get('/mensalidades/{contrato}/primeira-cobranca', [MensalidadeController::class, 'primeira']);
        Route::post('/mensalidades/{contrato}/primeira-cobranca', [MensalidadeController::class, 'confirmarPrimeira']);

        Route::get('/cobrancas', [CobrancaController::class, 'index']);
        Route::get('/cobrancas/gerar', [CobrancaController::class, 'gerar']);
        Route::post('/cobrancas/gerar', [CobrancaController::class, 'emitirLote']);
        Route::get('/cobrancas/imprimir', [CobrancaController::class, 'imprimir']);
        Route::get('/cobrancas/{cobranca}', [CobrancaController::class, 'show']);

        // operação (etapa 4): estadias e venda de lavagens e serviços
        Route::get('/estadias', [EstadiaController::class, 'index']);
        Route::get('/estadias/historico', [EstadiaController::class, 'historico']);
        Route::get('/estadias/placa', [EstadiaController::class, 'placa']);
        Route::post('/estadias', [EstadiaController::class, 'store']);
        Route::get('/estadias/{estadia}/calcular', [EstadiaController::class, 'calcular']);
        Route::post('/estadias/{estadia}/saida', [EstadiaController::class, 'saida']);
        Route::post('/estadias/{estadia}/cancelar', [EstadiaController::class, 'cancelar']);
        Route::get('/lavagens', [LavagemController::class, 'index']);
        Route::get('/lavagens/nova', [LavagemController::class, 'create']);
        Route::get('/lavagens/placa', [LavagemController::class, 'placa']);
        Route::post('/lavagens', [LavagemController::class, 'store']);
        Route::post('/lavagens/{ordem}/etapa', [LavagemController::class, 'etapa']);
        Route::post('/lavagens/{ordem}/receber', [LavagemController::class, 'receber']);
    });

    Route::middleware('permissao:contas_editar')->group(function () {
        Route::put('/ordens/{ordem}', [OrdemController::class, 'update']);
        Route::post('/ordens/{ordem}/cancelar', [OrdemController::class, 'cancelar']);
        Route::post('/pagamentos/{pagamento}/estornar', [ContaController::class, 'estornar']);
        Route::post('/cobrancas/{cobranca}/cancelar', [CobrancaController::class, 'cancelar']);
    });

    Route::middleware('permissao:contas_aberto')->group(function () {
        Route::get('/contas/abertas', [ContaController::class, 'abertas']);
    });

    Route::middleware('permissao:faturamento')->group(function () {
        Route::get('/relatorios/recebimentos', [RelatorioController::class, 'recebimentos']);
    });

    Route::middleware('permissao:extrato')->group(function () {
        Route::get('/extrato', [ExtratoController::class, 'index']);
        Route::post('/extrato', [ExtratoController::class, 'enviar']);
        Route::get('/extrato/conferir/{token}', [ExtratoController::class, 'conferir']);
        Route::post('/extrato/confirmar', [ExtratoController::class, 'confirmar']);
        Route::get('/extrato/resultado/{token}', [ExtratoController::class, 'resultado']);
        Route::get('/extrato/cliente/{cliente}', [ExtratoController::class, 'cliente']);
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

    Route::middleware('permissao:contas_pagar')->group(function () {
        Route::get('/pagar', [DespesaController::class, 'index']);
        Route::get('/pagar/nova', [DespesaController::class, 'create']);
        Route::post('/pagar', [DespesaController::class, 'store']);
        Route::post('/pagar/ler', [DespesaController::class, 'ler']);
        Route::get('/pagar/fixas', [RecorrenteController::class, 'index']);
        Route::post('/pagar/fixas', [RecorrenteController::class, 'store']);
        Route::put('/pagar/fixas/{recorrente}', [RecorrenteController::class, 'update']);
        Route::get('/pagar/{despesa}/editar', [DespesaController::class, 'edit']);
        Route::put('/pagar/{despesa}', [DespesaController::class, 'update']);
        Route::post('/pagar/{despesa}/pagar', [DespesaController::class, 'pagar']);
        Route::post('/pagar/{despesa}/desfazer', [DespesaController::class, 'desfazer']);
        Route::post('/pagar/{despesa}/cancelar', [DespesaController::class, 'cancelar']);
        Route::get('/pagar/{despesa}/comprovante', [DespesaController::class, 'comprovante']);
        Route::get('/fornecedores', [FornecedorController::class, 'index']);
        Route::post('/fornecedores', [FornecedorController::class, 'store']);
        Route::put('/fornecedores/{fornecedor}', [FornecedorController::class, 'update']);
        Route::get('/funcionarios', [FuncionarioController::class, 'index']);
        Route::post('/funcionarios', [FuncionarioController::class, 'store']);
        Route::get('/funcionarios/recibos', [FuncionarioController::class, 'recibos']);
        Route::post('/funcionarios/recibos/ler', [FuncionarioController::class, 'lerRecibos']);
        Route::post('/funcionarios/recibos', [FuncionarioController::class, 'importarRecibos']);
        Route::get('/funcionarios/diarias', [FuncionarioController::class, 'diarias']);
        Route::post('/funcionarios/diarias', [FuncionarioController::class, 'lancarDiarias']);
        Route::post('/funcionarios/diarias/pagar', [FuncionarioController::class, 'pagarDiarias']);
        Route::delete('/funcionarios/diarias/{diaria}', [FuncionarioController::class, 'removerDiaria']);
        Route::put('/funcionarios/{funcionario}', [FuncionarioController::class, 'update']);
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
        Route::put('/empresa/cobranca', [EmpresaController::class, 'cobranca']);

        Route::get('/configuracoes/pagamentos', [FormaPagamentoController::class, 'index']);
        Route::get('/conferencia', ConferenciaController::class);
        Route::get('/configuracoes/plano-contas', [PlanoContaController::class, 'index']);
        Route::post('/configuracoes/plano-contas', [PlanoContaController::class, 'store']);
        Route::put('/configuracoes/plano-contas/{conta}', [PlanoContaController::class, 'update']);
        Route::post('/configuracoes/plano-contas/{conta}/mover', [PlanoContaController::class, 'mover']);
        Route::delete('/configuracoes/plano-contas/{conta}', [PlanoContaController::class, 'destroy']);
        Route::get('/configuracoes/etapas', [EtapaLavagemController::class, 'index']);
        Route::post('/configuracoes/etapas', [EtapaLavagemController::class, 'store']);
        Route::put('/configuracoes/etapas/{etapa}', [EtapaLavagemController::class, 'update']);
        Route::post('/configuracoes/etapas/{etapa}/mover', [EtapaLavagemController::class, 'mover']);
        Route::delete('/configuracoes/etapas/{etapa}', [EtapaLavagemController::class, 'destroy']);
        Route::post('/configuracoes/formas', [FormaPagamentoController::class, 'storeForma']);
        Route::put('/configuracoes/formas/{forma}', [FormaPagamentoController::class, 'updateForma']);
        Route::post('/configuracoes/contas', [FormaPagamentoController::class, 'storeConta']);
        Route::put('/configuracoes/contas/{conta}', [FormaPagamentoController::class, 'updateConta']);

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
