import { useCallback, useEffect, useState } from 'react'
import { api, token } from './api'
import Parear from './telas/Parear'
import Inicio from './telas/Inicio'
import CadastrarPin from './telas/CadastrarPin'
import TesteImpressao from './telas/TesteImpressao'
import { PinProvider } from './Pin'

/**
 * PDV (etapa 8.1): pareamento do aparelho, PIN de cada pessoa e teste de impressão.
 * Estadias, lavagens e caixa entram nas próximas partes (docs/15-plano-pdv.md).
 */
export default function App() {
    const [eu, setEu] = useState(null)       // dados do aparelho e da empresa
    const [erro, setErro] = useState(null)
    const [tela, setTela] = useState('inicio')
    const [carregando, setCarregando] = useState(!!token.get())

    const carregar = useCallback(() => {
        if (!token.get()) { setEu(null); setCarregando(false); return }
        setCarregando(true)
        api('GET', '/eu').then((j) => { setEu(j); setErro(null) }).catch((e) => {
            if (e.parear) { token.set(null); setEu(null) } else setErro(e.message)
        }).finally(() => setCarregando(false))
    }, [])
    useEffect(() => { carregar() }, [carregar])

    if (carregando) return <div className="pdv-centro text-muted">Carregando…</div>
    if (!eu) {
        return erro
            ? <div className="pdv-centro"><div className="alert alert-danger">{erro}</div><button className="btn btn-primary" onClick={carregar}>Tentar de novo</button></div>
            : <Parear onPareado={carregar} />
    }

    const voltar = () => setTela('inicio')
    return (
        <PinProvider memoriaMin={eu.pin_memoria_min}>
            <div className="pdv-app">
                <header className="pdv-topo">
                    <img src="/imagens/logo-local.png" alt="" height="28" />
                    <div className="small text-end lh-sm">
                        <div className="fw-semibold">{eu.empresa.nome}</div>
                        <div className="text-muted">{eu.aparelho.nome}</div>
                    </div>
                </header>
                <main className="pdv-corpo">
                    {tela === 'inicio' && <Inicio eu={eu} irPara={setTela} onDesparear={() => { token.set(null); setEu(null) }} />}
                    {tela === 'cadastrar-pin' && <CadastrarPin onVoltar={voltar} />}
                    {tela === 'impressao' && <TesteImpressao eu={eu} onVoltar={voltar} />}
                </main>
            </div>
        </PinProvider>
    )
}
