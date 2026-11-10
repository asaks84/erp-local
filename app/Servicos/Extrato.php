<?php

namespace App\Servicos;

use App\Models\Cliente;
use App\Models\ExtratoLancamento;
use App\Models\PagadorVinculado;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Baixa pelo extrato bancário (CSV): lê as entradas, sugere o cliente de cada uma pelo pagador
 * vinculado, pelo CPF parcial que o banco mostra (•••.068.258-••) ou pelo nome. Nada é baixado
 * sem a conferência da equipe. Regras iguais às do sistema atual (extrato_model).
 */
class Extrato
{
    public const TAMANHO_MAX_KB = 2048;

    /**
     * Devolve ['entradas' => [...], 'saidas' => n, 'erro' => ''].
     * Cada entrada: id, data (AAAA-MM-DD), valor, tipo, nome, documento, conta, descricao.
     */
    public static function lerCsv(string $texto): array
    {
        $res = ['entradas' => [], 'saidas' => 0, 'erro' => ''];
        $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto);
        if (trim($texto) === '') {
            return ['erro' => 'O arquivo está vazio.'] + $res;
        }
        if (! mb_check_encoding($texto, 'UTF-8')) {
            $texto = mb_convert_encoding($texto, 'UTF-8', 'ISO-8859-1'); // planilhas antigas
        }
        $linhas = preg_split('/\r\n|\r|\n/', trim($texto));
        $sep = substr_count($linhas[0], ';') > substr_count($linhas[0], ',') ? ';' : ',';

        $cab = array_map([self::class, 'normalNome'], str_getcsv(array_shift($linhas), $sep, '"', ''));
        $col = [];
        foreach (['data' => ['DATA'], 'valor' => ['VALOR'], 'id' => ['IDENTIFICADOR', 'ID'], 'desc' => ['DESCRICAO', 'HISTORICO']] as $k => $nomes) {
            foreach ($nomes as $n) {
                $i = array_search($n, $cab, true);
                if ($i !== false) {
                    $col[$k] = $i;
                    break;
                }
            }
        }
        if (! isset($col['data'], $col['valor'], $col['desc'])) {
            return ['erro' => 'O arquivo não tem as colunas esperadas (Data, Valor, Descrição). Exporte o extrato em CSV pelo app do banco.'] + $res;
        }

        foreach ($linhas as $linha) {
            if (trim($linha) === '') {
                continue;
            }
            $c = str_getcsv($linha, $sep, '"', '');
            $data = self::data($c[$col['data']] ?? '');
            $valor = self::numero($c[$col['valor']] ?? '');
            $desc = trim($c[$col['desc']] ?? '');
            if (! $data || $valor === null) {
                continue;
            }
            if ($valor <= 0) {
                $res['saidas']++;

                continue;
            }
            $id = isset($col['id']) && ! empty($c[$col['id']]) ? trim($c[$col['id']])
                : 'h'.substr(sha1($data.'|'.$valor.'|'.$desc), 0, 40); // banco sem identificador
            $res['entradas'][] = ['id' => mb_substr($id, 0, 64), 'data' => $data, 'valor' => $valor, 'descricao' => mb_substr($desc, 0, 300)] + self::lerDescricao($desc);
        }
        if (! $res['entradas'] && ! $res['saidas']) {
            $res['erro'] = 'Nenhum lançamento encontrado no arquivo.';
        }

