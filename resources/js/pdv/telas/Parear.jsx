import { useState } from 'react'
import { api, token, VERSAO } from '../api'

/** primeira vez no celular: digita o código de 6 dígitos gerado na gestão (Configurações › Aparelhos) */
export default function Parear({ onPareado }) {
    const [codigo, setCodigo] = useState('')
    const [erro, setErro] = useState(null)
    const [enviando, setEnviando] = useState(false)

    const enviar = (e) => {
        e.preventDefault()
        setEnviando(true)
        setErro(null)
        api('POST', '/parear', { codigo, versao: VERSAO })
            .then((j) => { token.set(j.token); onPareado() })
            .catch((x) => setErro(x.message))
            .finally(() => setEnviando(false))
    }

    return (
        <div className="pdv-centro">
            <img src="/imagens/logo-local.png" alt="Local Estacionamento" height="44" className="mb-4" />
            <form onSubmit={enviar} className="card w-100" style={{ maxWidth: 360 }}>
                <div className="card-body">
                    <h1 className="h5 mb-1">Parear este celular</h1>
                    <p className="small text-muted">Na gestão, abra Configurações › Aparelhos (PDV), gere o código e digite aqui.</p>
                    <input className="form-control form-control-lg text-center font-monospace mb-2" inputMode="numeric" autoComplete="one-time-code" maxLength={6}
                        style={{ letterSpacing: '.4em', fontSize: '1.75rem' }} value={codigo} aria-label="Código de pareamento"
                        onChange={(e) => setCodigo(e.target.value.replace(/\D/g, '').slice(0, 6))} autoFocus />
                    {erro && <div className="alert alert-danger py-2 small">{erro}</div>}
                    <button type="submit" className="btn btn-primary btn-lg w-100" disabled={codigo.length !== 6 || enviando}>{enviando ? 'Pareando…' : 'Parear'}</button>
                </div>
            </form>
        </div>
    )
}
