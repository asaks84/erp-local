import { useState } from 'react'
import { usePin } from '../Pin'

/** tela inicial do PDV: estadias (8.2), caixa (8.3); lavagem na 8.4. PIN e impressão embaixo. */
export default function Inicio({ eu, irPara, onDesparear }) {
    const { pedirPin } = usePin()
    const [aviso, setAviso] = useState(null)
    const [sair, setSair] = useState(false)

    const testarPin = () => pedirPin('Testar meu PIN')
        .then(({ usuario }) => setAviso({ tipo: 'success', texto: `PIN de ${usuario.nome}${usuario.nivel ? ` (${usuario.nivel})` : ''}. Tudo certo.` }))
        .catch(() => {})

    return (
        <>
            {aviso && <div className={`alert alert-${aviso.tipo} py-2`} onClick={() => setAviso(null)}>{aviso.texto}</div>}
            <div className="pdv-botoes-grandes">
                <button type="button" className="pdv-grande" onClick={() => irPara('estadias')}><span className="pdv-grande-titulo">Estadia</span><span className="small">entrada, saída e pátio</span></button>
                <button type="button" className="pdv-grande" disabled><span className="pdv-grande-titulo">Lavagem</span><span className="small">em breve</span></button>
                <button type="button" className="pdv-grande" onClick={() => irPara('caixa')}><span className="pdv-grande-titulo">Caixa</span><span className="small">receber conta, retirada e fechamento</span></button>
            </div>
            <div className="list-group mt-4">
                <button type="button" className="list-group-item list-group-item-action py-3" onClick={testarPin}>Testar meu PIN</button>
                <button type="button" className="list-group-item list-group-item-action py-3" onClick={() => irPara('cadastrar-pin')}>Cadastrar ou trocar meu PIN</button>
                <button type="button" className="list-group-item list-group-item-action py-3" onClick={() => irPara('impressao')}>Teste de impressão</button>
            </div>
            <div className="text-center mt-4">
                {sair
                    ? <button type="button" className="btn btn-sm btn-danger" onClick={onDesparear}>Confirmar: tirar o pareamento deste celular</button>
                    : <button type="button" className="btn btn-sm btn-link text-muted" onClick={() => setSair(true)}>Tirar o pareamento deste celular</button>}
            </div>
            <p className="small text-muted text-center mt-2">Hora do servidor: {new Date(eu.servidor_hora).toLocaleString('pt-BR')}</p>
        </>
    )
}
