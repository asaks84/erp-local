# Etapa 8: plano do PDV

> Parte da documentação do ERP Local. Índice: [docs/README.md](README.md)

Documento de trabalho da etapa 8 (API e PDV), aberto em 17/11/2026. O desenho geral (API, PIN, caixa, modo de emergência, ticket, app separado) está em [07-pdv.md](07-pdv.md) e continua valendo. Aqui ficam o ponto de partida, o escopo da primeira versão, a ordem de construção e as decisões que ainda faltam. **Nada é construído antes de as perguntas da seção 8 estarem respondidas.**

## 1. Objetivo da primeira versão

O balcão do Local passa a registrar no celular a entrada e a saída dos avulsos, as lavagens e o caixa do turno, com o dinheiro caindo direto no ERP (mesma baixa da gestão, mesmo Resultado e fluxo de caixa).

- **Um aparelho só** (o celular do balcão) e **uma impressora térmica**. Mais aparelhos e mais unidades ficam para depois, mas o desenho já não amarra nada a "um aparelho" (cada aparelho tem o próprio cadastro e token).
- Quatro pessoas atendem pelo mesmo aparelho; cada ação fica no nome de quem digitou o PIN.
- A internet do pátio pode cair: o celular passa para a internet móvel; sem nenhuma internet, o modo de emergência segura a operação.

## 2. Ponto de partida (o que já existe no ERP)

| Já pronto (etapa 4, pela gestão) | Reaproveitado no PDV |
|---|---|
| Estadias: entrada, cálculo pelo tarifador, convênio, saída paga na hora ou na conta, cancelamento (`Estadias`, `Tarifador`) | O PDV chama os mesmos serviços pela API; o servidor continua calculando tudo |
| Estadia já nasce com `uuid` | Base para não duplicar evento reenviado (modo de emergência) |
| Venda de lavagens e serviços com etapas configuráveis e histórico de quem mudou (`Vendas`, `EtapasLavagem`) | Mesmo fluxo, telas de celular |
| Baixa única com forma de pagamento, Resultado, fluxo de caixa (`Baixa`) | Todo recebimento do PDV passa por ela |
| Pix do Inter (boleto com Pix) e baixa automática | Base para o Pix imediato do balcão (seção 5) |
| Página pública com código difícil de adivinhar (`/c/<código>` da cobrança) | Mesmo padrão para o ticket (`/t/<código>`) |
| Numeração própria por empresa e tipo de documento (ticket incluído) | Número do ticket |

**Não existe ainda:** API (rotas com token de aparelho), cadastro de aparelhos, PIN nas pessoas, caixa com turnos (abertura, sangria, suprimento, fechamento), Pix imediato, página do ticket, impressão, o app do PDV e o modo de emergência.

## 3. Escopo

**Entra na primeira versão**

1. Aparelho cadastrado na gestão e pareado ao celular por um código de uso único.
2. PIN de 4 dígitos por pessoa (regras de [07-pdv.md](07-pdv.md)). **Toda ação pede o PIN:** entrada, saída, pagamento, recebimento, mudança de etapa da lavagem, cancelamento, sangria e fechamento. No Local, a memória do PIN fica em "a cada ação" (a opção de lembrar por alguns minutos continua existindo na configuração).
3. Pátio: lista de veículos dentro, com tempo e valor até agora.
4. Entrada: placa (ou identificação sem placa), tipo do veículo vindo do cadastro (trocável), mensalista e convênio reconhecidos pela placa, ticket.
5. Saída: valor calculado pelo servidor, convênio aplicado, pagamento (dinheiro, Pix, cartão de crédito ou débito na maquininha), ou "na conta" para cliente cadastrado.
5a. Receber conta de cliente (mensalidade e ordens em aberto) no balcão, pela mesma baixa da gestão.
6. Lavagens e serviços: venda pela tabela, etapas (entrada, pronta, entregue no Local), pagar agora ou na entrega.
7. Caixa: um fechamento por dia pelo gerente (dinheiro contado, troco deixado, diferença guardada); o caixa seguinte abre com esse troco; sangria e suprimento com PIN de gerente.
8. Pix imediato do Inter com confirmação automática na tela.
9. Ticket: página pública e impressão na térmica.
10. Modo de emergência sem internet.
11. Na gestão: aparelhos, turnos de caixa (relatório e diferenças) e o que veio do PDV marcado como tal.

