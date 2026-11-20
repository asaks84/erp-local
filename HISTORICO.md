# Histórico de alterações do ERP

Alterações do ERP Local, da mais nova para a mais antiga. Cada alteração entra **no topo**: data, o que mudou e arquivos.

---

## 20/11/2026 — Registro: impressora Bluetooth e leitor de QR testados

- Teste do Cauê no balcão: a impressora térmica imprime por Bluetooth e o leitor de QR Code do PDV funciona perfeitamente. Registrado na decisão 2 de docs/15-plano-pdv.md. Sem mudança de código.

## 20/11/2026 — Notas fiscais: lista pelas cobranças do mês; "Emitir nota" na cobrança

- **Aba Cobranças** (era Clientes): no lugar de De/Até, escolhe o mês e a lista mostra as cobranças que vencem nele (em aberto, parciais ou pagas; canceladas e levadas para a seguinte ficam fora), com cliente, nº, situação, vencimento, total e o valor dos serviços ainda sem nota. Cobrança com tudo em nota sai da lista (o rodapé conta quantas). Quem teve nota no mês passado vem marcado. O total da seleção conta uma vez o serviço que está em duas cobranças.
- O controle continua por serviço: a cobrança só escolhe quais serviços vão para a tela de conferir. O que já entrou em nota, por esta cobrança ou por outra, não aparece de novo. A tela de conferir e emitir não mudou (duas cobranças do mesmo cliente viram uma nota).
- **Cobrança › Emitir nota:** botão na tela da cobrança (com permissão de NFS-e) quando ela tem serviços sem nota; abre o conferir só com ela.
- Desconto da cobrança ainda não entra na nota (vai o valor dos serviços); depende da contabilidade e da revisão dos descontos.
- Avulsos sem mudança.
- Arquivos: app/Servicos/Nfse/Notas.php, NfseController, CobrancaController, Pages/NotasFiscais/{Index,Conferir}.jsx, Pages/Cobrancas/Show.jsx, tests/Feature/NfseTest.php, docs 12 e 16.

## 20/11/2026 — PDV: dados do veículo em maiúsculas e botões com fundo; ordem dos tamanhos

- **Maiúsculas:** marca, modelo, cor e identificação do veículo gravam sempre em maiúsculas (veículos, estadias, ordens de serviço e cadastros do link público), venha do PDV, do ERP ou da importação. No PDV o campo já mostra em maiúsculas enquanto digita. A migração passa os dados já gravados para maiúsculas. O exemplo do campo Modelo deixou de sugerir cor ("Ex.: ONIX").
- **Botões de tamanho no PDV:** botões contornados ganharam o fundo de cartão; o desenho do fundo da tela não aparece mais por dentro deles.
- **Ordem dos tamanhos:** em Configurações › Tipos de veículo, setas sobem e descem cada tipo ativo. A ordem vale para o PDV (estadia e lavagem), cadastro de veículo, link público, pendentes, pátio, tabelas de estadia, serviços e mensalistas. Tipo novo entra no fim. O campo numérico "ordem" saiu da tela.
- Migração `2026_12_07_000001_veiculos_maiusculas_ordem_tipos`. Arquivos: app/Models/{Concerns/Maiusculas,Veiculo,Estadia,OrdemServico,CadastroPendente,TipoVeiculo,Tarifa}.php, TipoVeiculoController e os controllers que listam tipos (Tarifa, Patio, Servico, Mensalistas, Veiculo, CadastroPublico, Estadia, Pendente, Pdv/LavagemPdv), routes/web.php, Pages/Configuracoes/Tipos.jsx, Components/Icone.jsx, pdv/telas/{EstadiaEntrada,Lavagens}.jsx, scss/_pdv.scss, testes (Configuracoes, Patio, PdvEstadia, PdvLavagem), docs 12 e 13.

## 19/11/2026 — NFS-e: cidade do tomador pelo CEP e data na descrição

- **Cidade do tomador pelo CEP:** a nota pede o código IBGE da cidade; quando a cidade não está na lista da Baixada, o ERP consulta o CEP do cliente no ViaCEP na emissão e guarda o código no cadastro (com o CEP usado; se o CEP mudar, consulta de novo). Antes, cliente de cidade fora da lista saía sem endereço na nota.
- **Descrição** como o Cauê emite: modelo e depois placa ("POLO - TMA2G79"); estadia e serviço avulso levam a data na frente ("05/10 - POLO - TMA2G79 - R$ 60,00"); mensalidade continua com o mês. Sem modelo no cadastro do veículo, vai a marca (ou o tipo). Carro fora do cadastro lançado na conta do cliente (ex.: o da esposa): o modelo digitado no lançamento e a placa da própria ordem (sem modelo, o tipo do veículo). A primeira linha (descrição base) passou a ser opcional e vem em branco: a descrição fica só com as linhas dos serviços, como nas notas feitas no Emissor.
- Migração `2026_12_06_000003_clientes_cidade_ibge`. Arquivos: app/Servicos/Nfse/{Dps,ConfigNfse}.php, NfseController, Pages/Configuracoes/Nfse.jsx, tests/Feature/NfseTest.php, docs 12, 13 e 16.

## 19/11/2026 — DPS conferida contra uma nota real do Emissor Nacional

- O XML de uma nota real emitida pelo Emissor Nacional (Simples, tomador CNPJ em Santos, ISS retido a 2,01%) confirmou a estrutura da DPS montada pelo ERP: mesmos elementos e mesma ordem, inclusive tribMun (tribISSQN, tpRetISSQN, pAliq), tribFed (CST 00, tpRetPisCofins 0) e totTrib (pTotTribSN). Novo teste compara as duas estruturas.
- A inscrição municipal do prestador deixou de ir na DPS (o Emissor não manda; vem do cadastro nacional) e saiu da tela de configuração.
- Arquivos: app/Servicos/Nfse/Dps.php, Pages/Configuracoes/Nfse.jsx, tests/Feature/NfseTest.php, docs 13 e 16.

## 19/11/2026 — Opções de nota no cadastro do cliente; ícone de engrenagem

- **Cadastro do cliente › Nota fiscal (NFS-e):** emitir com os dados do tomador, ISS retido pelo tomador e retenção de tributos federais. Tudo desligado por padrão; sem os dados do tomador, as retenções ficam desligadas. É o padrão da tela de conferir e emitir (dá para mudar na hora, só para aquela nota). Substitui o "lembrar a última nota".
- Ícone de engrenagem (menu Configurações, conferir notas) trocado pelo do Lucide; o anterior parecia um sol.
- Migração `2026_12_06_000002_clientes_nota_fiscal`. Arquivos: ClienteController, Models/Cliente, Components/CamposCliente.jsx, Pages/Clientes/Form.jsx, NfseController, Pages/NotasFiscais/Conferir.jsx, Components/{EscolhasNota,Icone}.jsx, Pages/Configuracoes/Nfse.jsx, tests/Feature/NfseTest.php, docs 12, 13 e 16.

## 19/11/2026 — NFS-e por serviço: lista de clientes, conferir e emitir em lote, avulsos

- **Notas fiscais** agora parte dos **serviços sem nota** (pagos ou não), e não mais dos recebimentos. Abas:
  - **Clientes:** cada cliente com serviços sem nota no período (De/Até; padrão do início do mês anterior ao fim do mês atual), com quantidade e total. Marque os clientes e veja o total das notas; quem teve nota no mês passado já vem marcado. "Conferir e emitir" abre a próxima tela.
  - **Avulsos:** busca por número da OS, número do ticket ou placa; emite a nota daquele serviço. Sem cadastro, dá para digitar CPF/CNPJ e nome de quem pediu (ou deixar sem tomador).
  - **Notas:** as emitidas, como antes.
- **Conferir e emitir:** só os clientes marcados, com os serviços em letra menor (dá para tirar um serviço), as escolhas do cliente em etiquetas e a engrenagem para mudar. Emite um cliente ou **em lote**: a tela manda uma nota de cada vez, com barra de progresso e botão Parar; recusada fica marcada com o motivo e as outras seguem.
- Cada serviço guarda a nota em que entrou (ordens_servico.nfse_id): a nota pode sair antes do pagamento (clientes que fecham o mês) e o pagamento que chega depois não pede nota de novo. Rejeitada ou cancelada, os serviços voltam a ficar sem nota. Crédito e débito (ajustes) não entram.
- Migração `2026_12_06_000001_nfse_por_servico`. Arquivos: app/Servicos/Nfse/{Notas,Dps}.php, NfseController, Models/{Nfse,OrdemServico}, routes/web.php, Pages/NotasFiscais/{Index,Conferir}.jsx, Components/{EscolhasNota,Avisos}.jsx, lib/json.js, tests/Feature/NfseTest.php, docs 12, 13 e 16.

## 19/11/2026 — NFS-e igual ao Emissor Nacional, alíquotas do mês e escolhas por cliente

- **Configurações › Nota fiscal** reorganizada nos passos do Emissor Nacional (Emitente, Serviço, Tributação municipal, Série), com os mesmos nomes e opções. Códigos já preenchidos para o Local: 11.01.01 (nacional), 11.01.01.004 (complementar municipal de Santos) e NBS 106043000; aceita colar o código com pontos, como o Emissor mostra. Código complementar e NBS agora são obrigatórios (o Emissor de Santos pede).
- **Especificar cada serviço** (Configurações): ligado, cada linha da descrição diz o serviço ("ABC1D23 - Fiat Uno - Lavagem simples - R$ 40,00"); desligado (padrão), só a mensalidade é nomeada e o resto sai "placa - veículo - valor". Para não citar na nota serviço sem CNAE.
- **Competência = data da emissão** (dCompet é o dia em que a nota é emitida, não o do recebimento).
- **Alíquotas do mês** no alto de Gestão › Notas fiscais, por competência: alíquota de ISS e valor aproximado dos tributos, do e-mail da contabilidade. Sem as alíquotas do mês atual, não emite. Percentuais com a mesma máscara dos juros do Banco Inter (CampoValor: 0,00, números entram pelos centavos).
- **Emitir** abre uma prévia com as escolhas do cliente: declarar ou não os dados dele na nota e ISSQN retido pelo tomador (com alíquota, ISS retido e valor líquido). A próxima nota do mesmo cliente já vem com as mesmas escolhas.
- **Tributação federal:** em Configurações, a Situação Tributária do PIS/COFINS (00 a 09; no Simples, 00 - Nenhum) e as alíquotas de PIS, COFINS, CSLL, IRRF e previdenciária. A **retenção federal** (tipo de retenção do PIS/COFINS/CSLL, IRRF e previdenciária retidos) tem o tipo e as alíquotas na configuração da empresa (no Simples: não retidos, o optante fica fora dessa retenção); se o cliente retém é marcado na emissão, cliente a cliente (a opção só aparece com retenção configurada), e entra na prévia do valor líquido. Cliente a cliente: dados do cliente na nota, ISS retido e retenção federal.
- **IBS/CBS:** "Preencher as informações IBS/CBS?" (Sim/Não) em Configurações, com os códigos de situação e de classificação tributária. Com Sim, os códigos ficam guardados, mas a emissão por aqui fica bloqueada até o grupo IBS/CBS ser conferido no leiaute oficial.
- Migrações `2026_12_05_000001_nfse_aliquotas_retencao` e `2026_12_05_000002_nfse_retencao_federal`.
- Arquivos: app/Servicos/Nfse/{ConfigNfse,Dps,Notas}.php, NfseController, Models/Nfse, as duas migrações, routes/web.php, Pages/Configuracoes/Nfse.jsx, Pages/NotasFiscais/Index.jsx, tests/Feature/NfseTest.php, docs 12, 13 e 16.

## 19/11/2026 — NFS-e pelo Sistema Nacional (Emissor Nacional)

- **Gestão › Notas fiscais:** recebimentos sem nota ("A emitir", com o botão Emitir) e as notas emitidas, com PDF, XML, "Conferir" (quando o envio ficou sem resposta) e cancelamento com motivo. Uma nota por recebimento; cliente com CPF/CNPJ vai como tomador, sem documento a nota sai para consumidor.
- **Configurações › Nota fiscal (NFS-e):** certificado A1 (o .pfx é aberto no navegador: a senha não sai do computador; guardado criptografado e conferido contra o CNPJ da empresa), regime (Simples, híbrido com ISS por fora, fora do Simples), códigos do serviço, série da DPS e o botão Testes/Produção. Começa em testes (produção restrita, sem valor fiscal).
- Nova permissão "Notas fiscais (NFS-e)". Novo pacote `node-forge` (ler o .pfx no navegador).
- Ainda sem o grupo de IBS/CBS, emissão automática e envio ao cliente (docs/16-nfse.md).
- 273 testes.
- Migração: `2026_12_04_000001_create_nfses`.
- Arquivos: `app/Servicos/Nfse/*` (novos: ConfigNfse, Dps, AssinaturaXml, SefinNacional, SemResposta, Notas), `app/Models/Nfse.php`, `app/Http/Controllers/NfseController.php`, `config/permissoes.php`, `routes/web.php`, `resources/js/lib/menu.js`, `resources/js/Pages/Configuracoes/Nfse.jsx`, `resources/js/Pages/NotasFiscais/Index.jsx`, `package.json`, `package-lock.json`, `tests/Feature/NfseTest.php`, docs 12, 13 e 16 (novo).

