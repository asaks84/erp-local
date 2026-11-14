# Histórico de alterações do ERP

Alterações do ERP Local, da mais nova para a mais antiga. Cada alteração entra **no topo**: data, o que mudou e arquivos.

---

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
