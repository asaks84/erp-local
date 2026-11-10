@echo off
rem Importa do sistema atual (banco localm43_senna) e compara os numeros.
rem O resultado tambem fica em storage\logs\importacao.txt
cd /d "%~dp0"
echo Importando... (pode levar alguns segundos)
(
  echo === %date% %time% ===
  php artisan legado:importar --no-interaction
  echo.
  php artisan legado:comparar --no-interaction
) > storage\logs\importacao.txt 2>&1
type storage\logs\importacao.txt
echo.
pause