## 19/11/2026 — PDV: PINs da unidade valem sem internet (8.7 encerrada)

- A cópia do sistema que o celular guarda passa a trazer, de quem tem PIN na unidade do aparelho, uma versão do PIN para conferir sem internet (PBKDF2 com o sal da empresa; o PIN em si nunca sai do servidor). Funcionário novo ou PIN trocado já vale sem internet na próxima cópia, sem precisar usar o PIN naquele celular antes. PINs de antes disto ganham a versão no primeiro uso com internet.
- Este aparelho mostra "PIN sem internet de: ...".
- Com isto, a parte 8.7 fica encerrada com estadias sem internet. Lavagem e caixa sem internet, QR assinado e servidor local ficam para depois, se a falta aparecer (docs/15, seção 8j).
- 266 testes; `npm run test:js` confere também a conta do PIN.
- Migração: `2026_12_03_000001_pin_sem_internet` (`usuarios.pin_offline_hash`).
- Arquivos: `app/Servicos/Pdv/Pins.php`, `app/Servicos/Pdv/SemInternet.php`, `app/Models/Usuario.php`, `resources/js/pdv/offline/pins.js`, `prontidao.js`, `telas/Aparelho.jsx`, `tests/Unit/PinOfflineTest.php`, `tests/js/pin.test.mjs`, `tests/Feature/PdvSemInternetTest.php`, docs 12, 13 e 15.

## 19/11/2026 — PDV: conferir se o celular está pronto para ficar sem internet

- Em **Este aparelho**, novo bloco "Sem internet": mostra se o app está guardado no celular, de quando é a cópia do sistema e de quem é o PIN guardado, com o botão "Preparar agora". Se faltar algo, diz o que fazer.
- O service worker guarda a página primeiro e cada arquivo por conta própria (antes, uma falha ao ler a lista do Vite impedia guardar a página, e o app não abria sem internet).
- Sem "puxar para atualizar" no PDV (recarregar sem internet é o que levava à tela "You're offline" do Chrome quando o app não estava guardado).
- O service worker passa a ser registrado com a versão no endereço (`/pdv-sw.js?v=...`): a hospedagem pode entregar um `pdv-sw.js` antigo guardado em cache, e o celular nunca pegava a versão nova (era o "não respondeu" de Este aparelho). Com servidor fora do ar (erro 5xx), o PDV abre a página guardada.
- Quando o app não está guardado, o bloco diz o motivo (guardador não instalado, página não guardada com o erro, arquivos faltando, versão do guardador).
- Arquivos: `public/pdv-sw.js`, `resources/js/pdv/offline/prontidao.js` (novo), `resources/js/pdv/telas/Aparelho.jsx`, `resources/js/pdv/main.jsx`, `resources/scss/_pdv.scss`.

## 19/11/2026 — Usuário só do PDV (sem login e sem senha)

- Novo no cadastro de usuário: **"Acessa a gestão"** (ligado por padrão). Desligado, a pessoa fica sem login e sem senha, só com o PIN, e não entra na gestão. Quem virar só PDV com a sessão aberta é desconectado; a senha antiga é apagada. Para voltar a acessar a gestão, precisa de login e senha.
- **Código de cadastro do PIN:** a gestão gera um código de 6 dígitos (vale 24 horas e uma vez; guardado só como hash). No PDV, em Este aparelho › Cadastrar ou trocar meu PIN › **Tenho um código**, a pessoa digita o código e escolhe o próprio PIN, sem o gerente ficar sabendo. Usuário só do PDV criado sem PIN já sai com o código na tela. Na lista de usuários: "Só PDV", "Aguardando PIN" e "gerar código".
- Ninguém tira o próprio acesso à gestão, e sempre sobra um administrador ativo que entra na gestão.
- 265 testes.
- Migração: `2026_12_02_000001_usuarios_so_pdv` (login e senha opcionais, `acessa_gestao`, código do PIN).
- Arquivos: `app/Models/Usuario.php`, `app/Http/Controllers/UsuarioController.php`, `Auth/LoginController.php`, `Pdv/PdvController.php`, `app/Servicos/Pdv/Pins.php`, `app/Http/Middleware/Permissao.php`, `HandleInertiaRequests.php`, `routes/web.php`, `resources/js/Pages/Usuarios/Index.jsx` e `Form.jsx`, `resources/js/Components/CodigoPin.jsx` (novo), `resources/js/pdv/telas/CadastrarPin.jsx`, `tests/Feature/UsuarioSoPdvTest.php`, docs 12 e 13.

## 19/11/2026 — PDV 8.7, primeira entrega: estadias sem internet

- **O PDV abre e registra estadias sem internet.** Entrada e saída (por hora, diária, diária paga na entrada, convênio, mensalista, ticket perdido, dinheiro com troco, cartão, Pix pela chave fixa), busca do ticket e impressão funcionam sem internet. Lavagem, caixa e cancelamento pedem internet nesta entrega.
- Faixa no alto: "Sem internet · N para enviar". Quando a internet volta, a fila sobe sozinha, na ordem, com a hora em que cada coisa aconteceu; nada duplica (código único por ação, também com internet).
- Vale o que o cliente pagou; se o servidor calcular outro valor, a diferença vira pendência. O que não fecha (ticket já finalizado na gestão, etc.) vai para **Operação › Pátio › Pendências do PDV**, onde quem tem "Corrigir no pátio" resolve.
- PIN sem internet: só de quem já usou o PIN naquele celular com internet (o celular guarda só um hash).
- Tarifador também no celular, conferido contra 400 casos do PHP (`npm run test:js`, que passa a rodar no Publicar).
- App do PDV 0.7.0. 258 testes.
- Migração: `2026_12_01_000001_pdv_sem_internet` (tabela `pdv_acoes`, `estadias.numero_impresso`).
- Arquivos: `app/Servicos/Pdv/SemInternet.php`, `app/Models/PdvAcao.php`, `app/Http/Controllers/Pdv/SemInternetController.php`, `EstadiaPdvController.php`, `PdvController.php`, `PatioController.php`, `app/Servicos/Estadias.php`, `HandleInertiaRequests.php`, `routes/api.php`, `routes/web.php`, `resources/js/pdv/offline/*` (novos), `api.js`, `App.jsx`, `main.jsx`, `comum.jsx`, `Pages/Patio/Pendencias.jsx`, `Components/AbasPatio.jsx`, `scss/_pdv.scss`, `public/pdv-sw.js`, `package.json`, `.github/workflows/publicar.yml`, testes (`PdvSemInternetTest`, `TarifadorParidadeTest`, `tests/js/`), docs 12, 13 e 15 (seção 8j).

## 19/11/2026 — PDV: conteúdo não sobe quando as barras do celular aparecem

- O app passa a rolar dentro da própria caixa (`.pdv-app`), não a página. Quando as barras do Android aparecem e a tela muda de tamanho, o conteúdo fica no lugar.
- Na prévia do papel, texto comprido sem espaço (como o link do QR) quebra dentro da bobina em vez de vazar.
- Arquivos: `resources/scss/_pdv.scss`, `resources/js/pdv/visual.jsx` (rolarAoTopo), `App.jsx`, `telas/Lavagens.jsx`, `telas/Caixa.jsx`, `impressao/Papel.jsx`.

## 19/11/2026 — PDV: tela não pula quando as barras do celular aparecem; topo do Início fixo

- Em tela cheia, ao puxar as barras do Android, a tela inteira se deslocava (o espaço do entalhe da câmera mudava). O PDV não ocupa mais a área do entalhe, então nada se mexe. Arquivo: `resources/views/pdv.blade.php` (tirado `viewport-fit=cover`).
- O topo do Início (logo, aparelho e caixa) fica fixo ao rolar, como o das outras telas. Arquivo: `resources/scss/_pdv.scss`.

## 19/11/2026 — Menu do celular não arrasta a página

- Com o menu lateral aberto no celular, rolar o menu (ou o fundo escuro) não rola mais a página de trás. Arquivo: `resources/scss/_layout.scss`.

## 19/11/2026 — PDV: voltar do celular e tela cheia (modo quiosque)

- **Voltar do celular** agora fica dentro do app: faz o mesmo que a setinha do topo (volta uma etapa, ou ao Início) e fecha o PIN e as confirmações. No Início não faz nada; o app nunca fecha pelo voltar. Com a tela fixada (fixar app do Android), o app não trava mais.
- **Tela cheia:** instalado como app, o PDV abre em tela cheia, sem as barras do celular. No navegador, botão "Tela cheia" em Este aparelho (some quando instalado). Topo e resumo descem o espaço do entalhe da câmera.
- Ícones 192 e 512 px no manifesto (o Chrome exige para instalar como app de verdade).
- App do PDV 0.6.1. Para valer a tela cheia num celular que já tinha o PDV instalado: desinstalar e instalar de novo.
- Arquivos: `resources/js/pdv/voltar.js` (novo), `App.jsx`, `visual.jsx`, `comum.jsx`, `Pin.jsx`, `telas/Aparelho.jsx`, `api.js`, `scss/_pdv.scss`, `public/pdv.webmanifest`, `public/imagens/pdv-192.png`, `pdv-512.png`, `pdv-maskable-512.png`.

## 19/11/2026 — Avisos do ERP no popup do PDV

- As mensagens depois de salvar (sucesso, erro, aviso) agora descem do topo da tela, por cima de tudo, no mesmo modelo do PDV: verde some sozinha em 4 s com a barrinha de tempo; erro (laranja) e aviso (amarelo) ficam até clicar no X. Aparecem de novo mesmo se a mensagem se repetir; a atualização automática do Pátio não repete a anterior.
- ERP e PDV usam o mesmo componente (`Components/AvisosTopo.jsx`) e o mesmo estilo (`scss/_avisos-topo.scss`); os estilos de aviso saíram do `_pdv.scss`.

## 19/11/2026 — Pátio unificado na gestão

- **Operação › Pátio** substitui Estadias e Lavagens no menu. Aba **Agora**: estadias e lavagens em andamento numa lista só (estadia com o valor se saísse agora; lavagem com a etapa e "Estadia R$ x" pela regra de Configurações › Etapas, R$ 0 quando não cobra). Aba **Histórico**: as duas juntas por data, com filtro de tipo e situação e os totais.
- A gestão não dá mais entrada nem saída (é do PDV). **Ações** por linha: receber lavagem a receber e, com a nova permissão **Corrigir no pátio**, corrigir placa/modelo/tamanho, cancelar, encerrar estadia esquecida (sem receber, com o valor registrado para conferência, ou na conta de um cliente) e marcar lavagem como entregue.
- Os endereços antigos (/estadias, /lavagens) levam ao Pátio.
- Migração `2026_11_30_000001_patio` (observação da estadia encerrada). 3 testes novos (250).
- Planejamento da 8.7 (modo sem internet e servidor local) registrado em docs/15-plano-pdv.md, seção 8i.

## 19/11/2026 — Editor de impressão do PDV

- **Configurações › Impressão (PDV):** editor visual de três papéis: ticket de entrada (estadia e lavagem), recibo de pagamento (estadia, lavagem, contas de cliente: todo pagamento) e fechamento do caixa. Clique numa linha do papel para alinhar, pôr em negrito ou letra grande, subir, descer ou esconder; insira espaço, linha tracejada ou texto livre. "Voltar ao padrão" por papel.
- **Logo da impressão:** enviado no editor e convertido para preto e branco, do tamanho da bobina.
- O PDV imprime e mostra a prévia pelo modelo salvo (o Teste de impressão também). Papel não editado sai como antes.
- 2 testes novos (247).

## 19/11/2026 — PDV, parte 8.8: visual novo e tema escuro

- **Início novo:** atalhos Entrada (estadia ou lavagem) e Saída (lê o QR de qualquer ticket e abre a saída da estadia ou a entrega da lavagem), cartões com os números do momento (carros no pátio, lavagens em andamento), situação do caixa no topo, Impressão e Este aparelho.
- **Todas as telas:** topo com voltar, título e bolinhas da etapa; resumo fixo em cartão; placa no estilo Mercosul; formas de pagamento com ícone; botões no vermelho da marca.
- **Avisos** descem do topo da tela: sucesso em verde some sozinho; erro fica até tocar no X. Pix recebido vibra e toca um som.
- **Fundo** com os prédios de Santos e o monumento da Tomie Ohtake, fixo no pé de todas as telas.
- **Tema** Automático, Claro ou Escuro em Este aparelho (PIN e pareamento também foram para lá).
- App do PDV 0.6.0. 1 teste novo (245).

