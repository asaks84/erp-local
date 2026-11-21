<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Support\Carbon;

/**
 * Incidente de segurança com dados pessoais (LGPD, Art. 48). Prazo para comunicar ANPD e titulares quando o risco é
 * relevante: 3 dias úteis da ciência, em dobro para agente de pequeno porte (6). A conta só pula sábado e domingo:
 * feriado não estende o prazo aqui, o que deixa a data igual ou antes da real (lado seguro).
 */
class Incidente extends Modelo
{
    use PertenceAEmpresa;

    public const DIAS_UTEIS = 6;   // pequeno porte (ME/EPP), Res. CD/ANPD 2/2022 e 15/2024

    protected $table = 'incidentes';

    protected function casts(): array
    {
        return ['descoberto_em' => 'datetime', 'ocorrido_em' => 'datetime', 'comunicado_anpd_em' => 'date',
            'comunicado_titulares_em' => 'date', 'encerrado_em' => 'datetime', 'titulares' => 'integer'];
    }

    public function registrante()
    {
        return $this->belongsTo(Usuario::class, 'registrado_por');
    }

    public static function somarDiasUteis(Carbon $inicio, int $dias): Carbon
    {
        $d = $inicio->copy()->startOfDay();
        while ($dias > 0) {
            $d->addDay();
            if (! $d->isWeekend()) {
                $dias--;
            }
        }

        return $d;
    }

    public function prazo(): Carbon
    {
        return self::somarDiasUteis($this->descoberto_em, self::DIAS_UTEIS);
    }

    /** encerrado | avaliar (risco não decidido) | comunicar (relevante, falta aviso) | pronto (pode encerrar) */
    public function etapa(): string
    {
        if ($this->encerrado_em) {
            return 'encerrado';
        }
        if (! $this->risco) {
            return 'avaliar';
        }
        if ($this->risco === 'relevante' && (! $this->comunicado_anpd_em || ! $this->comunicado_titulares_em)) {
            return 'comunicar';
        }

        return 'pronto';
    }

    public function linha(): array
    {
        $prazo = $this->prazo();

        return [
            'id' => $this->id, 'numero' => $this->numero, 'titulo' => $this->titulo,
            'descoberto_em' => $this->descoberto_em->format('Y-m-d H:i'), 'ocorrido_em' => $this->ocorrido_em?->format('Y-m-d H:i'),
            'descricao' => $this->descricao, 'dados_afetados' => $this->dados_afetados, 'titulares' => $this->titulares,
            'contencao' => $this->contencao, 'risco' => $this->risco, 'justificativa' => $this->justificativa,
            'comunicado_anpd_em' => $this->comunicado_anpd_em?->format('Y-m-d'), 'protocolo_anpd' => $this->protocolo_anpd,
            'comunicado_titulares_em' => $this->comunicado_titulares_em?->format('Y-m-d'),
            'encerrado_em' => $this->encerrado_em?->format('d/m/Y'), 'registrado_por' => $this->registrante?->nome,
            'etapa' => $this->etapa(), 'prazo' => $prazo->format('Y-m-d'),
            'atrasado' => in_array($this->etapa(), ['avaliar', 'comunicar'], true) && now()->startOfDay()->gt($prazo),
        ];
    }
}