**Fica para depois**

- Mais de um aparelho trabalhando junto e mais de uma unidade usando o PDV.
- App Android na Play Store (Capacitor) e impressão por Bluetooth clássico/rede pelo app (a não ser que a impressora escolhida obrigue a antecipar; ver seção 6).
- Ticket por WhatsApp ou SMS e aviso de "lavagem pronta" (a régua e o editor de mensagens já existem; entra depois que o básico rodar).
- Validação de convênio por QR, credencial do mensalista (tag/cartão), câmera de placa e cancela.
- Maquininha de cartão integrada (na primeira versão o operador só escolhe a forma "cartão").

## 4. Como fica montado

- **App do PDV separado da gestão:** projeto React próprio (sem Inertia), servido pelo próprio ERP em `/pdv`, instalável como app pelo navegador (PWA). Fala com o servidor só pela API.
- **API:** rotas em `/api/pdv/...`, autenticadas pelo token do aparelho (Laravel Sanctum). O token identifica empresa, unidade e aparelho; o PIN identifica a pessoa em cada ação.
- **Servidor é a autoridade do dinheiro:** com internet, quem calcula é sempre o servidor. O celular só calcula no modo de emergência.
- **Hostinger:** sem WebSocket nem processo rodando o tempo todo. A tela do PDV atualiza consultando o servidor (pátio, Pix); nada depende de conexão aberta.
- **Hora:** o servidor grava a hora dele. O aparelho manda a hora dele junto; se a diferença passar de 2 minutos, o PDV avisa (relógio errado muda o valor da estadia). No modo de emergência vale a hora do aparelho.

**Tabelas novas (rascunho)**

| Tabela | Para quê |
|---|---|
| `aparelhos` | Nome, unidade, situação, último acesso, versão do app; token pelo Sanctum |
| `usuarios.pin_hash` (+ tentativas, travado até) | PIN de cada pessoa |
| `caixa_turnos` | Período entre dois fechamentos: aparelho, aberto em, troco inicial, fechado por/em, contado, troco deixado, esperado, diferença, observação |
| `caixa_movimentos` | Turno, tipo (sangria, suprimento), valor, motivo, quem |
| `pdv_eventos` | UUID, aparelho, tipo, dados, hora do aparelho, situação (aplicado, repetido, com diferença): base do modo de emergência e do "não duplica" |
| `tickets` (ou campos na estadia) | Código público, impresso em, canais |

Pagamento recebido no PDV ganha a origem "PDV" e o turno, para o fechamento somar o dinheiro do turno sem misturar com a gestão.

## 5. Pix no balcão

- A cobrança Pix do balcão é **Pix imediato** (API Pix do Inter, `cob`), não o boleto com Pix: sai na hora, com o valor exato e prazo curto.
- O PDV mostra o QR e o copia e cola e consulta o servidor a cada poucos segundos; o servidor consulta o Inter. Pago: baixa feita, ticket liberado, sem o operador apertar nada.
- O aviso do Inter (webhook) exige um tipo de conexão segura (mTLS) que a Hostinger não oferece; por isso a confirmação é pela consulta. Funciona igual, só gasta mais chamadas, e as chamadas param quando a tela fecha ou o tempo esgota.
- Precisa: a permissão de Pix (cobrança imediata) na integração do Inter e a chave Pix da conta (seção 8).

## 6. Impressão

O ticket é montado sempre do mesmo jeito (comandos ESC/POS); só o caminho até a impressora muda. A escolha depende da impressora:

| Caminho | Funciona com | Observação |
|---|---|---|
| Bluetooth pelo navegador (Chrome no Android) | Só impressoras **BLE** (Bluetooth de baixo consumo) | Sem instalar nada; muitas térmicas baratas não são BLE |
| App intermediário de impressão (ex.: RawBT) | Bluetooth clássico, USB, rede | O PDV manda o ticket para o app, que imprime; instalação única no celular |
| App próprio (Capacitor) | Bluetooth clássico e rede | É o "depois" do plano; antecipar só se nenhum caminho acima servir |
| Impressora de rede (Wi-Fi) | Rede local | Pelo navegador não dá; precisa do app intermediário ou do app próprio |

