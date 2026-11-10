# Histórico de alterações do ERP

Alterações do ERP Local, da mais nova para a mais antiga. Cada alteração entra **no topo**: data, o que mudou e arquivos.

---

## 09/11/2026 (noite) — Etapa 1: fundação

- **Projeto Laravel 13 + Inertia 3 + React 19 + Bootstrap 5.3**, com o visual do sistema atual (cores, fonte Inter servida pelo próprio sistema, cantos, tema claro e escuro, menu lateral recolhível, menu de baixo no celular).
- **Núcleo:** tabelas `empresas`, `unidades`, `niveis`, `usuarios`, `log_atividades`; filtro por empresa em toda consulta (falha fechada: sem empresa, nada aparece e nada grava).
- **Login por usuário** com as senhas do sistema atual (bcrypt e o SHA1 antigo, regravado na hora), limite de 5 tentativas por minuto, mesma mensagem para login inexistente e senha errada.
- **Permissões:** catálogo herdado + `contas_pagar`; toda rota declara a permissão e rota sem permissão é bloqueada; acesso negado vai para o registro de atividades.
- **Telas:** Início, Usuários (criar, editar, desativar), Níveis de acesso, Empresa e unidades, Registro de atividades.
- **Proteções:** ninguém muda o próprio nível nem se desativa; sempre sobra um usuário ativo com acesso total e uma unidade ativa.
- **Inatividade entre abas:** fecha só quando todas as abas estão paradas, com aviso um minuto antes; sair numa aba leva as outras.
- **Importação do sistema atual** (`legado:importar`, roda quantas vezes quiser) e **comparação** (`legado:comparar`, relatório em Markdown).
- **43 testes automáticos**: isolamento entre empresas, rotas, login, permissões, usuários e níveis, importação.
- Documentação: novo `docs/13-desenvolvimento.md`; atualizados README, CLAUDE.md, 04, 10, 12.
- **Instalação no WAMP:** tabelas sempre em InnoDB (o MySQL do WAMP vinha com MyISAM como padrão e travava o `migrate`); configuração dos testes sem a pasta `tests/Unit`; aviso informativo legível no tema escuro.

## 09/11/2026

- **Comparativo com o mercado decidido, ponto a ponto:** cobrança imutável, ajustes, extrato do cliente, plano de contas, centro de custo (lista cadastrada), contas financeiras e conciliação, formas de pagamento com taxas e prazo em dias, vigência de preços, pausa (pode ser cobrada) e cancelamento agendado, inadimplência a partir do primeiro vencimento, régua de cobrança (com WhatsApp), situação do pagamento (com Inter), campos fiscais, numeração própria, capacidade e ocupação. Atualizados 03, 05, 06, 11, 12.
- **Contas a pagar:** uma permissão só (`contas_pagar`); registrado em 08 e 12.
- **Termos definidos:** "ordem de serviço" é todo item que o cliente deve; "cobrança" (no lugar de "fatura") agrupa as ordens e gera o boleto; etapa da lavagem fica na própria ordem; cancelamento de ordem com permissão e motivo. Novo `docs/00-glossario.md`; ajustados 01, 03, 04, 05, 06, 07, 10, 11, 12, README e CLAUDE.md.
- **Início do projeto:** pasta `erplocal`, documentação detalhada em `docs/` (visão, tecnologia, modelo de dados, migração, regras de negócio, módulos, PDV, segurança, SaaS, plano de etapas, comparativo com o mercado, registro de decisões), `README.md`, `CLAUDE.md`, `.gitignore`.
