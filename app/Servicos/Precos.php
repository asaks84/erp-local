<?php

namespace App\Servicos;

use App\Models\ServicoPreco;
use App\Models\Tarifa;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Preço vigente: tabela de estadia e preço de serviço para um tipo de veículo, numa unidade, numa data.
 * A regra da unidade vence a da empresa; cada data usa a versão vigente naquele dia.
 * Ver docs/05-regras-de-negocio.md (vigência de preços).
 */
class Precos
{
    private static function vigente(Builder $q, string $dia, string $ini = 'vigencia_inicio', string $fim = 'vigencia_fim'): Builder
    {
        return $q->where($ini, '<=', $dia)->where(fn ($q) => $q->whereNull($fim)->orWhere($fim, '>=', $dia));
    }

    public static function tarifa(int $tipoVeiculoId, ?int $unidadeId = null, ?CarbonInterface $data = null): ?Tarifa
    {
        $dia = ($data ?? now())->toDateString();
        $q = Tarifa::whereHas('tipos', fn ($q) => $q->where('tipos_veiculo.id', $tipoVeiculoId))
            ->where(fn ($q) => $q->whereNull('unidade_id')->when($unidadeId, fn ($q) => $q->orWhere('unidade_id', $unidadeId)));
        self::vigente($q, $dia);

        // unidade antes da empresa; dentro do mesmo nível, a versão mais nova
        return $q->orderByRaw('unidade_id IS NULL')->orderByDesc('vigencia_inicio')->with(['faixas', 'pacotes'])->first();
    }

    public static function servico(int $servicoId, int $tipoVeiculoId, ?int $unidadeId = null, ?CarbonInterface $data = null): ?float
    {
        $dia = ($data ?? now())->toDateString();
        $q = ServicoPreco::where('servico_id', $servicoId)->where('tipo_veiculo_id', $tipoVeiculoId)
            ->where(fn ($q) => $q->whereNull('unidade_id')->when($unidadeId, fn ($q) => $q->orWhere('unidade_id', $unidadeId)));
        self::vigente($q, $dia);
        $preco = $q->orderByRaw('unidade_id IS NULL')->orderByDesc('vigencia_inicio')->first();

        return $preco ? (float) $preco->valor : null;
    }
}