Celular **Android** é obrigatório para imprimir direto (o iPhone não deixa o navegador falar com impressora Bluetooth).

## 7. Ordem de construção

Cada parte fica utilizável antes da próxima. Testes automáticos em todas, como no resto do ERP.

| Parte | O que entrega | Pronto quando |
|---|---|---|
| 8.1 Base da API | Aparelhos na gestão, pareamento, token, PIN das pessoas, registro de atividades com aparelho e pessoa | Celular pareado chama a API; PIN certo identifica a pessoa, errado trava como previsto |
| 8.2 App e pátio | App instalável, tela do pátio, entrada e saída pagas em dinheiro e cartão | Uma estadia entra e sai pelo celular e aparece igual na gestão e no Resultado |
| 8.3 Caixa e contas | Fechamento diário pelo gerente, sangria, suprimento; receber conta de cliente no balcão; relatório de fechamentos na gestão | Um fechamento bate com os recebimentos em dinheiro do período, separados por pessoa |
| 8.4 Lavagens | Venda, etapas e entrega pelo celular | Lavagem vendida no celular aparece no quadro da gestão e vice-versa |
| 8.5 Pix imediato | QR na tela com confirmação automática | Pix real pago no balcão dá baixa sozinho |
| 8.6 Ticket e impressão | Página `/t/<código>`, impressão na térmica escolhida | Ticket impresso com QR que abre a página certa |
| 8.7 Modo de emergência | Fila no aparelho, tarifador em JavaScript, envio ao voltar, diferenças para conferir | Um dia simulado sem internet sobe sem duplicar e com as diferenças apontadas |

**Portão da etapa 8:** uma a duas semanas de uso real no balcão, com o caixa de cada turno batendo e nenhuma estadia ou lavagem perdida.

## 8. Decisões em aberto (responder antes de construir)

**Respondidas (17/11/2026)**

1. **Celular:** Android. Um Samsung de entrada (Galaxy A07 ou parecido), ainda a comprar. Os testes rodam no Galaxy A56 do Cauê.
2. **Impressora:** ainda a comprar, provavelmente bobina de **80 mm**. Como ainda não foi comprada, a escolha segue a seção 6: comprar depois do teste de impressão (parte 8.6 antecipada para o começo) ou um modelo que já se saiba que funciona por um dos caminhos.
3. **Hoje no balcão:** tudo manual (papel): tickets de estadia e de serviços, fluxo de caixa e fila da lavagem. O sistema atual não tem os avulsos. Consequências: não há histórico de avulsos para migrar; o caixa por turno e o registro no celular são hábito novo para a equipe (precisa de treino antes de valer).
4. **Quando entra:** junto da virada. Até lá, o PDV é testado sem valer (ambiente local ou unidade de teste). Cuidado registrado: virada e PDV no mesmo dia são duas mudanças grandes juntas; por isso a equipe treina no PDV antes, com dados de teste, e a virada só acontece com o PDV já testado no balcão.

5. **Mensalista:** não registra entrada nem saída hoje (talvez no futuro, como opção da empresa). Se o operador digitar a placa de um mensalista ativo, o PDV avisa "mensalista" e não abre estadia, a não ser que ele confirme (ex.: veículo fora do contrato).
6. **Ticket perdido:** cobra pela tabela normal, pelo horário de entrada registrado (a placa acha a estadia). Antes de liberar, o operador registra a **identificação de quem retira**: nome e número de um documento, guardados na estadia junto com o PIN de quem conferiu. Sem foto do documento (guardar só o necessário, LGPD).
7. **Ticket impresso:** placa e modelo; tamanho do veículo (o tipo de veículo do cadastro, que define o preço: ex. pequeno, médio, grande); tipo de serviço (estadia ou o serviço/lavagem); dia e hora de entrada; número; QR Code; um espaço em branco para o **carimbo do convênio**. Consequências:
    - o modelo do veículo vem do cadastro; para avulso, a entrada ganha um campo **modelo** (opcional, digitado ou escolhido de uma lista curta);
    - o número impresso é o do ticket (estadia) ou o da ordem de serviço (lavagem), ambos da numeração própria da empresa;
    - o carimbo continua valendo como hoje; a validação do convênio por QR fica para depois.

