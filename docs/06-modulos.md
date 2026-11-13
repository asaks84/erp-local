# Módulos

> Parte da documentação do ERP Local. Índice: [docs/README.md](README.md)

Os quatro módulos previstos entram já no sistema novo, sobre o modelo de dados acima; nenhum deles será feito no CodeIgniter.

**1. Venda de lavagens e serviços**

- Tela de nova ordem de serviço: placa (busca o veículo ou cria um avulso), serviço principal, adicionais ligados a ele; o preço vem da tabela pelo tipo do veículo.
- Avulso sem cadastro: guarda placa e celular, para histórico e aviso de "lavagem pronta" no futuro.
- Etapa da lavagem na própria ordem de serviço: aguardando, lavando, pronta, entregue; pagar na hora ou deixar em aberto na conta (mensalista ou convênio).

**2. Baixa unificada com forma de pagamento**

- Um único serviço de baixa para receber contas, extrato, Inter e PDV.
- Todo recebimento registra a forma: dinheiro, Pix, cartão de débito, cartão de crédito, boleto, transferência.
- A tela de baixa parcial (escolher o que foi pago) que já existe passa a valer para todos os caminhos.
- Relatório de recebimentos por forma de pagamento e por caixa.

**Régua de cobrança (com o WhatsApp, etapa 7)**

- Sequência de avisos ligada ao vencimento; padrão inicial: 3 dias antes, no dia, 3 e 10 dias depois. Dias e textos configuráveis; cada etapa liga/desliga.
- Para sozinha quando o cliente paga; registra cada aviso (quando, para quem, canal); clientes marcados como "não recebe avisos".

**Capacidade e ocupação**

- Cada unidade cadastra vagas por tipo de veículo e quantas são reservadas para mensalistas; com as estadias do PDV, o sistema mostra a ocupação em tempo real e os horários de pico.
- Junto com o PDV: validação de convênio por QR Code/código no ticket. Futuro: credencial do mensalista (tag, cartão) e câmera de leitura de placa com cancela.

**Formas de pagamento aceitas (configuração)**

- Tela com cada forma que o estacionamento aceita: dinheiro, Pix (direto na conta), Pix na maquininha, débito, crédito à vista, crédito parcelado, boleto.
- Para cada uma: conta financeira de destino (caixa, Inter, maquininha...), taxa percentual e fixa (no crédito parcelado, por número de parcelas), prazo de recebimento em **número de dias** digitado livremente (0 = no mesmo dia, 1, 2, 14, 30...) e se está ativa. Só as ativas aparecem no PDV e no recebimento.
- O sistema calcula, em cada pagamento, o valor bruto, a taxa e o **valor líquido**, e a **data prevista** em que o dinheiro cai na conta.
- "A receber da maquininha" e as datas previstas entram no fluxo de caixa; a conciliação compara o depósito previsto com o que caiu no extrato.

**Contas financeiras e conciliação**

- Cadastro das contas: caixa físico, bancos (Inter, Nubank...), maquininha. Saldo de cada conta pelo sistema.
- Todo pagamento recebido e toda despesa paga diz em qual conta entrou ou saiu; transferência entre contas (ex.: depósito do caixa no banco).
- Conciliação: importa o extrato (CSV/OFX) e confere linha por linha, entradas e saídas, com o que está lançado; o que não bate aparece em destaque para lançar (ex.: tarifa bancária).

**3. Cobrança agrupando as ordens de serviço**

- Uma cobrança por cliente por competência, com os itens descritos (mensalidades, lavagens, estadias, débitos, créditos).
- Convênio: fechamento do período vira uma cobrança com todos os usos (placa, data, serviço, valor).
- Base para o boleto com Pix do Inter e para o link de pagamento no WhatsApp.
- **Boleto com Pix (Banco Inter, etapa 7):** ao emitir a cobrança (uma ou em lote), opção "Gerar boleto com Pix"; na tela da cobrança, gerar, atualizar, PDF, copiar linha digitável e Pix, cancelar. Consulta a cada 10 minutos (`inter:consultar`): pago dá baixa nas ordens da cobrança (multa e juros viram "Multa e juros do boleto", pago; o desconto da regra vira desconto); cobrança paga por outro meio, cancelada ou levada para a seguinte tem o boleto cancelado no Inter. Regras (multa % ou R$, juros % ao mês ou R$ por dia, desconto de pontualidade só nas mensalidades do mês, % ou R$ por mensalidade, até o vencimento do cliente; o boleto vence N dias depois, pelo prazo em Configurações › Banco Inter; baixa automática em X dias) em Configurações › Banco Inter; o cliente pode ter regras específicas (ficha do cliente).
- Contas em aberto e inadimplência passam a ser vistas por cobrança, com os itens dentro.

