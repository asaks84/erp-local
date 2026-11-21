-- =====================================================================================
-- ERP Local · LIMPEZA DOS TESTES · PASSO 2: APAGAR
-- Rode no phpMyAdmin, no banco do ERP, DEPOIS de conferir o 1-conferir.sql.
-- Faça um backup antes (Exportar no phpMyAdmin, ou o backup diário do ERP).
--
-- Apaga:
--  · do cliente 1012 (CAUÊ CARRERA): contas criadas no ERP, cobranças, boletos, avisos,
--    pagamentos, ajustes e estornos. O cadastro (cliente e veículos) FICA.
--    Contas que vieram do sistema atual ficam (o espelho traria de volta).
--  · do PDV: estadias, lavagens (e as contas lançadas por elas, inclusive na conta de outros
--    clientes), pagamentos, Pix com QR, caixas, movimentos da gaveta, as despesas pagas pelo caixa
--    e o registro de ações dos aparelhos (inclusive as feitas sem internet).
--    Contas de outros clientes (criadas fora do PDV) recebidas no PDV voltam a ficar em aberto.
-- Não mexe em aparelhos, PINs, usuários, configurações (impressão, chave Pix) nem no registro de atividades.
-- Notas fiscais (NFS-e) ficam; a que estiver ligada a um pagamento apagado só perde o vínculo.
-- Versão de 21/11/2026: inclui o registro de ações do PDV e o vínculo da NFS-e com o pagamento.
-- =====================================================================================

SET @c = (SELECT id FROM clientes WHERE id = 1012 AND nome LIKE 'CAU%CARRERA%');

