<?php

namespace App\Servicos;

use App\Models\Despesa;
use App\Models\Diaria;
use App\Models\Funcionario;
use App\Models\Holerite;
use App\Support\Atividades;
use App\Support\Formato;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Folha: recibos de salário importados do PDF do contador (o líquido vira conta a pagar, vencendo
 * no 5º dia útil) e diárias dos avulsos (juntadas num pagamento, que vira conta a pagar).
 * As guias (FGTS, INSS, DAS) entram como contas a pagar comuns, pela leitura de boleto/guia.
 */
class Folha
{
    private static function erro(string $campo, string $msg): never
    {
        throw ValidationException::withMessages([$campo => $msg]);
    }

    private static function conta(string $chave): int
    {
        $c = PlanoContas::porChave($chave);
        if (! $c || ! PlanoContas::lancavel($c->id)) {
            self::erro('conta', 'A conta "'.($c?->nome ?? $chave).'" do plano de contas está desativada ou tem subcontas. Ajuste em Configurações › Plano de contas.');
        }

        return $c->id;
    }

    /** recibo cuja conta foi cancelada pode ser importado de novo */
    public static function holeriteAtivo(int $funcionarioId, string $competencia): ?Holerite
    {
        return Holerite::where('funcionario_id', $funcionarioId)->where('competencia', $competencia.'-01')
            ->where(fn ($w) => $w->whereNull('despesa_id')->orWhereHas('despesa', fn ($d) => $d->where('situacao', '<>', 'cancelada')))->first();
    }

    /**
     * $itens: [matricula, nome, funcao, competencia (Y-m), salario_base, vencimentos, descontos, liquido, fgts,
     * itens, vencimento (data do pagamento), funcionario_id (vazio = cadastrar)]. Devolve quantos importou.
     */
    public static function importarHolerites(array $itens, ?UploadedFile $pdf): int
    {
        $arquivo = $pdf ? $pdf->storeAs('holerites/'.now()->format('Y/m'), Str::uuid().'.pdf', 'local') : null;
        $nomeArquivo = $pdf ? mb_substr($pdf->getClientOriginalName(), 0, 150) : null;
        $contaSalarios = self::conta('despesa_salarios');

        $n = DB::transaction(function () use ($itens, $arquivo, $nomeArquivo, $contaSalarios) {
            $n = 0;
            foreach ($itens as $k => $i) {
                $f = ! empty($i['funcionario_id']) ? Funcionario::find($i['funcionario_id']) : null;
                if (! empty($i['funcionario_id']) && ! $f) {
                    self::erro("itens.$k.funcionario_id", 'Funcionário não encontrado.');
                }
                if (! $f) {
                    $f = Funcionario::where('matricula', $i['matricula'])->first() ?? Funcionario::create([
                        'nome' => mb_strtoupper(trim($i['nome'])), 'tipo' => 'registrado', 'funcao' => $i['funcao'] ?? null, 'matricula' => $i['matricula'],
                    ]);
                } elseif (! $f->matricula) {
                    $f->update(['matricula' => $i['matricula']]);
                }
                if (self::holeriteAtivo($f->id, $i['competencia'])) {
                    self::erro("itens.$k.liquido", "O recibo de {$f->nome} de ".self::mes($i['competencia']).' já foi importado.');
                }
                $despesa = Despesa::create([
                    'unidade_id' => Ordens::unidadePadrao(),
                    'conta_id' => $contaSalarios,
                    'descricao' => mb_substr('Salário '.$f->nome.' '.self::mes($i['competencia']), 0, 120),
                    'competencia' => $i['competencia'].'-01',
                    'vencimento' => $i['vencimento'],
                    'valor' => round((float) $i['liquido'], 2),
                    'comprovante' => $arquivo, 'comprovante_nome' => $nomeArquivo,
                    'criado_por' => Auth::id(),
                ]);
                Holerite::where('funcionario_id', $f->id)->where('competencia', $i['competencia'].'-01')->delete(); // o cancelado, se houver
                Holerite::create([
                    'funcionario_id' => $f->id, 'competencia' => $i['competencia'].'-01',
                    'salario_base' => $i['salario_base'] ?? null, 'vencimentos' => $i['vencimentos'], 'descontos' => $i['descontos'],
                    'liquido' => $i['liquido'], 'fgts' => $i['fgts'] ?? null, 'itens' => $i['itens'] ?? null,
                    'arquivo' => $arquivo, 'despesa_id' => $despesa->id, 'criado_por' => Auth::id(),
                ]);
                $n++;
            }

            return $n;
        });
        Atividades::registrar('holerites_importados', $n.' recibo(s) de salário de '.self::mes($itens[0]['competencia']));

        return $n;
    }