**Regras decididas para a cobrança**

- Mensalista: uma cobrança por mês; ao gerar, entram todas as ordens de serviço em aberto até aquele dia (a mensalidade do mês, lavagens, estadias), inclusive as que sobraram de cobranças anteriores.
- Pagamento menor que a cobrança: o operador escolhe quais ordens de serviço estão pagas; as outras continuam em aberto e vão para a próxima cobrança.
- Para cada ordem que ficou em aberto, duas opções: manter até o cliente pagar (entra na próxima cobrança) ou cancelar a ordem, com motivo e registro de quem cancelou. Cancelar exige a permissão de alterar e excluir contas.
- **Extrato da conta do cliente:** na ficha do cliente, todas as ordens de serviço, pagamentos e ajustes em ordem de data, com saldo linha a linha; pode ser impresso ou enviado por WhatsApp. Montado das tabelas existentes (sem tabela nova).
- **Ajustes:** crédito (pagou a mais), débito (pagou a menos), estorno (baixa errada, Pix devolvido, cartão contestado) e desconto depois de emitida são documentos de ajuste (`ajustes`) ligados à cobrança ou ao pagamento, com motivo e usuário. Nada é editado por cima.
- **Cobrança emitida não muda:** guarda a cópia dos itens como estavam na emissão (`cobranca_itens`). O que não foi pago aparece na cobrança seguinte numa linha "saldo anterior", com as ordens descritas; a cobrança antiga fica como parcialmente paga, mostrando o que foi pago e o que foi levado para a próxima.
- Exemplo: o cliente tem 2 lavagens de outubro e a mensalidade de novembro na cobrança de novembro. Ao pagar, avisa que vai deixar de ser mensalista. A ordem de mensalidade de novembro é cancelada (com motivo) e ele paga só as 2 lavagens.
- Convênio fecha junto com a geração das cobranças, no dia de vencimento do próprio conveniado.

**4. Gestão de gastos e contas a pagar**

- Cadastro de funcionários: registrado ou avulso, cargo, combinado (mensal, semanal, por diária) e valor.
- Holerite em PDF: o sistema lê e preenche bruto, líquido, FGTS e INSS; você confere e registra o que pagou de fato (que pode ser diferente do holerite).
- Guias e impostos (FGTS, DCTF, INSS) e contas fixas (aluguel, energia, água) como despesas recorrentes.
- **Centro de custo (opcional):** área do negócio (ex.: Estacionamento, Lavagem) em despesas e ordens. Vem de uma lista cadastrada (`centros_custo`); o campo busca enquanto digita e só aceita um item da lista. Criar um centro novo é uma ação explícita ("+ Criar centro de custo"), nunca por texto digitado. Fornecedores e despesas recorrentes podem ter um centro padrão, que já vem preenchido.
- Resultado do mês (DRE): recebido menos pago, pelo **plano de contas** (árvore única de receitas e despesas, com modelo pronto e editável) e por unidade. Cada serviço e tipo de ordem aponta para uma conta de receita, então as entradas do PDV (estadias, lavagens) já chegam classificadas.

**Contas a pagar**

- Cadastro de fornecedores (energia, água, aluguel, contador, produtos de lavagem, prestadores).
- Cada conta com fornecedor, categoria, vencimento, valor, forma de pagamento, conta bancária de saída e comprovante anexado.
- Parcelamento (uma compra em várias parcelas) e contas recorrentes que se lançam sozinhas todo mês.
- Situação: a pagar, vence hoje, atrasada, paga; agenda dos próximos vencimentos e aviso no Início para quem tem a permissão.
- Baixa com valor pago real (juros, multa ou desconto) e data.
- Fluxo de caixa: a receber (cobranças) contra a pagar, por dia e por mês.

**Fatura do cartão de crédito da empresa**

- Importação da fatura (CSV ou OFX quando o banco oferecer; PDF lido e conferido como nos holerites).
- Cada compra vira uma despesa com data, estabelecimento, valor e parcela ("3/10"); a categoria é sugerida por regras que o sistema aprende ("posto X → combustível").
- A fatura em si vira uma conta a pagar com o vencimento do cartão.
- Pagar a fatura não conta como gasto de novo: o gasto já entrou pelas compras; o pagamento só quita o cartão. Sem essa regra, o resultado do mês mostraria o dobro.
- Compras parceladas aparecem nos meses seguintes como previstas, para o fluxo de caixa.
- Tabelas novas: cartoes (nome, final, dia de fechamento e vencimento) e faturas_cartao (cartão, competência, total, conta a pagar ligada).
