import { useCallback, useEffect, useState } from 'react'
import { api, token } from './api'
import Parear from './telas/Parear'
import Inicio from './telas/Inicio'
import CadastrarPin from './telas/CadastrarPin'
import TesteImpressao from './telas/TesteImpressao'
import Estadias from './telas/Estadias'
import EstadiaEntrada from './telas/EstadiaEntrada'
import EstadiaSaida from './telas/EstadiaSaida'
import Caixa from './telas/Caixa'
import Lavagens from './telas/Lavagens'
import { PinProvider } from './Pin'

/**
 * PDV: pareamento do aparelho, PIN de cada pessoa, teste de impressão (8.1) e estadias (8.2).
 * Lavagens e caixa entram nas próximas partes (docs/15-plano-pdv.md).
 */
export default function App() {
    const [eu, setEu] = useState(null)       // dados do aparelho e da empresa
    const [erro, setErro] = useState(null)
    const [tela, setTela] = useState('inicio')
    const [carregando, setCarregando] = useState(!!token.get())
    const [estadiaId, setEstadiaId] = useState(null)
    const [opcoes, setOpcoes] = useState(null)   // tamanhos, formas e convênios das estadias
    const [aviso, setAviso] = useState(null)

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
    const abrirEstadias = () => {
        setTela('estadias')
        api('GET', '/estadias/opcoes').then(setOpcoes).catch((e) => setAviso(e.message))
    }
    const feitoEstadia = (msg) => { setAviso(msg); setTela('estadias'); window.scrollTo(0, 0) }
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
                    {tela === 'inicio' && <Inicio eu={eu} irPara={(t) => (t === 'estadias' ? abrirEstadias() : setTela(t))} onDesparear={() => { token.set(null); setEu(null) }} />}
                    {tela === 'estadias' && (
                        <Estadias onVoltar={voltar} aviso={aviso} limparAviso={() => setAviso(null)}
                            onEntrada={() => { setAviso(null); setTela('estadia-entrada') }} onSaida={(id) => { setAviso(null); setEstadiaId(id); setTela('estadia-saida') }} />
                    )}
                    {tela === 'estadia-entrada' && (opcoes ? <EstadiaEntrada eu={eu} opcoes={opcoes} onVoltar={() => setTela('estadias')} onFeito={feitoEstadia} /> : <div className="text-muted">Carregando…</div>)}
                    {tela === 'estadia-saida' && (opcoes ? <EstadiaSaida eu={eu} opcoes={opcoes} id={estadiaId} onVoltar={() => setTela('estadias')} onFeito={feitoEstadia} /> : <div className="text-muted">Carregando…</div>)}
                    {tela === 'caixa' && <Caixa eu={eu} onVoltar={voltar} />}
                    {tela === 'lavagens' && <Lavagens eu={eu} onVoltar={voltar} />}
                    {tela === 'cadastrar-pin' && <CadastrarPin onVoltar={voltar} />}
                    {tela === 'impressao' && <TesteImpressao eu={eu} onVoltar={voltar} />}
                </main>
            </div>
        </PinProvider>
    )
}
