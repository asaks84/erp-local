<?php

namespace App\Servicos\WhatsApp;

use App\Models\Cobranca;
use App\Models\Empresa;
use App\Models\Integracao;
use App\Models\ModeloMensagem;
use App\Servicos\Inter\Boletos;
use App\Servicos\Pontualidade;
use App\Support\Formato;
use App\Support\Placa;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Mensagens do WhatsApp (modelos da Meta), editáveis em Configurações › WhatsApp › Mensagens.
 * O texto usa informações entre chaves ({nome}, {valor}...); para a Meta, viram {{1}}, {{2}}... na ordem
 * em que aparecem. Cada mensagem tem versões (mesmo grupo): o rascunho fica só no ERP e já vale no envio
 * manual (wa.me); pela API vale a última versão aprovada pela Meta.
 */
class Modelos
{
    public const IDIOMA = 'pt_BR';

    /** informação => [rótulo, exemplo] (o exemplo vai para a Meta e para a prévia) */
    public const VARIAVEIS = [
        'nome' => ['Primeiro nome do cliente', 'Maria'],
        'empresa' => ['Nome da empresa', ''],
        'valor' => ['Valor da cobrança', 'R$ 250,00'],
        'vencimento' => ['Vencimento', '10/12/2026'],
        'valor_com_desconto' => ['Valor com desconto', 'R$ 230,00'],
        'desconto_ate' => ['Data limite do desconto', '10/12/2026'],
        'placa' => ['Placa do veículo', 'ABC1D23'],
    ];

    /** informações que valem em propaganda (as da cobrança não fazem sentido lá) */
    public const VARIAVEIS_PROPAGANDA = ['nome', 'empresa'];

    public const FINALIDADES = ['cobranca' => 'Aviso de cobrança', 'propaganda' => 'Propaganda'];

    public const BOTAO = 'Ver cobrança';

    public const MAX_TEXTO = 1024;

    /** as três mensagens que vêm prontas (os nomes são os já usados na Meta) */
    public const PADRAO = [
        'cobranca_lembrete' => ['Lembrete (antes do vencimento)', 'Olá, {nome}! Aqui é do {empresa}. Sua cobrança de {valor} vence em {vencimento}. Para ver os detalhes e as formas de pagamento, toque no botão abaixo.'],
        'cobranca_vence_hoje' => ['Vence hoje', 'Olá, {nome}! Aqui é do {empresa}. Sua cobrança de {valor} vence hoje, {vencimento}. Para ver os detalhes e as formas de pagamento, toque no botão abaixo.'],
        'cobranca_em_atraso' => ['Em atraso', 'Olá, {nome}! Aqui é do {empresa}. Sua cobrança de {valor} venceu em {vencimento} e ainda está em aberto. Para ver os detalhes e pagar, toque no botão abaixo. Se já pagou, desconsidere este aviso.'],
    ];

    /** motivos de recusa da Meta, em português */
    public const MOTIVOS = [
        'INVALID_FORMAT' => 'Formato inválido: informação variável no começo ou no fim, duas seguidas, ou texto curto demais para a quantidade de informações.',
        'PROMOTIONAL' => 'A Meta achou o texto promocional demais para um aviso. Tire chamadas de venda e promoção.',
        'INCORRECT_CATEGORY' => 'A Meta achou que a categoria não combina com o texto (aviso × propaganda).',
        'TAG_CONTENT_MISMATCH' => 'O conteúdo não combina com a categoria escolhida.',
        'ABUSIVE_CONTENT' => 'A Meta considerou o conteúdo abusivo.',
        'SCAM' => 'A Meta considerou o texto parecido com golpe.',
    ];

    /* ------------------------------------------------------------------ mensagens e versões */

    /** cria as três mensagens prontas na primeira vez */
    public static function garantirPadrao(): void
    {
        if (ModeloMensagem::exists()) {
            return;
        }
        // com o WhatsApp configurado, elas podem já existir na Meta: a situação vem na próxima consulta
        $situacao = WhatsAppCliente::completa(Integracao::whatsapp()) ? 'verificar' : 'rascunho';
        foreach (self::PADRAO as $nome => [$rotulo, $texto]) {
            $m = ModeloMensagem::create(['rotulo' => $rotulo, 'nome' => $nome, 'texto' => $texto, 'situacao' => $situacao]);
            $m->update(['grupo' => $m->id]);
        }
    }

