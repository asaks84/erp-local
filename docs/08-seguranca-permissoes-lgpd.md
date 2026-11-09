# Segurança, permissões e LGPD

> Parte da documentação do ERP Local. Índice: [docs/README.md](README.md)

Tudo o que o sistema atual já protege continua protegido, agora como parte do framework e não como código espalhado por tela.

- **Permissões centrais:** cada rota declara a permissão que exige (Gates do Laravel); uma tela nova sem permissão declarada é bloqueada por padrão. A matriz de níveis que você configura hoje vira a mesma tela.
- **Isolamento por empresa:** um filtro global aplica `empresa_id` em toda consulta; testes automáticos tentam ler dados de outra empresa e precisam falhar.
- **Login e sessão:** senhas com hash forte (as atuais continuam valendo), sessão com troca de código, limite de tentativas, proteção CSRF em todo formulário.
- **Cabeçalhos:** CSP, HTTPS obrigatório, Cloudflare na frente como hoje, IP real só dos endereços do Cloudflare.
- **Segredos:** senha do banco, chaves do Inter e do WhatsApp em variáveis de ambiente; credenciais por empresa guardadas criptografadas no banco.
- **Aparelhos do PDV:** token próprio por celular, revogável na hora se o aparelho sumir.
- **Registro:** log de atividades para toda ação com dinheiro, permissão e acesso negado.
- **Backup:** diário, automático, guardado fora do servidor.

**LGPD:** o sistema guarda CPF, placa, telefone e endereço. Ficam previstos o consentimento do cadastro público (já existe), exportação dos dados de um cliente e anonimização quando ele pedir para ser apagado, sem perder o histórico financeiro.

## Catálogo de permissões (herdado do sistema atual)

O dia a dia (clientes, estadias, lavagens, receber contas, gerar e imprimir mensalidades) vale para todos. O nível fixo "Administrador" tem tudo.

| Grupo | Chave | Permite |
|---|---|---|
| Operação | `contas_editar` | Alterar e excluir contas (valor e vencimento) |
| Operação | `desconto` | Dar desconto em pagamentos |
| Operação | `mensalidade_valor` | Mudar o valor da mensalidade e da primeira cobrança (cortesia) |
| Operação | `excluir_cadastro` | Remover clientes e veículos |
| Operação | `aprovar` | Aprovar cadastros da fila de espera |
| Gestão | `contas_aberto` | Contas em aberto e inadimplência (valores no Início) |
| Gestão | `rel_mensalistas` | Relatórios de mensalistas (sem receita) |
| Gestão | `faturamento` | Faturamento: recebimentos, contas recebidas e receita |
| Gestão | `extrato` | Importar extrato bancário |
| Gestão | `convenios` | Configurar convênios |
| Sistema | `configuracoes` | Configurações (cobrança, serviços, veículos, níveis) |
| Sistema | `usuarios` | Usuários e registro de atividades |

**Propostas para o ERP (confirmar):** `contas_pagar_ver`, `contas_pagar_pagar` (lançar conta ≠ pagar conta), `caixa` (abrir/fechar caixa), `pdv`.

## Lições do sistema atual

- Sessão por inatividade deve considerar todas as abas abertas (no atual, uma aba parada derruba as outras).
- Formulário de login precisa renovar o código CSRF antes de enviar (tela aberta por horas).
- Nunca depender de locale do servidor para textos em português.