8. **Impressão:** sempre perguntando ("Imprimir o ticket?"), tanto na entrada quanto no recibo da saída e do pagamento.
9. **Caixa:** um **fechamento por dia**, feito pelo gerente; troco contado.
    - Exemplo do Local: o gerente fecha o caixa para conferência antes de o vigia entrar. O vigia atende a noite (recebe e registra, inclusive mensalidade), mas não fecha. O próximo fechamento é de novo do gerente, no fim do turno dele, e cobre a noite do vigia e o dia do gerente.
    - Então o "caixa" é o período entre dois fechamentos, não o turno de cada pessoa; cada recebimento continua no nome de quem digitou o PIN, e o relatório do fechamento separa por pessoa.
    - No fechamento, o gerente informa o dinheiro contado e quanto deixa de troco na gaveta; a diferença (contado × esperado) fica guardada com o nome dele. O caixa seguinte abre sozinho com esse troco.
    - O PDV tem um botão **Fechar caixa** sempre à vista (pede PIN de gerente ou administrador).
10. **Sangria, suprimento, fechar caixa, cancelar estadia ou venda e dar desconto:** só com PIN de **gerente ou administrador**.
11. **Atendentes:** cada um vira usuário com o nível **operador**, configurado por usuário na gestão (PIN para o PDV; entrar na gestão é opção do usuário).
12. **Cartão:** **crédito e débito separados** (taxas diferentes no Resultado).
13. **Pix:** a integração do Inter (ERP Local Estacionamento) já tem a permissão **"Receber e enviar pagamentos por Pix"**. Apesar do nome, os itens dela são todos do lado de receber: cobranças imediatas (a do PDV) e com vencimento, consulta de Pix recebidos, webhooks e payloads, e devolução de Pix recebido (só para quem pagou). Nenhum envia Pix para outra conta. A chave Pix da conta PJ é escolhida em Configurações › Banco Inter quando o Pix do PDV for construído (parte 8.5).

**Consequência para o escopo:** o vigia recebe mensalidade no balcão. A primeira versão do PDV ganha **receber conta de cliente**: buscar o cliente pela placa ou nome, ver as ordens e cobranças em aberto e receber (mesma baixa da gestão, com o turno e o PIN).

## 8a. Fluxo da estadia avulsa (desenho do Cauê, 17/11/2026)

**Entrada:** cliente chega → tela inicial com dois botões, **Estadia** ou **Lavagem** → Estadia → placa, modelo e tamanho do veículo → gera o ticket (pergunta se imprime) e registra a entrada.

**Diária na entrada (17/11/2026):** a estadia é **por hora** por padrão, com uma opção para **marcar diária** (as diárias da tabela daquele tamanho, ex.: "Diária 12h · R$ 25"). Marcada, o preço fica fechado mesmo se o cliente sair antes. O pagamento fica à escolha: na entrada (com recibo) ou na saída. Se passar da duração da diária, vale a regra que já existe na tabela para quem passa do maior pacote (Configurações › Tabelas de estadia).

**Saída:** cliente volta → **lê o ticket** → o sistema calcula a estadia → **modalidade**: convênio (de um cliente cadastrado), lançamento na conta de um cliente (ex.: mensalista) ou cobrança direta → **forma de pagamento**: Pix, dinheiro, cartão de crédito, cartão de débito e as demais formas habilitadas → confirma o pagamento ou o lançamento → **recibo** (pergunta se imprime) → fim.

**Como o sistema atende cada passo**

