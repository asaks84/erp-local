import { useState } from 'react'
import BuscaCliente from './BuscaCliente'

/**
 * "Na conta de:" qualquer cliente (ex.: mensalista que trouxe o carro do pai). Começa com o dono do
 * veículo, quando há; "trocar" abre a busca.
 */
export default function ContaDe({ cliente, onEscolher, erro }) {
    const [buscando, setBuscando] = useState(!cliente)
    return (
        <div className="mb-3">
            <div className="small fw-semibold mb-1">Na conta de</div>
            {cliente && !buscando ? (
                <div className="d-flex align-items-center gap-2">
                    <strong>{cliente.nome}</strong>
                    <button type="button" className="btn btn-link btn-sm p-0" onClick={() => setBuscando(true)}>trocar</button>
                </div>
            ) : (
                <BuscaCliente placeholder="Buscar o cliente que vai pagar (nome, código ou placa)" onEscolher={(c) => { onEscolher(c); setBuscando(false) }} />
            )}
            {erro && <div className="text-danger small mt-1">{erro}</div>}
        </div>
    )
}
