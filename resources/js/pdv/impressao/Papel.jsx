import { useEffect, useState } from 'react'
import QRCode from 'qrcode'
import { nomeDaLinha } from './modelo'

function Qr({ conteudo, tam }) {
    const [img, setImg] = useState(null)
    const px = { P: 90, M: 120, G: 150 }[tam] || 120
    useEffect(() => { QRCode.toDataURL(conteudo, { margin: 1, width: px * 2 }).then(setImg).catch(() => {}) }, [conteudo, px])
    return <div className="text-center my-1">{img ? <img src={img} alt="QR do ticket" width={px} height={px} /> : <div style={{ height: px }} />}</div>
}

function Saida({ s, logo }) {
    if (s.tipo === 'hr') return <hr />
    if (s.tipo === 'espaco') return <div style={{ height: `${s.alt * 1.35}em` }} />
    if (s.tipo === 'caixa') return <div className="pdv-carimbo" />
    if (s.tipo === 'logo') return logo ? <div className="text-center my-1"><img src={logo} alt="" style={{ maxWidth: '70%', maxHeight: 70 }} /></div> : null
    if (s.tipo === 'qr') return <Qr conteudo={s.conteudo} tam={s.tam} />
    const lados = s.b !== undefined && s.b !== null && s.b !== ''
    const just = lados ? 'space-between' : s.al === 'c' ? 'center' : s.al === 'd' ? 'flex-end' : 'flex-start'
    return (
        <div className="d-flex gap-2" style={{ justifyContent: just, textAlign: s.al === 'c' ? 'center' : s.al === 'd' ? 'right' : 'left', fontWeight: s.neg ? 700 : 400, fontSize: s.gra ? '1.7em' : undefined, lineHeight: 1.3 }}>
            <span>{s.a}</span>{lados && <span className="text-end">{s.b}</span>}
        </div>
    )
}

/**
 * O papel na tela, igual ao que sai na impressora (lista = blocos() de modelo.js).
 * No editor do ERP: onSelecionar torna cada bloco clicável; barra aparece em cima do selecionado e abaixo, embaixo dele.
 */
export default function Papel({ lista, colunas = 48, logo = null, selecionado = null, onSelecionar = null, barra = null, abaixo = null }) {
    return (
        <div className={`pdv-ticket${onSelecionar ? ' papel-editor' : ''}`} style={{ width: colunas === 48 ? '80mm' : '58mm' }}>
            {lista.map((b) => {
                const sel = selecionado === b.idx
                const conteudo = b.saida.length
                    ? b.saida.map((s, i) => <Saida key={i} s={s} logo={logo} />)
                    : onSelecionar ? <div className="papel-vazio">{nomeDaLinha(b.linha)} (vazio neste exemplo)</div> : null
                if (!onSelecionar) return <div key={b.idx}>{conteudo}</div>
                return (
                    <div key={b.idx} className="papel-bloco-caixa">
                        {sel && barra}
                        <button type="button" className={`papel-bloco${sel ? ' sel' : ''}`} aria-pressed={sel} aria-label={`Selecionar ${nomeDaLinha(b.linha)}`} onClick={() => onSelecionar(b.idx)}>{conteudo}</button>
                        {sel && abaixo}
                    </div>
                )
            })}
        </div>
    )
}
