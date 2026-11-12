<?php

use Illuminate\Support\Facades\Schedule;

// Agendador: no servidor, o cron roda "php artisan schedule:run" a cada minuto (docs/14-publicacao.md).

// Paralelo (etapa 5): espelha o sistema atual de hora em hora, das 7h às 23h, e compara os números.
// Só roda com ERP_ESPELHO_ATIVO=true (desligar na virada).
Schedule::command('legado:espelhar')->hourly()->between('7:00', '23:00')
    ->when(fn () => config('erp.espelho_ativo'))->withoutOverlapping(30);

// Backup diário dos bancos às 3h10.
Schedule::command('backup:banco')->dailyAt('03:10')->withoutOverlapping(60);
