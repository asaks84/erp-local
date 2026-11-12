#!/usr/bin/env bash
# Roda NO SERVIDOR, chamado pelo botão "Publicar" do GitHub (.github/workflows/publicar.yml).
# Uso: servidor.sh <versao> <pasta-publica-do-subdominio> [php]
# Estrutura: ~/erp/releases/<versao>  ~/erp/compartilhado/{.env,storage}  ~/erp/atual -> versão no ar
set -euo pipefail
VERSAO="$1"; DOCROOT="$2"; PHP="${3:-php}"
BASE="${ERP_DIR:-$HOME/erp}"
R="$BASE/releases/$VERSAO"; C="$BASE/compartilhado"

[ -d "$R" ] || { echo "Versão $R não encontrada."; exit 1; }
[ -f "$C/.env" ] || { echo "Falta $C/.env no servidor (veja docs/14-publicacao.md)."; exit 1; }

mkdir -p "$C/storage/app/private" "$C/storage/app/public" "$C/storage/framework/cache/data" \
         "$C/storage/framework/sessions" "$C/storage/framework/views" "$C/storage/logs"
chmod 600 "$C/.env"
rm -rf "$R/storage"
ln -sfn "$C/storage" "$R/storage"
ln -sfn "$C/.env" "$R/.env"
mkdir -p "$R/bootstrap/cache"

cd "$R"
grep -q '^APP_KEY=base64:' "$C/.env" || "$PHP" artisan key:generate --force
"$PHP" artisan migrate --force
"$PHP" artisan optimize:clear > /dev/null
"$PHP" artisan optimize

# troca a versão no ar de uma vez (sem meio-termo)
ln -sfn "$R" "$BASE/atual.novo" && mv -Tf "$BASE/atual.novo" "$BASE/atual"

# pasta pública do subdomínio aponta para a versão no ar
if [ -L "$DOCROOT" ]; then
    ln -sfn "$BASE/atual/public" "$DOCROOT.novo" && mv -Tf "$DOCROOT.novo" "$DOCROOT"
elif [ -d "$DOCROOT" ]; then
    extra=$(find "$DOCROOT" -mindepth 1 -maxdepth 1 ! -name 'default.php' ! -name 'index.php' ! -name 'index.html' ! -name '.htaccess' | head -1)
    if [ -n "$extra" ]; then
        echo "A pasta $DOCROOT tem arquivos ($extra); não vou substituir. Esvazie a pasta e publique de novo."; exit 1
    fi
    mv "$DOCROOT" "$DOCROOT.antes-erp-$VERSAO"
    ln -s "$BASE/atual/public" "$DOCROOT"
else
    ln -s "$BASE/atual/public" "$DOCROOT"
fi

# guarda as 5 últimas versões (para voltar atrás)
ls -1dt "$BASE"/releases/*/ | tail -n +6 | xargs -r rm -rf
echo "No ar: $VERSAO"
