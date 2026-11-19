import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react'
import AvisosTopo, { useFilaAvisos } from '../Components/AvisosTopo'

/*
 * Visual do PDV (8.8): ícones, topos, cidade do rodapé, avisos que descem do topo e o tema claro/escuro.
 * Tudo em cima do Bootstrap 5.3 (data-bs-theme); as cores próprias ficam nas variáveis --pdv-* do _pdv.scss.
 */

// ---------- ícones (traço, herdam a cor do texto) ----------
const PATHS = {
    carro: <><path d="M5 17h14M6 17v2M18 17v2M4 13l2-6h12l2 6v4H4z" /><circle cx="8" cy="14" r="1" /><circle cx="16" cy="14" r="1" /></>,
    gota: <path d="M12 3s6 6.5 6 11a6 6 0 0 1-12 0c0-4.5 6-11 6-11z" />,
    carteira: <><rect x="3" y="6" width="18" height="13" rx="2" /><path d="M3 10h18M16 15h2" /></>,
    mais: <path d="M12 5v14M5 12h14" />,
    qr: <><path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4z" /><path d="M14 14h2v2h-2zM18 18h2v2h-2zM18 14h2M14 18v2" /></>,
    impressora: <path d="M6 9V3h12v6M6 18H4v-7h16v7h-2M8 14h8v7H8z" />,
    celular: <><rect x="7" y="2" width="10" height="20" rx="2" /><path d="M11 18h2" /></>,
    voltar: <path d="M15 5l-7 7 7 7" />,
    seta: <path d="M9 6l6 6-6 6" />,
    check: <path d="M5 12.5l4.5 4.5L19 7.5" />,
    alerta: <><path d="M12 3l10 18H2z" /><path d="M12 10v5M12 18h.01" /></>,
    x: <path d="M6 6l12 12M18 6L6 18" />,
    dinheiro: <><rect x="2" y="6" width="20" height="12" rx="2" /><circle cx="12" cy="12" r="2.5" /><path d="M6 12h.01M18 12h.01" /></>,
    pix: <><path d="M12 3l9 9-9 9-9-9z" /><path d="M8.5 12l3.5 3.5 3.5-3.5L12 8.5z" /></>,
    debito: <><rect x="2" y="5" width="20" height="14" rx="2" /><path d="M2 10h20M6 15h4" /></>,
    credito: <><rect x="2" y="5" width="20" height="14" rx="2" /><path d="M2 10h20M15 15h3" /></>,
    conta: <><path d="M6 3h9l3 3v15H6z" /><path d="M9 9h6M9 13h6M9 17h4" /></>,
    relogio: <><path d="M3 12a9 9 0 1 0 3-6.7L3 8" /><path d="M3 3v5h5M12 7v5l3 2" /></>,
    copiar: <><rect x="8" y="8" width="12" height="12" rx="2" /><path d="M16 8V5a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3" /></>,
    camera: <><path d="M4 8h3l2-3h6l2 3h3v11H4z" /><circle cx="12" cy="13" r="3.5" /></>,
    sol: <><circle cx="12" cy="12" r="4" /><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" /></>,
    lua: <path d="M20 14.5A8 8 0 0 1 9.5 4 8 8 0 1 0 20 14.5z" />,
    auto: <><circle cx="12" cy="12" r="9" /><path d="M12 3v18" /><path d="M12 3a9 9 0 0 1 0 18z" fill="currentColor" /></>,
    chave: <><circle cx="8" cy="15" r="4" /><path d="M11 12l9-9M17 6l3 3M15 8l2 2" /></>,
    busca: <><circle cx="11" cy="11" r="7" /><path d="M20 20l-4-4" /></>,
}

export function Icone({ nome, tamanho = 24, espessura = 2, className = '', ...resto }) {
    return (
        <svg width={tamanho} height={tamanho} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={espessura}
            strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" className={className} {...resto}>
            {PATHS[nome]}
        </svg>
    )
}

/** ícone de cada forma de pagamento, pelo tipo */
export const iconeForma = (tipo) => ({ dinheiro: 'dinheiro', pix: 'pix', debito: 'debito', credito: 'credito', conta: 'conta' }[tipo] || 'carteira')

// ---------- tema ----------
const CHAVE_TEMA = 'pdv-tema'
const lerTema = () => { try { return localStorage.getItem(CHAVE_TEMA) || 'auto' } catch { return 'auto' } }
const escuroDoCelular = () => window.matchMedia?.('(prefers-color-scheme: dark)').matches

function aplicarTema(pref) {
    const escuro = pref === 'escuro' || (pref === 'auto' && escuroDoCelular())
    document.documentElement.setAttribute('data-bs-theme', escuro ? 'dark' : 'light')
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', escuro ? '#14171b' : '#c0281b')
}