## 18/11/2026 — PDV, parte 8.5: Pix com QR

- **Configurações › Banco Inter:** novo quadro "Pix com QR no PDV" com a chave Pix e o botão "Testar Pix".
- **PDV:** escolhendo Pix (estadia, lavagem, receber conta), aparece o QR do valor exato com o copia e cola; quando o Pix cai, a tela mostra "Pix recebido" com o nome de quem pagou, pede o PIN e registra. Desistiu: o QR sai do ar. "Cliente pagou sem o QR" continua possível (fica marcado para conferir no extrato).
- O valor fica parado enquanto o QR está na tela; saída da estadia e entrega da lavagem cobram pela hora do cálculo mostrado (até 20 minutos).
- O pagamento guarda o código do Pix (txid); o mesmo Pix não paga duas coisas nem valor diferente. O extrato do Inter deixa de fora o Pix do QR já registrado. Pix pago que não virou recebimento aparece no fechamento do caixa.
- O app do PDV relê a configuração ao voltar ao Início (antes, ligar o Pix na gestão só valia depois de reabrir o app); o quadro do Pix em Configurações diz por que está desligado (Inter desligado ou falta a chave).
- Migração `2026_11_29_000001_pix_pdv` (tabela `pix_cobrancas`, coluna `pagamentos.pix_txid`). App do PDV 0.5.0. 5 testes novos (244).

## 18/11/2026 — Plano do PDV: parte 8.8 (visual do app)

- Entrou no plano a parte 8.8: estilizar o app do PDV a partir de referências de apps que o Cauê vai escolher (só aparência).

## 18/11/2026 — Busca de cliente no celular

- Corrigido: no celular, tocar num resultado da busca de cliente (ex.: no Início) às vezes abria a tela que estava embaixo da lista (o card "Em aberto" levava a Contas em aberto). A escolha agora acontece no clique, e não no início do toque, então o "clique fantasma" não cai mais no link de baixo.

## 18/11/2026 — PDV: PIN pelo teclado do celular e dados do carro entre estadia e lavagem

- O PIN agora é digitado no teclado numérico do próprio celular (na tela aparecem só as 4 bolinhas sendo preenchidas) (campo com os dígitos escondidos, sem senha salva do navegador), no lugar do teclado na tela; confere sozinho ao completar 4 dígitos e, se errar, o campo volta selecionado.
- Placa avulsa que já passou pela lavagem traz modelo, tamanho e celular na entrada da estadia, e vice-versa (vale a visita mais recente, estadia ou lavagem).
- O PDV avisa o navegador para encolher a tela quando o teclado abre, para nada ficar escondido atrás dele. 1 teste novo (239).

## 18/11/2026 — PDV, parte 8.4: lavagem

- **PDV › Lavagem:** nova lavagem em etapas (placa, modelo e tamanho, serviço com o preço do tamanho, adicionais, celular, na entrega ou pagar agora com troco, ticket próprio), lista das em andamento com a próxima etapa em um toque, e entrega em etapas (convênio escolhido na hora, pagar agora, na conta do cliente ou de outro, recibo com troco).
- **Carro em estadia que pede lavagem:** ticket novo da lavagem e a pergunta do que fazer com a estadia: continuar cobrando ou cancelar (PIN de gerente).
- **Estadia depois de pronta** (Configurações › Etapas da lavagem): cobrar ou não e a tolerância; cobrada na entrega pelo PDV.
- Contas lançadas pelo PDV ficam com origem "pdv"; modelo do veículo avulso fica na venda. 3 testes novos (238).

## 18/11/2026 — Histórico de estadias

- Estadias ganhou abas **Pátio | Histórico** (o histórico existia, mas só por um botão no canto).
- Histórico: filtro por situação (todas, finalizadas, canceladas, no pátio), busca também por modelo, e os **totais do período** (estadias, total cobrado, pago na hora com as diárias pagas na entrada, na conta, convênios). A lista mostra modelo, tamanho, diária marcada, se veio do PDV, ticket perdido e o motivo do cancelamento. 1 teste novo (235).

## 18/11/2026 — PDV, parte 8.3: caixa

- **PDV › Caixa:** receber conta de cliente no balcão (cliente, contas, forma, troco, recibo); **Retirada** com PIN de gerente: sangria (cofre, depósito no banco ou retirada do sócio) ou pagamento de despesa com o dinheiro da gaveta (vira despesa paga em Contas a pagar, com a categoria); **Suprimento**; **Fechar o caixa** (recebido por serviço e por forma, dinheiro que deveria ter, contado, diferença, troco que fica, resumo impresso). O troco deixado abre o próximo caixa.
- Tudo o que é recebido pelo PDV cai no caixa aberto; recebido pela gestão não.
- No fechamento, o que foi recebido em "Receber conta de cliente" aparece numa linha só, **Contas de clientes** (mensalidades e serviços que estavam na conta), separado das estadias e lavagens feitas no dia. Migration `2026_11_28_000002_pagamentos_pdv_tipo`.
- **Gestão › Resultado e caixa › Caixa do PDV:** caixas do mês com o detalhe e as retiradas dos sócios (registrar as que saíram do banco).
- **Resultado do mês:** "(−) Retiradas dos sócios" abaixo do lucro e "Ficou na empresa"; fluxo de caixa mostra a retirada como saída.
- Migration `2026_11_28_000001_caixa_pdv` (caixas, caixa_movimentos, pagamentos.caixa_id). 2 testes novos (234).

## 17/11/2026 — WhatsApp: excluir modelo

- No editor de mensagens (Configurações › WhatsApp › Mensagens), versões já enviadas à Meta ganharam **Excluir**, com confirmação: apaga o modelo na Meta pela API e no ERP (o nome fica bloqueado na Meta por 30 dias; se já tinha sido apagado lá, só some daqui). Não deixa a régua sem mensagem nem tirar a única versão aprovada de uma mensagem da régua. Com a integração desligada, exclui só no ERP e avisa para apagar no Gerenciador do WhatsApp. 1 teste novo (232).

## 17/11/2026 — WhatsApp: primeiro aviso real pela API

- Modelos aprovados pela Meta; aviso de cobrança enviado pela API e entregue, com o botão "Ver cobrança" abrindo a página da cobrança; a situação passou a "Entregue" pelo webhook. Envio automático segue desligado por enquanto.
- Registrado para os arremates: o cliente gerar Pix na página da cobrança quando não houver boleto.

## 17/11/2026 — Listas: inativos embaixo

- Nas listas de cadastro com itens ativos e inativos, os inativos ficam embaixo de todos os ativos: tabelas de estadia (encerradas embaixo das vigentes e futuras), formas de pagamento e contas, tipos de veículo, convênios e unidades. Serviços, usuários, funcionários, fornecedores, cartões, despesas recorrentes e aparelhos já eram assim. Ordens de serviço, cobranças e outras listas por data continuam por data.

## 17/11/2026 — Máscara de valores em todo o sistema

- Todo campo de valor (R$ e %) da gestão e do PDV usa a máscara de maquininha: sempre mostra 0,00 e os números entram pelos centavos (1 → 0,01 → 0,15 → 1,50 → 15,00), com o cursor sempre no fim e o teclado numérico no celular. Vale para contas a pagar, recebimentos, mensalidades, reajuste, pausa, tarifas, serviços, formas de pagamento, convênios, funcionários, fluxo de caixa, juros e multa do boleto (que antes era um campo de número comum) e o troco das estadias.
- PDV: a cada etapa da entrada e da saída, o campo de digitar (placa, modelo, valor recebido, cliente da conta, ticket perdido, motivo do cancelamento) já vem selecionado com o teclado aberto; Enter na placa avança.
- Nas listas de convênio (venda de lavagem, entrada e saída da estadia, saída no PDV) aparece só o nome do convênio, sem o resumo das regras.
- Campos opcionais (preço por tamanho do serviço, taxa por parcela, desconto negociado, saldo inicial do fluxo, limite do convênio): zerado conta como vazio, como antes.

## 17/11/2026 — PDV: entrada e saída da estadia em etapas

- A saída no PDV mostra uma decisão por vez, com o resumo e o **A pagar** fixos no alto: (1) convênio, ticket perdido e **Pagar agora / Na conta de um cliente / Mensalista**; (2) forma de pagamento (ou o cliente da conta); (3) em dinheiro, **Quanto o cliente deu?** (valor exato e notas prontas). Confirmado o valor, vai o PIN e aparece o **troco** em destaque junto com "Imprimir o recibo?". "Voltar" volta uma etapa. Mesmas regras de antes.
- A **entrada** também vai em etapas, com placa, modelo e tamanho fixos no alto: (1) placa (avisos de já no pátio e de mensalista); (2) modelo e tamanho; (3) **Por hora** ou uma das diárias; (4) diária **paga agora** ou **na saída**; (5) forma de pagamento; (6) em dinheiro, quanto o cliente deu. Confirmado, vai o PIN e o troco aparece com "Imprimir o ticket?". Tamanho sem diária na tabela registra direto no passo 2.
- Campo do valor recebido (saída e diária paga na entrada) com máscara de dinheiro: sempre mostra 0,00 e os números entram pelos centavos (1 → 0,01 → 0,15 → 1,50 → 15,00). Na área verde, só o troco.

## 17/11/2026 — PDV, parte 8.2: estadia no PDV

- **PDV › Estadia:** entrada (placa, modelo e tamanho vindos do cadastro ou da última visita; aviso de mensalista e de veículo já no pátio; marcar diária, paga agora ou na saída; troco), saída (QR pela câmera, número ou placa; convênio escolhido na saída; paga agora, na conta de um cliente ou mensalista; ticket perdido com nome e documento), lista do pátio e cancelar entrada com PIN de gerente. Cada ação pede PIN e pergunta se imprime o ticket ou o recibo.
- **Impressão:** o PDV usa o último caminho que deu certo no Teste de impressão.
- **Gestão › Estadias:** modelo e diária na entrada; convênio, troco e ticket perdido na saída; pátio e saídas do dia mostram modelo, diária e "PDV".
- **Permissão nova:** "Gerente no PDV" (Níveis de acesso › Operação), para cancelar no PDV (depois: fechar caixa, sangria e suprimento).
- **API:** `/api/pdv/estadias/...` (opções, pátio, placa, buscar, cálculo, entrada, saída, cancelar) e `/api/pdv/clientes`. Pagamento feito pelo PDV fica com origem "pdv" e o aparelho.
- Migration `2026_11_27_000001_estadias_pdv` (estadias: código do QR, modelo, diária, pago na entrada, quem retirou com ticket perdido, aparelho; pagamentos: aparelho). 5 testes novos (231).

## 17/11/2026 — PIN do PDV pela gestão e campos de PIN

- **Usuários › novo/editar:** campo opcional "PIN do PDV" (com repetição), mesmas regras do PDV (4 números, nada óbvio, único; repetido cancela o do outro). PIN recusado no cadastro novo: o usuário é criado e a tela volta para a edição pedindo outro PIN.
- **Campos de PIN** (gestão e PDV): abrem o teclado numérico e escondem os números sem ser campo de senha, então o navegador não preenche mais a senha salva no lugar do PIN.
- 1 teste novo (226).

## 17/11/2026 — PDV, parte 8.1: aparelhos, pareamento, PIN e teste de impressão

- **Configurações › Aparelhos (PDV):** cadastra o celular do balcão e gera um código de 6 dígitos (15 min, uso único); "Parear de novo" e "Desativar" cortam o celular antigo na hora; memória do PIN (a cada ação ou 1 a 10 min).
- **App do PDV em `/pdv`** (React próprio, instalável pelo Chrome): pareamento pelo código, tela inicial, testar PIN, cadastrar/trocar PIN (com login e senha do sistema) e **teste de impressão** (ticket de exemplo por Bluetooth BLE, app RawBT ou impressão do Android; 80/58 mm, acentos, QR como imagem).
- **API `/api/pdv`** (`routes/api.php`, sem sessão): token do aparelho (só o hash no banco), PIN conferido em cada ação (5 erros travam o aparelho 1 minuto). PIN guardado como HMAC, único na empresa; PIN repetido cancela o do outro. Usuários mostra quem tem PIN, com "apagar". Registro de atividades com "· PDV: aparelho".
- Ideia registrada no fim de `docs/15-plano-pdv.md`: gestão de pátio com câmera de placa (para depois). Consulta de placa em serviço externo descartada.
- Migration `2026_11_26_000001_create_aparelhos` (tabela `aparelhos`, `pin_hash` nos usuários). 8 testes novos (225).