    /** todas as versões, agrupadas por mensagem (a mais nova primeiro em cada grupo) */
    public static function grupos(?string $finalidade = null): Collection
    {
        self::garantirPadrao();

        return ModeloMensagem::orderBy('grupo')->orderByDesc('versao')->get()
            ->groupBy('grupo')
            ->filter(fn ($v) => ! $finalidade || $v->first()->finalidade === $finalidade);
    }

    public static function versoes(int $grupo): Collection
    {
        return ModeloMensagem::where('grupo', $grupo)->orderByDesc('versao')->get();
    }

    /** a versão mais nova (rascunho incluído): é a do envio manual */
    public static function ultima(int $grupo): ?ModeloMensagem
    {
        return ModeloMensagem::where('grupo', $grupo)->orderByDesc('versao')->first();
    }

    /**
     * A versão que sai pela API: a aprovada mais nova; se nenhuma foi aprovada ainda, a que não se sabe a
     * situação (a Meta decide). [versão, motivo de não ter].
     */
    public static function paraApi(int $grupo): array
    {
        $v = self::versoes($grupo);
        $m = $v->firstWhere('situacao', 'APPROVED') ?? $v->firstWhere('situacao', 'verificar');
        if ($m) {
            return [$m, null];
        }
        $rotulo = $v->first()?->rotulo ?? 'da régua';
        $motivo = match (true) {
            $v->isEmpty() => 'A mensagem desse passo da régua não existe mais.',
            $v->contains('situacao', 'PENDING') => "A mensagem \"{$rotulo}\" ainda está em análise na Meta.",
            $v->contains('situacao', 'REJECTED') => "A mensagem \"{$rotulo}\" foi recusada pela Meta: corrija em Configurações › WhatsApp › Mensagens.",
            default => "A mensagem \"{$rotulo}\" ainda não foi enviada para aprovação da Meta.",
        };

        return [null, $motivo];
    }

    /** mensagem (grupo) usada num passo da régua: a escolhida no passo, ou a padrão pelo momento */
    public static function doPasso(int $dias, ?array $passos = null): ?int
    {
        self::garantirPadrao();
        $passos ??= Regua::passos();
        $escolhido = collect($passos)->first(fn ($p) => (int) $p['dias'] === $dias && ! empty($p['grupo']));
        if ($escolhido && ModeloMensagem::where('grupo', $escolhido['grupo'])->exists()) {
            return (int) $escolhido['grupo'];
        }
        $nome = $dias < 0 ? 'cobranca_lembrete' : ($dias === 0 ? 'cobranca_vence_hoje' : 'cobranca_em_atraso');

        return ModeloMensagem::where('nome', $nome)->value('grupo')
            ?? ModeloMensagem::where('finalidade', 'cobranca')->orderBy('grupo')->value('grupo');
    }

    /** nome na Meta para uma mensagem nova (minúsculas, números e _; único na empresa) */
    public static function nomeNovo(string $rotulo, string $finalidade): string
    {
        $base = Str::limit(Str::slug(Str::ascii($rotulo), '_'), 60, '') ?: 'mensagem';
        if (! preg_match('/^[a-z]/', $base)) {
            $base = ($finalidade === 'propaganda' ? 'propaganda_' : 'aviso_').$base;
        }
        $nome = $base;
        for ($n = 2; ModeloMensagem::where('nome', $nome)->exists(); $n++) {
            $nome = $base.'_'.$n;
        }

        return $nome;
    }

    /** nome da versão seguinte de um grupo: base_v2, base_v3... */
    public static function nomeVersao(ModeloMensagem $primeira, int $versao): string
    {
        $base = preg_replace('/_v\d+$/', '', $primeira->nome);
        $nome = $base.'_v'.$versao;
        for ($n = 2; ModeloMensagem::where('nome', $nome)->exists(); $n++) {
            $nome = $base.'_v'.$versao.'_'.$n;
        }

        return $nome;
    }

    /* ------------------------------------------------------------------ texto */

