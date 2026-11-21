<?php

namespace App\Servicos;

use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\Convenio;
use App\Models\Empresa;
use App\Models\Estadia;
use App\Models\MensalidadeContrato;
use App\Models\Nfse;
use App\Models\OrdemServico;
use App\Models\Pagamento;
use App\Support\Atividades;
use App\Support\Documento;
use App\Support\EmpresaAtual;
use App\Support\Formato;
use App\Support\Placa;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Direitos do titular (LGPD, Art. 18), atendidos pela equipe a pedido do cliente:
 * - exportar(): tudo o que o sistema guarda sobre o cliente (acesso e portabilidade), na tela ou em arquivo.
 * - anonimizar(): apaga o que identifica a pessoa e mantém valores, datas e notas fiscais (guarda legal), com o nome
 *   trocado por "CLIENTE ANONIMIZADO <código>". Não tem volta.
 * Bloqueios (o que a lei deixa guardar ou o que ainda está em uso): cliente ativo, conta ou crédito em aberto,
 * veículo no pátio, lavagem em andamento, convênio ativo, nota sendo emitida e, durante o paralelo, cliente que veio
 * do sistema atual (a importação traria os dados de volta).
 */
class DadosPessoais
{
    /** motivos aceitos para anonimizar */
    public const MOTIVOS = ['pedido' => 'Pedido do cliente', 'prazo' => 'Prazo de guarda vencido'];

    /** o que impede anonimizar agora (lista vazia = pode) */
    public static function bloqueios(Cliente $c): array
    {
        if ($c->anonimizado_em) {
            return ['Este cliente já foi anonimizado em '.$c->anonimizado_em->format('d/m/Y').'.'];
        }
        $b = [];
        if ($c->ativo) {
            $b[] = 'O cliente está ativo. Remova o cliente antes (as mensalidades são encerradas).';
        }
        $abertas = OrdemServico::abertas()->where('cliente_id', $c->id)->get();
        $devendo = $abertas->where('tipo', '<>', 'credito');
        if ($devendo->isNotEmpty()) {
            $b[] = 'Tem '.$devendo->count().' conta(s) em aberto ('.Formato::moeda($devendo->sum('valor')).'). Enquanto houver dívida, a lei permite guardar os dados para cobrar.';
        }
        $credito = $abertas->where('tipo', 'credito');
        if ($credito->isNotEmpty()) {
            $b[] = 'Tem crédito de '.Formato::moeda($credito->sum('valor')).' a devolver ou usar.';
        }
        if (MensalidadeContrato::where('cliente_id', $c->id)->ativos()->exists()) {
            $b[] = 'Tem mensalidade que ainda não terminou.';
        }
        if (Estadia::where('cliente_id', $c->id)->where('situacao', 'patio')->exists()) {
            $b[] = 'Está com veículo no pátio.';
        }
        if (OrdemServico::where('cliente_id', $c->id)->where('tipo', 'servico')->where('situacao', '<>', 'cancelada')
            ->whereNotNull('etapa')->where('etapa', '<>', 'entregue')->exists()) {
            $b[] = 'Tem lavagem em andamento.';
        }
        if (Convenio::where('cliente_id', $c->id)->where('ativo', true)->exists()) {
            $b[] = 'É um convênio ativo. Desative o convênio antes.';
        }
        if (Nfse::where('cliente_id', $c->id)->where('situacao', 'enviando')->exists()) {
            $b[] = 'Tem nota fiscal sendo emitida. Confira a nota antes.';
        }
        if ($c->legado_id && config('erp.espelho_ativo')) {
            $b[] = 'O cliente veio do sistema atual, que ainda é espelhado de hora em hora: a importação traria os dados de volta. Durante o paralelo, atenda o pedido no sistema atual; depois da virada, anonimize aqui.';
        }

        return $b;
    }

