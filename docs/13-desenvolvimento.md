# Desenvolvimento: como o código está organizado

> Parte da documentação do ERP Local. Índice: [docs/README.md](README.md)

Como o código da etapa 1 em diante está montado e as regras que cada tela nova precisa seguir. Instalação no computador: [README](../README.md).

## Versões

| Peça | Versão |
|---|---|
| Laravel | 13 (PHP 8.3+) |
| Inertia | 3 (servidor `inertiajs/inertia-laravel`, telas `@inertiajs/react`) |
| React | 19 |
| Bootstrap | 5.3 (Sass próprio em `resources/scss`) + React-Bootstrap só para janelas (Modal) |
| Vite | 8 |
| Fonte | Inter, servida pelo próprio sistema (`@fontsource-variable/inter`), sem Google Fonts |

## Pastas

```
app/
  Models/                 Empresa, Unidade, Nivel, Usuario, LogAtividade, Cliente, Veiculo, MensalidadeContrato, MensalidadePausa,
                          TipoVeiculo, Tarifa (+ faixas, pacotes), Servico, ServicoPreco, Convenio, CadastroPendente, PagadorVinculado,
                          OrdemServico, Cobranca (+ itens), Pagamento (+ itens), Ajuste, FormaPagamento, ContaFinanceira, ExtratoLancamento
  Models/Concerns/        PertenceAEmpresa (filtro por empresa) e EmpresaScope
  Http/Controllers/       uma classe por tela (Usuario, Nivel, Empresa, Unidade, Atividade, Auth/Login)
  Http/Middleware/        Permissao, PermissaoDeclarada, Publica, HandleInertiaRequests, CabecalhosSeguranca
  Servicos/               regras de negócio num lugar só: Tarifador (estadia), Precos (preço vigente), Contratos (mensalidade),
                          Ordens (lançar/alterar/cancelar), Numeracao, Mensalidades (geração e primeira cobrança), Baixa (receber
                          e estornar), Cobrancas (emitir, cancelar, situação), Extrato (CSV do banco), FormasPagamento,
                          Estadias (entrada, cálculo, saída), Vendas (lavagens e serviços, etapas), Convenios (divisão do valor)
  Support/                EmpresaAtual, Atividades (registro), Senha, Legado (banco atual), Placa, Documento (CPF/CNPJ), Formato, Csv
  Auth/UsuarioProvider    busca o usuário do login sem o filtro de empresa
  Console/Commands/       erp:instalar, legado:importar, legado:comparar
config/erp.php            inatividade, faixas de IP do Cloudflare
config/permissoes.php     catálogo de permissões (grupo, chave, rótulo, descrição)
database/migrations/      estrutura do banco
lang/pt_BR/               mensagens de validação em português
resources/js/
  app.jsx                 início das telas (layout padrão para todas, menos o login)
  Layouts/AppLayout.jsx   menu lateral, menu de baixo no celular, tema, aviso de inatividade
  Pages/                  uma pasta por módulo (Usuarios/Index.jsx, Usuarios/Form.jsx...)
  Components/             Campo, CampoData (dd/mm/aaaa), CampoValor (R$ 1.234,56), BuscaCliente, Janela, Confirmar,
                          CamposCliente, CamposVeiculo, CamposMensalidade, Paginacao, Icone, Avisos, TituloPagina,
                          Dinheiro (rótulos e selos de ordem/cobrança), ContasCliente (bloco Contas da ficha), BuscaClienteCampo,
                          Anotacoes (tarefas do Início), AbasConfiguracoes (abas de Sistema › Configurações; lista em menu.js CONFIGURACOES)
  lib/                    formato.js (datas e R$ em pt-BR), mascaras.js (CPF, telefone, CEP, placa), menu.js, permissoes.js, inatividade.js
resources/scss/           app.scss (cores, fonte, cantos, tema escuro), _layout.scss e _impressao.scss (cobrança e telas impressas)
tests/Feature/            testes automáticos
```

## Regras para cada tela nova

