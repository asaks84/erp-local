import Campo from './Campo'
import { placa as mPlaca } from '../lib/mascaras'

/** Placa (opcional), identificação alternativa, tipo, marca, modelo e cor. */
export default function CamposVeiculo({ data, setData, errors, tipos, semPlacaPermitido = true }) {
    const semPlaca = semPlacaPermitido && data.sem_placa
    return (
        <>
            {semPlacaPermitido && (
                <div className="form-check form-switch mb-3">
                    <input className="form-check-input" type="checkbox" role="switch" id="sem_placa" checked={!!data.sem_placa}
                        onChange={(e) => setData((d) => ({ ...d, sem_placa: e.target.checked, placa: e.target.checked ? '' : d.placa }))} />
                    <label className="form-check-label" htmlFor="sem_placa">Veículo sem placa (bicicleta elétrica, patinete…)</label>
                </div>
            )}
            <div className="row g-2">
                <div className="col-sm-5">
                    {semPlaca
                        ? <Campo rotulo="Identificação (quadro, série ou apelido)" value={data.identificacao || ''} erro={errors.identificacao} maxLength={60} onChange={(e) => setData('identificacao', e.target.value)} />
                        : <Campo rotulo="Placa" value={mPlaca(data.placa)} erro={errors.placa} autoCapitalize="characters" onChange={(e) => setData('placa', e.target.value)} />}
                </div>
                <div className="col-sm-7">
                    <Campo as="select" rotulo="Tipo de veículo" value={data.tipo_veiculo_id || ''} erro={errors.tipo_veiculo_id} onChange={(e) => setData('tipo_veiculo_id', e.target.value)}>
                        <option value="">Escolha…</option>
                        {tipos.map((t) => <option key={t.id} value={t.id}>{t.nome}{t.detalhe ? ` — ${t.detalhe}` : ''}</option>)}
                    </Campo>
                </div>
            </div>
            <div className="row g-2">
                <div className="col-sm-4"><Campo rotulo="Marca" value={data.marca || ''} erro={errors.marca} maxLength={30} onChange={(e) => setData('marca', e.target.value)} /></div>
                <div className="col-sm-5"><Campo rotulo="Modelo" value={data.modelo || ''} erro={errors.modelo} maxLength={40} onChange={(e) => setData('modelo', e.target.value)} /></div>
                <div className="col-sm-3"><Campo rotulo="Cor" value={data.cor || ''} erro={errors.cor} maxLength={20} onChange={(e) => setData('cor', e.target.value)} /></div>
            </div>
        </>
    )
}