    /** tudo o que o sistema guarda sobre o cliente, para mostrar, imprimir ou baixar */
    public static function exportar(Cliente $c): array
    {
        $c->loadMissing(['veiculos.tipo:id,nome', 'pagadores', 'convenio']);
        $veiculoIds = $c->veiculos->pluck('id');
        $empresa = Empresa::find(app(EmpresaAtual::class)->id());
        $data = fn ($v) => $v ? Carbon::parse($v)->format('d/m/Y') : null;
        $dataHora = fn ($v) => $v ? Carbon::parse($v)->format('d/m/Y H:i') : null;

        return [
            'gerado_em' => now()->format('d/m/Y H:i'),
            'controlador' => ['nome' => $empresa?->razao_social ?: $empresa?->nome, 'cnpj' => $empresa && Documento::valido($empresa->documento) ? Documento::formatar($empresa->documento) : null],
            'cadastro' => [
                'codigo' => $c->id, 'nome' => $c->nome, 'documento' => $c->documento ? Documento::formatar($c->documento) : null,
                'telefone' => $c->telefone, 'celular' => $c->celular, 'email' => $c->email,
                'endereco' => trim(implode(', ', array_filter([$c->logradouro, $c->numero, $c->complemento, $c->bairro]))) ?: null,
                'cidade' => trim(implode(' - ', array_filter([$c->cidade, $c->uf]))) ?: null, 'cep' => $c->cep,
                'cliente_desde' => $data($c->cliente_desde), 'dia_vencimento' => $c->dia_vencimento,
                'observacoes' => $c->observacoes, 'recebe_avisos_whatsapp' => ! $c->nao_recebe_avisos,
                'origem' => ['gestao' => 'Cadastrado pela equipe', 'cadastro_publico' => 'Cadastro pelo link público', 'importacao' => 'Sistema anterior'][$c->origem] ?? $c->origem,
                'ativo' => $c->ativo,
            ],
            'veiculos' => $c->veiculos->map(fn ($v) => [
                'placa' => $v->placa ? Placa::formatar($v->placa) : $v->identificacao, 'marca' => $v->marca, 'modelo' => $v->modelo, 'cor' => $v->cor,
                'tamanho' => $v->tipo?->nome, 'ativo' => (bool) $v->ativo,
            ])->values(),
            'trocas_de_placa' => DB::table('veiculo_placas')->whereIn('veiculo_id', $veiculoIds)->orderBy('id')->get()
                ->map(fn ($t) => ['data' => $data($t->criado_em), 'de' => $t->placa_anterior, 'para' => $t->placa_nova])->values(),
            'mensalidades' => MensalidadeContrato::where('cliente_id', $c->id)->orderBy('inicio')->get()
                ->map(fn ($m) => ['periodo' => $m->periodo, 'valor' => (float) $m->valor, 'inicio' => $data($m->inicio), 'fim' => $data($m->fim)])->values(),
            'servicos' => OrdemServico::where('cliente_id', $c->id)->orderBy('data')->orderBy('numero')->get()
                ->map(fn ($o) => ['numero' => $o->numero, 'data' => $data($o->data), 'tipo' => OrdemServico::TIPOS[$o->tipo] ?? $o->tipo, 'descricao' => $o->descricao,
                    'placa' => $o->placa ? Placa::formatar($o->placa) : null, 'valor' => (float) $o->valor, 'situacao' => $o->situacao])->values(),
            'estadias' => Estadia::where('cliente_id', $c->id)->orderBy('entrada')->get()
                ->map(fn ($e) => ['numero' => $e->numero_impresso ?: $e->numero, 'entrada' => $dataHora($e->entrada), 'saida' => $dataHora($e->saida),
                    'placa' => $e->placa ? Placa::formatar($e->placa) : $e->identificacao, 'valor' => (float) $e->valor_cliente])->values(),
            'cobrancas' => Cobranca::where('cliente_id', $c->id)->orderBy('numero')->get()
                ->map(fn ($b) => ['numero' => $b->numero, 'competencia' => $b->competencia, 'vencimento' => $data($b->vencimento), 'total' => (float) $b->total,
                    'situacao' => Cobranca::SITUACOES[$b->situacao] ?? $b->situacao])->values(),
            'pagamentos' => Pagamento::with('forma:id,nome')->where('cliente_id', $c->id)->orderBy('recebido_em')->get()
                ->map(fn ($p) => ['data' => $data($p->recebido_em), 'valor' => (float) $p->valor, 'forma' => $p->forma?->nome, 'situacao' => $p->situacao])->values(),
            'notas_fiscais' => Nfse::where('cliente_id', $c->id)->orderBy('id')->get()
                ->map(fn ($n) => ['numero' => $n->numero_nfse, 'data' => $data($n->emitida_em ?? $n->criado_em), 'valor' => (float) $n->valor, 'situacao' => $n->situacao,
                    'tomador_na_nota' => $n->tomador_nome])->values(),
            'pagadores_vinculados' => $c->pagadores->map(fn ($p) => ['nome' => $p->nome, 'documento' => $p->documento ? Documento::formatar($p->documento) : null])->values(),
            'avisos_whatsapp' => DB::table('avisos')->where('cliente_id', $c->id)->whereNotNull('enviado_em')->orderBy('enviado_em')->get()
                ->map(fn ($a) => ['enviado_em' => $dataHora($a->enviado_em), 'celular' => $a->celular, 'situacao' => $a->situacao])->values(),
            'atualizacoes_pelo_link' => DB::table('atualizacoes_cadastrais')->where('cliente_id', $c->id)->whereNotNull('respondido_em')->orderBy('respondido_em')->get()
                ->map(fn ($a) => ['respondido_em' => $dataHora($a->respondido_em), 'situacao' => $a->situacao, 'ip' => $a->ip])->values(),
            'politica' => url('/privacidade'),
            'compartilhamento' => [
                'Banco Inter: boletos e Pix das cobranças',
                'Sistema Nacional da NFS-e: notas fiscais de serviço',
                'Meta (WhatsApp): avisos de cobrança, quando o cliente recebe avisos',
                'Hostinger: hospedagem do sistema',
                'Cloudflare: proteção e entrega do site',
            ],
        ];
    }

