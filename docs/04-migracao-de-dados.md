# Migração dos dados do sistema atual

> Parte da documentação do ERP Local. Índice: [docs/README.md](README.md)

Todos os dados atuais passam para o banco novo por um script de migração que roda quantas vezes for preciso; nada é digitado de novo. Cada registro migrado recebe a empresa Local e a unidade principal.

| Hoje | No banco novo | O que muda |
|---|---|---|
| CLIENTES + CLIENTES_ENDERECO + CLIENTES_TELEFONE | clientes | As três tabelas viram uma; o vínculo por id de endereço e telefone some |
| VEICULOS | veiculos | O período sai do veículo e vai para o contrato |
| VEICULOS_MENSALISTA + período do veículo | mensalidades_contratos | Ganha início e fim: histórico de reajustes e cancelamentos |
| ORDEM_SERVICOS (em aberto) | ordens_servico | Tipo explícito em vez de código de serviço (MEN, CDT, DBT) |
| ORDEM_SERVICOS (pagas) | ordens_servico + pagamentos + pagamento_itens | Cada data de pagamento vira um pagamento com forma "não informada" |
| Estadia avulsa (EST em ORDEM_SERVICOS) | estadias + ordens_servico | Ganha entrada e saída quando houver; antigas ficam só como ordem de serviço |
| SERVICOS, SERVICOS_ADICIONAIS, SERVICOS_PRECO | servicos, servico_adicionais, servico_precos | Preço da empresa, com exceção por unidade; uma unidade nova pode copiar os preços de outra |
| VEICULOS_TIPO | tipos_veiculo | Igual, por empresa |
| TARIFAS, TARIFA_FAIXAS, TARIFA_PACOTES, TARIFA_VEICULOS | tarifas, tarifa_faixas, tarifa_pacotes, tarifa_tipos | Tabela da empresa, com exceção por unidade; uma unidade nova pode copiar as tabelas de outra |
| CONVENIOS | convenios | Igual, por empresa |
| EXTRATO_LANCAMENTOS | extrato_lancamentos | Liga ao pagamento que gerou |
| PAGADORES_VINCULADOS | pagadores_vinculados | Igual |
| CADASTROS_PENDENTES | cadastros_pendentes | Ganha unidade de destino |
| USUARIOS + NIVEIS_ACESSO | usuarios + niveis | Senhas continuam valendo (mesmo formato); colunas antigas usr_admin e usr_nivel saem |
| LOG_ATIVIDADES | log_atividades | Igual, com usuário por id |
| LOGIN_TENTATIVAS, ci_sessions | (não migram) | O Laravel tem os próprios; todos entram de novo na virada |

**Validação da migração:** antes da virada, um relatório compara os dois bancos: quantidade de clientes e veículos, total em aberto por cliente, total recebido por mês. Qualquer diferença de um centavo trava a virada.

## Como rodar

- `php artisan legado:importar` lê o banco do sistema atual (conexão `legado` no `.env`, só leitura) e grava no ERP. Cada registro guarda o id antigo (`legado_id`): rodar de novo atualiza, não duplica. Até a virada, o sistema atual sobrescreve o que veio dele; o que foi criado só no ERP fica intocado.
- `php artisan legado:comparar` mostra os números lado a lado e grava o relatório em Markdown em `storage/app/private/relatorios/`. Sai com erro se houver diferença.

| Etapa | O que a importação traz |
|---|---|
| 1 (pronta) | Empresa e unidade principal (na primeira vez), níveis de acesso (permissões que não existem mais são descartadas), usuários com a senha atual, registro de atividades |
| 2 | Clientes, veículos, contratos de mensalidade, preços, serviços, convênios, fila de cadastro |
| 3 | Ordens de serviço, pagamentos, extrato importado, pagadores vinculados |

## Cuidados

- No sistema atual, várias tabelas se ligam ao veículo **pela placa** (cobranças, mensalidades). O script troca essas ligações pelo id interno do veículo.
- Placas improvisadas (ex.: "SEMPLC BICI" para bicicleta) viram veículos sem placa com identificação alternativa.
- Códigos de serviço do sistema atual: `MEN` mensalidade, `EST` estadia avulsa, `CDT` crédito (abate), `DBT` débito (soma), demais = lavagens e serviços.
- O banco atual de produção usa nomes de tabela em MAIÚSCULAS; a cópia local (Windows) em minúsculas. O script lê sem depender disso.
