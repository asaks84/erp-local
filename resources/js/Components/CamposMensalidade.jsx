import Campo from './Campo'
import CampoData from './CampoData'
import CampoValor from './CampoValor'

/** Liga/desliga mensalista e os dados do contrato (período, valor, início, unidade). */
export default function CamposMensalidade({ data, setData, errors, periodos, unidades }) {
    return (
        <>
            <div className="form-check form-switch mb-3">
                <input className="form-check-input" type="checkbox" role="switch" id="mensalista" checked={!!data.mensalista}
                    onChange={(e) => setData('mensalista', e.target.checked)} />
                <label className="form-check-label fw-semibold" htmlFor="mensalista">Mensalista</label>
            </div>
            {data.mensalista && (
                <>
                    <div className="row g-2">
                        <div className="col-sm-4">
                            <Campo as="select" rotulo="Período" value={data.periodo} erro={errors.periodo} onChange={(e) => setData('periodo', e.target.value)}>
                                {periodos.map((p) => <option key={p}>{p}</option>)}
                            </Campo>
                        </div>
                        <div className="col-sm-4"><CampoValor rotulo="Mensalidade" value={data.valor} erro={errors.valor} onChange={(v) => setData('valor', v)} /></div>
                        <div className="col-sm-4"><CampoData rotulo="Começa em" value={data.inicio} erro={errors.inicio} onChange={(v) => setData('inicio', v)} /></div>
                    </div>
                    {unidades.length > 1 && (
                        <Campo as="select" rotulo="Unidade" value={data.unidade_id} erro={errors.unidade_id} onChange={(e) => setData('unidade_id', e.target.value)}>
                            {unidades.map((u) => <option key={u.id} value={u.id}>{u.nome}</option>)}
                        </Campo>
                    )}
                    <p className="text-muted small">A primeira cobrança será gerada pelo sistema novo a partir da etapa 3. Até lá, gere pelo sistema atual.</p>
                </>
            )}
        </>
    )
}
