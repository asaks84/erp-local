# Tecnologia e infraestrutura

> Parte da documentação do ERP Local. Índice: [docs/README.md](README.md)

Um servidor Laravel concentra gestão, regras e API; o PDV é um app leve no celular que conversa com essa API.

| Camada | Escolha | Por quê |
|---|---|---|
| Servidor | Laravel (PHP 8.3+), MySQL | Roda na hospedagem compartilhada; migração curta a partir do código atual |
| Telas da gestão | Inertia + React + Bootstrap 5.3 (React-Bootstrap), com as cores, fontes e cantos do visual atual e tema escuro nativo | Telas em JavaScript, que o Cauê acompanha; servidor e regras em PHP, sem API separada para as telas |
| API do PDV | Laravel Sanctum (um token por aparelho) | Cada celular autenticado e revogável |
| PDV | App React separado (sem Inertia), online primeiro: PWA agora, empacotado com Capacitor para a Play Store depois | O navegador só imprime em impressora Bluetooth BLE; Bluetooth clássico (a maioria das térmicas) e impressora de rede exigem o app (ver [07-pdv.md](07-pdv.md)) |
| Filas e agendas | Fila no banco + cron da hospedagem (`schedule:run` a cada minuto) | Mensalidades, WhatsApp e conciliação sem servidor dedicado |
| Arquivos | Disco configurável (local agora, nuvem depois) | Holerites e comprovantes mudam de lugar sem mexer no código |
| Hospedagem | Hostinger compartilhada para o Local; VPS ou nuvem no primeiro cliente pagante | Nada guardado no servidor além do banco e do disco configurado |

O código fica em repositório Git com testes automáticos das regras de dinheiro; a publicação é pelo repositório, não por envio manual de arquivos: botão **Publicar** no GitHub, que testa, gera o build e envia por SSH (detalhes em [14-publicacao.md](14-publicacao.md)).

**Hospedagem em uso (nov/2026):** Hostinger Premium Web Hosting, PHP 8.3, a mesma conta do sistema atual (migrado da GoDaddy em 11/11/2026). DNS na Cloudflare.

**Decidido:** Laravel continua no SaaS. Se um dia houver troca de linguagem, ela é testada primeiro no Local Estacionamento antes de chegar aos clientes.

**Idioma e formatos:** o sistema é sempre em português do Brasil, independente do idioma do navegador, do celular ou do servidor. Datas, meses, moeda (R$ 1.234,56) e números são formatados pelo próprio sistema com pt-BR fixo; campos de data usam um componente próprio (dd/mm/aaaa) em vez do campo de data do navegador. Os textos ficam num arquivo de tradução único, o que também deixa aberta a porta para outro idioma no SaaS.

## Hospedagem (resposta da Hostinger, plano Unlimited)

- **Não suporta:** processos contínuos em segundo plano, workers, bots, timers, WebSocket de entrada, webhook com certificado (mTLS).
- **Suporta:** cron jobs e webhooks HTTP comuns; até 5 apps Node (não usados neste projeto).
- **Consequências no desenho:**
  - Filas e tarefas agendadas pelo cron (`php artisan schedule:run` a cada minuto; fila processada com `queue:work --stop-when-empty` disparado pelo agendador).
  - PDV só por requisições HTTP; telas que precisam se atualizar (veículos no pátio) consultam a cada 15–30 s.
  - Banco Inter: baixa automática por **consulta periódica** (a cada 5–10 min) em vez de webhook. O certificado do Inter é usado só nas chamadas de saída, guardado fora da pasta pública. Webhook pode ser ligado quando houver VPS.

## Ambiente local

- WAMP: Apache 2.4.65, PHP 8.3.28, MySQL 8.4.7 (com `restrict_fk_on_non_standard_key=OFF` no `my.ini`), MariaDB 11.4 disponível.
- No Windows o MySQL grava nomes de tabela em minúsculas; o ERP usa nomes minúsculos (`snake_case`) desde o início, então se comporta igual no Windows e no Linux.
- Composer, Node.js e Git são necessários para o Laravel e o React.

## Padrões do projeto

- Nomes de tabelas e colunas em português, `snake_case`, minúsculas (`clientes`, `valor_total`).
- Código (classes, métodos) em inglês ou português de forma consistente por módulo; textos da interface sempre em português, num arquivo de tradução.
- Visual: Bootstrap 5.3 personalizado com as variáveis do visual atual (vermelho da marca, fonte, cantos, tema claro e escuro). Sem o CSS próprio do sistema antigo.
- Formulários com rótulo dentro do campo (`form-floating`).
- Campos de data com componente próprio dd/mm/aaaa; moeda R$ 1.234,56; fuso America/Sao_Paulo.
