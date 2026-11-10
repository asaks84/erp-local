# Histórico de alterações do ERP

Alterações do ERP Local, da mais nova para a mais antiga. Cada alteração entra **no topo**: data, o que mudou e arquivos.

---

## 10/11/2026 — Etapa 3: dinheiro

- **Ordens de serviço:** todo valor do cliente (mensalidade, lavagem/serviço, estadia, débito, crédito) vira uma ordem com número da empresa. Ordem nunca é apagada: em aberto pode ser alterada ou cancelada com motivo (permissão "alterar contas"). Lista em Contas › Ordens de serviço.
- **Lançar na conta do cliente:** lavagem/serviço com adicionais (preço da tabela pelo tipo do veículo; serviço "até" pede o valor), estadia, e débito/crédito com motivo (permissão "alterar contas").
- **Gerar mensalidades do mês:** prévia com cada mensalista (vai gerar, já gerada, já coberta, pausado com taxa, começou no mês), marcar e gerar. Nunca duas do mesmo mês para o mesmo veículo.
- **Primeira cobrança:** abre sozinha depois de cadastrar uma mensalidade (veículo novo, ficha do cliente ou aprovação da fila): proporcional, proporcional + próximo, mês cheio, cortesia ou não gerar.
- **Receber:** marca as contas pagas, valor, data e forma de pagamento (com parcelas, taxa e data em que o dinheiro cai). Créditos abatem; sobra vira crédito ou serviço feito na hora; falta vira débito ou desconto. **Estorno** com motivo.
- **Cobranças:** emissão por cliente (ficha) ou em lote no mês; cópia dos itens na emissão (não muda depois); o que não foi pago entra na próxima como saldo anterior; impressão na **via do cliente** do sistema atual (folha A4 deitada, duas cobranças lado a lado, seções Mensalidades, Lavagens e Estadias avulsas, subtotal, créditos, débitos e total), com número da cobrança, asterisco no que veio da cobrança anterior e o texto "como pagar" configurável em Empresa e unidades.
- **Extrato bancário:** envia o CSV, o sistema sugere o cliente (pagador vinculado, CPF parcial ou nome), a equipe confere e dá baixa; o mesmo PIX não baixa duas vezes; dá para lembrar o pagador.
- **Contas em aberto** (com "só vencidas" e planilha), **Recebimentos** por período (por forma e pelo que foi pago, com planilha), **extrato da conta** na ficha do cliente (com saldo linha a linha, para imprimir) e números no **Início**.
- **Formas de pagamento e contas** em Configurações (Dinheiro, Pix, cartões, boleto, transferência já vêm cadastrados).
- **Importação:** ordens com o mesmo número do sistema atual, pagamentos reconstruídos (forma "não informada") e extrato já importado; reimportar não duplica. **Comparação** do portão: ordens, em aberto por cliente e recebido mês a mês. Testado com 56 mil ordens (5 segundos).
- **Correção na importação:** ordem com data de pagamento inválida no sistema atual (ex.: `0000-00-00`) entrava como em aberto; agora entra como paga, sem data, igual ao sistema atual (que só considera em aberto quando a data está vazia).
- **Serviço feito na hora** (sobra no receber e no extrato): a lista inclui "Estadia avulsa", além das lavagens e serviços.
- **Início:** "Recebido no mês" passa a somar as contas baixadas (crédito usado abate), igual ao Início do sistema atual; o dinheiro por forma de pagamento fica em Recebimentos.
- **Importação na fase de paralelo:** conta paga nos dois sistemas não vira dois pagamentos (vale o pagamento feito no ERP). Novo `importar-legado.bat` na raiz: roda a importação e a comparação e grava o resultado em `storage/logs/importacao.txt`.
- **Portão da etapa 3 cumprido** com a cópia local do sistema atual (banco do ERP recriado do zero): 20.911 ordens, 49 em aberto (R$ 10.050,00) e o recebido de todos os meses iguais. Novo `recriar-banco.bat` (apaga o banco do ERP e importa tudo de novo; só para antes da virada).
- **Extrato da conta:** contas baixadas no sistema atual sem data de pagamento (`0000-00-00`) agora aparecem como "Baixada no sistema atual sem data de pagamento" e saem do saldo; antes o extrato terminava com um saldo que não existia (ex.: R$ 650 de um cliente sem nada em aberto). Pagamentos são buscados pelas contas do cliente. O saldo final do extrato bate com o em aberto em todos os clientes.
- **119 testes automáticos.**
- Banco: migração `2026_11_12_000001_create_dinheiro_tables` (rodar `php artisan migrate`).

## 10/11/2026

- **Empresa e unidades:** campo "CNPJ" (empresa é sempre pessoa jurídica), com máscara e conferência dos dígitos; telefone com máscara na empresa e nas unidades. Os dois passam a ser guardados só com os números.

## 10/11/2026 — Etapa 2: cadastros e configurações

