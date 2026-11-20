<?php

namespace App\Models;

use App\Models\Concerns\Maiusculas;
use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cadastro enviado pelo formulário público, esperando aprovação. Aprovado ou recusado, é apagado. */
class CadastroPendente extends Modelo
{
    use Maiusculas, PertenceAEmpresa;

    /** @var list<string> sempre em maiúsculas (Concerns\Maiusculas) */
    protected array $maiusculas = ['marca', 'modelo', 'cor'];

    protected $table = 'cadastros_pendentes';

    protected function casts(): array
    {
        return ['recebido_em' => 'datetime', 'consentimento_em' => 'datetime'];
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoVeiculo::class, 'tipo_veiculo_id');
    }
}