/** preferência do tema deste aparelho: auto (segue o celular), claro ou escuro */
export function useTema() {
    const [tema, setTemaEstado] = useState(lerTema)
    useEffect(() => {
        aplicarTema(tema)
        if (tema !== 'auto' || !window.matchMedia) return undefined
        const m = window.matchMedia('(prefers-color-scheme: dark)')
        const mudou = () => aplicarTema('auto')
        m.addEventListener?.('change', mudou)
        return () => m.removeEventListener?.('change', mudou)
    }, [tema])
    const setTema = (t) => { try { localStorage.setItem(CHAVE_TEMA, t) } catch { /* sem armazenamento: vale até fechar */ } setTemaEstado(t) }
    return [tema, setTema]
}
// aplica antes do primeiro desenho, para não piscar claro no tema escuro
aplicarTema(lerTema())

/** logo certo para o tema (a versão escura tem o texto claro) */
export function Logo({ altura = 40 }) {
    return (
        <>
            <img src="/imagens/logo-local.png" alt="Local Estacionamento" height={altura} className="pdv-logo pdv-logo-claro" />
            <img src="/imagens/logo-local-escuro.png" alt="Local Estacionamento" height={altura} className="pdv-logo pdv-logo-escuro" />
        </>
    )
}

// ---------- topos ----------
/** topo do Início: logo, aparelho e situação do caixa, com a onda vermelha */
export function TopoInicio({ eu, caixa }) {
    return (
        <header className="pdv-topo-inicio">
            <Logo altura={44} />
            <div className="text-end d-flex flex-column align-items-end gap-1">
                <span className="fw-semibold small">{eu.aparelho.nome}</span>
                {caixa === undefined ? null : caixa
                    ? <span className="pdv-chip pdv-chip-ok"><span className="pdv-ponto" />Caixa desde {caixa.desde}</span>
                    : <span className="pdv-chip">Caixa fechado</span>}
            </div>
            <svg className="pdv-onda" viewBox="0 0 390 26" preserveAspectRatio="none" aria-hidden="true">
                <path d="M0 14 C 90 2, 170 26, 260 16 S 360 6, 390 4 L390 26 L0 26 Z" className="pdv-onda-fundo" />
                <path d="M0 14 C 90 2, 170 26, 260 16 S 360 6, 390 4" className="pdv-onda-linha" strokeWidth="6" />
            </svg>
        </header>
    )
}

/** topo das telas: voltar, título e as bolinhas da etapa ([atual, total], contando de 1) */
export function TopoEtapa({ titulo, onVoltar, passo = null, direita = null }) {
    return (
        <header className="pdv-topo-etapa">
            {onVoltar
                ? <button type="button" className="pdv-voltar" aria-label="Voltar" onClick={onVoltar}><Icone nome="voltar" tamanho={26} espessura={2.6} /></button>
                : <span className="pdv-voltar" />}
            <h1 className="pdv-topo-titulo">{titulo}</h1>
            {direita}
            {passo && passo[1] > 1 && (
                <span className="pdv-passos" role="img" aria-label={`Etapa ${passo[0]} de ${passo[1]}`}>
                    {Array.from({ length: passo[1] }, (_, i) => <span key={i} className={i + 1 === passo[0] ? 'atual' : i + 1 < passo[0] ? 'feito' : ''} />)}
                </span>
            )}
            <svg className="pdv-onda" viewBox="0 0 390 12" preserveAspectRatio="none" aria-hidden="true">
                <path d="M0 8 C 120 2, 240 12, 390 4 L390 12 L0 12 Z" className="pdv-onda-fundo" />
                <path d="M0 8 C 120 2, 240 12, 390 4" className="pdv-onda-linha" strokeWidth="4" />
            </svg>
        </header>
    )
}

/** passo atual numa lista de etapas (apelidos: { dinheiro: 'forma' } contam como a mesma posição) */
export const passoDe = (lista, etapa, apelidos = {}) => {
    const i = lista.indexOf(apelidos[etapa] ?? etapa)
    return i < 0 ? null : [i + 1, lista.length]
}

