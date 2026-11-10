@echo off
rem Recria o banco do ERP do zero e importa tudo do sistema atual (localm43_senna).
rem APAGA tudo o que foi lancado so no ERP. O resultado fica em storage\logs\recriar.txt
cd /d "%~dp0"
echo Recriando o banco do ERP e importando... (pode levar alguns segundos)
(
  echo === %date% %time% ===
  php artisan migrate:fresh --force --no-interaction
  echo.
  php artisan legado:importar --empresa="Local Estacionamento" --no-interaction
  echo.
  php artisan legado:comparar --no-interaction
) > storage\logs\recriar.txt 2>&1
type storage\logs\recriar.txt
echo.
pause
