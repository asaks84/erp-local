# Auditoria de segurança

Registro das auditorias de segurança do ERP. Cada auditoria entra **no topo**, com escopo, achados e situação de cada um.

---

## 21/11/2026 — Auditoria rápida das rotas públicas e da API do PDV

- **Perfil:** rápido (uma passada só, lendo o código; sem execução e sem revisão independente de cada achado).
- **Versão revisada:** commit `e6c7e6c` (com alterações locais em `deploy/rsync-excluir.txt`, fora do escopo).
- **Escopo:** rotas públicas de `routes/web.php` (`/login`, `/cadastro`, `/atualizar`, `/c/`, `/privacidade`, webhook do WhatsApp), toda a API do PDV (`routes/api.php`).
- **Fora do escopo:** telas da gestão (rotas com login), configuração do servidor, bibliotecas.
- **Webhook do Inter:** não existe. O Inter funciona só por consulta a cada 10 minutos (`routes/console.php`), então não havia o que auditar.

### Resumo

| # | Gravidade | Achado | Situação |
|---|---|---|---|
| 1 | Média | O celular recebe a versão protegida dos PINs de todos, e ela se quebra em minutos | Corrigido em 21/11/2026 |
| 2 | Média | A fila sem internet aceita qualquer autor, sem PIN, e saída com data de até 7 dias atrás | Corrigido em 21/11/2026 |
| 3 | Baixa | A assinatura do webhook do WhatsApp é opcional | Corrigido em 21/11/2026 (conferir se a chave está preenchida em produção) |

**Correções (21/11/2026, decisão em `12-decisoes.md`):**
- 1: o pacote leva só uma dica do PIN (2 caracteres da versão sem internet; gerente 1), que vale para dezenas ou centenas de PINs. O celular não guarda mais nenhuma versão completa.
- 2: cada ação da fila leva o PIN trancado com a chave pública do servidor (RSA por empresa) e preso ao uuid da ação. Quem fez sai do PIN conferido no servidor; o `usuario_id` do celular é ignorado. PIN errado, ausente ou de outra ação vira pendência. Saída que chega mais de 60 minutos depois de feita é lançada e vai para conferência.
- 3: o webhook recusa avisos quando a chave secreta do aplicativo está vazia, e a chave é obrigatória na tela de configuração.
- Testes de regressão em `PdvSemInternetTest`, `WhatsAppTest` e `tests/js/pin.test.mjs`.
- Observação menor (também em 21/11/2026): o cadastro de PIN trava depois de 5 senhas erradas por minuto (por login e IP), como a tela de login; com o código de cadastro, 5 códigos errados por minuto por IP. PIN recusado pelas regras não conta.

Os achados 1 e 2 têm a mesma causa: no modo sem internet, o servidor confia no celular. A correção do 2 (conferir o PIN na sincronização) é o que permite resolver o 1 sem quebrar o funcionamento offline. É uma mudança de regra do PDV: decidir e registrar em `12-decisoes.md` antes de corrigir.

### 1. Média — o celular recebe os PINs de todos, e eles se descobrem em minutos

**Onde:** `app/Servicos/Pdv/SemInternet.php` (montagem do pacote, campos `usuarios[].h` e `pin_sal`); rota `GET /api/pdv/pacote`.

**O que acontece:** para funcionar sem internet, o pacote manda para o celular a versão protegida (PBKDF2, 100 mil rodadas) do PIN de cada usuário da unidade, gerentes incluídos, junto com o sal. O PIN tem 4 números, cerca de 10 mil combinações. Como o sal é o mesmo para a empresa inteira, uma única passada pelas 10 mil combinações revela os PINs de todos ao mesmo tempo. Estimativa (sem medição): alguns minutos num PC comum.

- **Quem consegue:** qualquer operador com um celular pareado, ou quem pegar o celular de um operador.
- **O que ganha:** o PIN de gerente, que vale também online para retirada, fechamento de caixa e cancelamento (`CaixaPdvController`, `EstadiaPdvController::cancelar`). O registro de atividades mostra o gerente como autor.
- **Por que a trava não ajuda:** a trava de 5 erros por minuto (`Pins::conferir`) não vale para quem testa no próprio computador.