/** prédios de Santos e o monumento da Tomie Ohtake, no pé do Início */
export function Cidade() {
    return (
        <svg className="pdv-cidade" viewBox="0 0 390 130" preserveAspectRatio="xMidYMax meet" aria-hidden="true">
            <path d="M252 112 L 316 110 C 330 109 330 97 316 97 L 284 96 C 270 95 270 83 284 82 L 330 80 C 348 79 348 63 330 63 L 276 62 C 256 61 254 44 274 42 L 372 14" fill="none" className="pdv-monumento" strokeWidth="6.5" strokeLinecap="round" strokeLinejoin="round" />
            <path d="M300 34 L 374 12 L 366 22 Z" className="pdv-monumento-ponta" />
            <rect x="12" y="66" width="22" height="64" className="pdv-predio-a" />
            <rect x="38" y="50" width="26" height="80" className="pdv-predio-b" />
            <rect x="68" y="74" width="18" height="56" className="pdv-predio-a" />
            <rect x="90" y="40" width="24" height="90" className="pdv-predio-b" />
            <rect x="118" y="62" width="30" height="68" className="pdv-predio-a" />
            <rect x="152" y="54" width="20" height="76" className="pdv-predio-b" />
            <rect x="176" y="80" width="26" height="50" className="pdv-predio-a" />
            <path d="M0 112 C 110 94, 250 124, 390 102 L390 130 L0 130 Z" className="pdv-faixa" />
        </svg>
    )
}

/** campo da placa com cara de placa Mercosul; já vem selecionado com o teclado aberto */
export function PlacaMercosul({ value, onChange, onEnter, id = 'placa' }) {
    return (
        <label className="pdv-placa-mercosul" htmlFor={id}>
            <span className="pdv-placa-faixa" aria-hidden="true"><span>BR</span><span>BRASIL</span><span className="pdv-placa-bandeira" /></span>
            <input id={id} className="pdv-placa" aria-label="Placa" value={value} autoComplete="off" autoCapitalize="characters" autoFocus enterKeyHint="next"
                spellCheck={false} onChange={(e) => onChange(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') onEnter?.() }} />
        </label>
    )
}

// ---------- vibração e som ----------
let audio = null
/** "deu certo" (dois bipes) ou "erro" (um bipe grave), com vibração */
export function sinal(tipo = 'ok') {
    try { navigator.vibrate?.(tipo === 'ok' ? [70, 50, 70] : [220]) } catch { /* sem vibração */ }
    try {
        audio = audio || new (window.AudioContext || window.webkitAudioContext)()
        const tocar = (freq, inicio, dur) => {
            const o = audio.createOscillator(); const g = audio.createGain()
            o.frequency.value = freq; o.type = 'sine'
            g.gain.setValueAtTime(0.0001, audio.currentTime + inicio)
            g.gain.exponentialRampToValueAtTime(0.25, audio.currentTime + inicio + 0.02)
            g.gain.exponentialRampToValueAtTime(0.0001, audio.currentTime + inicio + dur)
            o.connect(g).connect(audio.destination); o.start(audio.currentTime + inicio); o.stop(audio.currentTime + inicio + dur + 0.05)
        }
        if (tipo === 'ok') { tocar(880, 0, 0.12); tocar(1320, 0.14, 0.16) } else tocar(220, 0, 0.3)
    } catch { /* sem som */ }
}

// ---------- avisos (descem do topo da tela; mesmo componente do ERP) ----------
const AvisosCtx = createContext({ ok: () => {}, erro: () => {} })
export const useAvisos = () => useContext(AvisosCtx)
const bipeNoErro = (tipo) => { if (tipo === 'erro') sinal('erro') }

export function AvisosProvider({ children }) {
    const { lista, mostrar, fechar } = useFilaAvisos(bipeNoErro)
    const api = useRef(mostrar)
    api.current = mostrar
    const [valor] = useState(() => ({ ok: (t, titulo) => api.current('ok', t, titulo), erro: (t, titulo) => api.current('erro', t, titulo) }))
    return (
        <AvisosCtx.Provider value={valor}>
            {children}
            <AvisosTopo lista={lista} fechar={fechar} />
        </AvisosCtx.Provider>
    )
}

/** estado de erro da tela que já mostra cada erro como aviso (até o mesmo erro repetido aparece de novo) */
export function useErro() {
    const { erro: avisar } = useAvisos()
    const [erro, set] = useState(null)
    const setErro = useCallback((m) => { set(m); if (m) avisar(m) }, [avisar])
    return [erro, setErro]
}

/** mostra o erro da tela como aviso (some quando o estado muda); limpar: zera o estado para o mesmo erro poder voltar */
export function ErroAviso({ erro, limpar }) {
    const { erro: avisar } = useAvisos()
    useEffect(() => { if (erro) { avisar(erro); limpar?.() } }, [erro])
    return null
}

/** mostra a mensagem de sucesso como aviso verde */
export function OkAviso({ texto, limpar }) {
    const { ok } = useAvisos()
    useEffect(() => { if (texto) { ok(texto); limpar?.() } }, [texto])
    return null
}
