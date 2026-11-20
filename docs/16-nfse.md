# NFS-e pelo Sistema Nacional

Desde 2026 o Local emite NFS-e pelo **Emissor Nacional** (nfse.gov.br), e não mais pelo sistema da prefeitura. O ERP usa a API do mesmo Sistema Nacional (Sefin Nacional).

## Como funciona

- **Nota a partir dos serviços sem nota** (ordens de mensalidade, estadia e serviço; pagos ou não). Cada serviço guarda a nota em que entrou (ordens_servico.nfse_id); rejeitada ou cancelada, volta a ficar sem nota. Gestão › Notas fiscais:
  - **Clientes:** serviços sem nota no período De/Até (padrão: início do mês anterior ao fim do mês atual), por cliente, com total; marca os clientes (quem teve nota no mês passado vem marcado) e vai para **Conferir e emitir**: serviços de cada um (dá para tirar), escolhas, emissão de um ou em lote (a tela manda uma de cada vez, com progresso; recusada não para as outras).
  - **Avulsos:** busca por OS, ticket ou placa (qualquer data); sem cadastro, CPF/CNPJ e nome opcionais digitados na hora.
  - **Notas:** as emitidas.
- A descrição tem uma linha por serviço, "MODELO - PLACA - SERVIÇO - VALOR" (estadia e avulso com a data na frente: "05/10 - POLO - TMA2G79 - R$ 60,00"), e uma primeira linha opcional da configuração. Com "especificar cada serviço" desligado (padrão), só a mensalidade é nomeada; estadia, lavagem e outras saem "PLACA - VEÍCULO - VALOR".
- **Competência:** a data da emissão (dCompet = dia em que a nota é emitida), não a do recebimento.
- **Alíquotas do mês:** no alto de Gestão › Notas fiscais, por competência (o mês da emissão): alíquota de ISS e valor aproximado dos tributos, do e-mail mensal da contabilidade. Sem elas a nota da competência não sai. Os tributos vão em pTotTribSN ("Informar alíquota do Simples Nacional"); a de ISS só vai (pAliq) com ISS retido: ME/EPP no Simples sem retenção não pode informar alíquota (E0625/E0631) e com retenção é obrigatória, mínimo 1,8% (E0621/E0628).
- **Escolhas por cliente:** o padrão fica no cadastro do cliente (Nota fiscal: dados do tomador, ISS retido, retenção federal; tudo desligado por padrão) e dá para mudar na emissão, só para aquela nota: retenção federal (ver abaixo), declarar ou não os dados do cliente (sem eles a nota sai como "Tomador/Adquirente não informado") e ISSQN retido pelo tomador (tpRetISSQN 2). Reter exige os dados do cliente e endereço completo no município de incidência, como no Emissor.
- **Tomador:** cliente com CPF/CNPJ válido, com endereço quando o cadastro tem CEP, rua e bairro. O código IBGE da cidade vem da lista da Baixada ou, fora dela, da consulta do CEP no ViaCEP (guardado em clientes.cidade_ibge com o CEP usado). Sem documento, a nota sai sem tomador.
- **Fluxo:** monta a DPS (leiaute 1.01), assina com o certificado A1 (XMLDSig, RSA-SHA256, canonicalização exclusiva, assinatura irmã do infDPS), compacta (gzip + base64) e envia por TLS mútuo com o mesmo certificado.
  - Emitida: guarda chave de acesso, número e o XML da nota. PDF (DANFSe) e XML para baixar.
  - Rejeitada: guarda o motivo (código e descrição do Sistema Nacional). Corrigiu a configuração, emite de novo com outro número de DPS.
  - Sem resposta (a conexão caiu): fica "Enviando". "Conferir" pergunta pela DPS; se a nota saiu, completa; se não chegou, reenvia a mesma DPS.
  - Cancelamento: evento e101101 assinado, com motivo.
