import { useState } from 'react'
import { api } from '../api'
import { ErroAviso, OkAviso, TopoEtapa } from '../visual'

/**
 * Cada pessoa escolhe o próprio PIN: entrando com login e senha do sistema ou, quem é só do PDV (sem senha), com o
 * código de cadastro de 6 dígitos que o gerente gera na gestão (Usuários).
 */
export default function CadastrarPin({ onVoltar }) {
    const vazio = { login: '', senha: '', codigo: '', pin: '', pin2: '' }
    const [modo, setModo] = useState('codigo')
    const [d, setD] = useState(vazio)
    const [erro, setErro] = useState(null)
    const [ok, setOk] = useState(null)
    const [enviando, setEnviando] = useState(false)
    const mudar = (k) => (e) => {
        let v = e.target.value
        if (k.startsWith('pin')) v = v.replace(/\D/g, '').slice(0, 4)
        if (k === 'codigo') v = v.replace(/\D/g, '').slice(0, 6)
        setD({ ...d, [k]: v })
    }
    const comCodigo = modo === 'codigo'
    const pronto = d.pin.length === 4 && (comCodigo ? d.codigo.length === 6 : d.login && d.senha)

    const enviar = (e) => {
        e.preventDefault()
        if (d.pin !== d.pin2) { setErro('Os dois PINs não são iguais.'); return }
        setEnviando(true)
        setErro(null)
        api('POST', '/pin/cadastrar', comCodigo ? { codigo: d.codigo, pin: d.pin } : { login: d.login, senha: d.senha, pin: d.pin })
            .then((j) => { setOk(j.mensagem); setD(vazio) })
            .catch((x) => setErro(x.message))
            .finally(() => setEnviando(false))
    }

    return (
        <form onSubmit={enviar}>
            <TopoEtapa titulo="Meu PIN" onVoltar={onVoltar} />
            <div className="btn-group w-100 mb-3" role="group" aria-label="Como entrar">
                <button type="button" className={`btn btn-lg ${comCodigo ? 'btn-primary' : 'btn-outline-secondary'}`} onClick={() => { setModo('codigo'); setErro(null) }}>Tenho um código</button>
                <button type="button" className={`btn btn-lg ${!comCodigo ? 'btn-primary' : 'btn-outline-secondary'}`} onClick={() => { setModo('senha'); setErro(null) }}>Tenho login e senha</button>
            </div>
            <p className="small text-muted">
                {comCodigo ? 'Digite o código de 6 números que o gerente gerou para você e escolha 4 números.' : 'Entre com o seu login e a sua senha do sistema e escolha 4 números.'}
                {' '}O PIN é seu: não conte para ninguém. Sequências (1234) e números repetidos (1111) não valem.
            </p>
            <OkAviso texto={ok} limpar={() => setOk(null)} />
            {comCodigo ? (
                <input className="form-control form-control-lg mb-2 text-center font-monospace" placeholder="Código (6 números)" type="text" inputMode="numeric" pattern="[0-9]*"
                    autoComplete="one-time-code" maxLength={6} value={d.codigo} onChange={mudar('codigo')} aria-label="Código de cadastro" style={{ letterSpacing: '.3em' }} />
            ) : (
                <>
                    <input className="form-control form-control-lg mb-2" placeholder="Login" autoCapitalize="none" autoComplete="username" value={d.login} onChange={mudar('login')} aria-label="Login" />
                    <input className="form-control form-control-lg mb-2" placeholder="Senha" type="password" autoComplete="current-password" value={d.senha} onChange={mudar('senha')} aria-label="Senha" />
                </>
            )}
            <div className="row g-2 mb-2">
                <div className="col-6"><input className="form-control form-control-lg text-center texto-oculto" placeholder="Novo PIN" type="text" inputMode="numeric" pattern="[0-9]*" maxLength={4} autoComplete="off" name="pin-novo" value={d.pin} onChange={mudar('pin')} aria-label="Novo PIN" /></div>
                <div className="col-6"><input className="form-control form-control-lg text-center texto-oculto" placeholder="Repita" type="text" inputMode="numeric" pattern="[0-9]*" maxLength={4} autoComplete="off" name="pin-repita" value={d.pin2} onChange={mudar('pin2')} aria-label="Repita o PIN" /></div>
            </div>
            <ErroAviso erro={erro} limpar={() => setErro(null)} />
            <button type="submit" className="btn btn-primary btn-lg w-100" disabled={enviando || !pronto}>{enviando ? 'Salvando…' : 'Salvar PIN'}</button>
        </form>
    )
}
