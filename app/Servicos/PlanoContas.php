<?php

namespace App\Servicos;

use App\Models\PlanoConta;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Plano de contas: árvore única de receitas (1) e despesas (2). Sai com um modelo pronto,
 * editável. Contas com "chave" são usadas pelo sistema (receitas das ordens, folha) e não se apagam.
 * O código (2.3.1) é montado pela posição na árvore.
 */
class PlanoContas
{
    /** [nome, chave, filhas] */
    private const MODELO = [
        'receita' => ['Receitas', 'raiz_receita', [
            ['Mensalidades', 'receita_mensalidade', []],
            ['Estadias', 'receita_estadia', []],
            ['Lavagens e serviços', 'receita_servico', []],
            ['Outras receitas', 'receita_outras', []],
        ]],
        'despesa' => ['Despesas', 'raiz_despesa', [
            ['Pessoal', 'despesa_pessoal', [
                ['Salários', 'despesa_salarios', []],
                ['Diárias e avulsos', 'despesa_avulsos', []],
                ['FGTS', 'despesa_fgts', []],
                ['INSS e encargos', 'despesa_inss', []],
                ['Benefícios (transporte, alimentação)', null, []],
            ]],
            ['Ocupação', null, [['Aluguel', null, []], ['Condomínio', null, []], ['IPTU', null, []]]],
            ['Utilidades', null, [['Energia', null, []], ['Água', null, []], ['Internet e telefone', null, []]]],
            ['Lavagem', null, [['Produtos de lavagem', null, []], ['Equipamentos e manutenção', null, []]]],
            ['Administrativas', null, [['Contador', null, []], ['Sistemas e assinaturas', null, []], ['Tarifas bancárias', null, []], ['Material de escritório', null, []]]],
            ['Impostos e taxas', null, [['Simples Nacional (DAS)', 'despesa_simples', []], ['Taxas e licenças', null, []]]],
            ['Manutenção do pátio', null, []],
            ['Outras despesas', 'despesa_outras', []],
            ['Investimentos e equipamentos', 'despesa_investimentos', []],
            ['Cartão de crédito (a classificar)', 'despesa_cartao', []],
        ]],
    ];

    /** contas do sistema que entraram depois do modelo inicial: criadas no fim das despesas */
    private const NOVAS = [['Investimentos e equipamentos', 'despesa_investimentos'], ['Cartão de crédito (a classificar)', 'despesa_cartao']];

    public static function garantirPadrao(): void
    {
        if (PlanoConta::exists()) {
            self::garantirNovas();

            return;
        }
        DB::transaction(function () {
            $raiz = 0;
            foreach (self::MODELO as $tipo => [$nome, $chave, $filhas]) {
                self::criar($tipo, null, $nome, $chave, $filhas, $raiz++);
            }
            self::renumerar();
        });
    }

    private static function garantirNovas(): void
    {
        if (PlanoConta::whereIn('chave', array_column(self::NOVAS, 1))->count() === count(self::NOVAS)) {
            return;
        }
        $faltam = array_filter(self::NOVAS, fn ($n) => ! PlanoConta::where('chave', $n[1])->exists());
        $raiz = $faltam ? PlanoConta::where('chave', 'raiz_despesa')->first() : null;
        if ($raiz) {
            DB::transaction(function () use ($faltam, $raiz) {
                $ordem = (int) PlanoConta::where('pai_id', $raiz->id)->max('ordem');
                foreach ($faltam as [$nome, $chave]) {
                    PlanoConta::create(['tipo' => 'despesa', 'pai_id' => $raiz->id, 'nome' => $nome, 'chave' => $chave, 'ordem' => ++$ordem, 'codigo' => '']);
                }
                self::renumerar();
            });
        }
    }

    private static function criar(string $tipo, ?int $pai, string $nome, ?string $chave, array $filhas, int $ordem): void
    {
        $c = PlanoConta::create(['tipo' => $tipo, 'pai_id' => $pai, 'nome' => $nome, 'chave' => $chave, 'ordem' => $ordem, 'codigo' => '']);
        foreach ($filhas as $i => [$n, $k, $f]) {
            self::criar($tipo, $c->id, $n, $k, $f, $i);
        }
    }

    /** refaz os códigos pela posição: raiz de receitas = 1, de despesas = 2 */
    public static function renumerar(): void
    {
        $todas = PlanoConta::orderBy('ordem')->orderBy('id')->get()->groupBy(fn ($c) => (int) $c->pai_id);
        $raizes = $todas->get(0, collect())->sortBy(fn ($c) => $c->tipo === 'receita' ? 0 : 1)->values();
        $passo = function ($lista, string $prefixo) use (&$passo, $todas) {
            foreach ($lista->values() as $i => $c) {
                $codigo = $prefixo === '' ? (string) ($c->tipo === 'receita' ? 1 : 2) : $prefixo.'.'.($i + 1);
                if ($c->codigo !== $codigo) {
                    $c->update(['codigo' => $codigo]);
                }
                $passo($todas->get($c->id, collect()), $codigo);
            }
        };
        $passo($raizes, '');
    }

    /** árvore em lista plana, na ordem, com nível (para telas) */
    public static function arvore(?string $tipo = null): Collection
    {
        self::garantirPadrao();
        $todas = PlanoConta::when($tipo, fn ($q) => $q->where('tipo', $tipo))->orderBy('ordem')->orderBy('id')->get();
        $porPai = $todas->groupBy(fn ($c) => (int) $c->pai_id);
        $saida = collect();
        $andar = function ($lista, int $nivel) use (&$andar, $porPai, $saida) {
            foreach ($lista as $c) {
                $filhas = $porPai->get($c->id, collect());
                $saida->push(['id' => $c->id, 'codigo' => $c->codigo, 'nome' => $c->nome, 'tipo' => $c->tipo, 'nivel' => $nivel,
                    'ativa' => $c->ativa, 'sistema' => (bool) $c->chave, 'tem_filhas' => $filhas->isNotEmpty(), 'pai_id' => $c->pai_id]);
                $andar($filhas, $nivel + 1);
            }
        };
        $andar($todas->filter(fn ($c) => ! $c->pai_id || ! $todas->firstWhere('id', $c->pai_id))->sortBy(fn ($c) => $c->tipo === 'receita' ? 0 : 1), 0);

        return $saida;
    }

    /** contas de despesa onde se pode lançar: ativas e sem filhas */
    public static function lancaveis(): Collection
    {
        return self::arvore('despesa')->filter(fn ($c) => $c['ativa'] && ! $c['tem_filhas'] && $c['nivel'] > 0)->values();
    }

    public static function lancavel(int $id): ?PlanoConta
    {
        $c = PlanoConta::where('tipo', 'despesa')->where('ativa', true)->find($id);

        return $c && ! $c->filhas()->exists() && $c->pai_id ? $c : null;
    }

    public static function porChave(string $chave): ?PlanoConta
    {
        self::garantirPadrao();

        return PlanoConta::where('chave', $chave)->first();
    }
}
