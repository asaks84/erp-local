import { useCallback, useRef, useState } from 'react'

/*
 * Avisos que descem do topo da tela, por cima de tudo (ERP e PDV usam o mesmo).
 * ok: verde, some sozinho com a barrinha de tempo. erro e aviso: ficam até clicar no X, para dar tempo de ler.
 * Estilo em _avisos-topo.scss.
 */
export const DURACAO_OK = 4000

const ICONES = {
    ok: <path d="M5 12.5l4.5 4.5L19 7.5" />,
    erro: <><path d="M12 3l10 18H2z" /><path d="M12 10v5M12 18h.01" /></>,
    aviso: <><circle cx="12" cy="12" r="9" /><path d="M12 11v5M12 8h.01" /></>,
}

function Svg({ children, tamanho = 20, espessura = 2.4 }) {
    return (
        <svg width={tamanho} height={tamanho} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={espessura} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            {children}
        </svg>
    )
}

/** lista: [{ id, tipo: 'ok'|'erro'|'aviso', texto, titulo?, saindo? }] */
export default function AvisosTopo({ lista, fechar }) {
    return (
        <div className="avisos-topo" aria-live="polite">
            {lista.map((a) => (
                <div key={a.id} role={a.tipo === 'ok' ? 'status' : 'alert'} className={`aviso-topo aviso-topo-${a.tipo}${a.saindo ? ' saindo' : ''}`}>
                    <span className="aviso-topo-icone"><Svg espessura={a.tipo === 'ok' ? 3 : 2.4}>{ICONES[a.tipo] || ICONES.aviso}</Svg></span>
                    <span className="aviso-topo-texto">
                        {a.titulo && <strong>{a.titulo}</strong>}
                        <span>{a.texto}</span>
                    </span>
                    {a.tipo !== 'ok' && (
                        <button type="button" className="aviso-topo-fechar" aria-label="Fechar aviso" onClick={() => fechar(a.id)}>
                            <Svg tamanho={22} espessura={2.6}><path d="M6 6l12 12M18 6L6 18" /></Svg>
                        </button>
                    )}
                    {a.tipo === 'ok' && <span className="aviso-topo-tempo" style={{ animationDuration: `${DURACAO_OK}ms` }} />}
                </div>
            ))}
        </div>
    )
}

/** fila de avisos: mostrar(tipo, texto, titulo) e fechar(id); o ok some sozinho, repetido não empilha */
export function useFilaAvisos(aoMostrar) {
    const [lista, setLista] = useState([])
    const seq = useRef(0)
    const fechar = useCallback((id) => {
        setLista((l) => l.map((a) => (a.id === id ? { ...a, saindo: true } : a)))
        setTimeout(() => setLista((l) => l.filter((a) => a.id !== id)), 250)
    }, [])
    const mostrar = useCallback((tipo, texto, titulo) => {
        if (!texto) return
        const id = ++seq.current
        setLista((l) => [...l.filter((a) => !(a.texto === texto && !a.saindo)).slice(-2), { id, tipo, texto, titulo }])
        if (tipo === 'ok') setTimeout(() => fechar(id), DURACAO_OK)
        aoMostrar?.(tipo)
    }, [fechar, aoMostrar])
    return { lista, mostrar, fechar }
}
