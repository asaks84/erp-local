<?php

namespace App\Http\Controllers;

use App\Models\CadastroPendente;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\TipoVeiculo;
use App\Models\Unidade;
use App\Models\Veiculo;
use App\Servicos\Contratos;
use App\Support\Atividades;
use App\Support\Documento;
use App\Support\EmpresaAtual;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Fila de espera do cadastro público: revisar, aprovar (cliente novo ou existente) ou recusar. */
class PendenteController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Pendentes/Index', [
            'pendentes' => CadastroPendente::orderBy('recebido_em')->get()->map(fn ($p) => [
                'id' => $p->id,
                'nome' => $p->nome,
                'placa' => $p->placa,
                'modelo' => trim($p->marca.' '.$p->modelo),
                'recebido_em' => $p->recebido_em->format('Y-m-d H:i:s'),
                'cliente_existente' => $p->documento ? Cliente::where('documento', $p->documento)->value('nome') : null,
            ]),
        ]);
    }

    public function show(CadastroPendente $pendente): Response
    {
        $existente = $pendente->documento ? Cliente::where('documento', $pendente->documento)->first() : null;

        return Inertia::render('Pendentes/Revisar', [
            'pendente' => [
                ...$pendente->only(['id', 'nome', 'email', 'telefone', 'celular', 'cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'uf', 'placa', 'marca', 'modelo', 'cor', 'tipo_veiculo_id']),
                'documento' => Documento::formatar($pendente->documento),
                'recebido_em' => $pendente->recebido_em->format('Y-m-d H:i:s'),
                'consentimento_em' => $pendente->consentimento_em->format('Y-m-d H:i:s'),
            ],
            'existente' => $existente ? ['id' => $existente->id, 'nome' => $existente->nome] : null,
            'tipos' => TipoVeiculo::where('ativo', true)->orderBy('ordem')->orderBy('nome')->get(['id', 'nome']),
            'unidades' => Unidade::where('ativa', true)->orderBy('nome')->get(['id', 'nome']),
            'periodos' => Empresa::atual()->config('periodos'),
            'dia_padrao' => Empresa::atual()->config('dia_vencimento_padrao'),
        ]);
    }

    public function aprovar(Request $request, CadastroPendente $pendente): RedirectResponse
    {
        $empresa = app(EmpresaAtual::class)->id();
        $modo = $request->validate(['modo' => ['required', 'in:novo,vincular']])['modo'];

        $mensal = $request->validate([
            'placa' => ['required', 'string', 'min:5', 'max:10'],
            'marca' => ['nullable', 'string', 'max:30'],
            'modelo' => ['nullable', 'string', 'max:40'],
            'cor' => ['nullable', 'string', 'max:20'],
            'tipo_veiculo_id' => ['required', Rule::exists('tipos_veiculo', 'id')->where('empresa_id', $empresa)],
            'mensalista' => ['boolean'],
            'periodo' => ['exclude_unless:mensalista,true', 'required', 'string', 'max:30'],
            'valor' => ['exclude_unless:mensalista,true', 'required', 'numeric', 'gt:0', 'max:99999'],
            'inicio' => ['exclude_unless:mensalista,true', 'required', 'date'],
            'unidade_id' => ['exclude_unless:mensalista,true', 'required', Rule::exists('unidades', 'id')->where('empresa_id', $empresa)->where('ativa', true)],
        ]);

        $cliente = DB::transaction(function () use ($request, $pendente, $modo, $mensal) {
            if ($modo === 'vincular') {
                $doc = Documento::limpar($request->input('documento'));
                $cliente = $doc ? Cliente::where('documento', $doc)->first() : null;
                if (! $cliente) {
                    throw ValidationException::withMessages(['modo' => 'Nenhum cliente com este CPF/CNPJ. Use "Aprovar como cliente novo".']);
                }
            } else {
                $dados = ClienteController::validar($request);
                $cliente = Cliente::create($dados + ['criado_por' => $request->user()->id, 'origem' => 'cadastro_publico']);
            }

            $placa = \App\Support\Placa::normalizar($mensal['placa']);
            if (Veiculo::where('placa', $placa)->where('ativo', true)->exists()) {
                throw ValidationException::withMessages(['placa' => 'Esta placa já está em outro veículo ativo.']);
            }
            $veiculo = Veiculo::create([
                'cliente_id' => $cliente->id,
                'placa' => $placa,
                'marca' => mb_strtoupper((string) ($mensal['marca'] ?? '')) ?: null,
                'modelo' => mb_strtoupper((string) ($mensal['modelo'] ?? '')) ?: null,
                'cor' => mb_strtoupper((string) ($mensal['cor'] ?? '')) ?: null,
                'tipo_veiculo_id' => $mensal['tipo_veiculo_id'],
                'criado_por' => $request->user()->id,
            ]);
            $contrato = ! empty($mensal['mensalista']) ? Contratos::criar($veiculo, $mensal) : null;
            Atividades::registrar($modo === 'vincular' ? 'cadastro_publico_vinculado' : 'cadastro_publico_aprovado',
                'cliente '.$cliente->id.' - '.$cliente->nome.', '.$veiculo->rotulo());
            $pendente->delete();

            return [$cliente, $contrato];
        });
        [$cliente, $contrato] = $cliente;

        if ($contrato) {
            return redirect("/mensalidades/{$contrato->id}/primeira-cobranca?de=aprovacao")->with('sucesso', 'Cadastro aprovado.');
        }

        return redirect("/clientes/{$cliente->id}")->with('sucesso', 'Cadastro aprovado.');
    }

    public function recusar(CadastroPendente $pendente): RedirectResponse
    {
        Atividades::registrar('cadastro_publico_recusado', $pendente->nome);
        $pendente->delete(); // LGPD: dados de quem não virou cliente não ficam guardados

        return redirect('/pendentes')->with('sucesso', 'Cadastro recusado e apagado.');
    }
}
