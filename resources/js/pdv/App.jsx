import { useCallback, useEffect, useState } from 'react'
import { api, token } from './api'
import Parear from './telas/Parear'
import Inicio from './telas/Inicio'
import Entrada from './telas/Entrada'
import Saida from './telas/Saida'
import Aparelho from './telas/Aparelho'
import CadastrarPin from './telas/CadastrarPin'
import TesteImpressao from './telas/TesteImpressao'
import Estadias from './telas/Estadias'
import EstadiaEntrada from './telas/EstadiaEntrada'
import EstadiaSaida from './telas/EstadiaSaida'
import Caixa from './telas/Caixa'
import Lavagens from './telas/Lavagens'
import { PinProvider } from './Pin'
import { AvisosProvider, Cidade, Logo, useAvisos, useTema } from './visual'

/**
 * PDV: pareamento, PIN de cada pessoa, impressão (8.1), estadias (8.2), caixa (8.3), lavagens (8.4),
 * Pix com QR (8.5) e o visual novo com tema claro/escuro (8.8). Roteiro: docs/15-plano-pdv.md.
 */
export default function App() {
    return <AvisosProvider><Pdv /></AvisosProvider>
}

function Pdv() {
    const avisos = useAvisos()
    const [tema, setTema] = useTema()
    const [eu, setEu] = useState(null)       // dados do aparelho e da empresa
    const [erro, setErro] = useState(null)
    const [tela, setTela] = useState('inicio')
    const [origem, setOrigem] = useState('inicio') // para onde voltar depois de uma entrada/saída de estadia
    const [carregando, setCarregando] = useState(!!token.get())
    const [estadiaId, setEstadiaId] = useState(null)
    const [lavagem, setLavagem] = useState(null)  // { inicio: 'nova' | 'entrega', id }
    const [opcoes, setOpcoes] = useState(null)   // tamanhos, formas e convênios das estadias

    const carregar = useCallback(() => {
        if (!token.get()) { setEu(null); setCarregando(false); return }
        setCarregando(true)
        api('GET', '/eu').then((j) => { setEu(j); setErro(null) }).catch((e) => {
            if (e.parear) { token.set(null); setEu(null) } else setErro(e.message)
        }).finally(() => setCarregando(false))
    }, [])
    useEffect(() => { carregar() }, [carregar])
    // volta ao Início: relê os dados do aparelho em silêncio (ex.: o Pix com QR foi ligado na gestão depois de o app abrir)
    useEffect(() => {
        if (tela !== 'inicio' || !token.get()) return
        api('GET', '/eu').then(setEu).catch(() => { /* mantém o que tinha */ })
    }, [tela])
    useEffect(() => { window.scrollTo(0, 0) }, [tela])

    if (carregando) return <div className="pdv-centro text-muted"><Logo altura={48} />Carregando…</div>
    if (!eu) {
        return erro
            ? <div className="pdv-centro"><Logo altura={48} /><div className="alert alert-danger">{erro}</div><button className="btn btn-primary btn-lg" onClick={carregar}>Tentar de novo</button></div>
            : <Parear onPareado={carregar} />
    }

    const voltar = () => setTela('inicio')
    const carregarOpcoes = () => api('GET', '/estadias/opcoes').then(setOpcoes).catch((e) => avisos.erro(e.message))
    const abrirEstadias = () => { setTela('estadias'); carregarOpcoes() }
    const estadiaEntrada = (de) => { setOrigem(de); setTela('estadia-entrada'); if (!opcoes) carregarOpcoes() }
    const estadiaSaida = (id, de) => { setOrigem(de); setEstadiaId(id); setTela('estadia-saida'); if (!opcoes) carregarOpcoes() }
    const feitoEstadia = (msg) => { avisos.ok(msg); setTela(origem) }
    const abrirLavagem = (inicio, id = null) => { setLavagem({ inicio, id }); setTela('lavagens') }

    if (tela === 'inicio') {
        return (
            <PinProvider memoriaMin={eu.pin_memoria_min}>
                <Cidade />
                <div className="pdv-app"><Inicio eu={eu} irPara={(t) => (t === 'estadias' ? abrirEstadias() : t === 'lavagens' ? abrirLavagem(null) : setTela(t))} /></div>
            </PinProvider>
        )
    }
    const carregandoOpcoes = <div className="text-muted py-4 text-center">Carregando…</div>
    return (
        <PinProvider memoriaMin={eu.pin_memoria_min}>
            <Cidade />
            <div className="pdv-app">
                <main className="pdv-corpo">
                    {tela === 'entrada' && <Entrada onVoltar={voltar} onEstadia={() => estadiaEntrada('inicio')} onLavagem={() => abrirLavagem('nova')} />}
                    {tela === 'saida' && <Saida onVoltar={voltar} onEstadia={(id) => estadiaSaida(id, 'inicio')} onLavagem={(id) => abrirLavagem('entrega', id)} />}
                    {tela === 'estadias' && <Estadias onVoltar={voltar} onEntrada={() => estadiaEntrada('estadias')} onSaida={(id) => estadiaSaida(id, 'estadias')} />}
                    {tela === 'estadia-entrada' && (opcoes ? <EstadiaEntrada eu={eu} opcoes={opcoes} onVoltar={() => setTela(origem === 'inicio' ? 'entrada' : 'estadias')} onFeito={feitoEstadia} /> : carregandoOpcoes)}
                    {tela === 'estadia-saida' && (opcoes ? <EstadiaSaida eu={eu} opcoes={opcoes} id={estadiaId} onVoltar={() => setTela(origem)} onFeito={feitoEstadia} /> : carregandoOpcoes)}
                    {tela === 'caixa' && <Caixa eu={eu} onVoltar={voltar} />}
                    {tela === 'lavagens' && <Lavagens eu={eu} inicio={lavagem?.inicio} entregaInicial={lavagem?.id}
                        onVoltar={() => setTela(lavagem?.inicio === 'nova' ? 'entrada' : 'inicio')} onSair={voltar} />}
                    {tela === 'aparelho' && <Aparelho eu={eu} tema={tema} setTema={setTema} onVoltar={voltar} irPara={setTela} onDesparear={() => { token.set(null); setEu(null) }} />}
                    {tela === 'cadastrar-pin' && <CadastrarPin onVoltar={() => setTela('aparelho')} />}
                    {tela === 'impressao' && <TesteImpressao eu={eu} onVoltar={voltar} />}
                </main>
            </div>
        </PinProvider>
    )
}
