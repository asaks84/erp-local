# PDV

> Parte da documentação do ERP Local. Índice: [docs/README.md](README.md)

O PDV é online primeiro: o celular com 4G manda os eventos e o servidor calcula tudo; sem internet, ele só guarda eventos e envia quando a conexão volta. Não existe servidor local no pátio.

**O que a API oferece ao PDV**

- Entrada: placa, tipo, hora → cria a estadia e devolve o ticket (número, QR Code).
- Saída: estadia → devolve o valor já com tarifa e convênio aplicados.
- Pagamento: valor, forma, caixa → baixa pelo serviço único.
- Venda de serviço: placa, serviço, adicionais → ordem de serviço com preço da tabela.
- Caixa: abrir, sangria, suprimento, fechar com saldo informado.
- Consulta: veículos no pátio, mensalista ativo, convênio da placa.

**Modo de emergência (sem internet)**

1. O PDV grava entrada, saída e pagamento no próprio aparelho, cada um com seu UUID e a hora do aparelho.
2. Na saída, mostra um valor estimado pela cópia da tabela guardada no aparelho, marcado "a confirmar".
3. Quando a conexão volta, envia tudo em ordem; o servidor recalcula e aponta diferenças para o gerente conferir.
4. Enviar o mesmo evento duas vezes não duplica nada: o UUID já existe e o servidor ignora a repetição.

**Ticket:** impresso na térmica Bluetooth quando houver; sem impressora, vale a placa, o QR Code na tela ou o ticket enviado por WhatsApp.
