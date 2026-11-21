<?php

namespace App\Servicos\Pdv;

use App\Http\Controllers\EstadiaController;
use App\Models\Aparelho;
use App\Models\Caixa;
use App\Models\Concerns\EmpresaScope;
use App\Models\Convenio;
use App\Models\Estadia;
use App\Models\FormaPagamento;
use App\Models\MensalidadeContrato;
use App\Models\PdvAcao;
use App\Models\Usuario;
use App\Servicos\Estadias;
use App\Servicos\FormasPagamento;
use App\Servicos\Ordens;
use App\Servicos\Precos;
use App\Support\Atividades;
use App\Support\Formato;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Modo sem internet do PDV (parte 8.7, docs/15-plano-pdv.md seção 8i).
 *
 * - pacote(): a cópia que o celular guarda para trabalhar sem internet (tabelas, pátio, mensalistas, quem tem PIN).
 * - sincronizar(): as ações feitas sem internet sobem na ordem em que aconteceram, cada uma com um código único
 *   (uuid) que impede lançar duas vezes. Cada ação roda "na hora do celular" (entrada, saída e recebimento com
 *   a hora em que aconteceram). O que não fecha vira pendência para o gerente; a fila nunca trava.
 * - Vale o que o cliente pagou: se o cálculo daqui der diferente do celular, registra o que foi cobrado e
 *   aponta a diferença.
 */
class SemInternet
{
    public const TIPOS = ['estadia_entrada', 'estadia_saida'];

    /** mais antigo que isso não entra (celular esquecido sem internet por semanas: melhor resolver na mão) */
    public const DIAS_MAX = 7;

    /**
     * saída que chega mais de tantos minutos depois de feita é lançada (o cliente já pagou), mas vai para o gerente
     * conferir a hora e o valor: hora inventada ou relógio do celular atrasado baratearia a estadia (auditoria 21/11/2026)
     */
    public const SAIDA_CONFERIR_MIN = 60;

    /** tudo o que o celular precisa para trabalhar sem internet */
    public static function pacote(Aparelho $a): array
    {
        FormasPagamento::garantirPadrao();
        $unidade = Ordens::unidadePadrao();
        $tipos = collect(EstadiaController::tiposComTabela())->filter(fn ($t) => $t['tabela'])->values();
        $tabelas = [];
        foreach ($tipos as $t) {
            $tarifa = Precos::tarifa($t['id'], $unidade);
            if ($tarifa) {
                $tabelas[$t['id']] = ['id' => $tarifa->id, 'nome' => $tarifa->nome, 'regras' => $tarifa->regras()];
            }
        }
        $convenios = Convenio::with('cliente:id,nome', 'regras')->where('ativo', true)->get()
            ->filter(fn (Convenio $c) => $c->regra(null))
            ->map(fn (Convenio $c) => self::convenio($c))->sortBy('nome')->values();

        $patio = Estadia::with(['tipo:id,nome', 'cliente:id,nome', 'convenio.cliente:id,nome', 'convenio.regras', 'pacote:id,nome'])
            ->where('situacao', 'patio')->orderByDesc('entrada')->get()
            ->map(fn (Estadia $e) => EstadiaController::linha($e) + [
                'tipo_veiculo_id' => $e->tipo_veiculo_id,
                'identificacao' => $e->identificacao,
                'pacote_id' => $e->pacote_id,
                'valor_pacote' => $e->valor_pacote !== null ? (float) $e->valor_pacote : null,
                'numero_impresso' => $e->numero_impresso,
            ]);

        $mensalistas = MensalidadeContrato::with('veiculo.cliente:id,nome')->vigentes()->get()
            ->filter(fn ($m) => $m->veiculo?->placa)
            ->map(fn ($m) => ['placa' => $m->veiculo->placa, 'cliente' => $m->veiculo->cliente?->nome, 'cliente_id' => $m->veiculo->cliente_id,
                'tipo_id' => $m->veiculo->tipo_veiculo_id, 'modelo' => trim($m->veiculo->marca.' '.$m->veiculo->modelo) ?: null])
            ->unique('placa')->values();

        // quem tem PIN nesta unidade (ou em todas), com a dica do PIN (Pins::dica): o celular pega erro de digitação e mostra
        // o nome, sem receber nada que permita descobrir o PIN. Quem confere de verdade é o servidor, na sincronização.
        // Quem ainda não tem a versão sem internet ganha no próximo uso do PIN com internet.
        $usuarios = Usuario::withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $a->empresa_id)->where('ativo', true)
            ->whereNotNull('pin_hash')->when($a->unidade_id, fn ($q) => $q->where(fn ($w) => $w->whereNull('unidade_id')->orWhere('unidade_id', $a->unidade_id)))
            ->with('nivel')->get()
            ->map(fn (Usuario $u) => ['id' => $u->id, 'nome' => $u->nome, 'pin_desde' => $u->pin_definido_em ? Carbon::parse($u->pin_definido_em)->toIso8601String() : null,
                'gerente' => $u->pode('pdv_gerente'), 'nivel' => $u->nivel?->nome, 'dica' => Pins::dica($u)])->values();