**Correção sugerida:** parar de mandar uma versão do PIN que permita conferência exata. O celular aceita o PIN sem conferir, ou confere só uma "dica" curta que muitos PINs compartilham. A conferência de verdade acontece no servidor, na sincronização (achado 2). No mínimo, tirar do pacote os usuários gerentes, porque o modo sem internet só faz entrada e saída.

**Teste de regressão:** o pacote não contém nenhum dado que permita confirmar um PIN exato (nem o de gerente).

### 2. Média — a fila sem internet aceita qualquer autor, sem PIN, e saída com data de até 7 dias atrás

**Onde:** `app/Servicos/Pdv/SemInternet.php` (`executar`, `quando`, `DIAS_MAX`); rota `POST /api/pdv/sincronizar`.

**O que acontece:** o servidor aceita o `usuario_id` que o celular informar, sem conferir PIN nenhum. A hora da ação (`feita_em`) também vem do celular e pode ser de até 7 dias atrás. Na tela online, a saída só pode voltar 20 minutos (`PdvController::momento`).

**Cenário:** o carro ficou 10 horas; o operador manda pela fila uma saída "1 hora depois da entrada", em nome de outro colega. O servidor calcula a tarifa nessa hora inventada, o valor cobrado bate com o cálculo, a ação entra como **ok** e não aparece nas pendências. Resultado: cobrança menor que a devida, com a autoria atribuída a outra pessoa.

**Correção sugerida:** cada ação da fila leva o PIN digitado no celular, e o servidor confere esse PIN com o `usuario_id`; se não bater, a ação vira pendente. Toda saída cuja hora fique muito antes do momento da sincronização deveria virar pendência para alguém conferir, e não entrar direto como ok.

**Teste de regressão:** ação da fila com PIN errado ou sem PIN fica pendente; saída com hora muito antiga fica pendente mesmo com o valor batendo.

### 3. Baixa — a assinatura do webhook do WhatsApp é opcional

**Onde:** `app/Http/Controllers/WhatsAppWebhookController.php` (`receber`) e `app/Http/Controllers/WhatsAppController.php` (`credenciais`, campo `app_secret` opcional).

**O que acontece:** sem a "chave secreta do app" preenchida, a assinatura não é conferida. Qualquer pessoa que saiba o ID da conta (`waba_id`) pode mandar avisos falsos de mudança nos modelos de mensagem, por exemplo "DISABLED" ou "REJECTED" (`Modelos::atualizarSituacao`), o que pode atrapalhar os avisos de cobrança. Falsificar o status de entrega de uma mensagem é bem mais difícil, porque exige o ID da mensagem.

**A conferir:** se a `app_secret` está preenchida em produção (a configuração não está no código).

**Correção sugerida:** tornar `app_secret` obrigatório e recusar o webhook quando ela estiver vazia.

### O que foi conferido e está ok

- **Login:** mensagem igual para login inexistente e senha errada, tempo de resposta igualado, limite de 5 tentativas por minuto por login + IP, sessão renovada ao entrar.
- **`/c/{token}`:** código de 24 caracteres aleatórios. A página mostra só o primeiro nome, os itens e os dados do boleto.
- **`/atualizar/{token}`:** código de 40 caracteres. A conferência pela placa trava o link por 30 minutos depois de 5 erros; a liberação fica na sessão de cada link.
- **`/cadastro`:** campo escondido contra robôs, limite de 10 envios por hora por IP; só cria cadastro pendente.
- **Pareamento do PDV:** código de 6 dígitos que vale 15 minutos, limite de 10 tentativas por minuto; token de 64 caracteres, no banco só o hash; a empresa vem do aparelho, não da requisição.
- **XSS:** nenhum `dangerouslySetInnerHTML` com dados das telas (o único `innerHTML` é do componente de ícones).

**Observação menor (corrigida em 21/11/2026):** `POST /api/pdv/pin/cadastrar` também confere login e senha, com limite de 20 por minuto por IP, mais folgado que o da tela de login (5 por minuto). Vale alinhar os dois.

### Limites desta auditoria

- Uma passada só; nenhum achado passou por revisão independente.
- Nada foi executado; as conclusões vêm da leitura do código.
- As telas da gestão (rotas com login) não foram revisadas.
