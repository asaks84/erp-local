import { useCallback, useEffect, useState } from 'react'
import { prepararAgora, prontidao } from '../offline/prontidao'
import { atualizarPacote } from '../offline/fila'
import { usePin } from '../Pin'
import { VERSAO } from '../api'
import { Icone, TopoEtapa, useAvisos } from '../visual'

// instalado como app já abre em tela cheia (pdv.webmanifest); no navegador, só com um toque
const instalado = () => ['fullscreen', 'standalone'].some((m) => window.matchMedia?.(`(display-mode: ${m})`).matches)
const podeTelaCheia = () => !!document.fullscreenEnabled && !instalado()

/** se este celular já abre e trabalha sem internet (app guardado, cópia do sistema, PINs) */
function SemInternet() {
    const [p, setP] = useState(null)
    const [preparando, setPreparando] = useState(false)
    const conferir = useCallback(() => { prontidao().then(setP) }, [])
    useEffect(() => { conferir() }, [conferir])
    const preparar = async () => {
        setPreparando(true)
        await prepararAgora().catch(() => {})
        await atualizarPacote()
        setTimeout(() => { conferir(); setPreparando(false) }, 6000)
    }
    if (!p) return null
    const tudo = p.app && p.pacote && p.pins.length > 0
    const item = (ok, texto) => <li className={ok ? '' : 'text-danger-emphasis'}><Icone nome={ok ? 'check' : 'x'} tamanho={16} /> {texto}</li>
    return (
        <>
            <h2 className="h6 fw-bold">Sem internet</h2>
            <div className={`p-3 mb-1${tudo ? '' : ' border border-warning'}`} style={{ background: 'var(--pdv-cartao)', borderRadius: 16 }}>
                <div className="fw-semibold mb-1">{tudo ? 'Pronto para funcionar sem internet' : 'Ainda não funciona sem internet'}</div>
                <ul className="list-unstyled small mb-2 d-flex flex-column gap-1">
                    {item(p.app, p.app ? 'App guardado no celular' : `App ainda não guardado${p.motivo ? `: ${p.motivo}` : ''}`)}
                    {item(!!p.pacote, p.pacote ? `Cópia do sistema de ${p.pacote.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })}` : 'Sem cópia do sistema ainda')}
                    {item(p.pins.length > 0, p.pins.length ? `PIN guardado de: ${p.pins.join(', ')}` : 'Nenhum PIN guardado: cada pessoa usa o PIN uma vez com internet')}
                </ul>
                <button type="button" className="btn btn-sm btn-outline-secondary" disabled={preparando} onClick={preparar}>{preparando ? 'Preparando…' : 'Preparar agora'}</button>
            </div>
            <p className="small text-muted mb-4">Sem internet funcionam entrada e saída de estadia. Não recarregue nem feche o app à toa sem internet.</p>
        </>
    )
}

const TEMAS = [['auto', 'Automático', 'auto'], ['claro', 'Claro', 'sol'], ['escuro', 'Escuro', 'lua']]

/** Este aparelho: tema da tela, PIN (testar e cadastrar), hora do servidor e pareamento */
export default function Aparelho({ eu, tema, setTema, onVoltar, irPara, onDesparear }) {
    const { pedirPin } = usePin()
    const avisos = useAvisos()
    const [sair, setSair] = useState(false)
    const [cheia, setCheia] = useState(() => !!document.fullscreenElement)
    useEffect(() => {
        const mudou = () => setCheia(!!document.fullscreenElement)
        document.addEventListener('fullscreenchange', mudou)
        return () => document.removeEventListener('fullscreenchange', mudou)
    }, [])
    const telaCheia = () => (cheia
        ? document.exitFullscreen()
        : document.documentElement.requestFullscreen({ navigationUI: 'hide' })
    ).catch(() => avisos.erro('O navegador não deixou entrar em tela cheia.'))
    const testarPin = () => pedirPin('Testar meu PIN')
        .then(({ usuario }) => avisos.ok(`PIN de ${usuario.nome}${usuario.nivel ? ` (${usuario.nivel})` : ''}. Tudo certo.`))
        .catch(() => {})

    return (
        <div>
            <TopoEtapa titulo="Este aparelho" onVoltar={onVoltar} />
            <div className="fw-semibold mb-1">{eu.aparelho.nome}</div>
            <div className="small text-muted mb-4">{eu.empresa.nome} · app {VERSAO}</div>

            <h2 className="h6 fw-bold">Tela</h2>
            <div className="pdv-tema mb-1" role="radiogroup" aria-label="Tema da tela">
                {TEMAS.map(([v, rotulo, icone]) => (
                    <button key={v} type="button" role="radio" aria-checked={tema === v} className={tema === v ? 'escolhido' : ''} onClick={() => setTema(v)}>
                        <Icone nome={icone} tamanho={24} />{rotulo}
                    </button>
                ))}
            </div>
            <p className="small text-muted mb-4">Automático segue o modo claro ou escuro do celular. Vale só para este aparelho.</p>
            {podeTelaCheia() && (
                <>
                    <div className="pdv-lista mb-1">
                        <button type="button" onClick={telaCheia}><Icone nome={cheia ? 'x' : 'tela'} tamanho={22} />{cheia ? 'Sair da tela cheia' : 'Tela cheia'}</button>
                    </div>
                    <p className="small text-muted mb-4">Esconde as barras do celular. Instalado como app (menu do Chrome › Instalar app), o PDV já abre em tela cheia.</p>
                </>
            )}

            <SemInternet />

            <h2 className="h6 fw-bold">PIN</h2>
            <div className="pdv-lista mb-4">
                <button type="button" onClick={testarPin}><Icone nome="check" tamanho={22} />Testar meu PIN</button>
                <button type="button" onClick={() => irPara('cadastrar-pin')}><Icone nome="chave" tamanho={22} />Cadastrar ou trocar meu PIN</button>
            </div>

            <p className="small text-muted text-center">Hora do servidor: {new Date(eu.servidor_hora).toLocaleString('pt-BR')}</p>
            <div className="text-center mt-3">
                {sair
                    ? <button type="button" className="btn btn-danger" onClick={onDesparear}>Confirmar: tirar o pareamento deste celular</button>
                    : <button type="button" className="btn btn-link text-muted" onClick={() => setSair(true)}>Tirar o pareamento deste celular</button>}
            </div>
        </div>
    )
}
