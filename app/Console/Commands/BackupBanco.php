<?php

namespace App\Console\Commands;

use App\Servicos\Backup\Cofre;
use App\Servicos\Backup\GoogleDrive;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Backup dos bancos (ERP e, enquanto existir, o do sistema atual), compactado, em
 * storage/app/private/backups (fora da pasta pública). Guarda os últimos N dias (config erp.backup_dias).
 *
 * Feito em PHP puro (sem mysqldump): a hospedagem compartilhada não deixa abrir outros
 * programas (proc_open desligado). Gera um .sql.gz que se restaura com
 * "gunzip < arquivo.sql.gz | mysql banco" ou pelo phpMyAdmin.
 *
 * Com o Google Drive conectado (Configurações › Backup), cada arquivo também sai do servidor: criptografado com a
 * senha do backup (Cofre) e enviado para a pasta de backups do Drive, que guarda os últimos N dias.
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
        $novos = [];
        foreach (['erp' => config('database.default'), 'sistema-atual' => config('erp.legado_conexao')] as $nome => $conexao) {
            $c = config("database.connections.{$conexao}");
            if (! $c || empty($c['database'])) {
                continue;
            }
            $arquivo = $disco->path('backups/'.$nome.'-'.now()->format('Y-m-d-Hi').'.sql.gz');
            try {
                $linhas = $this->despejar($conexao, $arquivo);
                $this->info("Backup de {$nome}: ".basename($arquivo).' ('.round(filesize($arquivo) / 1024)." KB, {$linhas} registros)");
                $novos[] = $arquivo;
            } catch (\Throwable $e) {
                @unlink($arquivo);
                $this->error("Backup de {$nome} falhou: ".$e->getMessage());
                report($e);
                $ok = false;
            }
        }
        $this->limpar();
        if ($novos && ! $this->enviarParaDrive($novos)) {
            $ok = false;
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /** cópia criptografada no Google Drive; uma falha aqui não apaga o backup local, mas fica registrada na tela */
    private function enviarParaDrive(array $arquivos): bool
    {
        $i = GoogleDrive::integracao();
        if (! $i?->ativo || ! $i->credencial('refresh_token') || ! $i->credencial('senha')) {
            return true;
        }
        $temp = [];
        try {
            $drive = new GoogleDrive($i);
            $enviados = [];
            $tamanho = 0;
            foreach ($arquivos as $arquivo) {
                $cifrado = $temp[] = $arquivo.'.enc';
                Cofre::criptografar($arquivo, $cifrado, $i->credencial('senha'));
                $drive->enviar($cifrado, basename($cifrado));
                $enviados[] = basename($cifrado);
                $tamanho += filesize($cifrado);
            }
            $apagados = $drive->limpar((int) ($i->credencial('dias') ?: 30));
            $cred = $i->credenciais;
            $cred['ultimo_envio'] = ['arquivos' => $enviados, 'kb' => (int) round($tamanho / 1024), 'apagados' => $apagados];
            $i->forceFill(['credenciais' => $cred, 'testado_em' => now(), 'ultimo_erro' => null])->save();
            $this->info('Google Drive: '.implode(', ', $enviados).($apagados ? " ({$apagados} antigo(s) apagado(s))" : ''));

            return true;
        } catch (\Throwable $e) {
            $i->forceFill(['ultimo_erro' => mb_substr(now()->format('d/m H:i').' '.$e->getMessage(), 0, 255)])->save();
            $this->error('Google Drive: '.$e->getMessage());
            report($e);

            return false;
        } finally {
            foreach ($temp as $t) {
                @unlink($t);
            }
        }
    }

    /** grava estrutura e dados de todas as tabelas; devolve quantos registros */
    private function despejar(string $conexao, string $arquivo): int
    {
        $db = DB::connection($conexao);
        $pdo = $db->getPdo();
        $banco = $db->getDatabaseName();
        $gz = gzopen($arquivo, 'wb6');
        $total = 0;
        try {
            gzwrite($gz, "-- Backup de {$banco} em ".now()->format('d/m/Y H:i')."\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
            $tabelas = array_map(fn ($r) => array_values((array) $r)[0], $db->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"));
            foreach ($tabelas as $t) {
                $criar = array_values((array) $db->selectOne("SHOW CREATE TABLE `{$t}`"))[1];
                gzwrite($gz, "DROP TABLE IF EXISTS `{$t}`;\n{$criar};\n");
                // colunas geradas (ex.: placa_ativa) não entram no INSERT
                $colunas = array_map(fn ($r) => $r->COLUMN_NAME, $db->select(
                    "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND EXTRA NOT LIKE '%GENERATED%' ORDER BY ORDINAL_POSITION", [$banco, $t]));
                $lista = implode(', ', array_map(fn ($c) => "`{$c}`", $colunas));
                $lote = [];
                foreach ($db->cursor("SELECT {$lista} FROM `{$t}`") as $linha) {
                    $lote[] = '('.implode(', ', array_map(fn ($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? $v : $pdo->quote((string) $v)), array_values((array) $linha))).')';
                    $total++;
                    if (count($lote) === 200) {
                        gzwrite($gz, "INSERT INTO `{$t}` ({$lista}) VALUES\n".implode(",\n", $lote).";\n");
                        $lote = [];
                    }
                }
                if ($lote) {
                    gzwrite($gz, "INSERT INTO `{$t}` ({$lista}) VALUES\n".implode(",\n", $lote).";\n");
                }
                gzwrite($gz, "\n");
            }
            gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            gzclose($gz);
        }

        return $total;
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