- **Ambientes:** "Testes" = produção restrita do Sistema Nacional (sem valor fiscal); "Produção" = notas valendo. Começa em testes.

## Configuração (Configurações › Nota fiscal)

- **Certificado A1 (e-CNPJ):** o .pfx é aberto no navegador (node-forge); a senha não sai do computador. O servidor recebe certificado e chave em PEM, confere se a chave é do certificado, se não venceu e se é do CNPJ da empresa, e guarda criptografado (integracoes, tipo nfse). Ler no navegador evita o problema dos .pfx antigos (RC2), que o OpenSSL 3 do servidor não abre.
- A tela segue os passos do Emissor Nacional, com os mesmos nomes:
  1. **Emitente** (Pessoas): CNPJ, município, Opção no Simples Nacional, Regime de Apuração dos Tributos no SN (o Local: ME/EPP, tudo pelo Simples; as outras opções incluem o "híbrido", ISS pela NFS-e).
  2. **Serviço:** Código de Tributação Nacional 11.01.01, Código Complementar Municipal 11.01.01.004 (na DPS vão só os 3 últimos números), Item da NBS 106043000, descrição e "especificar cada serviço". Aceita colar com pontos.
  3. **Tributação municipal:** operação tributável, regime especial. As alíquotas ficam em Notas fiscais (mudam todo mês).
  4. **Série da DPS:** uma série que o Emissor Nacional na web não use. Número sequencial por série.
- Ficam fixos, como o Cauê preenche no Emissor: compras governamentais "Não", destinatário/intermediário não informados, sem imunidade, exigibilidade suspensa, benefício ou dedução. IBS/CBS em branco (opcional no Emissor hoje).
- **Tributação federal**, sempre enviada (tribFed): Situação Tributária do PIS/COFINS (00 a 09, configuração; no Simples, 00) e Tipo de retenção do PIS/COFINS/CSLL (0, 3 a 9, configuração; no Simples, 0: o optante fica fora da retenção, Lei 10.833/2003 art. 32). Com CST 01 a 07 vão base, alíquotas e valores de PIS e COFINS. Retenções pela NT 007: vRetCSLL leva a soma de PIS, COFINS e CSLL retidos; IRRF (vRetIRRF) e previdenciária (vRetCP) quando têm percentual na configuração. Se o cliente retém é marcado na emissão (lembrado por cliente; a opção só aparece com retenção configurada); com retenção, a nota precisa dos dados do cliente.
- **IBS/CBS:** "Preencher as informações IBS/CBS?" Sim/Não (hoje Não). Com Sim, guarda o código de situação tributária (3 números) e o de classificação tributária (6 números), mas a emissão fica bloqueada até o grupo IBS/CBS da DPS ser montado e conferido no leiaute oficial em vigor.
- Estrutura da DPS conferida contra o XML de uma nota real do Emissor Nacional (Simples, tomador CNPJ em Santos, ISS retido): mesmos elementos e ordem (teste test_dps_tem_a_mesma_estrutura_da_nota_real_do_emissor). O Emissor manda a mais só opcionais (fone e e-mail do prestador, IM e fone do tomador). A inscrição municipal do prestador não vai.

## O que ainda não faz

- Grupo de IBS/CBS da reforma tributária na DPS (a opção Sim/Não e os códigos já existem; o envio, não).
- Emissão automática (ex.: toda mensalidade paga) e envio da nota ao cliente por e-mail/WhatsApp.
- Substituição de nota.

## Testes

`tests/Feature/NfseTest.php`, com a API simulada e certificado de teste gerado na hora: DPS e assinatura conferidas, alíquotas do mês, ISS retido, nota sem os dados do cliente, descrição especificada ou não, tributação federal de outro regime, códigos colados com pontos, regime híbrido, consumidor sem CPF, rejeição, envio sem resposta + conferir, cancelamento assinado e as regras do certificado. A primeira nota de verdade é na produção restrita, com o A1 da empresa.
