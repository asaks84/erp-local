import { useState } from 'react'
import { api } from '../api'

/** cada pessoa escolhe o próprio PIN, entrando com o login e a senha do sistema */
export default function CadastrarPin({ onVoltar }) {
    const [d, setD] = useState({ login: '', senha: '', pin: '', pin2: '' })
    const [erro, setErro] = useState(null)
    const [ok, setOk] = useState(null)
    const [enviando, setEnviando] = useState(false)
    const mudar = (k) => (e) => setD({ ...d, [k]: k.startsWith('pin') ? e.target.value.replace(/\D/g, '').slice(0, 4) : e.target.value })

    const enviar = (e) => {
        e.preventDefault()
        if (d.pin !== d.pin2) { setErro('Os dois PINs não são iguais.'); return }
        setEnviando(true)
        setErro(null)
        api('POST', '/pin/cadastrar', { login: d.login, senha: d.senha, pin: d.pin })
            .then((j) => { setOk(j.mensagem); setD({ login: '', senha: '', pin: '', pin2: '' }) })
            .catch((x) => setErro(x.message))
            .finally(() => setEnviando(false))
    }

    return (
        <form onSubmit={enviar}>
            <button type="button" className="btn btn-link px-0 mb-2" onClick={onVoltar}>‹ Voltar</button>
            <h1 className="h5">Cadastrar ou trocar meu PIN</h1>
            <p className="small text-muted">Entre com o seu login e a sua senha do sistema e escolha 4 números. O PIN é seu: não conte para ninguém. Sequências (1234) e números repetidos (1111) não valem.</p>
            {ok && <div className="alert alert-success py-2">{ok}</div>}
            <input className="form-control form-control-lg mb-2" placeholder="Login" autoCapitalize="none" autoComplete="username" value={d.login} onChange={mudar('login')} aria-label="Login" />
            <input className="form-control form-control-lg mb-2" placeholder="Senha" type="password" autoComplete="current-password" value={d.senha} onChange={mudar('senha')} aria-label="Senha" />
            <div className="row g-2 mb-2">
                <div className="col-6"><input className="form-control form-control-lg text-center texto-oculto" placeholder="Novo PIN" type="text" inputMode="numeric" pattern="[0-9]*" maxLength={4} autoComplete="off" name="pin-novo" value={d.pin} onChange={mudar('pin')} aria-label="Novo PIN" /></div>
                <div className="col-6"><input className="form-control form-control-lg text-center texto-oculto" placeholder="Repita" type="text" inputMode="numeric" pattern="[0-9]*" maxLength={4} autoComplete="off" name="pin-repita" value={d.pin2} onChange={mudar('pin2')} aria-label="Repita o PIN" /></div>
            </div>
            {erro && <div className="alert alert-danger py-2 small">{erro}</div>}
            <button type="submit" className="btn btn-primary btn-lg w-100" disabled={enviando || !d.login || !d.senha || d.pin.length !== 4}>{enviando ? 'Salvando…' : 'Salvar PIN'}</button>
        </form>
    )
}
