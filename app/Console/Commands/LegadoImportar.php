<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Models\LogAtividade;
use App\Models\Nivel;
use App\Models\Unidade;
use App\Models\Usuario;
use App\Support\EmpresaAtual;
use App\Support\Legado;
use App\Console\Commands\Concerns\ImportaCadastros;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Importa os dados do sistema atual (CodeIgniter) para o ERP. Pode rodar quantas vezes
 * for preciso: cada registro guarda o id antigo (legado_id) e é atualizado, não duplicado.
 * Até a virada o sistema atual é o oficial, então ele sempre sobrescreve o que veio dele.
 *
 * Etapa 1: níveis de acesso, usuários e registro de atividades.
 * Etapa 2: tipos, tabelas de estadia, serviços, clientes, veículos, mensalidades, convênios,
 * fila do cadastro público e pagadores vinculados (Concerns\ImportaCadastros).
 * A etapa 3 acrescenta ordens de serviço e pagamentos.
 * Ver docs/04-migracao-de-dados.md.
 */
class LegadoImportar extends Command
{
    use ImportaCadastros;

    protected $signature = 'legado:importar
        {--empresa= : Nome da empresa (só na primeira importação)}
        {--unidade=Principal : Nome da unidade principal (só na primeira importação)}
        {--sem-atividades : Não importa o registro de atividades}';

    protected $description = 'Importa os dados do sistema atual (pode rodar várias vezes)';

    private array $resumo = [];

    public function handle(Legado $legado, EmpresaAtual $empresaAtual): int
    {
        $legado->exige('USUARIOS');

        $empresa = $this->empresa();
        if (! $empresa) {
            return self::FAILURE;
        }

        $empresaAtual->como($empresa->id, function () use ($legado) {
            DB::transaction(function () use ($legado) {
                $this->unidadePrincipal();
                $niveis = $this->niveis($legado);
                $usuarios = $this->usuarios($legado, $niveis);
                if (! $this->option('sem-atividades')) {
                    $this->atividades($legado, $usuarios);
                }
                $this->importarCadastros($legado);
            });
        });

        $this->table(['O quê', 'Novos', 'Atualizados', 'Ignorados'], $this->resumo);
        $this->info('Importação concluída. Para conferir: php artisan legado:comparar');

        return self::SUCCESS;
    }

    private function empresa(): ?Empresa
    {
        $total = Empresa::count();
        if ($total === 1) {
            return Empresa::first();
        }
        if ($total > 1) {
            $this->error('Há mais de uma empresa no ERP; a importação do sistema atual é para a primeira instalação.');

            return null;
        }
        $nome = $this->option('empresa') ?: $this->ask('Nome da empresa (como vai aparecer no sistema)');
        if (! $nome) {
            $this->error('Informe o nome da empresa (--empresa="...").');

            return null;
        }

        return Empresa::create(['nome' => $nome]);
    }

    private function unidadePrincipal(): void
    {
        if (! Unidade::exists()) {
            Unidade::create(['nome' => $this->option('unidade') ?: 'Principal']);
            $this->resumo[] = ['Unidade principal', 1, 0, 0];
        }
    }

    /** @return array<int,int> id do nível antigo → id do nível novo */
    private function niveis(Legado $legado): array
    {
        $catalogo = Nivel::catalogoChaves();
        $antigos = [];
        if ($tabela = $legado->tabela('NIVEIS_ACESSO')) {
            foreach ($legado->conexao()->table($tabela)->orderBy('niv_id')->get() as $n) {
                $antigos[] = [
                    'legado_id' => (int) $n->niv_id,
                    'nome' => self::consertarAcentos(trim($n->niv_nome)),
                    'fixo' => (bool) $n->niv_fixo,
                    'permissoes' => array_values(array_intersect(array_filter(array_map('trim', explode(',', (string) $n->niv_permissoes))), $catalogo)),
                ];
            }
        } else {
            // sistema atual antes dos níveis configuráveis: só administrador (1) e operador (3)
            $antigos[] = ['legado_id' => 1, 'nome' => 'Administrador', 'fixo' => true, 'permissoes' => []];
            $antigos[] = ['legado_id' => 3, 'nome' => 'Operador', 'fixo' => false, 'permissoes' => []];
        }

        $mapa = [];
        $novos = $atualizados = 0;
        foreach ($antigos as $a) {
            $nivel = Nivel::where('legado_id', $a['legado_id'])->first()
                ?? Nivel::whereNull('legado_id')->where('nome', $a['nome'])->first(); // criado pelo erp:instalar
            $novo = ! $nivel;
            $nivel ??= new Nivel;
            $nivel->fill($a);
            $nivel->legado_id = $a['legado_id'];
            $nivel->save();
            $novo ? $novos++ : $atualizados++;
            $mapa[$a['legado_id']] = $nivel->id;
        }
        $this->resumo[] = ['Níveis de acesso', $novos, $atualizados, 0];

        return $mapa;
    }

