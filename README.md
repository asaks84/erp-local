# ERP Local

Novo sistema de gestão do **Local Estacionamento** (Santos/SP): mensalistas, estadias, lavagens e serviços, cobranças, recebimentos, convênios, contas a pagar, gestão de gastos e PDV no celular. Preparado para várias unidades e, no futuro, para ser vendido como SaaS.

> Repositório privado. Não tornar público.

## Situação

Em planejamento. Nenhuma etapa de código iniciada. Ver [docs/10-plano-de-etapas.md](docs/10-plano-de-etapas.md).

O sistema atual (CodeIgniter, pasta `senna`) continua em produção até a virada e só recebe correções.

## Tecnologia

- **Laravel** (PHP 8.3+) + **MySQL**
- Telas: **Inertia + React + Bootstrap 5.3** (personalizado com o visual da marca, tema claro e escuro)
- PDV: PWA no celular, online primeiro, API com Laravel Sanctum
- Filas e tarefas agendadas pelo cron (hospedagem compartilhada)

## Documentação

Tudo em [`docs/`](docs/README.md): visão, modelo de dados, regras de negócio, módulos, PDV, segurança, plano de etapas e registro de decisões.

- [`HISTORICO.md`](HISTORICO.md) — alterações, da mais nova para a mais antiga.
- [`CLAUDE.md`](CLAUDE.md) — regras do projeto para o Claude.

## Rodar no computador

Ainda não há código. Quando a etapa 1 começar, este trecho recebe o passo a passo (Composer, Node.js, `.env`, banco local, `php artisan migrate`, `npm run dev`).