-- tabelas de apoio (apagadas no fim)
DROP TABLE IF EXISTS zz_limpa_ordens, zz_limpa_aux, zz_limpa_pags, zz_limpa_estadias, zz_limpa_cobs, zz_limpa_reabrir, zz_limpa_desp;
CREATE TABLE zz_limpa_ordens (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TABLE zz_limpa_aux (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TABLE zz_limpa_pags (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TABLE zz_limpa_estadias (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TABLE zz_limpa_cobs (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TABLE zz_limpa_reabrir (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TABLE zz_limpa_desp (id BIGINT UNSIGNED PRIMARY KEY);

START TRANSACTION;

-- ---------- o que sai ----------
-- estadias do PDV e as do cliente de teste
INSERT IGNORE INTO zz_limpa_estadias SELECT id FROM estadias WHERE aparelho_id IS NOT NULL OR (@c IS NOT NULL AND cliente_id = @c);

-- contas do cliente de teste criadas no ERP
INSERT IGNORE INTO zz_limpa_ordens SELECT id FROM ordens_servico WHERE @c IS NOT NULL AND cliente_id = @c AND legado_id IS NULL;
-- contas lançadas pelo PDV (lavagens, adicionais, estadia depois da lavagem, diária), de quem for
INSERT IGNORE INTO zz_limpa_ordens SELECT id FROM ordens_servico WHERE origem = 'pdv' AND legado_id IS NULL;
-- contas dessas estadias
INSERT IGNORE INTO zz_limpa_ordens SELECT o.id FROM ordens_servico o JOIN zz_limpa_estadias e ON e.id = o.estadia_id WHERE o.legado_id IS NULL;
INSERT IGNORE INTO zz_limpa_ordens SELECT s.ordem_id FROM estadias s JOIN zz_limpa_estadias e ON e.id = s.id WHERE s.ordem_id IS NOT NULL;
INSERT IGNORE INTO zz_limpa_ordens SELECT s.ordem_convenio_id FROM estadias s JOIN zz_limpa_estadias e ON e.id = s.id WHERE s.ordem_convenio_id IS NOT NULL;
INSERT IGNORE INTO zz_limpa_ordens SELECT s.ordem_entrada_id FROM estadias s JOIN zz_limpa_estadias e ON e.id = s.id WHERE s.ordem_entrada_id IS NOT NULL;

-- pagamentos do PDV, do cliente de teste (criados no ERP) e os que pagaram alguma conta que sai
INSERT IGNORE INTO zz_limpa_pags SELECT id FROM pagamentos WHERE origem = 'pdv' OR caixa_id IS NOT NULL OR aparelho_id IS NOT NULL OR (@c IS NOT NULL AND cliente_id = @c AND legado_chave IS NULL);
INSERT IGNORE INTO zz_limpa_pags SELECT DISTINCT i.pagamento_id FROM pagamento_itens i JOIN zz_limpa_ordens z ON z.id = i.ordem_id
    JOIN pagamentos p ON p.id = i.pagamento_id WHERE p.legado_chave IS NULL;

-- contas nascidas desses pagamentos (crédito, débito, multa e juros, serviço feito na hora)
INSERT IGNORE INTO zz_limpa_ordens SELECT o.id FROM ordens_servico o JOIN zz_limpa_pags p ON p.id = o.pagamento_origem_id WHERE o.legado_id IS NULL;
-- adicionais (cera etc.) de contas que saem
INSERT IGNORE INTO zz_limpa_aux SELECT o.id FROM ordens_servico o JOIN zz_limpa_ordens z ON z.id = o.principal_id;
INSERT IGNORE INTO zz_limpa_ordens SELECT id FROM zz_limpa_aux;

-- contas de outros clientes pagas por esses pagamentos: voltam a ficar em aberto
INSERT IGNORE INTO zz_limpa_reabrir SELECT DISTINCT i.ordem_id FROM pagamento_itens i JOIN zz_limpa_pags p ON p.id = i.pagamento_id
    LEFT JOIN zz_limpa_ordens z ON z.id = i.ordem_id WHERE z.id IS NULL;

-- cobranças do cliente de teste
INSERT IGNORE INTO zz_limpa_cobs SELECT id FROM cobrancas WHERE @c IS NOT NULL AND cliente_id = @c;

-- despesas pagas pelo caixa do PDV
INSERT IGNORE INTO zz_limpa_desp SELECT despesa_id FROM caixa_movimentos WHERE caixa_id IS NOT NULL AND despesa_id IS NOT NULL;

-- ---------- reabre o que era de outros clientes ----------
UPDATE ordens_servico SET situacao = 'aberta', pago_em = NULL WHERE id IN (SELECT id FROM zz_limpa_reabrir) AND situacao = 'paga';
UPDATE cobrancas c
    JOIN (SELECT DISTINCT o.cobranca_id FROM ordens_servico o JOIN zz_limpa_reabrir r ON r.id = o.id WHERE o.cobranca_id IS NOT NULL) x ON x.cobranca_id = c.id
    SET c.situacao = IF(EXISTS(SELECT 1 FROM ordens_servico o2 WHERE o2.cobranca_id = c.id AND o2.situacao = 'paga' AND o2.tipo <> 'credito'), 'parcial', 'aberta')
    WHERE c.situacao <> 'cancelada';

-- ---------- Pix com QR do PDV ----------
DELETE FROM pix_cobrancas;

-- ---------- pagamentos ----------
UPDATE nfses SET pagamento_id = NULL WHERE pagamento_id IN (SELECT id FROM zz_limpa_pags);
UPDATE extrato_lancamentos SET pagamento_id = NULL WHERE pagamento_id IN (SELECT id FROM zz_limpa_pags);
UPDATE boletos SET pagamento_id = NULL WHERE pagamento_id IN (SELECT id FROM zz_limpa_pags);
DELETE FROM ajustes WHERE pagamento_id IN (SELECT id FROM zz_limpa_pags) OR ordem_id IN (SELECT id FROM zz_limpa_ordens)
    OR cobranca_id IN (SELECT id FROM zz_limpa_cobs) OR (@c IS NOT NULL AND cliente_id = @c);
DELETE FROM pagamento_itens WHERE pagamento_id IN (SELECT id FROM zz_limpa_pags) OR ordem_id IN (SELECT id FROM zz_limpa_ordens);
UPDATE ordens_servico SET pagamento_origem_id = NULL WHERE pagamento_origem_id IN (SELECT id FROM zz_limpa_pags);
DELETE FROM pagamentos WHERE id IN (SELECT id FROM zz_limpa_pags);

-- ---------- cobranças, boletos, avisos e estornos do cliente de teste ----------
UPDATE estornos SET credito_restante_id = NULL WHERE credito_restante_id IN (SELECT id FROM zz_limpa_ordens);
DELETE FROM estornos WHERE @c IS NOT NULL AND cliente_id = @c;
DELETE FROM avisos WHERE (@c IS NOT NULL AND cliente_id = @c) OR cobranca_id IN (SELECT id FROM zz_limpa_cobs);
DELETE FROM boletos WHERE (@c IS NOT NULL AND cliente_id = @c) OR cobranca_id IN (SELECT id FROM zz_limpa_cobs);
DELETE FROM cobranca_itens WHERE cobranca_id IN (SELECT id FROM zz_limpa_cobs) OR ordem_id IN (SELECT id FROM zz_limpa_ordens);
UPDATE ordens_servico SET cobranca_id = NULL WHERE cobranca_id IN (SELECT id FROM zz_limpa_cobs);
DELETE FROM cobrancas WHERE id IN (SELECT id FROM zz_limpa_cobs);

-- ---------- contas e estadias ----------
DELETE FROM ordem_etapas WHERE ordem_id IN (SELECT id FROM zz_limpa_ordens);
UPDATE ordens_servico SET principal_id = NULL WHERE principal_id IN (SELECT id FROM zz_limpa_ordens);
UPDATE estadias SET ordem_id = NULL WHERE ordem_id IN (SELECT id FROM zz_limpa_ordens);
UPDATE estadias SET ordem_convenio_id = NULL WHERE ordem_convenio_id IN (SELECT id FROM zz_limpa_ordens);
UPDATE estadias SET ordem_entrada_id = NULL WHERE ordem_entrada_id IN (SELECT id FROM zz_limpa_ordens);
DELETE FROM ordens_servico WHERE id IN (SELECT id FROM zz_limpa_ordens);
-- registro de ações dos aparelhos (todo ele é do PDV de teste)
DELETE FROM pdv_acoes;
DELETE FROM estadias WHERE id IN (SELECT id FROM zz_limpa_estadias);

-- ---------- caixa do PDV ----------
DELETE FROM caixa_movimentos WHERE caixa_id IS NOT NULL;
DELETE FROM despesas WHERE id IN (SELECT id FROM zz_limpa_desp);
DELETE FROM caixas;

COMMIT;

-- resumo do que saiu
SELECT 'contas apagadas' AS o_que, COUNT(*) AS qtd FROM zz_limpa_ordens
UNION ALL SELECT 'pagamentos apagados', COUNT(*) FROM zz_limpa_pags
UNION ALL SELECT 'estadias apagadas', COUNT(*) FROM zz_limpa_estadias
UNION ALL SELECT 'cobranças apagadas', COUNT(*) FROM zz_limpa_cobs
UNION ALL SELECT 'despesas do caixa apagadas', COUNT(*) FROM zz_limpa_desp
UNION ALL SELECT 'Pix com QR restantes (deve ser 0)', COUNT(*) FROM pix_cobrancas
UNION ALL SELECT 'ações do PDV restantes (deve ser 0)', COUNT(*) FROM pdv_acoes
UNION ALL SELECT 'contas de outros clientes reabertas', COUNT(*) FROM zz_limpa_reabrir;

DROP TABLE IF EXISTS zz_limpa_ordens, zz_limpa_aux, zz_limpa_pags, zz_limpa_estadias, zz_limpa_cobs, zz_limpa_reabrir, zz_limpa_desp;
