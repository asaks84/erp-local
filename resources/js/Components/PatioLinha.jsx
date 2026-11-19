import { dataHora, moeda } from '../lib/formato'

/** célula do veículo, comum ao Agora e ao Histórico */
export function Veiculo({ l }) {
    return (
        <>
            <strong>{l.placa}</strong>{l.modelo && <span className="small"> · {l.modelo}</span>}
            <div className="small text-muted">{[l.cliente || 'Avulso', l.tamanho, l.convenio && `convênio ${l.convenio}`, l.pdv && 'PDV'].filter(Boolean).join(' · ')}</div>
            {l.obs && <div className="small text-muted">{l.situacao === 'cancelada' ? 'Cancelada' : 'Obs.'}: {l.obs}</div>}
        </>
    )
}

export const SeloTipo = ({ tipo }) => <span className={`selo ${tipo === 'lavagem' ? 'selo-info' : ''}`}>{tipo === 'lavagem' ? 'Lavagem' : 'Estadia'}</span>

/** o que foi feito: estadia (por hora ou diária) ou os serviços da lavagem, com a etapa */
export function Servico({ l }) {
    return (
        <>
            <div className="small">{l.servico}</div>
            {l.etapa && l.situacao === 'patio' && <span className="selo selo-aviso">{l.etapa}</span>}
        </>
    )
}

/** valor: estadia (até agora ou cobrado) ou lavagem + a estadia depois de pronta (R$ 0 quando não cobra) */
export function Valor({ l }) {
    return (
        <>
            {l.valor !== null ? moeda(l.valor) : <span className="text-muted small">sem tabela</span>}
            {l.tipo === 'lavagem' && <div className="small text-muted">Estadia {moeda(l.estadia)}</div>}
        </>
    )
}

export const Entrada = ({ l, comSaida = false }) => (
    <>
        <span className="text-nowrap">{dataHora(l.entrada)}</span>
        {comSaida && l.saida && <div className="small text-muted text-nowrap">até {dataHora(l.saida)}</div>}
        <div className="small text-muted">{l.tempo}</div>
    </>
)

export function Como({ l }) {
    if (l.situacao === 'cancelada') return <span className="selo">Cancelada</span>
    if (!l.como) return l.situacao === 'patio' ? <span className="selo selo-aviso">No pátio</span> : null
    return <span className={`selo ${l.ok ? 'selo-ok' : l.como === 'A receber' ? 'selo-aviso' : ''}`}>{l.como}</span>
}
