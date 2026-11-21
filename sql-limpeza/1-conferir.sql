-- =====================================================================================
-- ERP Local · LIMPEZA DOS TESTES · PASSO 1: CONFERIR (só lê, não muda nada)
-- Rode no phpMyAdmin, no banco do ERP. Depois rode o 2-apagar.sql.
-- =====================================================================================

-- 1) O 1012 é o cliente de teste? (o 2-apagar só age se o nome for CAU...CARRERA)
SELECT id, nome, ativo, legado_id FROM clientes WHERE id = 1012;

-- 2) O que vai sair
SELECT 'contas do 1012 criadas no ERP' AS o_que, COUNT(*) AS qtd FROM ordens_servico WHERE cliente_id = 1012 AND legado_id IS NULL
UNION ALL SELECT 'contas do 1012 vindas do sistema atual (FICAM)', COUNT(*) FROM ordens_servico WHERE cliente_id = 1012 AND legado_id IS NOT NULL
UNION ALL SELECT 'cobranças do 1012', COUNT(*) FROM cobrancas WHERE cliente_id = 1012
UNION ALL SELECT 'pagamentos do 1012 criados no ERP', COUNT(*) FROM pagamentos WHERE cliente_id = 1012 AND legado_chave IS NULL
UNION ALL SELECT 'estadias do PDV', COUNT(*) FROM estadias WHERE aparelho_id IS NOT NULL
UNION ALL SELECT 'estadias do pátio do ERP (FICAM, exceto as do 1012)', COUNT(*) FROM estadias WHERE aparelho_id IS NULL
UNION ALL SELECT 'contas lançadas pelo PDV', COUNT(*) FROM ordens_servico WHERE origem = 'pdv' AND legado_id IS NULL
UNION ALL SELECT 'pagamentos do PDV', COUNT(*) FROM pagamentos WHERE origem = 'pdv' OR caixa_id IS NOT NULL OR aparelho_id IS NOT NULL
UNION ALL SELECT 'Pix com QR', COUNT(*) FROM pix_cobrancas
UNION ALL SELECT 'caixas', COUNT(*) FROM caixas
UNION ALL SELECT 'movimentos da gaveta', COUNT(*) FROM caixa_movimentos
UNION ALL SELECT 'ações registradas dos aparelhos', COUNT(*) FROM pdv_acoes;

-- 3) PONTOS DE ATENÇÃO (o ideal é 0; se não for, me mande este resultado antes do passo 2)
SELECT 'A) boleto do 1012 ainda ativo no Inter (cancele no ERP antes)' AS atencao, COUNT(*) AS qtd
  FROM boletos WHERE cliente_id = 1012 AND situacao IN ('em_processamento', 'a_receber', 'atrasado')
UNION ALL SELECT 'B) NFS-e ligada a pagamento que sai (a nota fica, perde o vínculo)', COUNT(*)
  FROM nfses n JOIN pagamentos p ON p.id = n.pagamento_id
  WHERE p.origem = 'pdv' OR p.caixa_id IS NOT NULL OR p.aparelho_id IS NOT NULL OR (p.cliente_id = 1012 AND p.legado_chave IS NULL)
UNION ALL SELECT 'C) conta de outro cliente recebida no PDV (volta a ficar em aberto)', COUNT(DISTINCT i.ordem_id)
  FROM pagamento_itens i JOIN pagamentos p ON p.id = i.pagamento_id JOIN ordens_servico o ON o.id = i.ordem_id
  WHERE (p.origem = 'pdv' OR p.caixa_id IS NOT NULL OR p.aparelho_id IS NOT NULL) AND o.origem <> 'pdv' AND o.estadia_id IS NULL AND COALESCE(o.cliente_id, 0) <> 1012
UNION ALL SELECT 'D) despesa do caixa ligada a holerite, diária ou fatura', COUNT(*)
  FROM despesas d WHERE d.id IN (SELECT despesa_id FROM caixa_movimentos WHERE despesa_id IS NOT NULL)
    AND (EXISTS (SELECT 1 FROM holerites h WHERE h.despesa_id = d.id) OR EXISTS (SELECT 1 FROM diarias x WHERE x.despesa_id = d.id)
         OR EXISTS (SELECT 1 FROM faturas_cartao f WHERE f.despesa_id = d.id));