    /** apaga o que identifica o cliente; valores, datas e notas fiscais ficam */
    public static function anonimizar(Cliente $c, string $motivo): void
    {
        if ($b = self::bloqueios($c)) {
            throw ValidationException::withMessages(['confirmacao' => $b[0]]);
        }
        $rotulo = 'CLIENTE ANONIMIZADO '.$c->id;
        $nomeAntigo = (string) $c->nome;

        DB::transaction(function () use ($c, $rotulo, $nomeAntigo) {
            $veiculos = DB::table('veiculos')->where('cliente_id', $c->id)->pluck('id');
            $estadias = DB::table('estadias')->where('cliente_id', $c->id)->orWhereIn('veiculo_id', $veiculos->all() ?: [0])->pluck('id');
            $cobrancas = DB::table('cobrancas')->where('cliente_id', $c->id)->pluck('id');
            $pagamentos = DB::table('pagamentos')->where('cliente_id', $c->id)->pluck('id');
            $nulos = fn (array $cols) => array_fill_keys($cols, null);

            DB::table('clientes')->where('id', $c->id)->update($nulos(['documento', 'telefone', 'celular', 'email', 'cep', 'logradouro', 'numero',
                'complemento', 'bairro', 'cidade', 'uf', 'cidade_ibge', 'cidade_ibge_cep', 'observacoes', 'regras_boleto', 'campos_locais'])
                + ['nome' => $rotulo, 'nao_recebe_avisos' => true, 'nf_tomador' => false, 'nf_iss_retido' => false, 'nf_retem_federal' => false,
                    'ativo' => false, 'anonimizado_em' => now(), 'atualizado_em' => now()]);
            DB::table('veiculos')->whereIn('id', $veiculos)->update($nulos(['placa', 'identificacao', 'marca', 'modelo', 'cor']) + ['ativo' => false]);
            DB::table('veiculo_placas')->whereIn('veiculo_id', $veiculos)->delete();
            DB::table('ordens_servico')->where('cliente_id', $c->id)->update($nulos(['placa', 'veiculo_descricao', 'celular']));
            DB::table('cobranca_itens')->whereIn('cobranca_id', $cobrancas)->update(['placa' => null]);
            DB::table('estadias')->whereIn('id', $estadias)->update($nulos(['placa', 'identificacao', 'modelo', 'celular', 'retirada_nome', 'retirada_documento', 'observacao']));
            DB::table('pdv_acoes')->whereIn('estadia_id', $estadias)->update(['dados' => null]);
            DB::table('avisos')->where('cliente_id', $c->id)->update($nulos(['celular', 'texto']));
            DB::table('atualizacoes_cadastrais')->where('cliente_id', $c->id)->update($nulos(['dados', 'antes', 'ip']));
            DB::table('pagadores_vinculados')->where('cliente_id', $c->id)->delete();
            DB::table('extrato_lancamentos')->where('cliente_id', $c->id)->update($nulos(['nome', 'documento']));
            DB::table('pix_cobrancas')->whereIn('pagamento_id', $pagamentos)->update(['pagador' => null]);
            // registros de atividade citam o cliente pelo nome: troca pelo rótulo (nome curto demais trocaria outras palavras)
            if (mb_strlen($nomeAntigo) >= 5) {
                DB::table('log_atividades')->where('empresa_id', $c->empresa_id)->where('detalhe', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $nomeAntigo).'%')
                    ->update(['detalhe' => DB::raw('REPLACE(detalhe, '.DB::getPdo()->quote($nomeAntigo).', '.DB::getPdo()->quote($rotulo).')')]);
            }
        });
        Atividades::registrar('cliente_anonimizado', "cliente {$c->id} anonimizado (LGPD: ".(self::MOTIVOS[$motivo] ?? $motivo).'); valores e notas fiscais mantidos');
    }
}
