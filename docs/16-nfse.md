# NFS-e pelo Sistema Nacional

Desde 2026 o Local emite NFS-e pelo **Emissor Nacional** (nfse.gov.br), e não mais pelo sistema da prefeitura. O ERP usa a API do mesmo Sistema Nacional (Sefin Nacional).

## Como funciona

- **Uma nota por recebimento** (pagamento confirmado). Gestão › Notas fiscais lista os recebimentos sem nota ("A emitir") e as notas ("Notas"). A descrição é a da configuração e, abaixo, uma linha por conta paga: "PLACA - VEÍCULO - SERVIÇO - VALOR". Com "especificar cada serviço" desligado (padrão), só a mensalidade é nomeada; estadia, lavagem e outras saem "PLACA - VEÍCULO - VALOR".
- **Alíquotas do mês:** no alto de Gestão › Notas fiscais, por competência (a do recebimento): alíquota de ISS e valor aproximado dos tributos, do e-mail mensal da contabilidade. Sem elas a nota da competência não sai. Os tributos vão em pTotTribSN ("Informar alíquota do Simples Nacional"); a de ISS só vai (pAliq) com ISS retido: ME/EPP no Simples sem retenção não pode informar alíquota (E0625/E0631) e com retenção é obrigatória, mínimo 1,8% (E0621/E0628).
- **Escolhas por cliente, na emissão** (a prévia lembra a última nota do cliente): retenção federal (ver abaixo), declarar ou não os dados do cliente (sem eles a nota sai como "Tomador/Adquirente não informado") e ISSQN retido pelo tomador (tpRetISSQN 2). Reter exige os dados do cliente e endereço completo no município de incidência, como no Emissor.
- **Tomador:** cliente com CPF/CNPJ válido (com endereço, se a cidade for da Baixada e o cadastro tiver CEP, rua e bairro). Sem documento, a nota sai sem tomador.
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
- **Tributação federal**, sempre enviada (tribFed): Situação Tributária do PIS/COFINS (00 a 09, configuração; no Simples, 00) e Tipo de retenção do PIS/COFINS/CSLL (0, 3 a 9, escolhido para o cliente na emissão). Com CST 01 a 07 vão base, alíquotas e valores de PIS e COFINS. Retenções pela NT 007: vRetCSLL leva a soma de PIS, COFINS e CSLL retidos; IRRF (vRetIRRF) e previdenciária (vRetCP) quando o cliente retém. As alíquotas (PIS, COFINS, CSLL, IRRF, previdenciária) ficam na configuração; retenção sem a alíquota configurada não emite.
- **IBS/CBS:** "Preencher as informações IBS/CBS?" Sim/Não (hoje Não). Com Sim, guarda o código de situação tributária (3 números) e o de classificação tributária (6 números), mas a emissão fica bloqueada até o grupo IBS/CBS da DPS ser montado e conferido no leiaute oficial em vigor.
- A ordem do pAliq dentro de tribMun (depois de tpRetISSQN) segue os exemplos públicos; confere na primeira nota com retenção na produção restrita.

## O que ainda não faz

- Grupo de IBS/CBS da reforma tributária na DPS (a opção Sim/Não e os códigos já existem; o envio, não).
- Emissão automática (ex.: toda mensalidade paga) e envio da nota ao cliente por e-mail/WhatsApp.
- Substituição de nota.

## Testes

`tests/Feature/NfseTest.php`, com a API simulada e certificado de teste gerado na hora: DPS e assinatura conferidas, alíquotas do mês, ISS retido, nota sem os dados do cliente, descrição especificada ou não, tributação federal de outro regime, códigos colados com pontos, regime híbrido, consumidor sem CPF, rejeição, envio sem resposta + conferir, cancelamento assinado e as regras do certificado. A primeira nota de verdade é na produção restrita, com o A1 da empresa.
