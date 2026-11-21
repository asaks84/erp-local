<?php

namespace App\Console\Commands;

use App\Servicos\Backup\Cofre;
use Illuminate\Console\Command;

/**
 * Abre um backup criptografado baixado do Google Drive (.sql.gz.enc) e grava o .sql.gz ao lado.
 * Pede a senha do backup (a que foi definida em Configurações › Backup). Funciona no computador, sem o servidor.
 */
class AbrirBackup extends Command
{
    protected $signature = 'backup:abrir {arquivo : caminho do .sql.gz.enc}';

    protected $description = 'Descriptografa um backup baixado do Google Drive';

    public function handle(): int
    {
        $arquivo = $this->argument('arquivo');
        if (! is_file($arquivo)) {
            $this->error('Arquivo não encontrado: '.$arquivo);

            return self::FAILURE;
        }
        $destino = preg_replace('/\.enc$/', '', $arquivo);
        if ($destino === $arquivo) {
            $destino .= '.aberto';
        }
        $senha = (string) $this->secret('Senha do backup');
        try {
            Cofre::abrir($arquivo, $destino, $senha);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info('Pronto: '.$destino);
        $this->line('Para restaurar: phpMyAdmin › Importar, ou "gunzip < arquivo.sql.gz | mysql banco".');

        return self::SUCCESS;
    }
}