## 17/11/2026 — Convênio por serviço e "na conta de" outro cliente

- **Na conta de outro cliente:** na venda de lavagem e na saída da estadia, "Na conta de um cliente" abre o campo **Na conta de** (vem com o dono do veículo; "trocar" busca qualquer cliente). A ordem fica com a placa que entrou e o cliente que paga. Ex.: mensalista traz o carro do pai e a lavagem vai para a mensalidade dele.
- **Convênio por serviço:** a tela do convênio lista a Estadia e cada serviço (lavagens e adicionais); cada um marcado tem pagamento integral, pagamento parcial (o convênio paga até um valor; o que passar, quem usou paga) ou limite de tempo (só estadia). Serviço não marcado: quem usou paga. Sai o modo "desconto" (convênio nele aparece "Reconfigurar"). Na venda, quando o convênio paga só parte, o excedente vira uma conta de quem usou, ligada à mesma venda.
- **Fechamento do convênio:** junto com a mensalidade, a cada X dias ou toda semana num dia. No dia (ou depois, se passou), o Início e a lista de convênios avisam com o botão **Gerar cobrança**. A cada X dias ou semanal, a cobrança leva só as contas do convênio (pode haver mais de uma no mês) e vence em 7 dias; com a mensalidade, a cobrança do mês. O aviso no PDV entra com o PDV.
- **Paralelo:** o convênio continua vindo do sistema atual; as regras por serviço são montadas a partir do modo de lá a cada importação, até o convênio ser salvo no ERP.
- Migration `2026_11_25_000001_convenio_por_servico` (tabela `convenio_regras`, fechamento no convênio; converte os convênios existentes). 5 testes novos (217).

## 17/11/2026 — Etapa 8 (PDV): plano aberto

- Novo documento `docs/15-plano-pdv.md`: o que já existe e é reaproveitado, escopo da primeira versão (um aparelho e uma impressora), como fica montado (app separado em `/pdv`, API com token do aparelho, servidor calcula tudo), Pix imediato com confirmação por consulta (o aviso do Inter exige mTLS, que a Hostinger não tem), caminhos de impressão conforme a impressora, ordem de construção (8.1 a 8.7) e as decisões em aberto.
- Decisão: o PDV começa com um aparelho; o desenho segue aceitando vários.
- Respostas do Cauê (decisões 1 a 4): celular Android (Samsung de entrada a comprar; testes no A56), impressora de 80 mm a comprar, balcão hoje todo em papel, PDV entra junto da virada. Decisões 5 a 7: mensalista não registra entrada; ticket perdido pela tabela normal com identificação de quem retira; conteúdo do ticket impresso (com tamanho do veículo e espaço para carimbo do convênio). Decisões 8 a 12: impressão perguntando, fechamento diário pelo gerente, PIN de gerente para sangria/cancelamento/desconto, atendentes com nível operador, crédito e débito separados; o PDV ganha receber conta de cliente. Decisão 13: a permissão de Pix do Inter já cobre a cobrança imediata e só tem itens de receber (devolução vai só para quem pagou); estorno por devolução do Inter anotado como ideia. Fluxo da estadia avulsa (desenho do Cauê) registrado na seção 8a do plano: convênio na saída, troco na tela, leitura do ticket pela câmera. Fluxo da lavagem na seção 8b: serviço e adicionais na entrada, "Pronta" com PIN, estadia depois de pronta configurável com tolerância, "Pagar agora" como exceção. Estadia com opção de marcar diária na entrada (paga na entrada ou na saída; excedente pela regra da tabela).
- `07-pdv.md`, `10-plano-de-etapas.md`, `12-decisoes.md` e o índice atualizados. Nenhum código nesta entrada.

## 16/11/2026 — Mensagens do WhatsApp: cabeçalho e rodapé

- No editor de mensagens: **cabeçalho** (linha em negrito no topo, até 60 caracteres, até uma informação variável, sem emoji nem formatação) e **rodapé** (linha cinza no fim, até 60 caracteres, texto fixo). Vão para a Meta como HEADER (texto) e FOOTER; no envio pela API, o valor da informação do cabeçalho vai junto.
- No envio manual (wa.me) não há cabeçalho nem rodapé: o cabeçalho entra em negrito no começo do texto e o rodapé no fim.
- Os botões de informação entram onde está o cursor (cabeçalho ou texto). A prévia mostra *negrito*, _itálico_ e ~tachado~ como no WhatsApp.
- Mídia no cabeçalho (imagem, documento) fica para a etapa de marketing.
- Migration `2026_11_24_000002_add_cabecalho_rodape_to_modelos_mensagem`. 1 teste novo (212).

## 16/11/2026 — WhatsApp: área para editar as mensagens

- **Configurações › WhatsApp › Mensagens** (`/configuracoes/whatsapp/mensagens`): criar e editar as mensagens do WhatsApp antes de valerem. Nome (só no sistema), tipo (aviso de cobrança ou propaganda), texto com botões para inserir as informações ({nome}, {empresa}, {valor}, {vencimento}, {valor_com_desconto}, {desconto_ate}, {placa}), botão "Ver cobrança" opcional e prévia em balão de WhatsApp com um cliente de exemplo.
- **Rascunho** fica só no ERP e já vale no envio manual (wa.me). **Enviar para aprovação** manda para a Meta (as informações viram {{1}}, {{2}}... com os exemplos obrigatórios) e trava a versão. A situação (em análise, aprovado, recusado com o motivo em português) vem da Meta ao abrir a tela e pelo webhook (campo `message_template_status_update`).
- **Versões:** editar uma mensagem que já foi para a Meta cria a versão seguinte (nome_v2...). Pela API continua saindo a última versão aprovada até a nova ser aprovada; aí troca sozinho.
- O sistema confere antes de enviar o que a Meta costuma recusar: informação no começo ou no fim do texto, duas seguidas, informação desconhecida, pouco texto para muitas informações, mais de 1024 caracteres.
- **Régua:** cada passo escolhe a mensagem (só avisos de cobrança). Aviso de passo sem mensagem aprovada fica esperando na rotina automática (não vira falha); no envio pela API na mão, mostra o motivo.
- Os avisos ainda não enviados mostram o texto com a versão atual da mensagem.
- **Propaganda:** dá para escrever e aprovar (categoria marketing, sem as informações da cobrança, fora da régua). O envio de campanhas, com o consentimento do cliente, fica para uma próxima etapa.
- As três mensagens atuais viram a versão 1 de "Lembrete", "Vence hoje" e "Em atraso", com os mesmos nomes na Meta. O botão "Criar na Meta" saiu (agora é por mensagem).
- Migration `2026_11_24_000001_create_modelos_mensagem`. `App\Servicos\WhatsApp\Modelos` reescrito; `MensagemController`; tela `Configuracoes/WhatsAppMensagens`. 4 testes novos (211).

## 16/11/2026 — Desconto de pontualidade na cobrança (opção, começa desligada)

- Nova opção em **Configurações › Banco Inter › Regras do boleto**: "Desconto de pontualidade vale para qualquer forma de pagamento". **Desligada** de início: tudo continua como antes (desconto só no boleto).
- Ligada, o desconto das regras do boleto (só mensalidade do mês, até o vencimento) aparece na **tela da cobrança** ("Pagando até X: R$ …"), na **cobrança impressa** (linha abaixo do Total), na **página pública** `/c/<código>` e entra no **balcão (Receber)** e na **conferência do extrato**: pago até o vencimento, a diferença pode ser lançada como "Desconto de pontualidade" sem precisar da permissão de desconto. Depois do vencimento, ou diferença maior que o desconto, continua exigindo a permissão.
- Cobrança com desconto negociado não tem desconto de pontualidade (como no boleto). Com boleto emitido, vale o desconto que o Inter calculou.
- Serviço `App\Servicos\Pontualidade`; `Baixa::receber` aceita a falta "desconto" quando é pontualidade. Sem migration (a opção fica nas configurações da empresa). 5 testes novos (207).

## 16/11/2026 — Abas das Configurações quebram linha

- As abas de Configurações (Empresa e unidades, Tipos de veículo... WhatsApp) ficavam numa linha só, rolando para o lado, e parte sumia da tela. Agora quebram linha e ficam todas à vista, como botões arredondados; a aba atual fica destacada. No celular, um pouco menores.

## 16/11/2026 — Avisos: reenviar

- Em Cobranças › Avisos, os avisos já enviados ganham **Reenviar** (abre o WhatsApp com o texto de novo) e, com a API ligada, **Reenviar pela API**. Antes, o aviso enviado à mão sumia de "Para enviar" e não dava mais para mandar pela API. Reenvio que falha não muda o aviso (ele já tinha saído); só mostra o erro. Se a cobrança não está mais em aberto ou o celular é inválido, aparece "Não dá para reenviar".

## 16/11/2026 — Botões de WhatsApp e Ligar nos clientes

- Ficha do cliente (quadro Dados) e lista de clientes: botão **WhatsApp** (abre a conversa no wa.me; só aparece se o número for de celular, DDD + 9 + 8 dígitos) e **Ligar** (abre o discador no celular ou o aplicativo de chamadas no computador). Na lista, só os ícones; no celular, o botão "Abrir" sai da lista (a linha toda já abre o cliente). Componente `Contato`.

## 16/11/2026 — WhatsApp: régua de cobrança (etapa 7b)

- **Decisão:** API oficial da Meta (Cloud API), direto, sem plataforma intermediária, com número próprio para os avisos. Empresa verificada no Meta Business e app "ERP Local Estacionamento" criado em 16/11/2026.
- **Régua de cobrança** (`App\Servicos\WhatsApp\Regua`): cada cobrança em aberto recebe um aviso por passo (padrão: 3 dias antes, no dia, 3 e 10 dias depois do vencimento), uma vez só. Com boleto, conta o vencimento do boleto. Para quando a cobrança é paga ou cancelada. Ligar a régua tarde não despeja avisos atrasados (tolerância de 2 dias; só o passo mais recente). Cliente "não recebe avisos" fica de fora; celular inválido aparece com o motivo.
- **Cobranças › Avisos** (`/avisos`): lista do dia com "Abrir WhatsApp" (wa.me, texto pronto, marca como enviado), "Enviar pela API"/"Enviar todos" quando a API está ligada, "Dispensar" e os enviados (enviado, entregue, lido).
- **Configurações › WhatsApp**: credenciais (token, ID do número, ID da conta, chave secreta do app) guardadas criptografadas, teste de conexão, régua (passos, horário, envio automático), modelos de mensagem (3 textos fixos, criados na Meta pelo botão; aprovação da Meta) e os dados para colar no app da Meta (URL e código do webhook, URL da política de privacidade).
- **Envio automático:** rotina `whatsapp:regua` a cada 10 minutos (7h–20h): cria os avisos do dia e, com a API ligada e o automático marcado, envia a partir do horário escolhido.
- **Webhook** `/webhooks/whatsapp`: confirmação do endereço e status de entrega (entregue, lido, falha); confere a assinatura quando a chave secreta do app está guardada.
- **Página pública da cobrança** `/c/<código>`: valor, vencimento, Pix (QR e copia e cola) e linha digitável do boleto, itens. É o botão "Ver cobrança" dos avisos. **Política de privacidade** pública em `/privacidade` (exigida pela Meta para publicar o app).
- Migration `2026_11_23_000001_create_avisos_whatsapp` (tabela avisos, cobrancas.token).
- **Ainda não:** textos editáveis (são fixos, iguais aos modelos da Meta), receber e mostrar as respostas dos clientes, resposta automática, envio do recadastro em lote pela API.

## 16/11/2026 — Extrato: entrada já baixada no sistema atual; totais em Recebimentos

- **Problema:** mensalidade baixada à mão no sistema atual e, depois, o Pix dela confirmado no Extrato bancário do ERP. Como o cliente não tinha mais conta em aberto, a conferência lançou um pagamento novo (Pix) que virou crédito do cliente: o dinheiro contou duas vezes em "Por forma de pagamento" e não apareceu em "Pelo que foi pago".
- **Conferência do extrato:** quando o cliente tem uma baixa vinda do sistema atual com o mesmo valor e data próxima (até 10 dias), a entrada mostra o aviso e vem marcada como **Só registrar**: não lança pagamento nem crédito, só registra a entrada e passa a forma escolhida (Pix) para o pagamento de lá. A importação reaplica a forma a cada hora (`Extrato::religarRegistros`). Cliente sem contas em aberto e sem essa baixa: aviso em vermelho de que o valor vai virar pagamento novo/crédito.
- **Recebimentos:** linha de **Total** nas duas tabelas; quando os totais diferem, o rodapé mostra de quanto é a diferença e os motivos possíveis.
- Migration `2026_11_22_000001_add_so_registro_to_extrato_lancamentos`.

