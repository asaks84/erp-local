<?php

namespace App\Support;

use App\Models\LogAtividade;
use App\Models\Usuario;
use Illuminate\Support\Facades\Auth;

/** Registro de atividades: quem fez o quê (acessos, permissões, cadastros e dinheiro). */
class Atividades
{
    public static function registrar(string $acao, ?string $detalhe = null, ?Usuario $usuario = null): void
    {
        $usuario ??= Auth::user();
        $empresa = $usuario?->empresa_id ?? app(EmpresaAtual::class)->id();
        if ($empresa === null) {
            return; // sem empresa não há onde registrar (ex.: login com usuário inexistente)
        }

        $log = new LogAtividade([
            'usuario_id' => $usuario?->id,
            'usuario_nome' => $usuario?->nome,
            'acao' => mb_substr($acao, 0, 40),
            'detalhe' => $detalhe === null ? null : mb_substr($detalhe, 0, 500),
            'ip' => request()?->ip(),
        ]);
        $log->empresa_id = $empresa;
        $log->save();
    }
}
