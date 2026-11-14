<?php

namespace App\Servicos;

use App\Models\AtualizacaoCadastral;
use App\Models\Cliente;
use App\Models\Veiculo;
use App\Support\Atividades;
use App\Support\Documento;
use App\Support\Placa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Atualização cadastral: link individual por cliente (30 dias), aberto pelo cliente confirmando a
 * placa de um veículo dele; o que ele envia passa por aprovação. Aprovado, os campos alterados
 * passam a valer pelo ERP (clientes.campos_locais): o espelho do sistema atual não os sobrescreve.
 */
class Atualizacoes
{
    public const CAMPOS = ['documento', 'nome', 'celular', 'telefone', 'email', 'cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'uf'];

    public const VALIDADE_DIAS = 30;

    public const TENTATIVAS = 5;

    private static function erro(string $campo, string $msg): never
    {
        throw ValidationException::withMessages([$campo => $msg]);
    }

    /** problema do CPF/CNPJ (null = parece certo). $repetidos: documentos usados por mais de um cliente */
    public static function problemaDocumento(?string $doc, array $repetidos = []): ?string
    {
        $d = Documento::limpar($doc);
        if (! $d) {
            return 'Em branco';
        }
        if (! Documento::valido($d)) {
            return in_array(strlen($d), [11, 14], true) ? 'Dígitos não conferem' : 'Quantidade de números errada';
        }

        return in_array($d, $repetidos, true) ? 'Repetido em outro cliente' : null;
    }

    /** problema do celular (null = formato de celular brasileiro: DDD + 9 + 8 números) */
    public static function problemaCelular(?string $cel): ?string
    {
        $d = preg_replace('/\D/', '', (string) $cel);
        if (strlen($d) > 11 && str_starts_with($d, '55')) {
            $d = substr($d, 2);
        }
        if ($d === '') {
            return 'Sem celular';
        }
        if (strlen($d) === 10 && in_array($d[2], ['6', '7', '8', '9'], true)) {
            return 'Sem o 9 na frente';
        }
        if (strlen($d) === 10) {
            return 'É telefone fixo';
        }
        if (strlen($d) !== 11 || $d[2] !== '9' || preg_match('/^(\d)\1+$/', $d) || (int) substr($d, 0, 2) < 11) {
            return 'Formato inválido';
        }
        // 99999-9999, 98888-8888, 91234-5678: cara de número inventado
        if (preg_match('/^(\d)\1{7}$/', substr($d, 3)) || in_array(substr($d, 3), ['12345678', '87654321'], true)) {
            return 'Número suspeito';
        }

        return null;
    }

    /** link aberto e válido do cliente, ou um novo (o anterior vencido ou cancelado fica no histórico) */
    public static function gerar(Cliente $cliente, bool $renovar = false): AtualizacaoCadastral
    {
        $aberta = AtualizacaoCadastral::where('cliente_id', $cliente->id)->where('situacao', 'aberta')->latest('id')->first();
        if ($aberta && ! $renovar && ! $aberta->expirada()) {
            return $aberta;
        }
        $aberta?->update(['situacao' => 'cancelada']);
        do {
            $token = Str::random(40);
        } while (AtualizacaoCadastral::withoutGlobalScopes()->where('token', $token)->exists());

        return AtualizacaoCadastral::create([
            'cliente_id' => $cliente->id, 'token' => $token, 'expira_em' => now()->addDays(self::VALIDADE_DIAS)->endOfDay(), 'criado_por' => Auth::id(),
        ]);
    }

    /** confere a placa digitada contra os veículos ativos do cliente; 5 erros bloqueiam por 30 minutos */
    public static function conferirPlaca(AtualizacaoCadastral $a, ?string $placa): bool
    {
        if ($a->bloqueado_ate?->isFuture()) {
            self::erro('placa', 'Muitas tentativas. Tente de novo daqui a '.max(1, (int) ceil(now()->diffInMinutes($a->bloqueado_ate))).' minuto(s).');
        }
        $p = Placa::normalizar($placa);
        $ok = $p && Veiculo::where('cliente_id', $a->cliente_id)->where('ativo', true)->where('placa', $p)->exists();
        if ($ok) {
            $a->update(['tentativas' => 0, 'bloqueado_ate' => null]);

            return true;
        }
        $t = $a->tentativas + 1;
        $a->update(['tentativas' => $t >= self::TENTATIVAS ? 0 : $t, 'bloqueado_ate' => $t >= self::TENTATIVAS ? now()->addMinutes(30) : null]);
        self::erro('placa', $t >= self::TENTATIVAS ? 'Placa não confere. Muitas tentativas: tente de novo daqui a 30 minutos.' : 'Essa placa não confere com o cadastro. Confira e tente de novo.');
    }

    /** dados como estão no cadastro (para a revisão "antes → depois") */
    public static function retrato(Cliente $c): array
    {
        return collect(self::CAMPOS)->mapWithKeys(fn ($k) => [$k => $c->{$k}])->all();
    }

    /** o cliente enviou: guarda para a revisão (nada muda no cadastro ainda) */
    public static function responder(AtualizacaoCadastral $a, array $dados, ?string $ip = null): void
    {
        if ($a->situacao !== 'aberta' || $a->expirada()) {
            self::erro('link', 'Este link não está mais aberto.');
        }
        $a->update(['dados' => $dados, 'antes' => self::retrato($a->cliente), 'situacao' => 'respondida', 'respondido_em' => now(),
            'consentimento_em' => now(), 'ip' => $ip]);
    }

    /** campos que mudaram em relação ao cadastro de agora */
    public static function mudancas(AtualizacaoCadastral $a): array
    {
        $atual = self::retrato($a->cliente);
        $m = [];
        foreach (self::CAMPOS as $k) {
            $novo = $a->dados[$k] ?? null;
            if ((string) ($atual[$k] ?? '') !== (string) ($novo ?? '')) {
                $m[$k] = ['antes' => $atual[$k], 'depois' => $novo];
            }
        }

        return $m;
    }

    /** aprova: aplica o que mudou e marca esses campos como do ERP (o espelho não sobrescreve) */
    public static function aprovar(AtualizacaoCadastral $a): array
    {
        if ($a->situacao !== 'respondida') {
            self::erro('situacao', 'Só dá para aprovar uma atualização respondida.');
        }

        return DB::transaction(function () use ($a) {
            $c = $a->cliente()->lockForUpdate()->first();
            $m = self::mudancas($a);
            if (isset($m['documento']['depois']) && Cliente::where('documento', $m['documento']['depois'])->where('id', '<>', $c->id)->exists()) {
                self::erro('documento', 'Esse CPF/CNPJ já está em outro cliente. Confira antes de aprovar.');
            }
            foreach ($m as $k => $v) {
                $c->{$k} = $v['depois'];
            }
            $c->campos_locais = array_values(array_unique(array_merge((array) ($c->campos_locais ?? []), array_keys($m))));
            $c->save();
            $a->update(['situacao' => 'aprovada', 'revisado_por' => Auth::id(), 'revisado_em' => now()]);
            Atividades::registrar('atualizacao_aprovada', 'cliente '.$c->id.' ('.$c->nome.'): '.($m ? implode(', ', array_keys($m)) : 'sem mudanças'));

            return $m;
        });
    }

    public static function recusar(AtualizacaoCadastral $a, string $motivo): void
    {
        if ($a->situacao !== 'respondida') {
            self::erro('situacao', 'Só dá para recusar uma atualização respondida.');
        }
        $a->update(['situacao' => 'recusada', 'motivo_recusa' => $motivo, 'revisado_por' => Auth::id(), 'revisado_em' => now()]);
        Atividades::registrar('atualizacao_recusada', 'cliente '.$a->cliente_id.': '.$motivo);
    }
}
