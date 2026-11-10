<?php

namespace App\Servicos;

use App\Models\ContaFinanceira;
use App\Models\FormaPagamento;

/** Contas e formas de pagamento iniciais de uma empresa (editáveis em Configurações). */
class FormasPagamento
{
    public static function garantirPadrao(): void
    {
        if (! ContaFinanceira::exists()) {
            ContaFinanceira::create(['nome' => 'Caixa', 'tipo' => 'caixa', 'ordem' => 1]);
            ContaFinanceira::create(['nome' => 'Banco', 'tipo' => 'banco', 'ordem' => 2]);
        }
        if (! FormaPagamento::exists()) {
            $caixa = ContaFinanceira::where('tipo', 'caixa')->value('id');
            $banco = ContaFinanceira::where('tipo', 'banco')->value('id') ?? $caixa;
            $padrao = [
                ['Dinheiro', 'dinheiro', $caixa],
                ['Pix', 'pix', $banco],
                ['Cartão de débito', 'debito', $banco],
                ['Cartão de crédito', 'credito', $banco],
                ['Boleto', 'boleto', $banco],
                ['Transferência', 'transferencia', $banco],
            ];
            foreach ($padrao as $i => [$nome, $tipo, $conta]) {
                FormaPagamento::create(['nome' => $nome, 'tipo' => $tipo, 'conta_financeira_id' => $conta, 'ordem' => $i + 1]);
            }
        }
        self::naoInformada();
    }

    /** usada nos pagamentos importados do sistema atual, que não guardava a forma */
    public static function naoInformada(): FormaPagamento
    {
        return FormaPagamento::firstOrCreate(['tipo' => 'nao_informada'], ['nome' => 'Não informada', 'ativa' => false, 'ordem' => 99]);
    }
}
