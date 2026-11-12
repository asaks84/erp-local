# Plano de etapas e virada

> Parte da documentação do ERP Local. Índice: [docs/README.md](README.md)

A migração vai em oito etapas, cada uma utilizável antes da próxima; o sistema atual só é desligado depois que os números dos dois baterem por algumas semanas.

1. **Fundação:** projeto Laravel, login, níveis de acesso, empresa e unidade, layout atual, script de migração dos dados com relatório de comparação.
2. **Cadastros e configurações:** clientes, veículos, contratos de mensalidade, fila de cadastro, tipos de veículo, tarifas, serviços, convênios, usuários.
3. **Dinheiro:** ordens de serviço, geração de mensalidades e primeira cobrança, baixa unificada com forma de pagamento, importação de extrato, cobranças, contas em aberto e relatórios. (O fechamento de convênio foi para a etapa 4.)
    - Portão: totais em aberto e recebidos iguais aos do sistema atual.
4. **Operação na gestão:** estadias com entrada e saída, venda de lavagens e serviços (com etapa da lavagem), fechamento de convênio.
5. **Paralelo e virada:** sistema novo num subdomínio, usado lado a lado com o atual; depois da virada, o CodeIgniter fica só para consulta.
    - Portão: algumas semanas sem diferença nos relatórios; backup e plano de volta prontos.
6. **Gestão de gastos e contas a pagar:** funcionários, holerites, fornecedores, contas a pagar, fluxo de caixa e resultado do mês.
7. **API e PDV:** aparelhos, identificação por PIN ou operador de caixa (configurável), caixa com turnos e conferência, estadias e vendas pelo celular, modo de emergência.
8. **Integrações:** boleto com Pix e baixa automática pelo Inter, avisos e link de pagamento pelo WhatsApp.

Durante as etapas 1 a 5, o sistema atual continua sendo o oficial e só recebe correções.

## Situação

| Etapa | Situação |
|---|---|
| 1. Fundação | pronta para conferência (09/11/2026) |
| 2. Cadastros e configurações | pronta para conferência (10/11/2026) |
| 3. Dinheiro | pronta para conferência (10/11/2026); portão cumprido com os dados reais (cópia local): ordens, em aberto e recebido mês a mês iguais |
| 4. Operação na gestão | pronta para conferência (10/11/2026): estadias (entrada, saída pelo tarifador, convênio), lavagens e serviços com quadro de etapas, fechamento de convênio pela cobrança |
| 5. Paralelo e virada | paralelo iniciado em 12/11/2026: ERP no ar em erp.localestacionamento.com.br, primeira conferência igual ao sistema atual; espelho de hora em hora e backup diário rodando pelo cron; faltam as semanas de conferência sem diferença |
| 6. Gastos e contas a pagar | em andamento (12/11/2026): plano de contas, fornecedores e contas a pagar (parcelas, fixas, pagamento, comprovante) prontos; funcionários, recibos de salário, diárias e leitura de boleto/guia (com OCR) prontos; resultado do mês e fluxo de caixa prontos; falta a fatura do cartão |
| 7. API e PDV | não iniciada |
| 8. Integrações | não iniciada |
