# Registro de decisões

> Parte da documentação do ERP Local. Índice: [docs/README.md](README.md)

Cada decisão com data e motivo. Decisão nova entra no topo. Para mudar uma decisão, registrar a nova em vez de apagar a antiga.

| Data | Decisão | Motivo |
|---|---|---|
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

Nenhuma decisão pendente para a etapa 2.