    public static function mes(string $ym): string
    {
        return substr($ym, 5, 2).'/'.substr($ym, 0, 4);
    }

    /** diárias ainda não pagas (as de um pagamento cancelado voltam a ficar a pagar) */
    public static function diariasAPagar(): Builder
    {
        return Diaria::query()->where(fn ($w) => $w->whereNull('despesa_id')->orWhereHas('despesa', fn ($d) => $d->where('situacao', 'cancelada')));
    }

    /** lança um ou mais dias trabalhados; devolve quantos lançou (dias repetidos são ignorados) */
    public static function lancarDiarias(Funcionario $f, array $datas, float $valor, ?string $obs): int
    {
        if ($f->tipo !== 'avulso' || ! $f->ativo) {
            self::erro('funcionario_id', 'Diária é só para funcionário avulso ativo.');
        }
        $n = 0;
        foreach (array_unique($datas) as $data) {
            if (Diaria::where('funcionario_id', $f->id)->where('data', $data)->exists()) {
                continue;
            }
            Diaria::create(['funcionario_id' => $f->id, 'data' => $data, 'valor' => round($valor, 2), 'observacao' => $obs, 'criado_por' => Auth::id()]);
            $n++;
        }
        if ($n) {
            Atividades::registrar('diarias_lancadas', $f->nome.' · '.$n.' dia(s) de '.Formato::moeda($valor));
        }

        return $n;
    }

    public static function removerDiaria(Diaria $d): void
    {
        $d->loadMissing('despesa', 'funcionario');
        if ($d->despesa_id && $d->despesa?->situacao !== 'cancelada') {
            self::erro('diaria', 'Esta diária já entrou num pagamento. Cancele o pagamento em Contas a pagar antes.');
        }
        Atividades::registrar('diaria_removida', $d->funcionario->nome.' · '.$d->data->format('d/m/Y').' · '.Formato::moeda($d->valor));
        $d->delete();
    }

    /**
     * Junta diárias de um avulso num pagamento: cria a conta a pagar (já paga, se $p['pago']).
     * $p: pago, pago_em, forma_pagamento_id, conta_financeira_id, vencimento.
     */
    public static function pagarDiarias(Funcionario $f, array $ids, array $p): Despesa
    {
        $dias = self::diariasAPagar()->where('funcionario_id', $f->id)->whereIn('id', $ids)->orderBy('data')->get();
        if ($dias->isEmpty()) {
            self::erro('ids', 'Escolha as diárias que vai pagar.');
        }
        $de = $dias->first()->data;
        $ate = $dias->last()->data;
        $periodo = $de->eq($ate) ? $de->format('d/m') : $de->format('d/m').' a '.$ate->format('d/m');
        $venc = ! empty($p['pago']) ? $p['pago_em'] : ($p['vencimento'] ?? now()->toDateString());

        return DB::transaction(function () use ($f, $dias, $periodo, $venc, $ate, $p) {
            [$despesa] = Despesas::lancar([
                'descricao' => 'Diárias '.$f->nome.' '.$periodo.' ('.$dias->count().' dia'.($dias->count() > 1 ? 's' : '').')',
                'conta_id' => self::conta('despesa_avulsos'),
                'competencia' => $ate->format('Y-m'),
                'vencimento' => $venc,
                'valor' => $dias->sum('valor'),
                'observacao' => $f->pix ? 'Pix: '.$f->pix : null,
            ] + $p);
            Diaria::whereIn('id', $dias->pluck('id'))->update(['despesa_id' => $despesa->id]);

            return $despesa;
        });
    }

    /** data sugerida para pagar o salário do mês $ym: 5º dia útil do mês seguinte */
    public static function vencimentoSalario(string $ym): string
    {
        return LeitorDocumento::quintoDiaUtil(Carbon::createFromFormat('Y-m-d', $ym.'-01')->addMonthNoOverflow()->format('Y-m'));
    }
}
