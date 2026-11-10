# Registro de decisões

> Parte da documentação do ERP Local. Índice: [docs/README.md](README.md)

Cada decisão com data e motivo. Decisão nova entra no topo. Para mudar uma decisão, registrar a nova em vez de apagar a antiga.

| Data | Decisão | Motivo |
|---|---|---|
| 10/11/2026 | Conta paga nos dois sistemas (baixa no ERP e no sistema atual): vale o pagamento do ERP; a importação não recria o do sistema atual | Evitar recebido em dobro durante o uso em paralelo |
| 10/11/2026 | Ordem importada mantém o **mesmo número** do sistema atual (ord_id); as novas continuam a sequência. Ordem lançada só no ERP com número que coincida com um antigo ganha número novo na importação | Mesmo motivo do código do cliente; nunca dois documentos com o mesmo número |
| 10/11/2026 | O sistema atual não guardava pagamentos: na importação, as ordens pagas de um cliente no **mesmo dia e hora** viram **um pagamento** com forma "não informada" e o valor das ordens (crédito usado abate) | Reconstrói o histórico sem inventar forma nem valor; "recebido no mês" bate centavo a centavo |
| 10/11/2026 | Até a virada o sistema atual manda: pagamentos importados são refeitos a cada importação e **não podem ser estornados no ERP**; ordem apagada lá sai daqui (se não tiver sido usada no ERP); ordem recebida no ERP continua paga | Importação repetível sem duplicar; fase de paralelo segura |
| 10/11/2026 | Créditos em aberto **sempre abatem** na baixa (igual ao sistema atual). Sobra: crédito ou serviço feito na hora (já pago); no extrato também "não lançar". Falta: débito para a próxima cobrança ou desconto (permissão `desconto`, com motivo). Pagar só parte = desmarcar as contas que ficam | Regras do sistema atual + registro do desconto (antes ficava sem rastro) |
| 10/11/2026 | Estorno de pagamento (permissão `contas_editar`, com motivo): as ordens voltam a ficar em aberto e o crédito/débito nascido dele é cancelado; bloqueado se o crédito já foi usado em outro pagamento | Corrigir baixa errada sem apagar dinheiro |
| 10/11/2026 | Cobrança tem situação **paga**, **parcialmente paga**, **levada para a próxima** (nada pago, tudo foi para a cobrança seguinte), **em aberto** e **cancelada**. Uma por cliente e mês; cancelar só sem nada pago, e as contas voltam para a cobrança anterior | Mostrar o que aconteceu com cada cobrança emitida |
| 10/11/2026 | Geração do mês: valor da versão do contrato vigente no vencimento; mês pausado gera a taxa de reserva (taxa 0 não gera); contrato **novo no ERP** que começou no mês aparece desmarcado (a primeira cobrança decide); contrato importado segue a regra do sistema atual | Evitar cobrar o mês cheio de quem entrou no meio do mês |
| 10/11/2026 | Primeira cobrança: depois de cadastrar mensalidade (veículo, ficha ou fila), a tela de opções abre sozinha. Sem a permissão `mensalidade_valor`: só proporcional, proporcional + próximo ou mês cheio, sem mudar o valor | Igual ao sistema atual |
| 10/11/2026 | Fechamento de **convênio** passa para a etapa 4 (depende das estadias registradas no ERP) | Sem estadias no ERP não há o que fechar |
| 10/11/2026 | Forma "não informada" existe só para os importados (inativa, não aparece ao receber). Formas e contas iniciais: Dinheiro → Caixa; Pix, débito, crédito, boleto e transferência → Banco (editáveis) | Começar usando sem configurar nada |
| 10/11/2026 | Cliente importado mantém o **mesmo código** do sistema atual; os novos continuam a numeração | É como a equipe conhece e busca o cliente |
| 10/11/2026 | "Remover" cliente ou veículo = desativar (encerra a mensalidade no dia, guarda histórico, pode reativar). Placa só não se repete entre veículos ativos | A partir da etapa 3 há dinheiro ligado ao cliente; apagar quebraria o histórico |
| 10/11/2026 | Mensalidade é um contrato por veículo; reajuste = nova versão a partir de uma data; encerramento agendado = data de fim no contrato; pausa = meses inteiros com taxa (valor ou %) | Decisões do comparativo (vigência, pausa cobrada, cancelamento agendado) aplicadas |
| 10/11/2026 | Períodos de mensalidade (Diurno, Noturno, 24 horas), dia de vencimento padrão e taxa de pausa padrão são configurações da empresa | Nada de texto comercial fixo no código |
| 10/11/2026 | Cadastro público pelo endereço `/cadastro/<código>` (código gerado na tela da empresa, com QR Code); trocar o código invalida o QR antigo. Aprovado ou recusado, o cadastro sai da fila | Preparado para várias empresas; LGPD (não guardar dados de quem não virou cliente) |
| 10/11/2026 | CPF/CNPJ conferido pelos dígitos verificadores nos cadastros novos; os importados entram como estão | Evitar erro de digitação sem barrar os dados antigos |
| 10/11/2026 | Preços e tabelas importados valem "desde sempre" (01/01/2000); a partir daí, cada reajuste é uma versão com data | O sistema atual não tem vigência |
| 10/11/2026 | A primeira cobrança (aprovar cadastro ou adicionar veículo) e a geração das mensalidades entram na etapa 3, junto com as ordens de serviço | É dinheiro: fica no mesmo pacote das cobranças e da baixa |
| 09/11/2026 | Versões da etapa 1: Laravel 13, Inertia 3, React 19, Bootstrap 5.3, Vite 8; fonte Inter servida pelo próprio sistema | Versões atuais; sem Google Fonts a CSP fica mais simples e as telas não dependem de site externo |
| 09/11/2026 | Login único no sistema todo (não por empresa) | O usuário entra sem escolher empresa; no SaaS, se precisar, a empresa vem pelo endereço (subdomínio) |
| 09/11/2026 | Usuário não é excluído, é desativado | Mantém o histórico do registro de atividades e, nas próximas etapas, de quem lançou cada dinheiro |
| 09/11/2026 | Ambiente local em `http://erplocal.localhost` (Virtual Host do WAMP apontando para `public`) | Igual à produção, que vai num subdomínio; evita `/public` no endereço |
| 09/11/2026 | Testes automáticos em MySQL (`erp_teste`), não SQLite | Mesmo banco da produção; a importação depende de comandos do MySQL |
| 09/11/2026 | Recursos de estacionamento: capacidade e ocupação por unidade e tipo agora; validação de convênio por QR junto com o PDV; credencial do mensalista (tag/cartão) e câmera de placa/cancela registrados como futuro (comparativo, ponto 12) | Ocupação útil já; o resto depende de PDV ou hardware |
| 09/11/2026 | Campos fiscais preparados (código de serviço municipal e alíquota de ISS no serviço; tabela de notas fiscais ligada à cobrança, sem emissão) e numeração própria por empresa e tipo de documento (cobrança, OS, ticket), sem buracos (comparativo, ponto 11) | Deixar a NFS-e pronta para o futuro; número que o cliente vê, por empresa no SaaS |
| 09/11/2026 | Situação do pagamento (pendente, confirmado, falhou/expirado, estornado) junto com o Inter (etapa 8); baixa manual continua nascendo confirmada (comparativo, ponto 10) | Saber quais boletos/Pix estão emitidos aguardando pagamento |
| 09/11/2026 | Régua de cobrança junto com o WhatsApp (etapa 8): etapas configuráveis (padrão inicial: 3 dias antes, no dia, 3 e 10 dias depois), textos editáveis, para quando o cliente paga, registro de cada aviso, clientes que não recebem avisos (comparativo, ponto 9) | Reduzir atraso sem cobrança manual constrangedora |
| 09/11/2026 | Sem alerta de "possível desistência": o cliente fica **inadimplente a partir do primeiro vencimento não pago** e a cobrança é feita pelos funcionários (lista de inadimplentes) ou pelo WhatsApp (régua) | Resposta do Cauê: inadimplência já dispara a cobrança |
| 09/11/2026 | Contrato de mensalidade com pausa (período sem a mensalidade cheia; pausa pode ser cobrada com taxa de reserva fixa ou %, padrão configurável, zero = grátis) e cancelamento agendado (data de encerramento registrada antes) (comparativo, ponto 8) | Útil principalmente para o SaaS; no Local os clientes costumam sair sem avisar |
| 09/11/2026 | Preços, tabelas de estacionamento e contratos de mensalidade com vigência (início/fim); reajuste = nova versão com data de início, cadastrada antes; o sistema troca sozinho na data (comparativo, ponto 7) | Planejar o reajuste antes da mudança; histórico de reajustes; relatórios antigos continuam batendo |
| 09/11/2026 | Contas financeiras (caixa, bancos, maquininha) com saldo, transferências e conciliação de entradas e saídas pelo extrato (comparativo, ponto 6) | Saber quanto dinheiro existe e onde; base do fluxo de caixa |
| 09/11/2026 | Tela de formas de pagamento aceitas, cada uma com conta de destino, taxa (% e fixa, por parcela no crédito), prazo de recebimento em dias (número digitado, sem lista fixa) e ativa/inativa; o sistema calcula valor líquido e previsão de recebimento | Pedido do Cauê: ver condições e taxas de Pix, Pix na maquininha, débito, crédito |
| 09/11/2026 | Centro de custo opcional (ex.: Estacionamento, Lavagem) em despesas e ordens, escolhido de uma **lista cadastrada** com busca enquanto digita; texto livre não é aceito, criar um novo exige ação explícita (comparativo, ponto 5) | Evitar erro de digitação que tira o lançamento do centro certo |
| 09/11/2026 | Plano de contas único em árvore para receitas e despesas (sai com modelo pronto, editável); cada serviço/tipo de ordem e cada despesa aponta para uma conta; entradas do PDV classificadas automaticamente (comparativo, ponto 4) | Gera a DRE/resultado do mês; entradas do estacionamento automatizadas pelo PDV |
| 09/11/2026 | Extrato da conta do cliente na ficha: ordens, pagamentos e ajustes por data, com saldo; pode ser enviado ao cliente (comparativo, ponto 3) | Histórico de movimentação para conferência e discussões com cliente |
| 09/11/2026 | Correções por documento de ajuste (crédito, débito, estorno, desconto) ligado à cobrança ou ao pagamento, sempre com motivo; botão "Ajustar" (comparativo, ponto 2) | Consequência da cobrança imutável; histórico completo de cada correção |
| 09/11/2026 | Cobrança emitida é imutável: cópia dos itens na emissão; não pago vira "saldo anterior" na próxima (comparativo, ponto 1) | Histórico igual ao que o cliente recebeu; boleto e nota fiscal com valor fixo |
| 09/11/2026 | Contas a pagar com **uma permissão só** (`contas_pagar`: ver, lançar e pagar, inclusive folha); sem módulo de pedido de compra: a conta é lançada quando o material chega | Dono de estacionamento faz tudo sozinho; pedidos de material são por mensagem e pagos na entrega |
| 09/11/2026 | Termos: **ordem de serviço** = todo item que o cliente deve (mensalidade, estadia, lavagem, ajustes); **cobrança** = agrupa as ordens em aberto e gera o boleto (no lugar de "fatura"). A etapa da lavagem fica na própria ordem, sem tabela separada | É como o Local já trabalha e fala |
| 09/11/2026 | Cancelar uma ordem de serviço em aberto é permitido (ex.: mensalidade de quem deixou de ser mensalista), com permissão, motivo e registro | Resposta do Cauê |
| 09/11/2026 | Documentação e Git do ERP ficam em `C:\wamp64\www\erplocal` | Pasta própria do sistema novo |
| 08/11/2026 | Telas em React com Bootstrap 5.3 personalizado; CSS antigo não vai para o ERP | O Cauê acompanha JavaScript de frontend; padronizar layout sem misturar estilos |
| 08/11/2026 | Laravel (PHP) agora e no SaaS; troca de linguagem, se houver, testada primeiro no Local | Infraestrutura compartilhada roda PHP melhor; evitar reescrita dupla |
| 08/11/2026 | PDV online primeiro (celular com 4G), sem servidor local; modo de emergência só guarda eventos | Celular com internet roteada mantém a operação mesmo sem luz |
| 08/11/2026 | Banco Inter por consulta periódica, não webhook | Hostinger Unlimited não permite webhook com mTLS |
| 08/11/2026 | Filas e agendas pelo cron | Hostinger Unlimited não permite processos contínuos |
| 08/11/2026 | Sistema sempre em pt-BR, formatação própria | Navegador/servidor em outro idioma mostravam "October" |
| 08/11/2026 | Veículo identificado por id interno; placa opcional | Autopropelidos sem placa; troca de placa; placas estrangeiras |
| 08/11/2026 | Preço e tabela da empresa, com exceção por unidade e cópia de outra unidade | Resposta do Cauê |
| 08/11/2026 | Uma cobrança por mês por cliente com todos os itens em aberto; pagamento menor: escolher itens pagos; item restante pode ser mantido ou cancelado (com permissão e motivo) | Resposta do Cauê + regra de controle de caixa |
| 08/11/2026 | Convênio fecha junto com as cobranças, no dia de vencimento do conveniado | Resposta do Cauê |
| 08/11/2026 | Usuário pode trabalhar em mais de uma unidade (unidade vazia = todas) | Sugestão aceita |
| 08/11/2026 | Saída sem internet cobra o valor estimado e confere depois | Sugestão aceita |
| 08/11/2026 | "Operador de caixa" é qualificação por usuário | Resposta do Cauê |
| 08/11/2026 | Manter os termos atuais da interface | Sugestão aceita |
| 08/11/2026 | Gestão de gastos, contas a pagar e fatura do cartão entram no ERP | Pedido do Cauê |
| 08/11/2026 | Pronto para várias empresas (empresa_id/unidade_id em tudo), usado por uma | Preparar o produto para SaaS sem vender agora |
| 08/11/2026 | Mudanças estruturais só no ERP; sistema atual só recebe correções | Evitar fazer duas vezes |

## Em aberto

Nenhuma decisão pendente para a etapa 3.