        $caixa = Caixa::where('situacao', 'aberto')->orderBy('id')->first();

        return [
            'gerado_em' => now()->toIso8601String(),
            'opcoes' => [
                'tipos' => $tipos,
                'formas' => FormaPagamento::where('ativa', true)->orderBy('ordem')->get(['id', 'nome', 'tipo', 'parcelas_max']),
                'convenios' => $convenios->map(fn ($c) => collect($c)->only(['id', 'nome', 'resumo'])->all())->values(),
            ],
            'tabelas' => (object) $tabelas,
            'convenios' => $convenios,
            'patio' => $patio,
            'mensalistas' => $mensalistas,
            'ultimo_numero' => (int) (DB::table('numeracoes')->where('empresa_id', $a->empresa_id)->where('tipo', 'estadia')->value('ultimo')
                ?? Estadia::max('numero')),
            'usuarios' => $usuarios,
            'pin_sal' => Pins::salOffline($a->empresa_id),
            'pin_rodadas' => Pins::OFFLINE_RODADAS,
            'pin_chave' => Pins::chavePublica((int) $a->empresa_id),
            'caixa' => $caixa ? ['desde' => $caixa->aberto_em->format('H:i')] : null,
            'pendentes' => PdvAcao::where('aparelho_id', $a->id)->where('situacao', 'pendente')->count(),
        ];
    }

    /** convênio com a regra da estadia (para o celular dividir o valor igual ao servidor) */
    private static function convenio(Convenio $c): array
    {
        $r = $c->regra(null);

        return ['id' => $c->id, 'nome' => $c->cliente?->nome, 'resumo' => $c->resumo(),
            'regra' => $r ? ['modo' => $r->modo, 'limite_valor' => $r->limite_valor !== null ? (float) $r->limite_valor : null, 'limite_min' => $r->limite_min !== null ? (int) $r->limite_min : null] : null];
    }

    /**
     * Sobe as ações da fila, na ordem. Devolve, para cada uuid, ok ou pendente (com a mensagem). Ação já recebida
     * (mesmo uuid) responde de novo o mesmo resultado, sem refazer.
     *
     * @param  array<int, array{uuid: string, tipo: string, feita_em: string, usuario_id: ?int, prova: ?string, dados: array}>  $acoes
     */
    public static function sincronizar(Aparelho $a, array $acoes): array
    {
        $saida = [];
        foreach ($acoes as $x) {
            $uuid = mb_strtolower((string) ($x['uuid'] ?? ''));
            if (! Str::isUuid($uuid)) {
                continue;
            }
            $ja = PdvAcao::where('uuid', $uuid)->first();
            if ($ja) {
                $saida[] = self::resposta($ja);

                continue;
            }
            $saida[] = self::resposta(self::executar($a, $uuid, $x));
        }

        return $saida;
    }

    private static function resposta(PdvAcao $p): array
    {
        return ['uuid' => $p->uuid, 'situacao' => $p->situacao === 'ok' ? 'ok' : 'pendente', 'mensagem' => $p->mensagem];
    }

    private static function executar(Aparelho $a, string $uuid, array $x): PdvAcao
    {
        $tipo = (string) ($x['tipo'] ?? '');
        $dados = is_array($x['dados'] ?? null) ? $x['dados'] : [];
        $quando = self::quando($x['feita_em'] ?? null);
        // quem fez sai do PIN que o celular trancou com a chave do servidor, nunca do usuario_id que o celular informa
        $u = Pins::abrirProva((int) $a->empresa_id, isset($x['prova']) ? (string) $x['prova'] : null, $uuid);
        $acao = new PdvAcao(['aparelho_id' => $a->id, 'uuid' => $uuid, 'tipo' => mb_substr($tipo, 0, 30), 'dados' => $dados,
            'usuario_id' => $u instanceof Usuario ? $u->id : null, 'feita_em' => $quando, 'sem_internet' => true]);

        $problema = match (true) {
            ! in_array($tipo, self::TIPOS, true) => 'Ação desconhecida ('.$tipo.'). Atualize o app do PDV.',
            ! $quando => 'Hora da ação inválida.',
            $quando->isAfter(now()->addMinutes(10)) => 'A hora do celular estava no futuro ('.$quando->format('d/m H:i').'). Confira a data e hora do celular.',
            $quando->lt(now()->subDays(self::DIAS_MAX)) => 'Ação feita há mais de '.self::DIAS_MAX.' dias ('.$quando->format('d/m H:i').'): confira e lance na gestão.',
            is_string($u) => $u,
            default => null,
        };
        if ($problema) {
            return self::pendente($acao, $problema);
        }

        Auth::setUser($u);
        // tudo roda na hora em que aconteceu no celular (entrada, saída, recebimento, registro de atividades)
        $agoraReal = now();
        $relogio = Carbon::getTestNow(); // nos testes já há um relógio fixo: volta para ele
        Carbon::setTestNow($quando);
        try {
            DB::transaction(function () use ($acao, $tipo, $dados) {
                $tipo === 'estadia_entrada' ? self::entrada($acao, $dados) : self::saida($acao, $dados);
                $acao->save();
            });
        } catch (ValidationException $e) {
            Carbon::setTestNow($relogio);

            return self::pendente($acao, collect($e->errors())->flatten()->first() ?: 'Não deu certo.');
        } catch (\Throwable $e) {
            Carbon::setTestNow($relogio);
            report($e);

            return self::pendente($acao, 'Erro ao lançar: '.mb_substr($e->getMessage(), 0, 200));
        } finally {
            Carbon::setTestNow($relogio);
        }
        $acao->forceFill(['criado_em' => $agoraReal, 'atualizado_em' => $agoraReal])->saveQuietly();
        $atraso = (int) $quando->diffInMinutes($agoraReal);
        if ($tipo === 'estadia_saida' && $atraso > self::SAIDA_CONFERIR_MIN) {
            $aviso = 'Saída feita no celular às '.$quando->format('d/m H:i').' e enviada só às '.$agoraReal->format('d/m H:i')
                .' por '.$u->nome.': já está lançada; confira se a hora e o valor batem.';
            self::pendente($acao, $acao->situacao === 'pendente' && $acao->mensagem ? $acao->mensagem.' '.$aviso : $aviso);
        }

        return $acao;
    }

    private static function pendente(PdvAcao $acao, string $mensagem): PdvAcao
    {
        $acao->forceFill(['situacao' => 'pendente', 'mensagem' => mb_substr($mensagem, 0, 500), 'estadia_id' => $acao->estadia_id])->save();
        Atividades::registrar('pdv_pendencia', (PdvAcao::TIPOS[$acao->tipo] ?? $acao->tipo).' feita sem internet ficou pendente: '.$mensagem);

        return $acao;
    }

    private static function quando(?string $q): ?Carbon
    {
        try {
            return $q ? Carbon::parse($q)->setTimezone(config('app.timezone'))->seconds(0) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function entrada(PdvAcao $acao, array $d): void
    {
        $e = Estadias::entrar([
            'placa' => $d['placa'] ?? null, 'identificacao' => $d['identificacao'] ?? null, 'modelo' => $d['modelo'] ?? null,
            'tipo_veiculo_id' => $d['tipo_veiculo_id'] ?? null, 'pacote_id' => $d['pacote_id'] ?? null, 'pagar_agora' => ! empty($d['pagar_agora']),
            'forma_pagamento_id' => $d['forma_pagamento_id'] ?? null, 'valor_recebido' => $d['valor_recebido'] ?? null,
            // o celular já avisou que era mensalista e o operador confirmou
            'confirmar_mensalista' => true,
            'entrada' => now()->format('Y-m-d H:i'), 'uuid' => $acao->uuid, 'codigo' => $d['codigo'] ?? null, 'numero_impresso' => $d['numero'] ?? null,
        ]);
        $acao->estadia_id = $e->id;
        if (! empty($d['codigo']) && $e->codigo !== mb_strtolower((string) $d['codigo'])) {
            // não deveria acontecer (código repetido): o QR impresso não abre esta estadia
            $acao->situacao = 'pendente';
            $acao->mensagem = 'O código do QR impresso já existia: o ticket '.$e->numero.' ('.$e->rotulo().') ficou com outro código. Ache pela placa ou pelo número.';
        }
    }

    private static function saida(PdvAcao $acao, array $d): void
    {
        $codigo = mb_strtolower((string) ($d['codigo'] ?? ''));
        $e = $codigo ? Estadia::where('codigo', $codigo)->first() : null;
        if (! $e) {
            throw ValidationException::withMessages(['codigo' => 'Ticket não encontrado (a entrada não subiu ou foi feita em outro aparelho sem internet). Saída de '
                .($d['rotulo'] ?? 'veículo').' às '.now()->format('d/m H:i').self::cobrado($d).'.']);
        }
        $acao->estadia_id = $e->id;
        if ($e->situacao !== 'patio') {
            throw ValidationException::withMessages(['saida' => 'Saída feita no celular às '.now()->format('d/m H:i').self::cobrado($d).', mas o ticket '.$e->numero.' ('.$e->rotulo().') já estava '
                .($e->situacao === 'cancelada' ? 'cancelado' : 'finalizado'.($e->saida ? ' ('.$e->saida->format('d/m H:i').')' : '')).' na gestão.']);
        }
        $c = Estadias::calcular($e, now());
        $destino = $d['destino'] ?? 'pago';
        $cobrado = isset($d['cobrado']) ? round((float) $d['cobrado'], 2) : null;
        $dados = [
            'destino' => $destino, 'saida' => now()->format('Y-m-d H:i'),
            'convenio_id' => $d['convenio_id'] ?? null, 'conta_cliente_id' => $d['conta_cliente_id'] ?? null,
            'forma_pagamento_id' => $d['forma_pagamento_id'] ?? null, 'valor_recebido' => $d['valor_recebido'] ?? null,
            'perdido' => ! empty($d['perdido']), 'retirada_nome' => $d['retirada_nome'] ?? null, 'retirada_documento' => $d['retirada_documento'] ?? null,
        ];
        if ($cobrado !== null && $destino !== 'mensalista') {
            $dados['cobrado_no_celular'] = $cobrado;
        }
        Estadias::sair($e, $dados);
        if ($cobrado !== null && $destino !== 'mensalista' && abs($cobrado - $c['a_pagar']) > 0.009) {
            $acao->situacao = 'pendente';
            $acao->mensagem = 'Diferença de cálculo no ticket '.$e->numero.' ('.$e->rotulo().'): o celular cobrou '.Formato::moeda($cobrado)
                .' e a gestão calcula '.Formato::moeda($c['a_pagar']).'. Ficou lançado o que foi cobrado.';
        }
    }

    private static function cobrado(array $d): string
    {
        return isset($d['cobrado']) && (float) $d['cobrado'] > 0 ? ', cobrado '.Formato::moeda($d['cobrado']).(($d['forma_nome'] ?? null) ? ' no '.$d['forma_nome'] : '') : '';
    }

    /** registrada na hora (com internet), com o código da ação: se o celular mandar de novo pela fila, não duplica */
    public static function registrarNaHora(?string $uuid, string $tipo, ?Estadia $e = null): void
    {
        if (! $uuid || ! Str::isUuid($uuid) || PdvAcao::where('uuid', mb_strtolower($uuid))->exists()) {
            return;
        }
        PdvAcao::create(['aparelho_id' => request()?->attributes->get('aparelho')?->id, 'uuid' => mb_strtolower($uuid), 'tipo' => $tipo,
            'usuario_id' => Auth::id(), 'feita_em' => now(), 'estadia_id' => $e?->id]);
    }

    /** a pendência foi resolvida na gestão (com o que foi feito) */
    public static function resolver(PdvAcao $p, string $observacao): void
    {
        $p->update(['situacao' => 'resolvida', 'observacao' => mb_substr($observacao, 0, 300), 'resolvida_por' => Auth::id(), 'resolvida_em' => now()]);
        Atividades::registrar('pdv_pendencia_resolvida', (PdvAcao::TIPOS[$p->tipo] ?? $p->tipo).' sem internet: '.$observacao);
    }
}