- **Clientes:** lista com busca enquanto digita (nome, código, CPF/CNPJ ou placa), ficha com dados, veículos, mensalidade e histórico, cadastro com busca de endereço pelo CEP, CPF/CNPJ conferido pelos dígitos. "Remover" desativa (encerra as mensalidades hoje e guarda o histórico); dá para reativar.
- **Busca de cliente no topo** de todas as telas (como no sistema atual).
- **Veículos:** placa opcional (bicicleta, patinete: identificação alternativa), placa única só entre veículos ativos, troca de placa guardada no histórico.
- **Mensalidade (contrato por veículo):** criar, reajustar (nova versão a partir de uma data; a anterior termina na véspera), encerrar (inclusive agendado, com motivo) e pausar meses inteiros com taxa de reserva (valor ou %, 0 = grátis).
- **Fila de espera e cadastro público:** formulário pelo link/QR Code (gerado em Empresa e unidades), aprovação como cliente novo ou ligado a um cliente existente pelo CPF; aprovado ou recusado, sai da fila (dados de quem não virou cliente não ficam guardados).
- **Configurações:** tipos de veículo; tabelas de estadia com vigência, exceção por unidade e simulador; lavagens e serviços com adicionais e preço por tipo com vigência (reajuste agendado) e exceção por unidade; padrões de mensalidade (dia de vencimento, períodos, taxa de pausa).
- **Convênios:** paga tudo, paga até um tempo ou desconto (% ou R$), para estadias e/ou serviços.
- **Tarifador** trazido do sistema atual, com o mesmo resultado em 12 mil estadias sorteadas, e testes com as tabelas do Local.
- **Importação** de tipos, tabelas, serviços e preços, clientes (com o mesmo código do sistema atual), veículos (placa improvisada vira "sem placa"), mensalidades (ligadas pelo id do veículo), convênios, fila e pagadores vinculados; acentos gravados errado no sistema atual são corrigidos. **Comparação** com os novos itens (inclusive a soma das mensalidades).
- **86 testes automáticos.**
- A **primeira cobrança** e a geração das mensalidades ficam para a etapa 3 (até lá, pelo sistema atual).
- Telas carregadas sob demanda (o sistema abre mais rápido).

## 09/11/2026 (noite) — Etapa 1: fundação

- **Projeto Laravel 13 + Inertia 3 + React 19 + Bootstrap 5.3**, com o visual do sistema atual (cores, fonte Inter servida pelo próprio sistema, cantos, tema claro e escuro, menu lateral recolhível, menu de baixo no celular).
- **Núcleo:** tabelas `empresas`, `unidades`, `niveis`, `usuarios`, `log_atividades`; filtro por empresa em toda consulta (falha fechada: sem empresa, nada aparece e nada grava).
- **Login por usuário** com as senhas do sistema atual (bcrypt e o SHA1 antigo, regravado na hora), limite de 5 tentativas por minuto, mesma mensagem para login inexistente e senha errada.
- **Permissões:** catálogo herdado + `contas_pagar`; toda rota declara a permissão e rota sem permissão é bloqueada; acesso negado vai para o registro de atividades.
- **Telas:** Início, Usuários (criar, editar, desativar), Níveis de acesso, Empresa e unidades, Registro de atividades.
- **Proteções:** ninguém muda o próprio nível nem se desativa; sempre sobra um usuário ativo com acesso total e uma unidade ativa.
- **Inatividade entre abas:** fecha só quando todas as abas estão paradas, com aviso um minuto antes; sair numa aba leva as outras.
- **Importação do sistema atual** (`legado:importar`, roda quantas vezes quiser) e **comparação** (`legado:comparar`, relatório em Markdown).
- **43 testes automáticos**: isolamento entre empresas, rotas, login, permissões, usuários e níveis, importação.
- Documentação: novo `docs/13-desenvolvimento.md`; atualizados README, CLAUDE.md, 04, 10, 12.
- **Instalação no WAMP:** tabelas sempre em InnoDB (o MySQL do WAMP vinha com MyISAM como padrão e travava o `migrate`); configuração dos testes sem a pasta `tests/Unit`; aviso informativo legível no tema escuro.

## 09/11/2026

- **Comparativo com o mercado decidido, ponto a ponto:** cobrança imutável, ajustes, extrato do cliente, plano de contas, centro de custo (lista cadastrada), contas financeiras e conciliação, formas de pagamento com taxas e prazo em dias, vigência de preços, pausa (pode ser cobrada) e cancelamento agendado, inadimplência a partir do primeiro vencimento, régua de cobrança (com WhatsApp), situação do pagamento (com Inter), campos fiscais, numeração própria, capacidade e ocupação. Atualizados 03, 05, 06, 11, 12.
- **Contas a pagar:** uma permissão só (`contas_pagar`); registrado em 08 e 12.
- **Termos definidos:** "ordem de serviço" é todo item que o cliente deve; "cobrança" (no lugar de "fatura") agrupa as ordens e gera o boleto; etapa da lavagem fica na própria ordem; cancelamento de ordem com permissão e motivo. Novo `docs/00-glossario.md`; ajustados 01, 03, 04, 05, 06, 07, 10, 11, 12, README e CLAUDE.md.
- **Início do projeto:** pasta `erplocal`, documentação detalhada em `docs/` (visão, tecnologia, modelo de dados, migração, regras de negócio, módulos, PDV, segurança, SaaS, plano de etapas, comparativo com o mercado, registro de decisões), `README.md`, `CLAUDE.md`, `.gitignore`.
