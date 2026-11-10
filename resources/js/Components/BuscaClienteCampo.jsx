import { useState } from 'react'
import BuscaCliente from './BuscaCliente'

/** Cliente escolhido (com "trocar") ou a busca para escolher um. */
export default function BuscaClienteCampo({ valor, onEscolher }) {
    const [trocando, setTrocando] = useState(false)
    if (valor && !trocando) {
        return (
            <div className="d-flex align-items-center gap-2 border rounded px-3 py-2">
                <span className="flex-grow-1"><span className="small text-muted d-block">Cliente</span><strong>{valor.nome}</strong> <span className="small text-muted">cód. {valor.id}</span></span>
                <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setTrocando(true)}>Trocar</button>
                <button type="button" className="btn btn-sm btn-link text-muted" onClick={() => onEscolher(null)}>Nenhum</button>
            </div>
        )
    }
    return (
        <div className="d-flex gap-2 align-items-center">
            <BuscaCliente className="flex-grow-1" placeholder="Escolher o cliente (nome, código ou placa)" autoFocus={trocando}
                onEscolher={(c) => { setTrocando(false); onEscolher(c) }} />
            {trocando && <button type="button" className="btn btn-sm btn-link" onClick={() => setTrocando(false)}>Voltar</button>}
        </div>
    )
}