## 14/11/2026 — Celular gravado no campo telefone

- Cliente com o celular vazio e um número de celular (DDD + 9 + 8 dígitos, com ou sem 55) no campo telefone: o número passa para o celular e o telefone fica vazio. Feito na importação (a cada hora, para não voltar) e uma vez para todos na publicação (migration `2026_11_21_000005_celular_no_campo_telefone`, inclui os clientes criados no ERP). Fixo de verdade, número com cara de inventado, número sem o 9 e cliente que atualizou o telefone pelo recadastro não mudam. `App\Support\Telefone`.
- Sistema atual: `celular-legado.sql` (entregue à parte, rodado pelo Cauê no phpMyAdmin) faz a mesma correção na origem.

## 14/11/2026 — Placas sem traço

- Placa exibida sempre junta, só letras e números (antes as antigas apareciam como "ABC-1234" e as Mercosul juntas). Os campos de placa só aceitam letras e números.
- Migration `2026_11_21_000004_placas_sem_traco`: tira traço e espaço de todas as placas gravadas (veículos, histórico de placas, fila de cadastro, ordens, itens de cobrança, estadias). Na identificação de veículo sem placa, só o que for placa brasileira entra no padrão (o resto é texto livre: quadro, série, apelido).
- A importação já gravava a placa limpa; a identificação de placa repetida também passa a vir limpa.
- Sistema atual: `placas-legado.sql` (entregue à parte, rodado pelo Cauê no phpMyAdmin) tira espaço e traço das placas de lá, com conferência antes e depois.

## 14/11/2026 — Código dos clientes: correção do cliente duplicado

- **Problema:** o código do cliente é o id. O cliente de teste, criado no ERP, ficou com o 1009. O Matheus mandou a ficha pelo link do sistema atual, que foi aprovada primeiro no ERP por engano (virou 1010, com a placa) e depois no sistema atual (1009 lá); a importação o trouxe como 1011, sem placa e sem CPF por estarem repetidos.
- **Correção automática na publicação** (migration `2026_11_21_000003_corrigir_numeracao_clientes`, serviço `NumeracaoClientes`): o cadastro criado no ERP que é o mesmo cliente de um importado (mesmo CPF ou mesma placa) é **excluído** com veículo, mensalidade e contas geradas no ERP, liberando o código (só se não tiver pagamento, boleto ou outro registro; senão fica um aviso). O CPF e a placa passam para o importado. Importado com código diferente volta ao código do sistema atual; cliente criado no ERP que estiver nesse código vai para o próximo número livre. Cada mudança fica no Registro de atividades ("Sistema").
- A numeração continua a sequência normal (sem faixa separada para o ERP).
- **Fila de espera:** ficha que veio do link do sistema atual não se aprova nem se recusa mais no ERP (selo "Aprovar no sistema atual" e aviso na revisão).

## 14/11/2026 — Mensalistas: marcar os cadastrados só no ERP

- Clientes › Mensalistas contava também os contratos criados no ERP (cliente de teste, cadastro feito aqui), por isso ficava acima do relatório do sistema atual, enquanto a Conferência (que compara só o que veio do sistema atual) dava "Igual". Agora esses veículos têm o selo **Só no ERP** na lista e a nota abaixo diz quantos são e quanto somam.

## 14/11/2026 — Extrato e saldo pela API do Inter

- **Extrato bancário › Banco Inter:** saldo disponível no Inter (carregado depois da tela, guardado por 10 minutos) e **Buscar do Inter** por período (até 90 dias, sem datas futuras). Abre a mesma conferência do CSV: o sistema sugere o cliente de cada Pix (nome e CPF/CNPJ do pagador vêm da API) e nada é baixado sem confirmar. O CSV continua como reserva.
- Recebimentos de boleto (e o Pix do boleto, pelo txid, código ou nosso número) não entram: já foram baixados pela cobrança; a conferência mostra quantos ficaram de fora. Pix já baixado antes pelo CSV (outro identificador, mesma data, valor e pagador) aparece como processado.
- **Saídas do período** na conferência: cada saída é comparada com contas pagas e estornos a clientes (mesmo valor, até 3 dias de diferença); as que não batem aparecem como "Sem registro", com atalho para lançar a conta paga.
- Token do extrato pedido à parte (permissão `extrato.read`): se a permissão faltar, o boleto continua funcionando.
- **Aviso de vencimento do certificado** do Inter (30 dias antes) no Início e em Configurações › Banco Inter: boleto, baixa automática e extrato param juntos quando vence.

## 14/11/2026 — Estorno ao cliente

- **Estornar** (ficha do cliente, com crédito a favor dele; permissão de editar contas): registra a devolução do crédito. O Pix ou a transferência é feito no app do banco; aqui vão valor (até o crédito livre, descontadas as contas em aberto), data, como foi devolvido (Pix, transferência, dinheiro) e motivo obrigatório. Usa os créditos mais antigos primeiro; se o estorno for parcial, a sobra vira um crédito novo. Lista "Estornos" na ficha, com **Desfazer** (o crédito volta).
- O estorno entra no **fluxo de caixa** como saída ("Estorno a cliente") e no **Resultado pelo olhar de caixa** como "(−) Estornos a clientes" (no olhar de referência não entra: o crédito nunca foi receita do mês). O extrato do cliente mostra a linha do estorno.
- O antigo "Estornar" dos pagamentos (ficha e Recebimentos) passou a se chamar **Desfazer** ("Desfeito" no selo): desfaz um pagamento lançado errado e as contas voltam a ficar em aberto. Não devolve dinheiro.
- Migration `2026_11_21_000002_create_estornos`. Serviço `App\Servicos\Estornos`.

## 14/11/2026 — Desconto negociado na cobrança; Inter confirmado em produção

- **Desconto negociado** (só na cobrança unitária): campos "Desconto negociado" e "Motivo do desconto" na emissão pela ficha do cliente e no quadro do boleto da cobrança. Exige a permissão de dar desconto; o motivo é obrigatório. O total das ordens continua o mesmo (relatórios); a tela da cobrança e a impressão mostram Total, Desconto e **Total a pagar**. O boleto sai com o valor em aberto menos o desconto e, nesse caso, sem o desconto de pontualidade. Pago, a diferença vira desconto na baixa, com o motivo. Com boleto ativo, o desconto só muda cancelando o boleto. Migration `2026_11_21_000001_add_desconto_to_cobrancas` (cobrancas.desconto, desconto_motivo, desconto_por).
- **Inter confirmado em produção:** boleto emitido, QR Code e Pix na tela sozinhos, pago com Pix e baixa automática conferida (cobrança e mensalidade pagas).
- **Registrado para o PDV** (docs/07-pdv.md): Pix com confirmação automática na tela, sem botão de atualizar.

## 14/11/2026 — Boleto de cobrança vencida

- **Correção:** "Gerar boleto" numa cobrança cujo vencimento já passou não fazia nada na tela. O sistema recusava, mas a mensagem não aparecia. Agora o erro do boleto aparece no quadro do boleto.
- **Vencimento do boleto sempre editável:** o quadro do boleto mostra o campo "Vencimento do boleto", já preenchido com o vencimento da cobrança + o prazo do boleto (Configurações › Banco Inter). Se essa data já passou, a sugestão vai para o mesmo dia no mês seguinte. Dá para trocar a data (entre hoje e 90 dias). A cobrança continua com o vencimento original; só o boleto vence na data do campo.
- A geração em lote (Gerar cobranças com boleto) segue a mesma regra: cobrança vencida sai com o boleto para o mês seguinte, em vez de dar erro.
- O desconto de pontualidade só entra se a cobrança ainda não venceu (vale até o vencimento combinado); multa e juros contam a partir do vencimento do boleto.

## 13/11/2026 — Recadastro (atualização cadastral dos clientes)

