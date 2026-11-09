# Glossário

> Parte da documentação do ERP Local. Índice: [docs/README.md](README.md)

Os termos abaixo são os que o Local já usa no dia a dia. Telas, código, banco e documentação seguem estes nomes.

| Termo | O que é |
|---|---|
| **Ordem de serviço (OS)** | Todo item que o cliente deve: ordem de mensalidade, de estadia, de lavagem/serviço, além de débitos e créditos. Situação: aberta, paga ou cancelada (com motivo). Na lavagem, também tem a etapa: aguardando, lavando, pronta, entregue. Tabela `ordens_servico`. |
| **Conta do cliente** | O conjunto das ordens de serviço em aberto de um cliente. O cliente sempre deve as ordens em aberto. |
| **Cobrança** | Agrupa as ordens de serviço em aberto de um cliente (ou de um convênio) num momento, com número e vencimento, e gera o boleto/Pix. Mensalista: uma cobrança por mês. O que não for pago continua em aberto e entra na próxima cobrança. Tabela `cobrancas`. |
| **Pagamento** | Dinheiro recebido, com forma (dinheiro, Pix, cartão, boleto). Dá baixa em uma ou mais ordens de serviço; pode ser parcial. |
| **Baixa** | Marcar ordens de serviço como pagas por um pagamento. |
| **Cancelar ordem** | Tirar uma ordem em aberto da conta sem pagamento (ex.: mensalidade de quem deixou de ser mensalista). Exige permissão, motivo e fica registrado. Nunca apaga. |
| **Estadia** | Entrada e saída de um veículo no pátio (ticket). Ao fechar, gera uma ordem de estadia. |
| **Mensalidade (contrato)** | O plano do veículo mensalista: período, valor, início e fim. Gera uma ordem de mensalidade por mês. |
| **Convênio** | Cliente (empresa) que paga, total ou parcialmente, pelo uso de outras pessoas. |
| **Tabela de estacionamento (tarifa)** | Regras de preço da estadia por tempo: tolerância, faixas, diárias, pernoite. |
| **Serviço principal / adicional** | Lavagens e o que se soma a elas (cera, limpeza pesada), com preço por tipo de veículo. |
| **Empresa / unidade** | Empresa = o negócio (hoje só o Local). Unidade = cada pátio. |
| **Caixa** | Turno de quem recebe dinheiro no PDV: abertura, sangria, suprimento, fechamento. |
| **Conta a pagar / despesa** | O que a empresa deve pagar (fornecedores, funcionários, impostos). |
| **Fatura do cartão** | Só a fatura do cartão de crédito **da empresa**, em contas a pagar. Não é usada para clientes. |