    /** @return array<string,Usuario> login antigo (minúsculo) → usuário novo */
    private function usuarios(Legado $legado, array $niveis): array
    {
        $tabela = $legado->exige('USUARIOS');
        $temNivel = $legado->tem('USUARIOS', 'usr_niv_id');
        $mapa = [];
        $novos = $atualizados = $ignorados = 0;

        foreach ($legado->conexao()->table($tabela)->orderBy('usr_id')->get() as $u) {
            $nivelAntigo = $temNivel && $u->usr_niv_id ? (int) $u->usr_niv_id : ((int) $u->usr_admin === 1 ? 1 : 3);
            $nivelId = $niveis[$nivelAntigo] ?? $niveis[(int) $u->usr_admin === 1 ? 1 : 3] ?? null;
            $login = trim($u->usr_nickname);

            $usuario = Usuario::where('legado_id', $u->usr_id)->first();
            $conflito = Usuario::withoutGlobalScopes()->where('login', $login)
                ->when($usuario, fn ($q) => $q->where('id', '<>', $usuario->id))->exists();
            if ($conflito || ! $nivelId) {
                $this->warn("Usuário \"{$login}\" ignorado: ".($conflito ? 'já existe outro usuário com esse login no ERP.' : 'nível não encontrado.'));
                $ignorados++;

                continue;
            }

            $novo = ! $usuario;
            $usuario ??= new Usuario;
            $usuario->fill([
                'nome' => self::consertarAcentos(trim($u->usr_full_name)),
                'login' => $login,
                'nivel_id' => $nivelId,
                'ativo' => true,
            ]);
            $usuario->senha = $u->usr_password; // mesmo formato (bcrypt); a senha atual continua valendo
            $usuario->legado_id = $u->usr_id;
            $usuario->save();
            $novo ? $novos++ : $atualizados++;
            $mapa[mb_strtolower($login)] = $usuario;
        }
        $this->resumo[] = ['Usuários', $novos, $atualizados, $ignorados];

        return $mapa;
    }

    private function atividades(Legado $legado, array $usuarios): void
    {
        $tabela = $legado->tabela('LOG_ATIVIDADES');
        if (! $tabela) {
            return;
        }
        $ultimo = (int) LogAtividade::max('legado_id');
        $novos = 0;
        $legado->conexao()->table($tabela)->where('log_id', '>', $ultimo)->orderBy('log_id')
            ->chunk(500, function ($linhas) use ($usuarios, &$novos) {
                $lote = [];
                foreach ($linhas as $l) {
                    $usuario = $usuarios[mb_strtolower(trim((string) $l->log_usuario))] ?? null;
                    $lote[] = [
                        'empresa_id' => app(EmpresaAtual::class)->id(),
                        'usuario_id' => $usuario?->id,
                        'usuario_nome' => $usuario?->nome ?? mb_substr((string) $l->log_usuario, 0, 80),
                        'acao' => mb_substr((string) $l->log_acao, 0, 40),
                        'detalhe' => $l->log_detalhe === null ? null : mb_substr($l->log_detalhe, 0, 500),
                        'ip' => $l->log_ip,
                        'criado_em' => $l->log_quando,
                        'legado_id' => $l->log_id,
                    ];
                }
                LogAtividade::insert($lote);
                $novos += count($lote);
            });
        $this->resumo[] = ['Registro de atividades', $novos, 0, 0];
    }
}