- **Clientes › Recadastro** (`/atualizacao-cadastral`, permissão de aprovar cadastros): resumo e duas tabelas, **CPF/CNPJ com problema** (dígitos que não conferem, quantidade errada, em branco, repetido em outro cliente) e **sem celular válido** (sem celular, telefone fixo, sem o 9, formato inválido, número suspeito tipo 99999-9999). Contador no menu com as respostas para revisar.
- **Cartões: escolher o que imprimir.** A tela de cartões abre com todos os links abertos (ou só os clientes marcados na lista) e tem busca, marcar/desmarcar todos e um checkbox por cliente. Os cartões escolhidos são distribuídos em folhas A4 em pé, 8 por folha (2 × 4), com quebra de página entre as folhas. "Marcar como entregues" vale só para os escolhidos.
- **Link individual** por cliente (código aleatório de 40 caracteres, válido 30 dias; gerar de novo mantém o aberto, "Novo link" cancela o anterior). Envio por **WhatsApp** (abre a conversa com a mensagem pronta, pelo celular de quem envia), **copiar** a mensagem, ou **cartão impresso** (folha A4 com 8 cartões e QR Code, para quem não tem celular válido; o cartão não mostra a placa). Enquanto o link estiver aberto, o **QR Code sai no pé da cobrança impressa**.
- **Formulário do cliente** (`/atualizar/<código>`, celular): confirma a placa de um veículo dele (5 erros bloqueiam 30 minutos); depois vê os dados para conferir. O CPF não aparece inteiro: ele digita o CPF completo para confirmar (pega os inventados). Celular tem que ser celular válido. Autorização LGPD.
- **Revisão:** tabela antes → depois com o que mudou destacado; Aprovar ou Recusar (com motivo). CPF já usado por outro cliente não aprova.
- **Paralelo (decisão a):** ao aprovar, os campos que mudaram passam a valer pelo ERP (`clientes.campos_locais`) e o espelho do sistema atual não os sobrescreve mais; os outros campos continuam vindo do sistema atual.
- Banco: tabela `atualizacoes_cadastrais` e coluna `clientes.campos_locais` (`php artisan migrate` no PC; o Publicar já roda).
- **180 testes automáticos.**
- Arquivos: `app/Servicos/Atualizacoes.php`, `app/Models/AtualizacaoCadastral.php`, `app/Http/Controllers/AtualizacaoController.php`, `AtualizarPublicoController.php`, `CobrancaController.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `app/Console/Commands/Concerns/ImportaCadastros.php`, `app/Models/Cliente.php`, `routes/web.php`, migration `2026_11_20_000001`, `resources/js/Pages/Atualizacao/` (Index, Revisar, Cartoes, Publico), `resources/js/Pages/Cobrancas/Imprimir.jsx`, `resources/js/Layouts/AppLayout.jsx`, `resources/js/lib/menu.js`, `resources/scss/_impressao.scss`, `tests/Feature/AtualizacaoCadastralTest.php`, `ImportacaoCadastrosTest.php`.

## 13/11/2026 — Telas com a mesma largura (grid do Bootstrap) e prazo do boleto na aba do Inter

- **Prazo do boleto** saiu de Configurações gerais e foi para Configurações › Banco Inter, junto das regras (só faz sentido com o Inter ligado). Rótulos mais curtos: "Prazo do boleto (dias)" e "Baixa automática (dias)", lado a lado.
- **Todas as telas com a largura do conteúdo.** Saíram as larguras fixas (formulários de 640 px, cobrança, extrato do cliente, tipos de veículo, etapas, plano de contas, formas de pagamento). Os campos se distribuem pelo grid do Bootstrap (`row`/`col-*`): em tela grande ficam lado a lado; no celular, um embaixo do outro.
- Telas com mais de um quadro lado a lado: Banco Inter (credenciais | regras), Receber (contas | pagamento), Formas de pagamento (formas | contas), Nova venda, Primeira cobrança, Novo convênio e Serviço (dados | preços). Empresa e unidades: "Cobrança impressa" foi para a coluna da direita.
- Formulários de cliente, veículo, mensalidade, usuário, unidade, nível (permissões em colunas), lançar, conta a pagar e extrato bancário reorganizados em colunas. O cadastro público (QR Code) continua estreito.
- Arquivos: `resources/scss/app.scss`, `resources/js/Components/CamposCliente.jsx`, `CamposVeiculo.jsx`, `CamposMensalidade.jsx`, `RegrasBoletoCliente.jsx`, `resources/js/Pages/` (Clientes/Form e Extrato, Veiculos/Form, Pendentes/Revisar, Usuarios/Form, Empresa/UnidadeForm e Edit, Niveis/Form, Convenios/Form, Lavagens/Nova, Mensalidades/Primeira, Ordens/Lancar, Pagar/Form, Contas/Receber, Extrato/Index, Cobrancas/Show, Configuracoes/ServicoForm, Pagamentos, Tipos, Etapas, PlanoContas, Inter), `app/Http/Controllers/InterController.php`, `EmpresaController.php`, `tests/Feature/InterTest.php`.

## 13/11/2026 — Desconto de pontualidade só nas mensalidades e prazo do boleto

- **Prazo do boleto** (depois movido para Configurações › Banco Inter): quantos dias depois do vencimento do cliente o boleto vence (ex.: mensalidade dia 5, boleto dia 10 = 5; 0 a 30, padrão 0).
- **Desconto de pontualidade:** vale até o vencimento do cliente (o boleto vence depois, pelo prazo); multa e juros só depois do vencimento do boleto. O ERP calcula o valor e manda ao Inter em reais: % sobre a soma das mensalidades do mês, ou R$ fixo por mensalidade do mês. Lavagens, estadias, débitos e mensalidade atrasada (saldo anterior) não ganham desconto. Cobrança sem mensalidade do mês: boleto sem desconto. Saiu o campo "vale até quantos dias antes do vencimento".
- **Boleto em emissão:** a tela da cobrança consulta o Inter a cada 5 segundos por até 2 minutos ("Aguardando o banco…") e mostra o boleto quando fica pronto, sem clicar em Atualizar.
- **174 testes automáticos.**
- Arquivos: `app/Servicos/Inter/Boletos.php`, `app/Http/Controllers/BoletoController.php`, `EmpresaController.php`, `resources/js/Pages/Empresa/Edit.jsx`, `resources/js/Pages/Configuracoes/Inter.jsx`, `resources/js/Components/BoletoCobranca.jsx`, `RegrasBoleto.jsx`, `RegrasBoletoCliente.jsx`, `tests/Feature/InterTest.php`, docs 06, 12 e 13.

## 13/11/2026 — Resultado: multa e juros e descontos em linhas próprias

- **Multa e juros recebidos** (boleto pago com atraso) ganharam linha própria no Resultado, nos dois olhares (por referência e pelo dinheiro), pela data do pagamento. Antes, no olhar por referência, não entravam.
- **(−) Descontos dados** aparece também no olhar pelo dinheiro (antes, lá, o desconto virava "outras receitas" negativas). Vale para o desconto do boleto e para o dado no Receber contas.
- Arquivos: `app/Servicos/Resultado.php`, `app/Servicos/Baixa.php`, `tests/Feature/InterTest.php`.

## 13/11/2026 — Boleto sem as regras na primeira mensalidade

- Ao gerar o boleto (tela da cobrança, "Emitir cobrança" na ficha e emissão em lote) há a caixa **"Aplicar as regras do boleto (multa, juros e desconto)"**. Desmarcada, o boleto sai sem multa, juros e desconto; a baixa automática continua.
- A caixa já vem **desmarcada** quando a cobrança tem a primeira mensalidade do contrato ou uma mensalidade proporcional. No lote, essas cobranças saem sem as regras (selo "Boleto sem regras"); desmarcar no lote tira as regras de todos.
- O boleto guarda que saiu sem as regras ("sem multa, juros e desconto" na tela da cobrança).
- Gerar boleto agora espera alguns segundos pelo Inter antes de voltar, para já mostrar a linha digitável e o Pix (sem precisar clicar em Atualizar).
- **173 testes automáticos.**
- Arquivos: `app/Servicos/Inter/Boletos.php`, `app/Http/Controllers/BoletoController.php`, `CobrancaController.php`, `ClienteController.php`, `resources/js/Components/BoletoCobranca.jsx`, `ContasCliente.jsx`, `resources/js/Pages/Cobrancas/Show.jsx`, `Gerar.jsx`, `resources/js/Pages/Clientes/Show.jsx`, `tests/Feature/InterTest.php`, docs 12 e 13.

## 13/11/2026 — Banco Inter: correção no teste de conexão

- O "Testar conexão" falhava com "CURLOPT_SSLCERT_BLOB ... not supported": o Guzzle não aceita o certificado em memória. Agora o certificado e a chave vão para arquivos temporários em `storage/app/private/inter-tmp` (fora da pasta pública, só o dono lê), apagados ao fim de cada conexão; sobra de processo interrompido é apagada depois de 10 minutos.
- Arquivo: `app/Servicos/Inter/InterCliente.php`.

## 13/11/2026 — Banco Inter: boleto com Pix (etapa 7)

- **Configurações › Banco Inter:** credenciais (client id, client secret, certificado .crt e chave .key, ambiente produção ou testes, conta corrente opcional), guardadas criptografadas; "Testar conexão" liga a integração; aviso quando o certificado está para vencer. Regras do boleto: multa (% ou R$), juros (% ao mês ou R$ por dia), desconto (% ou R$ até X dias antes do vencimento) e baixa automática (0 a 60 dias depois do vencimento, padrão 30).
- **Regras específicas por cliente:** na ficha do cliente, "Regras do boleto" com a escolha entre as regras da empresa e regras específicas dele.
- **Gerar boleto:** caixa "Gerar boleto com Pix" ao emitir cobrança (uma ou em lote) e botão na tela da cobrança. O Inter exige CPF/CNPJ, CEP, endereço, cidade e UF do cliente: a tela avisa o que falta. Valor mínimo R$ 2,50; vencimento não pode ter passado.
- **Tela da cobrança:** situação do boleto, linha digitável e Pix copia e cola (com QR Code) para copiar, PDF do Inter, Atualizar, Cancelar boleto (quem altera contas). Lista de cobranças mostra a situação do boleto.
- **Consulta a cada 10 minutos** (`inter:consultar` no agendador): boleto pago dá baixa nas ordens da cobrança (forma Boleto, ou Pix se pago pelo QR Code); multa e juros entram como "Multa e juros do boleto"; desconto da regra vira desconto; se a cobrança já estava paga, o valor vira crédito do cliente.
- **Cancelamento automático:** cobrança paga por outro meio (receber contas), cancelada ou levada para a cobrança seguinte tem o boleto cancelado no Inter.
- Banco: tabelas `integracoes` e `boletos`, coluna `clientes.regras_boleto` (rodar `php artisan migrate` — o Publicar já roda).
- **172 testes automáticos** (10 novos, com a API do Inter simulada).
- Arquivos: `app/Servicos/Inter/` (InterCliente, Boletos, InterErro), `app/Models/Integracao.php`, `app/Models/Boleto.php`, `app/Console/Commands/InterConsultar.php`, `app/Http/Controllers/InterController.php`, `app/Http/Controllers/BoletoController.php`, `app/Http/Controllers/CobrancaController.php`, `app/Http/Controllers/ContaController.php`, `app/Http/Controllers/ClienteController.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `app/Servicos/Baixa.php`, `app/Models/Cliente.php`, `routes/web.php`, `routes/console.php`, migrations `2026_11_19_000001` e `000002`, `resources/js/Pages/Configuracoes/Inter.jsx`, `resources/js/Components/BoletoCobranca.jsx`, `RegrasBoleto.jsx`, `RegrasBoletoCliente.jsx`, `ContasCliente.jsx`, `resources/js/Pages/Cobrancas/Show.jsx`, `Index.jsx`, `Gerar.jsx`, `resources/js/Pages/Clientes/Show.jsx`, `resources/js/lib/menu.js`, `tests/Feature/InterTest.php`, docs 06, 10, 12, 13 e 14.

## 13/11/2026 — Tabelas de estadia: começar imediatamente e seletor de tempo

- **Reajustar** ganhou a opção **Começar imediatamente**: a nova versão vale a partir de hoje (a anterior termina ontem). Aviso: veículos que entraram hoje, mesmo antes da mudança, saem pela tabela nova. Sem marcar, continua começando amanhã ou na data escolhida.
- **Seletor de tempo** nos campos de minutos (tolerância, fração, duração da faixa, bloco e duração do pacote): o campo continua em minutos e pode ser digitado; o relógio abre horas e minutos com atalhos (15 min a 24h) e converte (12h → 720 min).
- Campos dos pacotes e das faixas reorganizados para caber o relógio. A data padrão da tela usa o dia local (antes, depois das 21h, saía o dia seguinte).
- Durante o paralelo, mudança de preço continua sendo feita no sistema atual: o espelho de hora em hora sobrescreve as tabelas que vieram de lá.
- Arquivos: `resources/js/Components/CampoMinutos.jsx` (novo), `resources/js/Pages/Configuracoes/TarifaForm.jsx`, `resources/scss/_layout.scss`.

## 13/11/2026 — Entrada de estadia mostra e deixa trocar o tipo do veículo

- O campo **Tipo do veículo** aparece sempre na entrada, já preenchido pelo cadastro da placa e podendo ser trocado (cadastro errado, outro veículo). O tipo escolhido é o que vale na cobrança; um aviso mostra quando ele difere do cadastro.
- Abaixo do campo aparece a tabela de estadia que vai valer, com as faixas e os pacotes (ex.: "Motos · Diária (12h) R$ 10,00"); a lista de tabelas em Configurações também passa a mostrar a duração do pacote. Tipo sem tabela aparece desabilitado e é **barrado na entrada** (antes o erro só aparecia na saída).
- PDV (documentação): sem internet, fila no aparelho e cobrança pela cópia local, atualizada ao iniciar a operação do dia; o servidor recalcula ao voltar.
- **162 testes automáticos.**
- Arquivos: `app/Servicos/Estadias.php`, `app/Http/Controllers/EstadiaController.php`, `app/Http/Controllers/TarifaController.php`, `app/Models/Tarifa.php`, `resources/js/Pages/Estadias/Index.jsx`, `tests/Feature/OperacaoTest.php`, `docs/07-pdv.md`, `docs/12-decisoes.md`, `docs/13-desenvolvimento.md`.

## 13/11/2026 — Desenho do PDV e nova ordem das etapas (só documentação)

- **Etapas 7 e 8 trocadas:** a 7 passa a ser Integrações (Inter e WhatsApp) e a 8, API e PDV.
- **PDV:** app React separado da gestão, PWA agora e empacotado com Capacitor para a Play Store depois; impressão (ESC/POS) e armazenamento local atrás de portas trocáveis; nenhuma regra de dinheiro no celular.
- **Ticket como página com link:** bobina, QR na tela e WhatsApp/SMS entregam a mesma página; WhatsApp e QR opcionais.
- Corrigido: impressora pelo navegador só funciona com Bluetooth BLE.
- Arquivos: `docs/02-tecnologia-e-infraestrutura.md`, `docs/06-modulos.md`, `docs/07-pdv.md`, `docs/10-plano-de-etapas.md`, `docs/12-decisoes.md`.

## 12/11/2026 — Cliente excluído: ordens com o nome, remover com conta em aberto, menu Lavagens

- **Espelho:** as ordens, pagamentos e linhas do extrato de cliente excluído no sistema atual continuam ligados ao cliente (inativo no ERP). Antes viravam "sem cliente" a cada hora, e a ficha do cliente perdia o histórico.
- **Remover cliente com conta em aberto** pergunta o que fazer: **Excluir** (as contas a receber são canceladas com o motivo "Cliente removido" e saem da inadimplência e do resultado; créditos ficam; pede a permissão de alterar contas), **Manter** (o cliente sai e as contas continuam a receber) ou **Receber** (vai para a tela de receber; o cliente não é removido).
- **Contas em aberto** marca "Cliente excluído". **Resultado do mês** (por referência) avisa quanto das receitas está em aberto em cliente excluído.
- Menu **Lavagens e serviços** passa a se chamar **Lavagens** (estadia também é serviço).
- **161 testes automáticos.**
- Arquivos: `app/Console/Commands/Concerns/ImportaCadastros.php`, `app/Http/Controllers/ClienteController.php`, `app/Http/Controllers/ContaController.php`, `app/Servicos/Resultado.php`, `resources/js/Pages/Clientes/Show.jsx`, `resources/js/Pages/Contas/Abertas.jsx`, `resources/js/Pages/Resultado/Mes.jsx`, `resources/js/Pages/Lavagens/Index.jsx`, `resources/js/lib/menu.js`, `tests/Feature/CadastrosTest.php`, `tests/Feature/ImportacaoDinheiroTest.php`, `docs/12-decisoes.md`, `docs/13-desenvolvimento.md`.

## 12/11/2026 — Relatório de mensalistas e impressão no padrão do sistema atual

