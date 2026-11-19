import { useEffect, useState } from 'react'
import { usePin } from '../Pin'
import { VERSAO } from '../api'
import { Icone, TopoEtapa, useAvisos } from '../visual'

// instalado como app já abre em tela cheia (pdv.webmanifest); no navegador, só com um toque
const instalado = () => ['fullscreen', 'standalone'].some((m) => window.matchMedia?.(`(display-mode: ${m})`).matches)
const podeTelaCheia = () => !!document.fullscreenEnabled && !instalado()

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
