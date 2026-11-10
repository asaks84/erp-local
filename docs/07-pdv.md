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

**Quem atendeu (identificação no PDV)**

O jeito de identificar quem fez cada ação é uma configuração da empresa (ou da unidade):

- **Por PIN** (o padrão do Local): o aparelho do balcão fica sempre conectado como aparelho, sem login de pessoa. Cada ação (entrada, saída, venda, recebimento, mudança de etapa da lavagem) pede o PIN de 4 dígitos de quem está atendendo, e a ação fica no nome dessa pessoa. Quem digitou é o responsável; cada um cuida do próprio PIN.
- **Por operador de caixa**: uma pessoa abre o turno e tudo sai no nome dela até o fechamento.
- **Tempo de memória do PIN**: configurável, de "a cada ação" (padrão) até alguns minutos; tocar em "trocar" pede o PIN de novo na hora.
- **Regras do PIN**:
  - a pessoa escolhe o próprio PIN; PINs óbvios (0000, 1234, 1111...) são recusados;
  - PIN é único na empresa; se alguém escolher um PIN que já é de outra pessoa, o sistema responde só "PIN indisponível, escolha outro" e **cancela o PIN antigo** (que acabou de ser descoberto); o dono cadastra um novo no próximo uso;
  - 5 PINs errados seguidos travam o aparelho por 1 minuto e vão para o registro de atividades;
  - sem internet, o aparelho confere o PIN por uma cópia protegida (hash) guardada nele.
- **Caixa**: uma gaveta só, do aparelho, dividida em turnos. Na troca de turno, quem fecha informa o dinheiro contado; o sistema mostra o esperado (troco inicial + recebido em dinheiro − sangrias) e guarda a diferença com o nome. Cada recebimento continua com o nome de quem digitou o PIN.
- O sistema registra quem **digitou**: a regra de uso é "cada um registra o que fez". Se um dia precisar, a etapa da lavagem ganha um campo "executado por".

**Modo de emergência (sem internet)**

1. O PDV grava entrada, saída e pagamento no próprio aparelho, cada um com seu UUID e a hora do aparelho.
2. Na saída, mostra um valor estimado pela cópia da tabela guardada no aparelho, marcado "a confirmar".
3. Quando a conexão volta, envia tudo em ordem; o servidor recalcula e aponta diferenças para o gerente conferir.
4. Enviar o mesmo evento duas vezes não duplica nada: o UUID já existe e o servidor ignora a repetição.

**Ticket:** impresso na térmica Bluetooth quando houver; sem impressora, vale a placa, o QR Code na tela ou o ticket enviado por WhatsApp.
