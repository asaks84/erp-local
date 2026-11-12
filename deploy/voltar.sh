#!/usr/bin/env bash
# Roda NO SERVIDOR: volta o ERP para a versão anterior (botão "Publicar" com a ação "voltar").
# O banco NÃO volta: migrações já aplicadas continuam. Para o banco, use o backup (docs/14-publicacao.md).
set -euo pipefail
BASE="${ERP_DIR:-$HOME/erp}"
atual="$(basename "$(readlink -f "$BASE/atual")")"
anterior="$(ls -1dt "$BASE"/releases/*/ | xargs -n1 basename | grep -vx "$atual" | head -1 || true)"
[ -n "$anterior" ] || { echo "Não há versão anterior guardada."; exit 1; }
ln -sfn "$BASE/releases/$anterior" "$BASE/atual.novo" && mv -Tf "$BASE/atual.novo" "$BASE/atual"
# a versão que saiu fica marcada como mais antiga, para não voltar para ela de novo
touch -d '2000-01-01' "$BASE/releases/$atual"
echo "Voltou de $atual para $anterior"
