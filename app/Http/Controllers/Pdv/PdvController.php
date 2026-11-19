<?php

namespace App\Http\Controllers\Pdv;

use App\Http\Controllers\Controller;
use App\Models\Aparelho;
use App\Models\Empresa;
use App\Models\Unidade;
use App\Models\Usuario;
use App\Servicos\Pdv\Aparelhos;
use App\Servicos\Pdv\ImpressaoModelos;
use App\Servicos\Pdv\Pins;
use App\Servicos\Pdv\PixPdv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/** API do PDV, base (etapa 8.1): pareamento, dados do aparelho e PIN. */
class PdvController extends Controller
{
    /** aparelho da requisição (definido pelo middleware "aparelho") */
    public static function aparelho(Request $r): Aparelho
    {
        return $r->attributes->get('aparelho');
    }

    /** confere o PIN da requisição e põe a pessoa como autora da ação (registro de atividades, criado_por...) */
    public static function pessoa(Request $r): Usuario
    {
        $u = Pins::conferir(self::aparelho($r), $r->input('pin', $r->header('X-Pin')));
        Auth::setUser($u);

        return $u;
    }

    /** PIN de gerente ou administrador (permissão "Gerente no PDV"): caixa, retiradas, cancelamentos */
    public static function gerente(Request $r, string $acao = 'Isso'): Usuario
    {
        $u = self::pessoa($r);
        if (! $u->pode('pdv_gerente')) {
            throw ValidationException::withMessages(['pin' => $acao.' pede o PIN de um gerente ou administrador.']);
        }

        return $u;
    }

    public function parear(Request $r): JsonResponse
    {
        $d = $r->validate(['codigo' => ['required', 'string', 'max:12'], 'versao' => ['nullable', 'string', 'max:20']]);
        [$a, $token] = Aparelhos::parear($d['codigo'], $d['versao'] ?? null);

        return response()->json(['token' => $token, 'aparelho' => ['id' => $a->id, 'nome' => $a->nome]]);
    }

    public function eu(Request $r): JsonResponse
    {
        $a = self::aparelho($r)->load('unidade');
        $e = Empresa::atual();
        $u = $a->unidade ?? Unidade::orderBy('id')->first();

        return response()->json([
            'aparelho' => ['id' => $a->id, 'nome' => $a->nome, 'unidade' => $a->unidade?->nome],
            // dados do cabeçalho do ticket
            'empresa' => ['nome' => $e->nome, 'documento' => $e->documento, 'telefone' => $u?->telefone ?: $e->telefone, 'endereco' => $u?->endereco],
            'pin_memoria_min' => (int) ($e->config('pdv_pin_memoria_min') ?? 0),
            'servidor_hora' => now()->toIso8601String(),
            // Pix com QR do Inter no lugar do "Recebi no Pix" (8.5)
            'pix_qr' => PixPdv::ligado(),
            // modelos de impressão editados no ERP (8.8); papel sem modelo sai no padrão do app
            'impressao' => ImpressaoModelos::config(),
        ]);
    }

    public function conferirPin(Request $r): JsonResponse
    {
        $r->validate(['pin' => ['required', 'string', 'max:8']]);
        $u = self::pessoa($r);

        // gerente e pin_desde: o celular guarda (só o hash do PIN, feito lá) para conferir sem internet (8.7)
        return response()->json(['usuario' => ['id' => $u->id, 'nome' => $u->nome, 'nivel' => $u->nivel?->nome,
            'gerente' => $u->pode('pdv_gerente'), 'pin_desde' => $u->pin_definido_em ? Carbon::parse($u->pin_definido_em)->toIso8601String() : null]]);
    }

    public function cadastrarPin(Request $r): JsonResponse
    {
        // com login e senha, ou (usuário só do PDV, sem senha) com o código de cadastro gerado na gestão
        $d = $r->validate([
            'codigo' => ['nullable', 'string', 'max:12'],
            'login' => ['required_without:codigo', 'nullable', 'string', 'max:40'],
            'senha' => ['required_without:codigo', 'nullable', 'string', 'max:200'],
            'pin' => ['required', 'string', 'max:8'],
        ], ['login.required_without' => 'Informe o login.', 'senha.required_without' => 'Informe a senha.', 'pin.required' => 'Escolha o PIN.']);
        $empresa = self::aparelho($r)->empresa_id;
        $u = ! empty($d['codigo'])
            ? Pins::cadastrarComCodigo($empresa, $d['codigo'], $d['pin'])
            : Pins::cadastrar($empresa, $d['login'], $d['senha'], $d['pin']);

        return response()->json(['ok' => true, 'mensagem' => 'PIN de '.$u->nome.' cadastrado.']);
    }

    /**
     * Hora do cálculo que a tela mostrou, para cobrar o mesmo valor no registro (o cliente pode levar uns
     * minutos no Pix). Só vale até 20 minutos atrás; fora disso, null (vale a hora de agora).
     */
    public static function momento(?string $quando): ?Carbon
    {
        if (! $quando) {
            return null;
        }
        try {
            $m = Carbon::parse($quando);
        } catch (\Throwable) {
            return null;
        }

        return $m->between(now()->subMinutes(20), now()->addMinute()) ? $m : null;
    }
}
