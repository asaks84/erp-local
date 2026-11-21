<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Models\Incidente;
use App\Support\Atividades;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sistema › Incidentes (LGPD): roteiro de resposta e registro de cada incidente com dados pessoais. Registra, avalia
 * o risco, comunica ANPD e clientes quando o risco é relevante (prazo de 6 dias úteis para pequeno porte) e encerra.
 * Não há exclusão: o registro é a prova de que o caso foi tratado.
 */
class IncidenteController extends Controller
{
    public function index(): Response
    {
        $empresa = Empresa::atual();

        return Inertia::render('Sistema/Incidentes', [
            'incidentes' => Incidente::with('registrante:id,nome')->orderByRaw('encerrado_em IS NOT NULL')->orderByDesc('numero')->get()->map->linha(),
            'dias_uteis' => Incidente::DIAS_UTEIS,
            'empresa_aviso' => ['nome' => $empresa->razao_social ?: $empresa->nome, 'contato' => $empresa->config('privacidade_contato') ?: ($empresa->email ?: $empresa->telefone)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $this->validar($request);
        $inc = DB::transaction(function () use ($d, $request) {
            $numero = (int) Incidente::lockForUpdate()->max('numero') + 1;

            return Incidente::create($d + ['numero' => $numero, 'registrado_por' => $request->user()->id]);
        });
        Atividades::registrar('incidente_registrado', "incidente {$inc->numero}: {$inc->titulo}");

        return back()->with('sucesso', "Incidente {$inc->numero} registrado. Prazo para decidir e, se for o caso, comunicar: ".$inc->prazo()->format('d/m/Y').'.');
    }

    public function update(Request $request, Incidente $incidente): RedirectResponse
    {
        abort_if((bool) $incidente->encerrado_em, 403, 'Incidente encerrado: o registro não muda mais.');
        $incidente->update($this->validar($request));
        Atividades::registrar('incidente_alterado', "incidente {$incidente->numero}: {$incidente->titulo}");

        return back()->with('sucesso', 'Incidente salvo.');
    }

    /** encerra: risco decidido; se relevante, ANPD e clientes comunicados; se baixo, com a justificativa de não comunicar */
    public function encerrar(Incidente $incidente): RedirectResponse
    {
        abort_if((bool) $incidente->encerrado_em, 403);
        $falta = match (true) {
            ! $incidente->risco => 'Decida o risco antes de encerrar.',
            $incidente->etapa() === 'comunicar' => 'Risco relevante: registre a comunicação à ANPD e aos clientes antes de encerrar.',
            ! trim((string) $incidente->justificativa) => 'Escreva por que comunicou ou não comunicou (justificativa).',
            ! trim((string) $incidente->contencao) => 'Registre o que foi feito para conter o incidente.',
            default => null,
        };
        if ($falta) {
            throw ValidationException::withMessages(['incidente' => $falta]);
        }
        $incidente->update(['encerrado_em' => now()]);
        Atividades::registrar('incidente_encerrado', "incidente {$incidente->numero}: {$incidente->titulo}");

        return back()->with('sucesso', "Incidente {$incidente->numero} encerrado.");
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'titulo' => ['required', 'string', 'max:120'],
            'descoberto_em' => ['required', 'date', 'before_or_equal:now'],
            'ocorrido_em' => ['nullable', 'date', 'before_or_equal:descoberto_em'],
            'descricao' => ['required', 'string', 'max:5000'],
            'dados_afetados' => ['nullable', 'string', 'max:2000'],
            'titulares' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'contencao' => ['nullable', 'string', 'max:5000'],
            'risco' => ['nullable', 'in:baixo,relevante'],
            'justificativa' => ['nullable', 'string', 'max:5000'],
            'comunicado_anpd_em' => ['nullable', 'date', 'before_or_equal:today'],
            'protocolo_anpd' => ['nullable', 'string', 'max:60'],
            'comunicado_titulares_em' => ['nullable', 'date', 'before_or_equal:today'],
        ], [
            'titulo.required' => 'Dê um título curto (ex.: Celular do balcão roubado).',
            'descoberto_em.before_or_equal' => 'A data em que soube não pode ser no futuro.',
            'ocorrido_em.before_or_equal' => 'A data do ocorrido não pode ser depois de quando soube.',
            'descricao.required' => 'Descreva o que aconteceu.',
        ]);
    }
}
