<?php

namespace App\Http\Controllers;

use App\Models\EtapaLavagem;
use App\Models\OrdemServico;
use App\Servicos\EtapasLavagem;
use App\Support\Atividades;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Configurações › Etapas da lavagem: o que é registrado no quadro, em que ordem. Etapa usada não é apagada: desativa. */
class EtapaLavagemController extends Controller
{
    public function index(): Response
    {
        $usadas = OrdemServico::whereNotNull('etapa')->distinct()->pluck('etapa')->all();

        return Inertia::render('Configuracoes/Etapas', [
            'etapas' => EtapasLavagem::todas()->map(fn ($e) => [
                ...$e->only(['id', 'chave', 'nome', 'ativa', 'fixa']),
                'usada' => in_array($e->chave, $usadas, true),
            ])->values(),
        ]);
    }

    private function nome(Request $request, ?EtapaLavagem $e = null): string
    {
        $nome = trim($request->validate(['nome' => ['required', 'string', 'max:40']], ['nome.required' => 'Dê um nome para a etapa.'])['nome']);
        // sem diferenciar maiúsculas (o banco de testes diferencia)
        if (EtapaLavagem::whereRaw('LOWER(nome) = ?', [mb_strtolower($nome)])->when($e, fn ($q) => $q->whereKeyNot($e->id))->exists()) {
            throw ValidationException::withMessages(['nome' => 'Já existe uma etapa com esse nome.']);
        }

        return $nome;
    }

    public function store(Request $request): RedirectResponse
    {
        EtapasLavagem::garantirPadrao();
        $nome = $this->nome($request);
        $max = (int) EtapaLavagem::where('fixa', false)->max('ordem');
        EtapaLavagem::create(['chave' => EtapasLavagem::chavePara($nome), 'nome' => $nome, 'ordem' => $max + 10]);
        Atividades::registrar('config_etapa', 'nova etapa da lavagem: '.$nome);

        return back()->with('sucesso', 'Etapa "'.$nome.'" criada antes de Entregue.');
    }

    public function update(Request $request, EtapaLavagem $etapa): RedirectResponse
    {
        $nome = $this->nome($request, $etapa);
        $ativa = $etapa->fixa ? true : $request->boolean('ativa', $etapa->ativa);
        $etapa->update(['nome' => $nome, 'ativa' => $ativa]);
        Atividades::registrar('config_etapa', 'etapa da lavagem: '.$nome.($ativa ? '' : ' (desativada)'));

        return back();
    }

    /** sobe ou desce uma etapa do meio */
    public function mover(Request $request, EtapaLavagem $etapa): RedirectResponse
    {
        $d = $request->validate(['direcao' => ['required', 'in:subir,descer']]);
        abort_if($etapa->fixa, 422, 'Etapa fixa.');
        $ids = EtapaLavagem::where('fixa', false)->orderBy('ordem')->orderBy('id')->pluck('id')->all();
        $i = array_search($etapa->id, $ids, true);
        $j = $d['direcao'] === 'subir' ? $i - 1 : $i + 1;
        if ($j >= 0 && $j < count($ids)) {
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            EtapasLavagem::reordenar($ids);
        }

        return back();
    }

    /** apaga só etapa do meio que nunca foi usada */
    public function destroy(EtapaLavagem $etapa): RedirectResponse
    {
        if ($etapa->fixa || OrdemServico::where('etapa', $etapa->chave)->exists()) {
            return back()->with('erro', 'Etapa fixa ou já usada não é apagada: desative.');
        }
        $etapa->delete();

        return back()->with('sucesso', 'Etapa apagada.');
    }
}