- **Placa conhecida preenche sozinho:** placa de cliente cadastrado traz modelo e tamanho do cadastro; placa avulsa que já veio antes traz o modelo e o tamanho da última vez. O operador só confere. Placa de mensalista avisa "mensalista" (decisão 5).
- **Ler o ticket:** pela câmera do celular, lendo o QR do ticket; sem o papel, digitando o número do ticket ou a placa (a lista do pátio também abre a saída com um toque). Ticket perdido segue a decisão 6.
- **Convênio escolhido na saída**, não na entrada: é na saída que o cliente traz o ticket carimbado. A entrada fica mais rápida (sem perguntar convênio); se algum convênio precisar ser marcado já na entrada, o campo fica opcional lá. *(confirmado em 17/11/2026)*
- **Convênio + o que sobra:** quando o convênio paga só uma parte (parcial ou tempo), a tela mostra as duas partes e o excedente segue para a forma de pagamento (cobrança direta).
- **Dinheiro com troco:** o operador digita quanto recebeu e o PDV mostra o troco. *(confirmado em 17/11/2026)*
- **Pix:** QR na tela e confirmação automática (seção 5); cartão: só registra crédito ou débito (a maquininha não é integrada).
- **Lançamento na conta:** o campo "Na conta de" (qualquer cliente, como na gestão).

## 8b. Fluxo da lavagem (desenho do Cauê, 17/11/2026)

**Entrada:** cliente pede lavagem → botão **Lavagem** → placa, modelo e tamanho → celular/WhatsApp (opcional) → registra o serviço e gera o ticket.

**Saída:** cliente volta → lê o ticket da lavagem → gera a cobrança (mesma lógica da estadia) → modalidade: pagamento direto, convênio ou conta de um cliente (ex.: mensalista) → forma de pagamento, quando houver → finaliza e emite o recibo.

**Como o sistema atende**

- Mesmo preenchimento automático da estadia (placa conhecida traz modelo e tamanho; avulso que já veio traz também o celular da última vez).
- O celular diz para que serve (avisar quando a lavagem ficar pronta e mandar o ticket), por causa da LGPD.
- Convênio, conta de cliente, troco e Pix iguais aos da estadia; o convênio é escolhido na saída.
- O ticket da lavagem sai com o número da ordem de serviço e o serviço escolhido.
- **Serviço e adicionais:** depois de tocar em Lavagem, o operador escolhe o serviço (simples, completa...) e marca os adicionais, vendo o total pelo tamanho do veículo.
- **Pronta:** lista de lavagens em andamento no PDV; um toque em **Pronta** (com PIN); a saída marca **entregue**. Prepara o aviso de "lavagem pronta" por WhatsApp, para depois.
- **Estadia depois de pronta (configurável):** a empresa escolhe se cobra ou não; se cobra, depois de uma tolerância (minutos, configurável) contada de quando ficou pronta, o tempo passa a contar como estadia pela tabela normal do tamanho do veículo e entra na mesma saída.
- **Pagar agora:** na entrada, botão de exceção para quem quer pagar ao deixar o carro; o padrão é pagar na saída.
- *(confirmado em 17/11/2026)*

**Mudanças no ERP antes do PDV (17/11/2026)**

O PDV usa as mesmas regras da gestão, então estas entram primeiro (ver [05-regras-de-negocio.md](05-regras-de-negocio.md)):

- "Conta de:" na venda e na saída da estadia (lançar na conta de qualquer cliente).
- Convênio por serviço (integral, parcial com teto em R$, limite de tempo na estadia) e fechamento programado.

## 9. Riscos e cuidados

- **Impressora:** é o ponto que mais pode atrasar. Testar a impressora escolhida logo no começo (um ticket de teste) antes de construir o resto em cima.
- **Dois tarifadores:** o do servidor (PHP) e o de emergência (JavaScript) precisam dar o mesmo valor. Uma lista única de casos de teste roda nos dois; se divergirem, a publicação para.
- **PIN fraco ou emprestado:** as regras de [07-pdv.md](07-pdv.md) (PIN óbvio recusado, PIN descoberto cancelado, trava após 5 erros) e o registro de atividades por pessoa.
- **Celular perdido ou trocado:** o aparelho é desativado na gestão e o token deixa de valer na hora; um novo é pareado com outro código.
- **Virada junto com o PDV:** a equipe passa do papel para o celular no mesmo período em que o ERP vira oficial. Treinar com dados de teste antes e ter o papel como reserva nos primeiros dias.
