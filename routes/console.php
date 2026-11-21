<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Agendador: no servidor, o cron roda "php artisan schedule:run" a cada minuto (docs/14-publicacao.md).
// As tarefas rodam DENTRO do mesmo processo (Schedule::call + Artisan::call), e não como comando
// separado (Schedule::command): a hospedagem compartilhada desliga o proc_open, e o agendador
// não conseguia abrir o comando ("The Process class relies on proc_open").

// Paralelo (etapa 5): espelha o sistema atual de hora em hora, das 7h às 23h, e compara os números.
// Só roda com ERP_ESPELHO_ATIVO=true (desligar na virada).
Schedule::call(fn () => Artisan::call('legado:espelhar'))->name('legado:espelhar')
    ->hourly()->between('7:00', '23:00')->when(fn () => config('erp.espelho_ativo'))->withoutOverlapping(30);

// Contas fixas: gera as contas a pagar até o mês seguinte (também é feito ao abrir Contas a pagar).
Schedule::call(fn () => Artisan::call('pagar:gerar-fixas'))->name('pagar:gerar-fixas')->dailyAt('06:05');

// Banco Inter: consulta os boletos em aberto a cada 10 minutos (baixa dos pagos, cancela os de
// cobranças já resolvidas). Sem Inter configurado e ligado, não faz nada.
Schedule::call(fn () => Artisan::call('inter:consultar'))->name('inter:consultar')->everyTenMinutes()->withoutOverlapping(15);

// WhatsApp: cria os avisos de cobrança do dia e, com a API ligada e o envio automático marcado, envia
// (só depois do horário escolhido em Configurações › WhatsApp, até as 20h).
Schedule::call(fn () => Artisan::call('whatsapp:regua'))->name('whatsapp:regua')->everyTenMinutes()->between('7:00', '20:00')->withoutOverlapping(15);

// Backup diário dos bancos às 3h10.
Schedule::call(fn () => Artisan::call('backup:banco'))->name('backup:banco')->dailyAt('03:10')->withoutOverlapping(60);

// LGPD: prazos de guarda (fila de espera 120 dias; ex-cliente 5 anos), depois do backup
Schedule::call(fn () => Artisan::call('lgpd:limpar'))->name('lgpd:limpar')->dailyAt('03:40')->withoutOverlapping(30);
