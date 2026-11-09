# Regras de negócio

> Parte da documentação do ERP Local. Índice: [docs/README.md](README.md)

Seis serviços concentram todas as contas de dinheiro; telas, PDV, API e relatórios só chamam esses serviços e nunca recalculam por conta própria. Cada um tem testes automáticos com casos reais do Local antes da virada.

| Serviço | O que decide | Hoje está em |
|---|---|---|
| Tarifador | Valor da estadia: tolerância, faixas, fração, diárias, pernoite | biblioteca Tarifador + configurações |
| Convênio | Quem paga quanto: convênio paga tudo, até um tempo ou desconto para quem usa | só cadastrado; aplicação é nova |
| Mensalidade | Geração do mês, primeira cobrança (proporcional de 30 dias, mês cheio, cortesia), reajuste | Collection + servicos_model |
| Preço de serviço | Principal + adicionais pelo tipo do veículo e pela unidade | configurações de serviços |
| Baixa | Recebe um pagamento e distribui entre ordens de serviço: total, parcial escolhida, sobra vira crédito, falta vira débito ou desconto | três caminhos diferentes (receber contas, lote, extrato) |
| Cobrança | Agrupa as ordens de serviço em aberto de um cliente ou convênio, com vencimento e número; gera o boleto | novo |

**Exemplo de teste que trava a publicação se falhar:** "SUV entrou 8h10, saiu 10h45, tabela Carros, sem convênio → R$ X". Os valores reais saem das tabelas que você já configurou.

## Detalhe das regras que já existem no sistema atual (preservar)

**Tarifador (estadia)**
- Tolerância: minutos iniciais grátis.
- Fração: minutos de cortesia antes de cobrar o próximo bloco.
- Faixas em sequência: `fixo` (valor único pela faixa) ou `bloco` (a cada N minutos, um valor); a última vai até o fim.
- Pacotes (diárias/períodos) funcionam como teto; com hora de início e fim viram pernoite/período (preço fechado se a estadia estiver toda na janela).
- Restante acima do maior pacote: volta às faixas ou só pacotes inteiros.
- Sempre escolhe a combinação mais barata para o cliente.
- Referência: `application/libraries/Tarifador.php` do sistema atual.

**Mensalidade**
- Mês comercial de 30 dias. Proporcional = mensalidade × (30 − min(dia, 30) + 1) / 30.
- Primeira cobrança (depois de aprovar cadastro ou adicionar veículo): só proporcional; proporcional + próximo mês; mês cheio; cortesia neste mês (gera só o próximo); não gerar. Vencimento do próximo mês no dia do cliente (dia 31 vira o último dia do mês).
- Geração mensal pula o veículo que já tem mensalidade de mês posterior gerada até o fim do mês de referência.
- Nunca duas mensalidades do mesmo mês para o mesmo veículo.

**Baixa e extrato**
- Total em aberto do cliente = itens em aberto − créditos.
- PIX menor que o total: escolher os itens pagos (pré-marca a combinação exata, mais antigos primeiro); falta nas marcadas vira desconto ou débito; sobra vira crédito.
- Desconto só com permissão.

**Convênio**
- Modos: convênio paga tudo; convênio paga até um tempo (excedente com quem usou); desconto para quem usa (% ou R$).
- Vale para estadias, lavagens e serviços, ou os dois. No modo por tempo, o conveniado paga o serviço inteiro.

## Regras decididas para o ERP

- **Pausa e cancelamento agendado da mensalidade:** pausa = período em que a mensalidade cheia não é gerada; a pausa **pode ser cobrada**: ao pausar, define-se o valor da taxa de reserva (fixo ou % da mensalidade, com padrão configurável por empresa; zero = pausa grátis), que vira uma ordem de mensalidade de pausa em cada mês pausado; cancelamento agendado = data de encerramento registrada antes, o sistema gera a última mensalidade e para sozinho.
- **Inadimplência:** o cliente fica inadimplente a partir do primeiro vencimento não pago. A cobrança é feita pelos funcionários (lista de inadimplentes, com contato e o que deve) ou pelo WhatsApp (régua de cobrança). Sem alerta separado de desistência.
- **Vigência de preços:** serviços, tabelas de estacionamento e contratos de mensalidade têm início e fim. Reajuste é uma nova versão com data de início, cadastrada com antecedência; na data, o sistema passa a usar a nova sozinho. Cada ordem usa o preço vigente na data em que aconteceu.

Ver também a seção de cobrança em [06-modulos.md](06-modulos.md).

- Saída do PDV durante queda de internet: cobra o valor estimado e o gerente confere depois.
- Caixa: só quem recebe dinheiro abre caixa; "operador de caixa" é uma qualificação no cadastro do usuário.
- Termos: **ordem de serviço** é todo item que o cliente deve (mensalidade, estadia, lavagem); **cobrança** agrupa as ordens em aberto e gera o boleto. Ver [00-glossario.md](00-glossario.md).
