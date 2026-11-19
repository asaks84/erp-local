import { useCallback, useEffect, useState } from 'react'
import { api } from '../api'
import LeitorQr from '../LeitorQr'
import { Icone, TopoEtapa, useErro } from '../visual'

/** estadias: entrada, saída (QR, número ou placa) e o pátio */
export default function Estadias({ onVoltar, onEntrada, onSaida }) {
    const [patio, setPatio] = useState(null)
    const [busca, setBusca] = useState('')
    const [erro, setErro] = useErro()
    const [lendo, setLendo] = useState(false)
    const [buscando, setBuscando] = useState(false)

    const carregar = useCallback(() => {
        api('GET', '/estadias/patio').then(setPatio).catch((e) => setErro(e.message))
    }, [])
    useEffect(() => {
        carregar()
        const t = setInterval(carregar, 60000)
        return () => clearInterval(t)
    }, [carregar])

    const procurar = useCallback((q) => {
        if (!q.trim()) return
        setBuscando(true)
        setErro(null)
        api('GET', `/estadias/buscar?q=${encodeURIComponent(q.trim())}`)
            .then((j) => onSaida(j.estadia.id))
            .catch((e) => setErro(e.message))
            .finally(() => setBuscando(false))
    }, [onSaida])
    const lido = useCallback((texto) => { setLendo(false); procurar(texto) }, [procurar])

    const filtro = busca.replace(/[^A-Za-z0-9]/g, '').toUpperCase()
    const lista = (patio || []).filter((e) => !filtro || (e.placa || '').includes(filtro) || String(e.numero).includes(filtro) || (e.modelo || '').toUpperCase().includes(filtro))

    return (
        <div>
            <TopoEtapa titulo="Estadia" onVoltar={onVoltar} />
            <div className="pdv-botoes-grandes mb-3">
                <button type="button" className="pdv-grande" onClick={onEntrada}><Icone nome="mais" tamanho={28} className="mb-auto" /><span className="pdv-grande-titulo">Entrada</span><span className="small">novo veículo</span></button>
                <button type="button" className="pdv-grande" onClick={() => setLendo(true)}><Icone nome="qr" tamanho={28} className="mb-auto" /><span className="pdv-grande-titulo">Saída</span><span className="small">ler o QR</span></button>
            </div>
            <form className="d-flex gap-2 mb-2" onSubmit={(e) => { e.preventDefault(); procurar(busca) }}>
                <input className="form-control form-control-lg" placeholder="Nº do ticket ou placa" aria-label="Número do ticket ou placa" value={busca} autoComplete="off"
                    onChange={(e) => { setBusca(e.target.value.toUpperCase()); setErro(null) }} style={{ textTransform: 'uppercase' }} />
                <button type="submit" className="btn btn-primary btn-lg px-3" aria-label="Buscar" disabled={buscando || !busca.trim()}><Icone nome="busca" tamanho={22} /></button>
            </form>

            <div className="d-flex align-items-baseline gap-2 mt-3 mb-2">
                <h2 className="h6 mb-0">No pátio</h2>
                <span className="small text-muted">{patio ? `${patio.length} veículo(s)` : 'carregando…'}</span>
            </div>
            <div className="list-group">
                {patio && lista.length === 0 && <div className="list-group-item text-muted small py-3">{patio.length ? 'Nenhum veículo com esse filtro.' : 'Nenhum veículo no pátio.'}</div>}
                {lista.map((e) => (
                    <button key={e.id} type="button" className="list-group-item list-group-item-action py-2" onClick={() => onSaida(e.id)}>
                        <div className="d-flex justify-content-between align-items-baseline">
                            <span><strong className="fs-5">{e.rotulo}</strong>{e.modelo && <span className="small"> · {e.modelo}</span>}</span>
                            <span className="small text-nowrap">{e.tempo}</span>
                        </div>
                        <div className="small text-muted">
                            Nº {e.numero} · {e.entrada.slice(11, 16)}{e.tipo ? ` · ${e.tipo}` : ''}
                            {e.diaria && <span className={`selo ms-1 ${e.pago_entrada > 0 ? 'selo-ok' : ''}`}>{e.diaria.nome}{e.pago_entrada > 0 ? ' paga' : ''}</span>}
                            {e.mensalista && <span className="selo selo-marca ms-1">Mensalista</span>}
                        </div>
                    </button>
                ))}
            </div>
            {lendo && <LeitorQr onLido={lido} onFechar={() => setLendo(false)} />}
        </div>
    )
}