- **Clientes › Mensalistas** (permissão "Relatórios de mensalistas"), no lugar de Relatórios › Controle e Lista de mensalistas do sistema atual: resumo com receita mensal e quantidade de carros, motos e caminhões; tabela por tipo e período (Diurno, Noturno, 24 horas) com quantidade e receita; lista dos mensalistas (cliente, veículo, tipo, período, mensalidade) com filtro por tipo de veículo; planilha e **Imprimir**. A receita só aparece para quem tem a permissão de faturamento.
- **Impressão no padrão do sistema atual** (logo, título e "Emitido em", linha grossa, quadros de resumo, tabela com bordas e total) em Mensalistas, Contas em aberto (com "Só vencidas" sai como "Inadimplência": cliente e placas, situação, vencido desde, contas, vencido e total) e Recebimentos.
- Impressão: A4 em pé com margem é o padrão; só as folhas da cobrança usam A4 deitada sem margem (antes a regra da cobrança valia para tudo e a lista virava folha deitada a partir da 3ª página).
- **159 testes automáticos.**
- Arquivos: `app/Http/Controllers/MensalistasController.php`, `app/Http/Controllers/ContaController.php`, `routes/web.php`, `resources/js/Pages/Clientes/Mensalistas.jsx`, `resources/js/Pages/Clientes/Index.jsx`, `resources/js/Components/AbasClientes.jsx`, `resources/js/Components/CabecalhoImpressao.jsx`, `resources/js/Pages/Contas/Abertas.jsx`, `resources/js/Pages/Relatorios/Recebimentos.jsx`, `resources/scss/_impressao.scss`, `tests/Feature/MensalistasTest.php`.

## 12/11/2026 — Gerar mensalidades sem os clientes que saíram

- **Gerar mensalidades** não lista mais contrato de cliente ou veículo inativo (removido no ERP ou excluído no sistema atual).
- **Espelho:** mensalista que saiu do sistema atual e teve a mensalidade do mês apagada junto passa a terminar no fim do mês anterior (antes terminava no dia, e o mês aparecia como "vai gerar"). Se a mensalidade do mês continua lá, termina no dia.
- Arquivos: `app/Servicos/Mensalidades.php`, `app/Console/Commands/Concerns/ImportaCadastros.php`, `app/Console/Commands/LegadoImportar.php`, `tests/Feature/ImportacaoCadastrosTest.php`.

## 12/11/2026 — Imprimir lista de inadimplentes e recebimentos

- **Contas em aberto** e **Recebimentos** ganharam o botão **Imprimir**, como os relatórios do sistema atual: A4 em pé, sem menu, filtros e botões; mostra todas as colunas (itens, mais antigo, forma, ordens) e, em Contas em aberto, o total no fim e a data de emissão. Com "Só vencidas", o título vira "Inadimplentes (contas vencidas)".
- Arquivos: `resources/js/Pages/Contas/Abertas.jsx`, `resources/js/Pages/Relatorios/Recebimentos.jsx`, `resources/scss/_impressao.scss`.

## 12/11/2026 — Agendador e backup funcionando na Hostinger

- **Causa do espelho parado:** o agendador abria cada tarefa como um processo separado, e a Hostinger desliga isso (`proc_open`). O cron rodava a cada minuto, mas o espelho (de hora em hora) e o backup (3h10) falhavam com "The Process class relies on proc_open". Agora as tarefas rodam dentro do próprio processo (`Schedule::call` + `Artisan::call`).
- **Backup em PHP puro**, sem `mysqldump` (que também precisa abrir outro programa): estrutura e dados de todas as tabelas, compactado em `.sql.gz`; colunas geradas ficam fora do INSERT. Testado: restaurado num banco vazio, com as mesmas contagens.
- Arquivos: `routes/console.php`, `app/Console/Commands/BackupBanco.php`, `tests/Feature/ParaleloTest.php`, `docs/14-publicacao.md`.

## 12/11/2026 — Espelho repassa exclusões; aviso quando a importação para

- **Espelho:** cliente, veículo ou convênio **excluído no sistema atual** fica **inativo** no ERP (com o histórico), como o "Remover" do ERP. Antes ele continuava ativo e a conferência acusava diferença.
- **Conferência:** compara só clientes, veículos e convênios ativos; mensalidade encerrada pelo espelho no dia (mensalista que saiu ou mensalidade 0, como licença-maternidade) já não conta como mensalista.
- **Aviso** no Início e na Conferência quando a última importação tem mais de 2 horas (das 9h às 23h59): sinal de que o cron parou.
- **158 testes automáticos.**
- Arquivos: `app/Console/Commands/Concerns/ImportaCadastros.php`, `app/Console/Commands/LegadoComparar.php`, `app/Http/Controllers/ConferenciaController.php`, `resources/js/Pages/Sistema/Conferencia.jsx`, `resources/js/Pages/Inicio.jsx`.

## 12/11/2026 — Botão Publicar atualizado

- `.github/workflows/publicar.yml`: `actions/checkout@v5` e `actions/setup-node@v5` (as v4 rodam em Node.js 20, que o GitHub está desativando) e máquina fixa `ubuntu-24.04` (a `ubuntu-latest` muda para o Ubuntu 26 em outubro de 2026).

## 12/11/2026 — Etapa 6 (parte 4): fatura do cartão de crédito — Etapa 6 concluída

- **Contas a pagar › Cartões:** cadastro dos cartões (da empresa ou pessoal do sócio) e importação da fatura pelo arquivo do banco (**OFX**, padrão de quase todos os bancos, ou **CSV do Nubank**). O pagamento da fatura anterior é ignorado; estorno abate. Vencimento pelo nome do arquivo do Nubank (`Nubank_2026-10-23`), pelo dia do cartão ou informado. Arquivo de outro cartão e fatura repetida são recusados.
- **A fatura vira uma conta a pagar só**, no vencimento, pelo valor que o banco cobra. Paga-se por Contas a pagar, como qualquer conta; o valor não se altera por lá (vem das compras).
- **Classificação das compras:** cada compra numa conta do plano, com "o que foi" (ex.: "Telhas da cobertura") e a chave "Da empresa". No resultado do mês conta a compra, na sua conta, e não a fatura; compra sem conta aparece em "Cartão de crédito (a classificar)".
- **O sistema aprende:** loja classificada numa conta passa a vir classificada nas próximas faturas; se for classificada em outra conta, passa a ser perguntada. Marketplaces (Mercado Livre, Amazon, Shopee, Magalu...) são sempre perguntados. Parcela seguinte herda conta e descrição da anterior.
- **Cartão pessoal do sócio:** só entram as compras marcadas como da empresa; viram uma conta "paga pelo sócio": contam no resultado, mas não saem do caixa da empresa (fluxo de caixa). Para se reembolsar, é só desfazer o pagamento dessa conta e pagá-la pela empresa.
- **Resultado do mês:** grupo novo **Investimentos e equipamentos** e compras do cartão da empresa que não são da empresa ficam abaixo do "Resultado da operação", com "Sobrou no fim". Contas novas no plano (criadas sozinhas no plano existente): *Investimentos e equipamentos* e *Cartão de crédito (a classificar)*.
- **Desfazer importação** (arquivo errado): cancela a conta da fatura e apaga a classificação, enquanto ela não foi paga.
- **156 testes automáticos.**
- Banco: migração `2026_11_18_000001_create_cartoes_tables` (no servidor, roda sozinha ao publicar).

## 12/11/2026 — Etapa 6 (parte 3): resultado do mês, fluxo de caixa e calendário nos campos de data

- **Gestão › Resultado e caixa** (permissão nova `resultado`; o Administrador já tem):
  - **Resultado do mês** em dois olhares: *por referência* (o que o mês faturou: mensalidades do mês de referência, estadias e serviços pela data, menos descontos; despesas do mês de referência, pagas ou não) e *pelo dinheiro* (recebimentos do mês e contas pagas no mês). Taxa de cartão/maquininha entra como despesa nos dois. Árvore do plano de contas com os grupos somados, quanto sobrou ou faltou, margem e os últimos 6 meses.
  - Débitos (pagou a menos) e créditos (pagou a mais) não entram como receita: são resto ou adiantamento de conta já contada.
  - **Fluxo de caixa** por dia: realizado até hoje (cartão no dia em que cai, já sem a taxa; contas pelo dia do pagamento) e previsto dos próximos dias (a receber pelo vencimento, contas a pagar em aberto), com saldo acumulado a partir de um saldo inicial opcional. Atrasados (a receber e a pagar) à parte. Clique no dia mostra o que entrou e saiu.
- **Calendário em todos os campos de data:** o campo de data do sistema (`CampoData`) ganhou o botão de calendário próprio, em português (grade de meses nos campos de mês). Continua dando para digitar. Vale para todas as telas.
- **152 testes automáticos.**
- Sem migração nova.

## 12/11/2026 — Etapa 6 (parte 2): funcionários, recibos de salário, diárias e leitura de boleto/guia

- **Ler boleto ou guia** (Contas a pagar › Nova conta): PDF, foto ou código de barras colado. Preenche descrição, fornecedor (pelo CPF/CNPJ do beneficiário), conta do plano, valor, vencimento e mês de referência, e já anexa o arquivo. Reconhece boleto de qualquer banco, DAS (Simples), DARF (INSS) e guia do FGTS Digital. O valor do código de barras é conferido pelos dígitos verificadores; o da guia do FGTS, pela soma FGTS + consignado. A pessoa confere antes de lançar.
- **OCR no navegador:** quando o PDF é imagem (impressão em PDF, digitalização) ou é foto, o texto é lido no próprio navegador (tesseract.js, arquivos em `public/ocr`, português), sem depender do servidor. A guia do FGTS tem uma 2ª leitura com as cores invertidas, para o vencimento na caixa escura.
- **Gestão › Funcionários:** cadastro de registrados (matrícula do recibo) e avulsos (valor da diária, Pix).
- **Recibos de salário:** importa o PDF de recibos do contador; mostra bruto, descontos, líquido e FGTS de cada um para conferir; cada recibo vira uma conta a pagar do líquido em Salários, vencendo no 5º dia útil do mês seguinte (sábado conta), com o PDF anexado. Funcionário novo é cadastrado na importação. Recibo repetido não entra (só se a conta for cancelada).
- **Diárias:** lança os dias trabalhados por avulso (vários dias de uma vez); na hora de pagar, junta as diárias marcadas num pagamento (já pago ou a pagar), que vira uma conta em Diárias e avulsos. Pagamento cancelado devolve as diárias para "a pagar".
- Conta "Simples Nacional (DAS)" passou a ser do sistema (chave `despesa_simples`).
- Dependências novas: `smalot/pdfparser` (Composer), `tesseract.js` e `pdfjs-dist` (npm). `public/.htaccess`: `.mjs` servido como JavaScript.
- **148 testes automáticos.**
- Banco: migração `2026_11_17_000001_create_funcionarios_tables` (no servidor, roda sozinha ao publicar).

## 12/11/2026 — Etapa 6 (parte 1): contas a pagar

- **Gestão › Contas a pagar** (permissão `contas_pagar`): lançar a conta (descrição, fornecedor, conta do plano, valor, vencimento, mês de referência, observação, boleto/nota/comprovante em PDF ou foto), à vista ou em até 24 parcelas mensais, ou já paga. Resumo: atrasadas, vencem hoje, próximos 7 dias e pago no mês.
- **Pagar:** data, valor pago de fato (com juros ou desconto), forma de pagamento e de onde saiu o dinheiro (caixa, banco...), com comprovante. **Desfazer pagamento** e **cancelar** sempre com motivo; nada é apagado; tudo no registro de atividades.
- **Contas fixas** (aluguel, energia, contador...): geram uma conta a pagar por mês, até o mês seguinte ao atual, com o valor previsto (ajustável em cada mês). Vencimento no dia 31 cai no último dia do mês. Geradas ao abrir a tela e todo dia às 6h05.
- **Fornecedores:** nome, CPF/CNPJ, contato, chave Pix e conta padrão (já vem escolhida ao lançar).
- **Configurações › Plano de contas:** árvore de receitas (1) e despesas (2), com modelo pronto (Pessoal, Ocupação, Utilidades, Lavagem, Administrativas, Impostos...). Criar, renomear, reordenar e desativar; contas do sistema (receitas das ordens e folha) e contas com lançamentos não se apagam.
- **Início:** aviso de contas atrasadas ou vencendo hoje para quem tem a permissão.
- No celular, Contas a pagar vira uma lista com os botões embaixo de cada conta.
- Como o sistema atual não tem contas a pagar, isto **já pode ser usado no ERP de produção** durante o paralelo (não entra na conferência).
- **143 testes automáticos.**
- Banco: migração `2026_11_16_000001_create_contas_pagar_tables` (no servidor, roda sozinha ao publicar).

## 12/11/2026 — Paralelo iniciado

- **Primeira publicação** pelo botão Publicar: ERP no ar em `erp.localestacionamento.com.br` (Hostinger, pasta base `/home/u612820499/domains/localestacionamento.com.br/erp`).
- **Cron** criado (a cada minuto, `schedule:run`) e **primeira importação** feita: conferência igual ao sistema atual.
- Senha do banco do sistema atual trocada (atualizada no `database.php` do sistema atual e no `.env` do ERP).
- A partir daqui, a equipe segue só no sistema atual; nada é lançado no ERP de produção até a virada.

## 11/11/2026 — Etapa 5: publicação e paralelo