    /** informações usadas no texto, na ordem (cada ocorrência vira um {{n}}) */
    public static function variaveis(string $texto): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $texto, $m);

        return $m[1];
    }

    /** texto no formato da Meta ({{1}}, {{2}}...) */
    public static function paraMeta(string $texto): string
    {
        $n = 0;

        return preg_replace_callback('/\{([a-z_]+)\}/', function () use (&$n) {
            return '{{'.(++$n).'}}';
        }, $texto);
    }

    /** problemas que fariam a Meta recusar (ou que o ERP não sabe preencher). Vazio = ok. */
    public static function problemas(string $texto, string $finalidade = 'cobranca'): array
    {
        $t = trim($texto);
        $p = [];
        if ($t === '') {
            return ['Escreva o texto da mensagem.'];
        }
        if (mb_strlen($t) > self::MAX_TEXTO) {
            $p[] = 'Texto longo demais: até '.self::MAX_TEXTO.' caracteres.';
        }
        $permitidas = $finalidade === 'propaganda' ? self::VARIAVEIS_PROPAGANDA : array_keys(self::VARIAVEIS);
        foreach (array_unique(self::variaveis($t)) as $v) {
            if (! isset(self::VARIAVEIS[$v])) {
                $p[] = "Informação desconhecida: {{$v}}. Use os botões acima do texto.";
            } elseif (! in_array($v, $permitidas, true)) {
                $p[] = "{{$v}} só vale em aviso de cobrança.";
            }
        }
        if (preg_match('/[{}]/', preg_replace('/\{[a-z_]+\}/', '', $t))) {
            $p[] = 'Chave { ou } sobrando no texto.';
        }
        if (preg_match('/^\{[a-z_]+\}/', $t)) {
            $p[] = 'A Meta não aceita começar com uma informação variável. Comece com uma palavra (ex.: "Olá, {nome}").';
        }
        if (preg_match('/\{[a-z_]+\}$/', $t)) {
            $p[] = 'A Meta não aceita terminar com uma informação variável. Termine com uma palavra ou um ponto.';
        }
        if (preg_match('/\}\s*\{/', $t)) {
            $p[] = 'Duas informações variáveis seguidas: coloque pelo menos uma palavra entre elas.';
        }
        $n = count(self::variaveis($t));
        $palavras = str_word_count(Str::ascii(preg_replace('/\{[a-z_]+\}/', ' ', $t)));
        if ($n > 0 && $palavras < 2 * $n) {
            $p[] = 'Pouco texto para tantas informações variáveis: a Meta costuma recusar. Escreva mais ou use menos informações.';
        }
        if (str_contains($t, "\t") || preg_match('/ {4,}/', $t)) {
            $p[] = 'Tire tabulações e sequências de espaços.';
        }
        if (preg_match('/\n{3,}/', $t)) {
            $p[] = 'No máximo uma linha em branco seguida.';
        }

        return $p;
    }

    /** exemplos de cada informação (prévia e exemplo obrigatório da Meta) */
    public static function exemplos(?Empresa $e = null): array
    {
        return collect(self::VARIAVEIS)->map(fn ($v, $k) => $k === 'empresa' ? (($e ?? Empresa::atual())->nome) : $v[1])->all();
    }

    /** valores reais das informações para uma cobrança */
    public static function valores(Cobranca $c, Carbon $vencimento): array
    {
        $c->loadMissing('cliente');
        $cli = $c->cliente;
        $valor = round(Boletos::valorEmAberto($c) - (float) $c->desconto, 2);
        $pont = Pontualidade::resumo($c);
        $placas = $cli ? $cli->veiculos()->where('ativo', true)->orderBy('id')->get()
            ->map(fn ($v) => $v->placa ?: Placa::identificacao($v->identificacao))->filter()->unique()->take(3)->implode(', ') : '';

        return [
            'nome' => mb_convert_case(strtok(trim((string) $cli?->nome), ' ') ?: 'cliente', MB_CASE_TITLE),
            'empresa' => Empresa::atual()->nome,
            'valor' => Formato::moeda($valor),
            'vencimento' => $vencimento->format('d/m/Y'),
            // sem desconto de pontualidade, vale o valor cheio até o vencimento (a Meta não aceita informação vazia)
            'valor_com_desconto' => Formato::moeda($pont ? $pont['a_pagar'] : $valor),
            'desconto_ate' => $pont ? Carbon::parse($pont['ate'])->format('d/m/Y') : $vencimento->format('d/m/Y'),
            'placa' => $placas ?: 'seu veículo',
        ];
    }

    /** texto pronto (envio manual e o que fica guardado no aviso). O link entra no lugar do botão. */
    public static function texto(ModeloMensagem $m, array $valores, ?string $link = null): string
    {
        $t = preg_replace_callback('/\{([a-z_]+)\}/', fn ($x) => (string) ($valores[$x[1]] ?? $x[0]), $m->texto);
        if ($link && $m->botao && $m->finalidade === 'cobranca') {
            $trocado = preg_replace_callback('/(t)oque no botão abaixo/iu', fn ($x) => ($x[1] === 'T' ? 'Acesse: ' : 'acesse: ').$link, $t, 1, $n);
            $t = $n ? $trocado : rtrim($t)."\n\n".self::BOTAO.': '.$link;
        }

        return $t;
    }

    /** valores de {{1}}, {{2}}... na ordem do texto */
    public static function parametros(ModeloMensagem $m, array $valores): array
    {
        return array_map(fn ($v) => (string) ($valores[$v] ?? '-'), self::variaveis($m->texto));
    }

    /** definição do modelo para criar na Meta */
    public static function definicao(ModeloMensagem $m, string $baseLink): array
    {
        $ex = self::exemplos();
        $corpo = ['type' => 'BODY', 'text' => self::paraMeta(trim($m->texto))];
        if ($vars = self::variaveis($m->texto)) {
            $corpo['example'] = ['body_text' => [array_map(fn ($v) => (string) ($ex[$v] ?? $v), $vars)]];
        }
        $comp = [$corpo];
        if ($m->botao && $m->finalidade === 'cobranca') {
            $base = rtrim($baseLink, '/');
            $comp[] = ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => self::BOTAO, 'url' => $base.'/c/{{1}}', 'example' => [$base.'/c/exemplo']]]];
        }

        return ['name' => $m->nome, 'language' => self::IDIOMA, 'category' => $m->categoria, 'components' => $comp];
    }

    /* ------------------------------------------------------------------ Meta */

    public static function motivo(?string $codigo): ?string
    {
        if (! $codigo || $codigo === 'NONE') {
            return null;
        }

        return self::MOTIVOS[$codigo] ?? $codigo;
    }

    /** traz da Meta a situação de cada versão enviada. Devolve quantas mudaram. */
    public static function sincronizar(WhatsAppCliente $api): int
    {
        self::garantirPadrao();
        $na = collect($api->modelosDetalhes())->keyBy('name');
        $n = 0;
        foreach (ModeloMensagem::where('situacao', '<>', 'rascunho')->get() as $m) {
            $x = $na->get($m->nome);
            $novo = $x
                ? ['situacao' => (string) $x['status'], 'categoria' => (string) ($x['category'] ?? $m->categoria), 'meta_id' => (string) ($x['id'] ?? $m->meta_id),
                    'motivo' => $x['status'] === 'REJECTED' ? mb_substr((string) self::motivo($x['rejected_reason'] ?? null), 0, 255) ?: 'Recusado pela Meta.' : null]
                : ($m->situacao === 'verificar' ? ['situacao' => 'rascunho'] : ['situacao' => 'DISABLED', 'motivo' => 'Não existe mais na Meta (foi apagado lá).']);
            $m->fill($novo);
            if ($m->isDirty()) {
                $m->save();
                $n++;
            }
        }

        return $n;
    }

    /** aviso da Meta (webhook) de que a situação de um modelo mudou */
    public static function atualizarSituacao(string $nome, string $evento, ?string $motivo = null): void
    {
        $m = ModeloMensagem::where('nome', $nome)->first();
        if (! $m || $m->rascunho()) {
            return;
        }
        $mapa = ['APPROVED' => 'APPROVED', 'REJECTED' => 'REJECTED', 'PENDING' => 'PENDING', 'PAUSED' => 'PAUSED', 'DISABLED' => 'DISABLED', 'FLAGGED' => 'PAUSED', 'REINSTATED' => 'APPROVED', 'PENDING_DELETION' => 'DISABLED'];
        if (! isset($mapa[$evento])) {
            return;
        }
        $m->update(['situacao' => $mapa[$evento], 'motivo' => $mapa[$evento] === 'REJECTED' ? (mb_substr((string) self::motivo($motivo), 0, 255) ?: 'Recusado pela Meta.') : null]);
    }
}