        return $res;
    }

    /** "Transferência recebida pelo Pix - NOME - •••.068.258-•• - BANCO (0104) Agência: 1613 Conta: 12880-2" */
    public static function lerDescricao(string $desc): array
    {
        $partes = array_map('trim', explode(' - ', $desc));
        $r = ['tipo' => $partes[0], 'nome' => '', 'documento' => '', 'conta' => ''];
        if (preg_match('/\((\d{3,4})\)\s*Ag[eê]ncia:\s*(\d+)\s*Conta:\s*([\dXx\-]+)/u', $desc, $m)) {
            $r['conta'] = mb_substr($m[1].'|'.ltrim($m[2], '0').'|'.strtoupper($m[3]), 0, 60);
        }
        if (count($partes) >= 2) {
            $r['nome'] = $partes[1];
        }
        if (count($partes) >= 3 && preg_match('/\d{3}\D\d{3}/u', $partes[2])) {
            $dig = preg_replace('/\D/', '', $partes[2]);
            // CPF mascarado mostra só os 6 dígitos do meio; CNPJ vem completo
            if (in_array(strlen($dig), [6, 11, 14], true)) {
                $r['documento'] = $dig;
            }
        }

        return $r;
    }

    /** clientes ativos com o que precisa para a sugestão */
    public static function clientes(): Collection
    {
        return Cliente::where('ativo', true)->get(['id', 'nome', 'documento'])
            ->map(fn ($c) => (object) ['id' => $c->id, 'nome' => $c->nome, 'documento' => (string) $c->documento, 'tokens' => self::tokens($c->nome)])
            ->keyBy('id');
    }

    /** Sugere o cliente de uma entrada: [cliente_id|null, certeza alta|media|'', motivo] */
    public static function sugerir(array $e, Collection $clientes): array
    {
        $nome = self::normalNome($e['nome']);
        $doc = $e['documento'];

        if (($v = self::vinculoDoPagador($e)) && $clientes->has($v->cliente_id)) {
            return [$v->cliente_id, 'alta', 'pagador vinculado'];
        }
        if ($doc !== '') {
            $achados = $clientes->filter(fn ($c) => $c->documento !== '' && ((strlen($doc) === 6 && strlen($c->documento) === 11 && substr($c->documento, 3, 6) === $doc) || $c->documento === $doc))->keys()->all();
            if (count($achados) === 1) {
                return [$achados[0], 'alta', 'CPF/CNPJ confere'];
            }
            if (count($achados) > 1) {
                $melhor = null;
                $nota = 0;
                foreach ($achados as $id) {
                    $n = self::semelhanca($nome, $clientes[$id]->tokens);
                    if ($n > $nota) {
                        [$nota, $melhor] = [$n, $id];
                    }
                }
                if ($melhor) {
                    return [$melhor, 'alta', 'CPF e nome conferem'];
                }
            }
        }
        $melhor = null;
        $nota = 0;
        $empate = false;
        foreach ($clientes as $id => $c) {
            $n = self::semelhanca($nome, $c->tokens);
            if ($n > $nota) {
                [$nota, $melhor, $empate] = [$n, $id, false];
            } elseif ($n === $nota && $n > 0) {
                $empate = true;
            }
        }
        if ($melhor && $nota >= 60) {
            if ($nota >= 90 && ! $empate) {
                return [$melhor, 'alta', 'nome confere'];
            }

            return [$melhor, 'media', $empate ? 'nome parecido com mais de um cliente' : 'nome parecido'];
        }

        return [null, '', 'nenhum cliente encontrado'];
    }

    /** 0 a 100: quanto o nome do pagador parece com o do cliente (aceita abreviação: "S", "SCHL") */
    public static function semelhanca(string $nomePagador, array $tokensCli): int
    {
        $p = self::tokens($nomePagador);
        if (! $p || ! $tokensCli) {
            return 0;
        }
        if ($p === $tokensCli) {
            return 100;
        }
        $bate = function ($t) use ($tokensCli) {
            foreach ($tokensCli as $c) {
                if ($c === $t || (strlen($t) === 1 && $c[0] === $t) || (strlen($t) >= 3 && str_starts_with($c, $t))) {
                    return true;
                }
            }

            return false;
        };
        $primeiro = $p[0] === $tokensCli[0];
        $ultimo = $bate(end($p));
        $comuns = count(array_filter($p, $bate));

        return match (true) {
            $primeiro && $comuns === count($p) && count($p) >= 2 => 95,
            $primeiro && $ultimo && $comuns >= 2 => 90,
            $primeiro && $comuns >= 2 => 75,
            $comuns >= 2 && $ultimo => 60,
            default => 0,
        };
    }

    public static function vinculoDoPagador(array $e): ?PagadorVinculado
    {
        if (($e['conta'] ?? '') !== '' && ($v = PagadorVinculado::where('conta', $e['conta'])->first())) {
            return $v;
        }
        $nome = self::normalNome($e['nome']);
        if ($nome === '') {
            return null;
        }

        return PagadorVinculado::where('nome', $nome)->whereRaw("COALESCE(documento, '') = ?", [(string) $e['documento']])->first();
    }

    /** liga o pagador ao cliente (substitui o vínculo anterior do mesmo pagador ou da mesma conta) */
    public static function vincular(array $e, int $clienteId): void
    {
        $nome = mb_substr(self::normalNome($e['nome']), 0, 100);
        if ($nome === '') {
            return;
        }
        $doc = (string) $e['documento'];
        $conta = ($e['conta'] ?? '') !== '' ? $e['conta'] : null;
        DB::transaction(function () use ($nome, $doc, $conta, $clienteId) {
            if ($conta) {
                PagadorVinculado::where('conta', $conta)->where(fn ($q) => $q->where('nome', '<>', $nome)->orWhereRaw("COALESCE(documento, '') <> ?", [$doc]))->delete();
            }
            $v = PagadorVinculado::where('nome', $nome)->whereRaw("COALESCE(documento, '') = ?", [$doc])->first() ?? new PagadorVinculado(['nome' => $nome, 'documento' => $doc ?: null]);
            $v->fill(['cliente_id' => $clienteId, 'conta' => $conta ?? $v->conta, 'criado_por' => Auth::id()])->save();
        });
    }

    /** identificadores já processados (o mesmo PIX não baixa duas vezes) */
    public static function jaProcessados(array $ids): Collection
    {
        return ExtratoLancamento::with('cliente:id,nome')->whereIn('identificador', $ids ?: ['-'])->get()->keyBy('identificador');
    }

    public static function normalNome(?string $s): string
    {
        $s = strtr(mb_strtoupper((string) $s, 'UTF-8'), [
            'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ç' => 'C', 'Ñ' => 'N']);
        $s = preg_replace('/[^A-Z ]/', ' ', $s);

        return trim(preg_replace('/\s+/', ' ', $s));
    }

    public static function tokens(string $nome): array
    {
        return array_values(array_filter(explode(' ', self::normalNome($nome)), fn ($p) => $p !== '' && ! in_array($p, ['DE', 'DA', 'DO', 'DAS', 'DOS', 'E'], true)));
    }

    private static function data(string $s): ?string
    {
        $s = trim($s);
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $s, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return "$m[3]-$m[2]-$m[1]";
        }
        if (preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $s, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $s;
        }

        return null;
    }

    /** "10.00", "1.234,56", "-31,50", "R$ 90,00" → float */
    private static function numero(string $s): ?float
    {
        $s = preg_replace('/[^\d,.\-]/', '', $s);
        if ($s === '' || $s === '-') {
            return null;
        }
        if (str_contains($s, ',')) {
            $s = str_replace(',', '.', str_replace('.', '', $s));
        }

        return is_numeric($s) ? round((float) $s, 2) : null;
    }
}