- **Botão Publicar** (GitHub › Actions › Publicar): roda os testes, gera o build e envia a versão para a Hostinger por SSH; no servidor, cada publicação é uma pasta nova e a troca é instantânea. Ação **voltar para a versão anterior** para desfazer. Arquivos: `.github/workflows/publicar.yml`, `deploy/servidor.sh`, `deploy/voltar.sh`, `deploy/rsync-excluir.txt`, `deploy/env-producao.exemplo`.
- **Espelho do sistema atual:** `php artisan legado:espelhar` importa e compara; roda sozinho de hora em hora (7h às 23h) quando `ERP_ESPELHO_ATIVO=true`. Cada execução (igual, com diferença ou erro) fica registrada.
- **Sistema › Conferência:** última conferência com os números lado a lado, dias seguidos sem diferença e histórico. O aviso do Início mostra a hora da última importação e se bateu.
- **Backup diário** (3h10): `php artisan backup:banco` grava os bancos do ERP e do sistema atual compactados em `storage/app/private/backups`, guardando 14 dias.
- **Passo a passo da primeira publicação** em `docs/14-publicacao.md`.
- **139 testes automáticos.**
- Banco: migração `2026_11_15_000001_create_conferencias_table` (rodar `php artisan migrate`).

## 10/11/2026 — Anotações em destaque

- No Início, as anotações vêm primeiro e em destaque (texto maior e em negrito); o campo de adicionar ficou menor, embaixo do bloco.

## 10/11/2026 — Etapas da lavagem configuráveis

- **Configurações › Etapas da lavagem** (aba nova): a empresa escolhe o que registra no quadro de lavagens. Aguardando (entrada) é sempre a primeira e Entregue a última (só mudam de nome); as do meio são criadas, renomeadas, reordenadas com as setas e desativadas. Etapa já usada não é apagada.
- Padrão do Local: **Aguardando → Pronta → Entregue** ("Lavando" fica criada, desativada). Um detailer pode ter, por exemplo, Lavagem, Descontaminação, Polimento e Vitrificação.
- **Histórico da lavagem:** cada mudança de etapa fica gravada com quem registrou e quando (base para ver o tempo de espera e de cada etapa). O quadro monta as colunas pelas etapas ativas; carro numa etapa desativada aparece na primeira coluna.
- **PDV (decidido, entra na etapa 7):** identificação por PIN de 4 dígitos ou por operador de caixa, configurável; tempo de memória do PIN configurável; caixa único com turnos e conferência. Detalhes em `docs/07-pdv.md`.
- **135 testes automáticos.**
- Banco: migração `2026_11_14_000002_create_etapas_lavagem_tables` (rodar `php artisan migrate`).

## 10/11/2026 — Início: anotações e alinhamento

- **Anotações** no Início: cada usuário anota as tarefas que precisa fazer (ex.: cobrar um cliente, ligar para a contabilidade), com data opcional (mostra "hoje" e "atrasada"), marca como feita, edita e apaga. Só quem anotou vê. As 10 últimas feitas ficam em "Ver as feitas".
- **Busca de cliente do Início** só aparece no celular (no computador ela já está no topo de todas as telas).
- **Alinhamento:** "Recebido no mês" e "Anotações" ficam na mesma grade dos quatro números de cima (cada bloco ocupa duas colunas).
- **134 testes automáticos.**
- Banco: migração `2026_11_14_000001_create_anotacoes_table` (rodar `php artisan migrate`).

## 10/11/2026 — Menu reorganizado

- **Operação:** Estadias, Lavagens e serviços, Gerar mensalidades e Ordens de serviço.
- **Gestão:** Contas em aberto, Cobranças, Extrato bancário, Recebimentos e Convênios. O grupo "Contas" deixou de existir.
- **Sistema › Configurações:** um link só, com abas: Empresa e unidades, Tipos de veículo, Tabelas de estadia, Lavagens e serviços e Formas de pagamento (no celular as abas rolam para o lado). O grupo "Configurações" deixou de existir.
- Arquivos: `resources/js/lib/menu.js`, novo `Components/AbasConfiguracoes.jsx`, telas de configuração e `_layout.scss`.

## 10/11/2026 — Etapa 4: operação na gestão

- **Estadias** (menu Operação): entrada pela placa (o sistema reconhece o veículo cadastrado e o mensalista; sem cadastro pede o tipo do veículo e o celular), sem placa com identificação, entrada em outro horário e convênio. Não deixa a mesma placa entrar duas vezes no pátio. Ticket com número da empresa.
- **Pátio e saída:** lista de quem está dentro com o tempo correndo; na saída o valor é calculado pela tabela de estadia do tipo do veículo (com o detalhe de cada faixa) e dividido com o convênio. A parte de quem usou é paga na hora (forma e parcelas), vai para a conta do cliente cadastrado ou, se for mensalista, sai sem cobrança. Saída dentro da tolerância não gera conta. Estadia lançada por engano pode ser cancelada com motivo enquanto está no pátio. Saídas do dia e **histórico** com busca por placa, identificação ou ticket.
- **Lavagens e serviços:** nova venda pela placa (cadastrado ou avulso, que lembra o tipo e o celular da última vez), serviço principal com adicionais pelo preço da tabela do tipo do veículo (serviços sem preço para o tipo aparecem bloqueados), convênio, e paga agora, na entrega ou na conta do cliente. **Quadro** com as etapas aguardando, lavando, pronta e entregue (as entregues de hoje), e receber no balcão o que o avulso deixou para pagar na entrega.
- **Convênio:** a parte do convênio vira uma conta em aberto no cliente do convênio, com "(convênio)" na descrição; o fechamento é a cobrança normal desse cliente. Regras: "tudo" paga tudo; "tempo" paga a estadia até o limite (lavagens e serviços quem paga é o usuário); "desconto" dá o desconto a quem usou e o convênio não paga nada.
- **Menu do celular:** Estadias e Lavagens ficam em "Mais"; o menu de baixo continua com Início, Clientes e Contas em aberto (o painel é administrativo, não é o caixa).
- **132 testes automáticos.**
- Banco: migração `2026_11_13_000001_create_operacao_tables` (rodar `php artisan migrate`).

## 10/11/2026 — Etapa 3: dinheiro

- **Ordens de serviço:** todo valor do cliente (mensalidade, lavagem/serviço, estadia, débito, crédito) vira uma ordem com número da empresa. Ordem nunca é apagada: em aberto pode ser alterada ou cancelada com motivo (permissão "alterar contas"). Lista em Contas › Ordens de serviço.
- **Lançar na conta do cliente:** lavagem/serviço com adicionais (preço da tabela pelo tipo do veículo; serviço "até" pede o valor), estadia, e débito/crédito com motivo (permissão "alterar contas").
- **Gerar mensalidades do mês:** prévia com cada mensalista (vai gerar, já gerada, já coberta, pausado com taxa, começou no mês), marcar e gerar. Nunca duas do mesmo mês para o mesmo veículo.
- **Primeira cobrança:** abre sozinha depois de cadastrar uma mensalidade (veículo novo, ficha do cliente ou aprovação da fila): proporcional, proporcional + próximo, mês cheio, cortesia ou não gerar.
- **Receber:** marca as contas pagas, valor, data e forma de pagamento (com parcelas, taxa e data em que o dinheiro cai). Créditos abatem; sobra vira crédito ou serviço feito na hora; falta vira débito ou desconto. **Estorno** com motivo.
- **Cobranças:** emissão por cliente (ficha) ou em lote no mês; cópia dos itens na emissão (não muda depois); o que não foi pago entra na próxima como saldo anterior; impressão na **via do cliente** do sistema atual (folha A4 deitada, duas cobranças lado a lado, seções Mensalidades, Lavagens e Estadias avulsas, subtotal, créditos, débitos e total), com número da cobrança, asterisco no que veio da cobrança anterior e o texto "como pagar" configurável em Empresa e unidades.
- **Extrato bancário:** envia o CSV, o sistema sugere o cliente (pagador vinculado, CPF parcial ou nome), a equipe confere e dá baixa; o mesmo PIX não baixa duas vezes; dá para lembrar o pagador.
- **Contas em aberto** (com "só vencidas" e planilha), **Recebimentos** por período (por forma e pelo que foi pago, com planilha), **extrato da conta** na ficha do cliente (com saldo linha a linha, para imprimir) e números no **Início**.
- **Formas de pagamento e contas** em Configurações (Dinheiro, Pix, cartões, boleto, transferência já vêm cadastrados).
- **Importação:** ordens com o mesmo número do sistema atual, pagamentos reconstruídos (forma "não informada") e extrato já importado; reimportar não duplica. **Comparação** do portão: ordens, em aberto por cliente e recebido mês a mês. Testado com 56 mil ordens (5 segundos).
- **Correção na importação:** ordem com data de pagamento inválida no sistema atual (ex.: `0000-00-00`) entrava como em aberto; agora entra como paga, sem data, igual ao sistema atual (que só considera em aberto quando a data está vazia).
- **Serviço feito na hora** (sobra no receber e no extrato): a lista inclui "Estadia avulsa", além das lavagens e serviços.
- **Início:** "Recebido no mês" passa a somar as contas baixadas (crédito usado abate), igual ao Início do sistema atual; o dinheiro por forma de pagamento fica em Recebimentos.
- **Importação na fase de paralelo:** conta paga nos dois sistemas não vira dois pagamentos (vale o pagamento feito no ERP). Novo `importar-legado.bat` na raiz: roda a importação e a comparação e grava o resultado em `storage/logs/importacao.txt`.
- **Portão da etapa 3 cumprido** com a cópia local do sistema atual (banco do ERP recriado do zero): 20.911 ordens, 49 em aberto (R$ 10.050,00) e o recebido de todos os meses iguais. Novo `recriar-banco.bat` (apaga o banco do ERP e importa tudo de novo; só para antes da virada).
- **Extrato da conta:** contas baixadas no sistema atual sem data de pagamento (`0000-00-00`) agora aparecem como "Baixada no sistema atual sem data de pagamento" e saem do saldo; antes o extrato terminava com um saldo que não existia (ex.: R$ 650 de um cliente sem nada em aberto). Pagamentos são buscados pelas contas do cliente. O saldo final do extrato bate com o em aberto em todos os clientes.
- **119 testes automáticos.**
- Banco: migração `2026_11_12_000001_create_dinheiro_tables` (rodar `php artisan migrate`).

## 10/11/2026

- **Empresa e unidades:** campo "CNPJ" (empresa é sempre pessoa jurídica), com máscara e conferência dos dígitos; telefone com máscara na empresa e nas unidades. Os dois passam a ser guardados só com os números.

## 10/11/2026 — Etapa 2: cadastros e configurações

- **Clientes:** lista com busca enquanto digita (nome, código, CPF/CNPJ ou placa), ficha com dados, veículos, mensalidade e histórico, cadastro com busca de endereço pelo CEP, CPF/CNPJ conferido pelos dígitos. "Remover" desativa (encerra as mensalidades hoje e guarda o histórico); dá para reativar.
- **Busca de cliente no topo** de todas as telas (como no sistema atual).
- **Veículos:** placa opcional (bicicleta, patinete: identificação alternativa), placa única só entre veículos ativos, troca de placa guardada no histórico.
- **Mensalidade (contrato por veículo):** criar, reajustar (nova versão a partir de uma data; a anterior termina na véspera), encerrar (inclusive agendado, com motivo) e pausar meses inteiros com taxa de reserva (valor ou %, 0 = grátis).
- **Fila de espera e cadastro público:** formulário pelo link/QR Code (gerado em Empresa e unidades), aprovação como cliente novo ou ligado a um cliente existente pelo CPF; aprovado ou recusado, sai da fila (dados de quem não virou cliente não ficam guardados).
- **Configurações:** tipos de veículo; tabelas de estadia com vigência, exceção por unidade e simulador; lavagens e serviços com adicionais e preço por tipo com vigência (reajuste agendado) e exceção por unidade; padrões de mensalidade (dia de vencimento, períodos, taxa de pausa).
- **Convênios:** paga tudo, paga até um tempo ou desconto (% ou R$), para estadias e/ou serviços.
- **Tarifador** trazido do sistema atual, com o mesmo resultado em 12 mil estadias sorteadas, e testes com as tabelas do Local.
- **Importação** de tipos, tabelas, serviços e preços, clientes (com o mesmo código do sistema atual), veículos (placa improvisada vira "sem placa"), mensalidades (ligadas pelo id do veículo), convênios, fila e pagadores vinculados; acentos gravados errado no sistema atual são corrigidos. **Comparação** com os novos itens (inclusive a soma das mensalidades).
- **86 testes automáticos.**
- A **primeira cobrança** e a geração das mensalidades ficam para a etapa 3 (até lá, pelo sistema atual).
- Telas carregadas sob demanda (o sistema abre mais rápido).

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
