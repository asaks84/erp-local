# PDV

> Parte da documentação do ERP Local. Índice: [docs/README.md](README.md)

O PDV é online primeiro: o celular com 4G manda os eventos e o servidor calcula tudo; sem internet, ele só guarda eventos e envia quando a conexão volta. Não existe servidor local no pátio.

**O que a API oferece ao PDV**

- Entrada: placa, tipo, hora → cria a estadia e devolve o ticket (número, QR Code). O tipo vem do cadastro, mas o operador pode trocar; tipo sem tabela de estadia não entra.
- Celular do cliente (opcional) na entrada da estadia e na venda de lavagem, para o ticket e o aviso de lavagem pronta, dizendo para que serve o número (LGPD).
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

O PDV não tem banco próprio: com internet, toda operação vai direto para o ERP pela API e o servidor calcula com os dados do momento. A cópia local só serve para quando a internet cai.

- **Cópia local, atualizada ao iniciar a operação do dia** (e por um botão "Atualizar tabelas"): tarifas por tipo de veículo, tabela de serviços e lavagens, convênios, placas de mensalistas ativos e os PINs protegidos (hash). Preço muda de um dia para o outro, não no meio do dia.

1. Sem internet, o PDV grava numa fila do aparelho as entradas, saídas, pagamentos e lavagens, cada um com seu UUID e a hora do aparelho.
2. Na saída, calcula a cobrança pela cópia local e cobra esse valor, marcado "a confirmar".
3. Quando a conexão volta, envia a fila em ordem; o servidor recalcula cada cobrança e aponta as diferenças para o gerente conferir (nunca altera sozinho o que foi cobrado).
4. Enviar o mesmo evento duas vezes não duplica nada: o UUID já existe e o servidor ignora a repetição.

**Ticket: uma página com link próprio**

Cada estadia ganha uma página pública com um código difícil de adivinhar (ex.: `/t/x7k2p9`): placa, hora de entrada, valor até agora e o QR Code da saída. Os canais só entregam essa mesma página:

- **Bobina (o padrão do negócio):** imprime os dados e um QR que aponta para a página.
- **QR na tela:** o cliente aponta a câmera do celular dele para a tela do PDV e abre a página. Sem custo e sem telefone.
- **WhatsApp ou SMS:** o servidor manda o link por um provedor de mensagens (modelo "utilidade", texto só informativo, sem promoção).

WhatsApp e QR na tela são opcionais, ligados por estacionamento nas configurações; no SaaS, o WhatsApp é módulo adicional com o custo das mensagens repassado. A placa continua sendo a identificação principal na saída.

**Desenho para não reescrever o PDV (app na Play Store depois)**

O PDV começa como PWA e mais tarde é empacotado como app Android (Capacitor), para imprimir por Bluetooth clássico e por rede/Wi-Fi. Para essa troca ser só empacotamento, quatro regras:

1. **PDV é um app separado da gestão:** projeto React próprio, sem Inertia, que só conversa com a API pelo token do aparelho.
2. **Impressão atrás de uma porta única:** o ticket é sempre montado do mesmo jeito (comandos ESC/POS gerados no PDV); só o transporte muda. Agora: Bluetooth pelo navegador (só impressoras BLE). No app: Bluetooth clássico e rede (porta 9100).
3. **Armazenamento local atrás de outra porta:** a fila do modo de emergência fica no IndexedDB, que funciona igual dentro do app; trocar por SQLite nativo, se precisar, não mexe no resto.
4. **O servidor é a autoridade do dinheiro:** com internet, quem calcula é sempre o servidor. O PDV só calcula sem internet, com um tarifador em JavaScript que repete o do servidor; uma lista única de casos de teste ("carro, entrou 08:00, saiu 10:17, convênio X → R$ Y") roda nos dois, e se um errar a publicação para.

Impressoras no SaaS: lista de modelos homologados (testados), não "qualquer impressora". O provedor de WhatsApp/SMS também fica atrás de uma porta única, para trocar de fornecedor sem mexer no PDV.
