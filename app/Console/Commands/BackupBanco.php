<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Backup dos bancos (ERP e, enquanto existir, o do sistema atual) com mysqldump, compactado,
 * em storage/app/private/backups (fora da pasta pública). Guarda os últimos N dias (config erp.backup_dias).
 */
class BackupBanco extends Command
{
    protected $signature = 'backup:banco';

    protected $description = 'Backup compactado dos bancos do ERP e do sistema atual';

    public function handle(): int
    {
        $disco = Storage::disk('local');
        $disco->makeDirectory('backups');
        $ok = true;
        foreach (['erp' => config('database.default'), 'sistema-atual' => config('erp.legado_conexao')] as $nome => $conexao) {
            $c = config("database.connections.{$conexao}");
            if (! $c || empty($c['database'])) {
                continue;
            }
            $base = 'backups/'.$nome.'-'.now()->format('Y-m-d-Hi');
            $sql = $disco->path($base.'.sql');
            // senha pela variável de ambiente, para não aparecer na lista de processos
            $r = Process::env(['MYSQL_PWD' => (string) $c['password']])->timeout(600)->run([
                'mysqldump', '--single-transaction', '--quick', '--skip-lock-tables', '--no-tablespaces',
                '-h', (string) $c['host'], '-P', (string) $c['port'], '-u', (string) $c['username'],
                '--result-file='.$sql, (string) $c['database'],
            ]);
            if (! $r->successful() || ! is_file($sql) || filesize($sql) === 0) {
                @unlink($sql);
                $this->error("Backup de {$nome} falhou: ".trim($r->errorOutput()));
                $ok = false;

                continue;
            }
            $this->compactar($sql, $disco->path($base.'.sql.gz'));
            $this->info("Backup de {$nome}: {$base}.sql.gz (".round(filesize($disco->path($base.'.sql.gz')) / 1024).' KB)');
        }
        $this->limpar();

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function compactar(string $origem, string $destino): void
    {
        $in = fopen($origem, 'rb');
        $out = gzopen($destino, 'wb6');
        while (! feof($in)) {
            gzwrite($out, fread($in, 1 << 20));
        }
        fclose($in);
        gzclose($out);
        unlink($origem);
    }

    private function limpar(): void
    {
        $limite = now()->subDays(config('erp.backup_dias'))->getTimestamp();
        foreach (Storage::disk('local')->files('backups') as $f) {
            if (Storage::disk('local')->lastModified($f) < $limite) {
                Storage::disk('local')->delete($f);
            }
        }
    }
}
