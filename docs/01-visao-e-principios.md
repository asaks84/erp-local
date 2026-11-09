# Visão e princípios

> Parte da documentação do ERP Local. Índice: [docs/README.md](README.md)

O sistema será refeito em Laravel como um produto completo para o Local Estacionamento, já desenhado para virar SaaS depois, sem ser vendido agora. A gestão nova nasce com os módulos previstos (venda de serviços, baixa unificada com forma de pagamento, cobrança e gestão de gastos) e com a API que o PDV vai usar.

**Princípios**

- **Pronto para várias empresas, usado por uma.** Toda tabela de negócio leva `empresa_id` e `unidade_id` desde o primeiro dia; hoje existe uma empresa (Local) e uma unidade.
- **Uma regra, um lugar.** Tarifa, convênio, proporcional, baixa e cobrança viram serviços únicos no servidor, usados pela gestão, pelo PDV e pelos relatórios.
- **Tudo configurável por tela.** Nada de nome da empresa, preço, prazo ou regra comercial fixo no código.
- **Dinheiro sempre rastreável.** Cada centavo tem origem (ordem de serviço), cobrança (agrupa as ordens e gera o boleto), recebimento (pagamento com forma) e responsável (usuário e caixa).
- **O CodeIgniter atual só recebe correções** até a virada; as mudanças estruturais acontecem direto no sistema novo.

**Fica para depois do produto pronto:** cadastro de novas empresas, cobrança dos clientes do SaaS, painel de suporte, NFS-e multi-cidade e hospedagem dedicada.

## Contexto

- O sistema atual (CodeIgniter 2, em `C:\wamp64\www\senna` e em produção em `/sys/senna/`) continua oficial até a virada e só recebe correções.
- O ERP novo é desenvolvido em `C:\wamp64\www\erplocal`.
- Objetivo de produto: ficar pronto do jeito que o Cauê espera, rodar no Local Estacionamento, e só então estruturar a venda como SaaS.
