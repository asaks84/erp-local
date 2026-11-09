# Comparativo com o mercado

> Parte da documentação do ERP Local. Índice: [docs/README.md](README.md)

O modelo está alinhado ao mercado em empresa e unidade, alocação de pagamento parcial e convênio como pagador separado. As maiores diferenças estão em cinco pontos: fatura que muda depois de emitida, falta de plano de contas e centro de custo, falta de contas bancárias com conciliação, preços sem vigência e ordem de serviço misturada com item de cobrança.

Referências: ERPs (Odoo, Omie, SAP), plataformas de cobrança recorrente (Stripe Billing, Chargebee) e sistemas de estacionamento.

| Aspecto | Como o mercado faz | Nosso modelo | Recomendação |
|---|---|---|---|
| Fatura emitida | Rascunho editável; depois de emitida fica imutável, com número e itens copiados; correção por nota de crédito ou cancelamento e reemissão (Stripe) | A ordem em aberto sai de uma fatura e entra na próxima | **Adotar agora:** fatura com itens copiados (fatura_itens); o que sobra entra na próxima como linha "saldo anterior" e a antiga fica como emitida |
| Ajustes e estornos | Nota de crédito ou lançamento de estorno; nada é editado | Cancelamento com motivo; crédito e débito como ordens | **Adotar agora:** documento de ajuste (crédito, débito, estorno) ligado à fatura ou ao pagamento |
| Conta corrente do cliente | Saldo calculado de um livro de movimentos imutável, aplicado na próxima fatura (Stripe); razão do parceiro (Odoo) | Saldo calculado somando ordens abertas menos créditos | **Adotar agora:** tela de extrato do cliente (cobrado, pago, ajustes, saldo) a partir das tabelas existentes |
| Plano de contas | Categorias de receita e despesa em árvore, baseadas no plano referencial, só administrador ou contador altera (Omie) | Categorias só em despesas, sem hierarquia | **Adotar agora:** plano de contas único para receitas e despesas, em árvore; cada serviço e cada despesa aponta para uma conta; dá a DRE |
| Centro de custo | Departamento ou centro de custo em cada lançamento | Unidade em cada registro | **Adotar agora:** unidade funciona como centro de custo; espaço para centro de custo extra (lavagem, estacionamento) |
| Contas bancárias e conciliação | Cadastro de contas (caixa, bancos, cartão), extrato importado ou conectado, cada linha conciliada com um lançamento (Omie, Odoo) | Extrato só para PIX recebido; pagamento sem conta de destino | **Adotar agora:** contas_financeiras + movimentos; todo pagamento e toda despesa paga diz em qual conta entrou ou saiu; conciliação para entradas e saídas |
| Preços | Lista de preços separada do catálogo, com validade (início e fim) e por grupo de cliente | Preço atual por tipo e unidade, sem histórico | **Adotar agora:** vigência em preços, tarifas e contratos; o reajuste cria nova versão em vez de sobrescrever |
| Assinatura (mensalidade) | Assinatura com plano, preço versionado, data-âncora, proporcional, pausa e cancelamento agendado | Contrato com valor, início, fim e regras de proporcional | **Adotar depois:** pausa e cancelamento agendado; o resto já está coberto |
| Régua de cobrança | Lembretes antes e depois do vencimento, novas tentativas, suspensão automática (Stripe, Chargebee) | Avisos de WhatsApp planejados, sem regra | **Adotar com o WhatsApp:** tabela de régua (D-3, D0, D+3, D+10) e registro de cada contato |
| Situação do pagamento | Intenção de pagamento com pendente, confirmado, falhou, estornado | Pagamento só existe quando confirmado | **Adotar com o Inter:** situação no pagamento (pendente, confirmado, estornado) para Pix, boleto e cartão |
| Fiscal | Código de serviço municipal, alíquota de ISS, nota ligada à fatura | Sem campos fiscais | **Adotar agora (só os campos):** código de serviço e ISS no serviço, tabela notas_fiscais ligada à fatura; a emissão fica para depois |
| Ordem de serviço | OS separada da cobrança: etapas, responsável, horários, itens consumidos; gera itens faturáveis | "Ordem" é o item cobrado e também a lavagem em execução | **Adotar agora:** separar ordens_servico (execução: etapa, funcionário, fotos, horários) de lancamentos (o que é cobrado) |
| Numeração | Sequência por empresa e tipo de documento, sem buracos | Ids do banco | **Adotar agora:** numeração própria por empresa para fatura, OS e ticket |
| Estacionamento | Vagas e capacidade, ocupação em tempo real, credencial do mensalista (tag, cartão, placa), validação de convênio por código ou QR, câmera de placa e cancela | Sem vagas, sem credencial, convênio sem validação | **Adotar agora:** capacidade por unidade e tipo; **depois:** credenciais e validação de convênio por QR com o PDV |

**O que não recomendo copiar:** contabilidade completa em partidas dobradas, como SAP e Odoo fazem. Para o seu porte, plano de contas, centro de custo e conciliação dão a DRE e o fluxo de caixa; a contabilidade oficial continua com o contador, que recebe os relatórios exportados.

**Efeito no modelo de dados se as recomendações forem aceitas**

- Novas tabelas: cobranca_itens, ajustes, plano_contas, contas_financeiras, movimentos_financeiros, notas_fiscais, numeracoes, regua_cobranca.
- **Decidido (09/11/2026):** a ordem de serviço **não** é separada da cobrança como nos ERPs: no Local, ordem de serviço é todo item que o cliente deve, e a etapa da lavagem (aguardando, lavando, pronta, entregue) fica na própria ordem. "Fatura" passa a se chamar **cobrança**.
- Preços, tarifas e contratos ganham início e fim de vigência.
- Pagamentos e despesas ganham conta financeira e situação.

Fontes: [Stripe — finalização e situações da fatura](https://docs.stripe.com/invoicing/integration/workflow-transitions) · [Stripe — saldo do cliente](https://edge-docs.stripe.com/invoicing/customer/balance) · [Omie — categorias](https://ajuda.omie.com.br/pt-BR/articles/498765-o-que-sao-categorias) · [Omie — conciliação bancária](https://www.omie.com.br/blog/conciliacao-bancaria/) · [Odoo — conciliação automática](https://www.ksolves.com/blog/odoo/understanding-odoo-auto-reconciliation-engine)

**Situação (09/11/2026): todas as linhas decididas.** Adotadas: cobrança emitida imutável, ajustes e estornos, extrato da conta do cliente, plano de contas, centro de custo (opcional, lista cadastrada), contas financeiras e conciliação (com formas de pagamento, taxas e prazos), vigência de preços, pausa e cancelamento agendado, régua de cobrança (com o WhatsApp), situação do pagamento (com o Inter), campos fiscais, numeração própria, capacidade e ocupação (validação de convênio por QR com o PDV; credencial e câmera como futuro). Não adotada: separar ordem de serviço do item cobrado. Onde a tabela diz "fatura", ler "cobrança". Detalhes em [12-decisoes.md](12-decisoes.md).