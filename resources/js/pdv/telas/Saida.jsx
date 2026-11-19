import { useCallback, useState } from 'react'
import { api } from '../api'
import LeitorQr, { temLeitor } from '../LeitorQr'
import { Icone, TopoEtapa, useAvisos } from '../visual'

/** Saída pelo Início: lê qualquer ticket (QR, número ou placa) e abre a saída da estadia ou a entrega da lavagem */
export default function Saida({ onVoltar, onEstadia, onLavagem }) {
    const avisos = useAvisos()
    const [lendo, setLendo] = useState(temLeitor)
    const [busca, setBusca] = useState('')
    const [buscando, setBuscando] = useState(false)
    const [achados, setAchados] = useState(null)

    const abrir = useCallback((a) => (a.tipo === 'estadia' ? onEstadia(a.id) : onLavagem(a.id)), [onEstadia, onLavagem])
    const procurar = useCallback((q) => {
        if (!q.trim()) return
        setBuscando(true)
        setAchados(null)
        api('GET', `/ticket?q=${encodeURIComponent(q.trim())}`)
            .then((j) => (j.achados.length === 1 ? abrir(j.achados[0]) : setAchados(j.achados)))
            .catch((e) => avisos.erro(e.message))
            .finally(() => setBuscando(false))
    }, [abrir, avisos])
    const lido = useCallback((t) => { setLendo(false); procurar(t) }, [procurar])

    return (
        <div>
            <TopoEtapa titulo="Saída" onVoltar={onVoltar} />
            <button type="button" className="pdv-escolha mb-3" onClick={() => setLendo(true)}>
                <span className="pdv-cartao-icone"><Icone nome="qr" tamanho={38} /></span>
                <span><strong>Ler o QR</strong><span className="pdv-escolha-legenda">Ticket de estadia ou de lavagem</span></span>
            </button>
            <form className="d-flex gap-2" onSubmit={(e) => { e.preventDefault(); procurar(busca) }}>
                <input className="form-control form-control-lg" placeholder="Nº do ticket ou placa" aria-label="Número do ticket ou placa" value={busca} autoComplete="off"
                    autoCapitalize="characters" onChange={(e) => setBusca(e.target.value.toUpperCase())} />
                <button type="submit" className="btn btn-primary btn-lg px-3" aria-label="Buscar" disabled={buscando || !busca.trim()}><Icone nome="busca" tamanho={22} /></button>
            </form>
            {achados && (
                <div className="mt-4">
                    <h2 className="h6 fw-bold">Essa placa tem mais de um ticket aberto. Qual?</h2>
                    <div className="pdv-cartoes">
                        {achados.map((a) => (
                            <button key={a.tipo} type="button" className="pdv-cartao" onClick={() => abrir(a)}>
                                <span className="pdv-cartao-icone"><Icone nome={a.tipo === 'estadia' ? 'carro' : 'gota'} tamanho={28} /></span>
                                <span className="pdv-cartao-texto"><strong>{a.placa} · Nº {a.numero}</strong><span>{a.detalhe}</span></span>
                                <Icone nome="seta" tamanho={20} espessura={2.4} className="pdv-seta" />
                            </button>
                        ))}
                    </div>
                </div>
            )}
            {lendo && <LeitorQr onLido={lido} onFechar={() => setLendo(false)} />}
        </div>
    )
}
