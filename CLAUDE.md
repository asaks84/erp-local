# ERP Local — regras do projeto

Novo sistema do Local Estacionamento, em Laravel + Inertia + React + Bootstrap 5.3.
A documentação completa está em `docs/` (comece por `docs/README.md`), **sempre em arquivos Markdown (.md) dentro do projeto** — não usar documentos externos. **Ler a documentação relevante antes de qualquer mudança.**

## Como trabalhar com o dono (Cauê)

- Responder sempre em **português**, direto, com análise crítica honesta (apontar riscos e erros, não só concordar).
- O Cauê conhece JavaScript de frontend e não programa em PHP: explicar o lado do servidor em linguagem simples.
- Perguntar antes de decisões grandes ou ambíguas; registrar cada decisão em `docs/12-decisoes.md`.
- Toda alteração entra no topo do `HISTORICO.md`.
- Quando uma regra de negócio mudar, atualizar o documento correspondente em `docs/` no mesmo trabalho.

## Termos

Usar sempre os termos de `docs/00-glossario.md`: **ordem de serviço** (todo item que o cliente deve) e **cobrança** (agrupa as ordens e gera o boleto). Não usar "fatura" para o cliente.

## Regras de arquitetura (não negociáveis)

- `empresa_id` em toda tabela de negócio; `unidade_id` em tudo que acontece num pátio. Escopo global filtra por empresa em toda consulta, com testes que tentam ler dados de outra empresa.
- Uma regra, um lugar: tarifa, convênio, mensalidade, preço de serviço, baixa e cobrança são serviços únicos. Telas, API e relatórios só chamam esses serviços.
- Dinheiro nunca é apagado: cancelar/estornar com motivo, data e usuário.
- Lançamentos criados pelo PDV usam UUID; envio repetido não duplica.
- Veículo identificado por id interno; placa opcional e normalizada.
- Nada de texto comercial fixo no código (nome da empresa, preços, prazos): tudo configurável.
- Toda rota declara a permissão que exige; rota sem permissão é bloqueada por padrão.
- Sistema sempre em pt-BR, formatado pelo próprio sistema (nunca pelo locale do servidor ou do navegador).
- Tabelas e colunas em `snake_case` minúsculo.

## Código

Organização, versões e o passo a passo de cada tela nova: `docs/13-desenvolvimento.md`. Resumo:

- Rota sempre dentro de `permissao:<chave>` (ou `permissao:livre`); chave nova em `config/permissoes.php`.
- Modelo de negócio com `use PertenceAEmpresa;`; `Rule::exists`/`Rule::unique` filtrando `empresa_id`.
- `Atividades::registrar()` em ação sensível; datas `criado_em`/`atualizado_em`.
- Telas em `resources/js/Pages`, formatos só por `resources/js/lib/formato.js`, campos com `Components/Campo`.
- **Dinheiro:** toda ordem nasce por `App\Servicos\Ordens` (número da empresa via `Numeracao`); toda baixa passa por `App\Servicos\Baixa::receber()` (telas, extrato e, depois, PDV e Inter); cobrança só por `Cobrancas::emitir()`. Nunca apagar ordem ou pagamento: cancelar/estornar com motivo. Valor com sinal (crédito negativo) por `OrdemServico::SQL_VALOR`/`valorComSinal()`.
- Rodar `php artisan test` antes de entregar; banco de teste `erp_teste`.
- O Cauê roda os comandos (composer, npm, artisan) no PC dele; as bibliotecas do PHP chegam a este ambiente por um zip da pasta `vendor` quando mudam (o site de pacotes do PHP é bloqueado aqui).

## Infraestrutura

- Produção: hospedagem compartilhada Hostinger (sem processos contínuos, sem WebSocket, sem webhook mTLS). Filas e agendas pelo cron. Inter por consulta periódica.
- Local: WAMP em `C:\wamp64\www\erplocal` (Apache 2.4, PHP 8.3, MySQL 8.4).
- Nunca versionar `.env`, dumps de banco com dados de clientes (LGPD) ou chaves.

## Testes

- Testes automáticos obrigatórios para as regras de dinheiro (tarifador, mensalidade, baixa, cobrança, convênio), com casos reais do Local.
- Testar telas no navegador, no tamanho de celular e no tema escuro, com mais de um nível de acesso.

## Sistema atual (referência)

- Pasta `C:\wamp64\www\senna` (CodeIgniter 2), produção em `/sys/senna/`. Regras atuais que o ERP precisa preservar estão em `docs/05-regras-de-negocio.md`.
