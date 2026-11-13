# Publicação e paralelo (etapa 5)

> Parte da documentação do ERP Local. Índice: [docs/README.md](README.md)

O ERP fica em **https://erp.localestacionamento.com.br**, na mesma conta da Hostinger do sistema atual. Durante o paralelo, ele **espelha** o sistema atual: de hora em hora (7h às 23h) importa os dados e compara os números. A equipe continua usando só o sistema atual. O resultado de cada conferência fica em **Sistema › Conferência**. A virada só acontece depois de algumas semanas sem diferença.

## Como uma versão vai ao ar

Nada é enviado à mão. No GitHub: **Actions › Publicar › Run workflow**, ação **publicar**:

1. roda todos os testes (se um falhar, nada vai ao ar);
2. gera o build das telas e as dependências de produção;
3. envia a versão para `~/erp/releases/<data-hora>-<commit>` por SSH;
4. no servidor (`deploy/servidor.sh`): liga o `.env` e o `storage` compartilhados, roda as migrações, otimiza e troca a versão no ar de uma vez (`~/erp/atual`).

**Voltar atrás:** a mesma tela, ação **voltar para a versão anterior** (`deploy/voltar.sh`). O código volta na hora; o banco não volta (para isso, o backup abaixo). O servidor guarda as 5 últimas versões.

## Estrutura no servidor

No Local, a pasta base é `/home/u612820499/domains/localestacionamento.com.br/erp` (o Gerenciador de Arquivos da Hostinger abre na pasta do site, não na pasta inicial da conta), informada ao GitHub pela variável `ERP_DIR`. Abaixo, `~/erp` quer dizer essa pasta base.

```
~/erp/releases/<versão>/     uma pasta por publicação (sem .env e sem storage próprios)
~/erp/compartilhado/.env     configuração (senhas) — só existe no servidor
~/erp/compartilhado/storage/ logs, sessões, relatórios e backups
~/erp/atual -> releases/...  versão no ar
<pasta do subdomínio>  ->  ~/erp/atual/public   (só a pasta public fica acessível pela web)
```

## Primeira publicação (uma vez só)

1. **Subdomínio** — hPanel › Domínios › Subdomínios: criar `erp` em localestacionamento.com.br. Anote a pasta que o hPanel mostrar (algo como `/home/u612820499/domains/localestacionamento.com.br/public_html/erp`). Deixe a pasta vazia (só o arquivo padrão da Hostinger, se houver).
2. **DNS** — Cloudflare: `A · erp · 185.245.180.71`, **DNS only** até a Hostinger emitir o SSL do subdomínio; depois pode ser Proxied com SSL/TLS em Full (strict).
3. **Banco do ERP** — hPanel › Bancos de dados: criar `u612820499_erp` com um usuário próprio. Guarde a senha no gerenciador de senhas.
4. **SSH** — hPanel › Avançado › Acesso SSH: ativar; anote IP, porta (geralmente 65002) e usuário.
5. **Chave de publicação** — no PC (PowerShell): `ssh-keygen -t ed25519 -C "erp-publicar" -f $HOME\.ssh\erp-publicar` (Enter duas vezes, sem senha). O conteúdo de `erp-publicar.pub` vai em hPanel › Acesso SSH › Chaves SSH. O conteúdo de `erp-publicar` (a chave privada) vai só no GitHub (passo 7).
6. **`.env` do servidor** — Gerenciador de Arquivos (abre na pasta do site, fora do `public_html`): criar a pasta `erp/compartilhado` e, dentro dela, o arquivo `.env` com o conteúdo de `deploy/env-producao.exemplo`, preenchendo os bancos. O `APP_KEY` fica vazio: a primeira publicação gera.
7. **GitHub** — repositório › Settings › Secrets and variables › Actions:
   - *Secrets*: `SSH_HOST` (IP), `SSH_PORT`, `SSH_USER`, `SSH_CHAVE` (chave privada inteira, com as linhas BEGIN/END);
   - *Variables*: `ERP_DOCROOT` = a pasta do subdomínio (passo 1, caminho completo). Opcionais: `PHP_BIN` (se o `php` do SSH não for 8.3, ex.: `/usr/bin/php8.3`), `ERP_DIR` (caminho completo da pasta base; no Local, `/home/u612820499/domains/localestacionamento.com.br/erp`).
8. **Publicar** — Actions › Publicar › Run workflow › publicar.
9. **Cron** — hPanel › Avançado › Cron Jobs, a cada minuto: `/usr/bin/php /home/u612820499/domains/localestacionamento.com.br/erp/atual/artisan schedule:run` (ajuste o caminho do PHP se o hPanel indicar outro).
10. **Primeira importação** — acontece sozinha na próxima hora cheia (ou por SSH: `cd ~/erp/atual && php artisan legado:espelhar`). O login é o mesmo do sistema atual (as senhas vêm junto).

## Agendamentos (routes/console.php)

| Quando | O quê |
|---|---|
| de hora em hora, 7h às 23h (só com `ERP_ESPELHO_ATIVO=true`) | `legado:espelhar`: importa do sistema atual e compara; grava em Sistema › Conferência |
| todo dia às 3h10 | `backup:banco`: backup compactado do banco do ERP e do sistema atual em `storage/app/private/backups`, 14 dias |

A Hostinger também faz backup semanal da conta. Para restaurar um backup do ERP: phpMyAdmin › Importar o `.sql.gz`, ou pelo SSH: `gunzip < arquivo.sql.gz | mysql -u USUARIO -p BANCO`.

A consulta dos boletos do Banco Inter (`inter:consultar`, a cada 10 minutos) usa o mesmo cron; sem o Inter ligado em Configurações › Banco Inter, não faz nada.

As tarefas agendadas rodam dentro do próprio `schedule:run` (`Schedule::call`), e o backup é feito em PHP, sem `mysqldump`: a hospedagem compartilhada desliga o `proc_open`, e um comando separado não roda pelo cron (erro "The Process class relies on proc_open" no log). Para conferir se o agendador está rodando: o "Ver resultado" do cron no hPanel e o log em `storage/logs/laravel-AAAA-MM-DD.log`.

## Portão da virada

- **Sistema › Conferência** com várias semanas seguidas sem diferença ("Dias seguidos sem diferença"), passando por pelo menos um ciclo completo de mensalidades e cobranças.
- Backup diário funcionando (arquivos novos em `storage/app/private/backups`) e uma restauração testada.
- Plano de volta: até a virada, o sistema atual continua sendo o oficial; depois dela, ele fica só para consulta e o ERP passa a ser o oficial (`ERP_ESPELHO_ATIVO=false`).

## Segurança

- Só a pasta `public` fica acessível pela web; `.env`, código e backups ficam fora dela.
- `APP_DEBUG=false` e `SESSION_SECURE_COOKIE=true` no servidor.
- A chave de publicação só serve para essa conta SSH; se vazar, apague-a no hPanel e gere outra.
- A senha do banco do sistema atual apareceu numa conversa durante a migração de hospedagem: troque-a no hPanel e atualize o `database.php` do sistema atual e o `LEGADO_DB_PASSWORD` do ERP.