1. **Rota com permissão.** Toda rota em `routes/web.php` fica dentro de um grupo `permissao:<chave>` (ou `permissao:livre` para qualquer usuário logado). Rota sem isso devolve 403, e o teste `RotasTest` falha. Chave nova entra em `config/permissoes.php` e aparece sozinha na tela de níveis.
2. **Tabela de negócio com `empresa_id`** e o modelo com `use PertenceAEmpresa;`. O filtro é automático em toda consulta e o `empresa_id` é preenchido ao gravar. Sem empresa definida, a consulta volta vazia e a gravação dá erro (falha fechada).
3. **Validação com `Rule::exists`/`Rule::unique` filtrando `empresa_id`** quando o campo aponta para outra tabela de negócio (o filtro global não vale dentro da regra de validação).
4. **Registrar** ações sensíveis com `Atividades::registrar('acao', 'detalhe')`.
5. **Item de menu** em `resources/js/lib/menu.js`, com a mesma permissão da rota (o menu só esconde; quem bloqueia é o servidor).
6. **Textos em português**, datas e valores sempre por `lib/formato.js` (`data()`, `dataHora()`, `mesAno()`, `moeda()`), nunca por `toLocaleString`. Campos de data com `CampoData` e de valor com `CampoValor` (nunca o campo de data do navegador).
9. **Preço e tarifa** sempre pelos serviços `Precos` e `Tarifador` (nunca recalcular na tela ou no controlador).
7. **Colunas de data** do ERP se chamam `criado_em` e `atualizado_em` (base `App\Models\Modelo`).
8. **Teste** para cada regra nova; para dinheiro, obrigatório.

## Empresa atual

- Ao identificar o usuário (login ou sessão), a empresa dele vira a "empresa atual" (`App\Support\EmpresaAtual`).
- Comandos e testes escolhem a empresa com `app(EmpresaAtual::class)->como($id, fn () => ...)`.
- O usuário é buscado no login sem o filtro (é ele quem diz a empresa); por isso o login é único no sistema todo.

## Sessão e inatividade

- O sistema fecha depois de `ERP_INATIVIDADE_MINUTOS` (10) sem uso, **somando todas as abas**: o último uso fica no navegador e é compartilhado entre as abas. Um minuto antes aparece o aviso "O sistema fecha em X s" com o botão Continuar.
- Enquanto a pessoa usa a tela sem trocar de página, uma consulta leve (`/sessao/ping`) a cada 4 min mantém a sessão do servidor viva. A sessão do servidor (`SESSION_LIFETIME`) dura 2 min a mais que a inatividade.
- Sair numa aba leva as outras para a tela de acesso.
- Tela de login aberta por muito tempo: o envio volta para a tela com aviso, sem a página de erro 419.

## Testes

- Rodam num banco MySQL separado, `erp_teste` (e `erp_teste_legado` para a importação). Criar os dois vazios no phpMyAdmin uma vez.
- `php artisan test` roda todos. Hoje: 134 testes (isolamento entre empresas, rotas, login, permissões, usuários e níveis, clientes, veículos, mensalidades, cadastro público, configurações, tarifador com as tabelas do Local, dinheiro: geração, primeira cobrança, baixa, estorno, cobranças, extrato; estadias, convênios e vendas; importação e comparação).
- Regra de dinheiro nova: escrever o teste com casos reais antes de ligar na tela.

## Comandos

| Comando | Para quê |
|---|---|
| `php artisan legado:importar` | Traz do sistema atual: níveis, usuários (as senhas continuam valendo), registro de atividades, cadastros, configurações, ordens, pagamentos e extrato. Pode rodar várias vezes (o sistema atual manda até a virada). Na primeira, cria a empresa e a unidade principal |
| `php artisan legado:comparar` | Compara os números dos dois sistemas (inclusive em aberto por cliente e recebido mês a mês) e grava o relatório em `storage/app/private/relatorios/` |
| `php artisan erp:instalar` | Banco novo sem sistema atual: cria empresa, unidade, nível de acesso total e o primeiro administrador |
| `php artisan migrate --seed` | Banco de exemplo para desenvolvimento (login `admin`, senha `trocar123`) |
| `npm run dev` | Telas com atualização automática enquanto edita |
| `npm run build` | Gera os arquivos finais das telas (`public/build`) |
