import { useEffect, useRef, useState } from 'react'
import QRCode from 'qrcode'
import { moeda } from './impressao/ticket'
import { cursorNoFim, deDigitos, paraTela } from '../Components/CampoValor'
import { imprimir, preferencia, NOMES_VIA } from './impressao/preferencia'
import { api } from './api'

export { moeda }

export const numero = (v) => (v === '' || v === null || v === undefined ? null : Number(String(v).replace(',', '.')))

const rotuloForma = (n) => { const t = n.replace(/^Cartão de /i, ''); return t.charAt(0).toUpperCase() + t.slice(1) }

/** formas de pagamento do balcão: botões grandes (boleto fica de fora) */
export function Formas({ formas, value, onChange }) {
    return (
        <div className="pdv-opcoes">
            {formas.filter((f) => f.tipo !== 'boleto').map((f) => (
                <button key={f.id} type="button" className={`btn ${Number(value) === f.id ? 'btn-primary' : 'btn-outline-secondary'}`} onClick={() => onChange(f.id)}>{rotuloForma(f.nome)}</button>
            ))}
        </div>
    )
}

/**
 * campo de dinheiro estilo maquininha: sempre mostra 0,00 e cada número digitado entra pelos centavos
 * (1 → 0,01 → 0,15 → 1,50 → 15,00). value e onChange em reais (número).
 */
export function CampoDinheiro({ value, onChange, className = '', ...resto }) {
    return (
        <input {...resto} type="text" inputMode="numeric" autoComplete="off" className={`form-control form-control-lg pdv-valor ${className}`} value={paraTela(value)}
            onFocus={(e) => cursorNoFim(e.target)} onClick={(e) => cursorNoFim(e.target)}
            onChange={(e) => { onChange(deDigitos(e.target.value, 7)); cursorNoFim(e.target) }} />
    )
}

/** dinheiro: quanto o cliente deu e o troco */
export function Troco({ total, value, onChange, erro }) {
    const recebido = Number(value) || 0
    const atalhos = [...new Set([Math.ceil(total / 10) * 10, Math.ceil(total / 50) * 50, 100].filter((v) => v > total))].slice(0, 3)
    return (
        <div className="mb-3">
            <label className="form-label small mb-1" htmlFor="recebido">Valor recebido</label>
            <CampoDinheiro id="recebido" className={erro ? 'is-invalid' : ''} value={value} onChange={onChange} />
            {erro && <div className="invalid-feedback">{erro}</div>}
            <div className="d-flex gap-2 mt-2">
                {atalhos.map((v) => <button key={v} type="button" className="btn btn-sm btn-outline-secondary" onClick={() => onChange(v)}>{moeda(v)}</button>)}
            </div>
            {recebido > 0 && (recebido + 0.001 >= total
                ? <div className="pdv-troco">Troco: <strong>{moeda(recebido - total)}</strong></div>
                : <div className="text-danger small mt-1">Menor que {moeda(total)}</div>)}
        </div>
    )
}

/** a forma escolhida é Pix e o Pix com QR do Inter está ligado */
export const usaPixQr = (eu, forma) => !!eu?.pix_qr && forma?.tipo === 'pix'

/**
 * Pix com QR (8.5): gera o Pix do valor exato no Inter, mostra o QR e o copia e cola e consulta a cada
 * 3 segundos. Pago, chama onPago(pixId) (o PIN e o registro vêm em seguida); se o registro falhar, o
 * botão "Concluir" tenta de novo com o mesmo Pix. onManual: cliente pagou sem o QR (vai conferir no extrato).
 */
export function PixQr({ valor, descricao, onPago, onManual, enviando, erro }) {
    const [pix, setPix] = useState(null)
    const [falha, setFalha] = useState(null)
    const [img, setImg] = useState(null)
    const [resta, setResta] = useState(null)
    const [copiado, setCopiado] = useState(false)
    const [tentativa, setTentativa] = useState(0)
    const atual = useRef(null)
    const avisou = useRef(false)

    // gera (de novo a cada tentativa)
    useEffect(() => {
        let vivo = true
        setPix(null); setFalha(null); setImg(null); avisou.current = false
        api('POST', '/pix', { valor, descricao }).then((j) => { if (vivo) { atual.current = j; setPix(j) } else api('POST', `/pix/${j.id}/cancelar`).catch(() => {}) })
            .catch((e) => vivo && setFalha(e.message))
        return () => { vivo = false }
    }, [valor, descricao, tentativa])

    // saiu da tela sem pagar: tira o QR do ar
    useEffect(() => () => { const p = atual.current; if (p && p.situacao === 'ativa') api('POST', `/pix/${p.id}/cancelar`).catch(() => {}) }, [])

    useEffect(() => { if (pix?.copia_cola) QRCode.toDataURL(pix.copia_cola, { margin: 1, width: 320 }).then(setImg).catch(() => {}) }, [pix?.copia_cola])

    // consulta até mudar de situação
    useEffect(() => {
        if (!pix || pix.situacao !== 'ativa') return undefined
        const fim = Date.now() + (pix.segundos ?? 900) * 1000
        const relogio = setInterval(() => setResta(Math.max(0, Math.round((fim - Date.now()) / 1000))), 1000)
        const consulta = setInterval(() => {
            api('GET', `/pix/${pix.id}`).then((j) => { if (j.situacao !== 'ativa') { atual.current = j; setPix(j) } }).catch(() => { /* rede oscilou: tenta na próxima */ })
        }, 3000)
        return () => { clearInterval(relogio); clearInterval(consulta) }
    }, [pix?.id, pix?.situacao])

    useEffect(() => {
        if (pix?.situacao === 'paga' && !avisou.current) { avisou.current = true; onPago(pix.id) }
    }, [pix?.situacao])

    const copiar = () => {
        navigator.clipboard?.writeText(pix.copia_cola).then(() => { setCopiado(true); setTimeout(() => setCopiado(false), 2000) }).catch(() => {})
    }
    const semQr = onManual && <button type="button" className="btn btn-link w-100 mt-2" disabled={enviando} onClick={onManual}>Cliente pagou sem o QR</button>

    if (falha) {
        return (
            <div className="mt-3">
                <div className="alert alert-danger py-2 small">{falha}</div>
                <button type="button" className="btn btn-primary btn-lg w-100" onClick={() => setTentativa((t) => t + 1)}>Tentar de novo</button>
                {semQr}
            </div>
        )
    }
    if (!pix) return <div className="text-muted mt-3">Gerando o Pix…</div>
    if (pix.situacao === 'paga') {
        return (
            <div className="mt-3">
                <div className="pdv-troco-final"><div>Pix recebido <strong>{moeda(pix.valor)}</strong></div>{pix.pagador && <div className="small">{pix.pagador}</div>}</div>
                {erro && <div className="alert alert-danger py-2 small mt-3">{erro}</div>}
                <button type="button" className="btn btn-primary btn-lg w-100 mt-3" disabled={enviando} onClick={() => onPago(pix.id)}>{enviando ? 'Registrando…' : 'Concluir'}</button>
            </div>
        )
    }
    if (pix.situacao !== 'ativa') {
        return (
            <div className="mt-3">
                <div className="alert alert-warning py-2 small">{pix.situacao === 'expirada' ? 'O QR venceu sem pagamento.' : 'Este QR foi cancelado.'}</div>
                <button type="button" className="btn btn-primary btn-lg w-100" onClick={() => setTentativa((t) => t + 1)}>Gerar outro QR</button>
                {semQr}
            </div>
        )
    }
    return (
        <div className="mt-3 text-center">
            <div className="fw-semibold">Mostre o QR ao cliente</div>
            <div className="pdv-pix-qr my-2">{img ? <img src={img} alt="QR do Pix" width="260" height="260" /> : <div className="text-muted">…</div>}</div>
            <div className="fs-4 fw-bold">{moeda(pix.valor)}</div>
            <div className="small text-muted mb-2">
                <span className="spinner-grow spinner-grow-sm text-success me-1" aria-hidden="true" />
                Aguardando o pagamento{resta !== null && ` · vence em ${Math.floor(resta / 60)}:${String(resta % 60).padStart(2, '0')}`}
            </div>
            <button type="button" className="btn btn-outline-secondary w-100" onClick={copiar}>{copiado ? 'Copiado!' : 'Copiar código Pix (copia e cola)'}</button>
            {semQr}
        </div>
    )
}

/** janela de baixo (confirmações, perguntas) */
export function Folha({ titulo, children, onFechar }) {
    return (
        <div className="pdv-pin" role="dialog" aria-modal="true" aria-label={titulo} onClick={(e) => { if (e.target === e.currentTarget && onFechar) onFechar() }}>
            <div className="pdv-pin-caixa">
                <div className="fw-semibold mb-2">{titulo}</div>
                {children}
            </div>
        </div>
    )
}

/** prévia do papel (é ela que sai no caminho "impressão do Android") */
export function Previa({ linhas, qr, colunas = 48 }) {
    const [img, setImg] = useState(null)
    useEffect(() => { if (qr) QRCode.toDataURL(qr, { margin: 1, width: 220 }).then(setImg).catch(() => {}) }, [qr])
    return (
        <div className="pdv-ticket" style={{ width: colunas === 48 ? '80mm' : '58mm' }}>
            {linhas.map((l, i) => (l === '-' ? <hr key={i} /> : Array.isArray(l)
                ? <div key={i} className="d-flex justify-content-between gap-2"><span>{l[0]}</span><span className="text-end">{l[1]}</span></div>
                : <div key={i} className={l.startsWith('#') ? 'text-center fw-bold fs-5' : 'text-center'}>{l.replace(/^#/, '')}</div>))}
            {img && <div className="text-center my-2"><img src={img} alt="QR do ticket" width="120" height="120" /></div>}
        </div>
    )
}

/**
 * "Deseja imprimir?" (decisão 8 do plano: sempre pergunta). montar(opcoes) devolve os bytes ESC/POS;
 * previa é o mesmo papel na tela.
 */
export function PerguntaImprimir({ titulo, montar, previa, onFim, destaque = null }) {
    const [msg, setMsg] = useState(null)
    const [ocupado, setOcupado] = useState(false)
    const via = preferencia.get().via
    const sim = () => {
        setOcupado(true)
        setMsg(null)
        imprimir(montar).then(() => onFim(true)).catch((e) => setMsg(e.name === 'NotFoundError' ? 'Nenhuma impressora escolhida.' : e.message)).finally(() => setOcupado(false))
    }
    return (
        <Folha titulo={titulo}>
            {destaque}
            <div className="pdv-previa-rolagem">{previa}</div>
            {!via && <div className="small text-muted mb-2">Este celular ainda não tem impressora testada: vai pela impressão do Android. Configure em Início › Teste de impressão.</div>}
            {via && <div className="small text-muted mb-2">Pelo {NOMES_VIA[via]}.</div>}
            {msg && <div className="alert alert-danger py-2 small">{msg}</div>}
            <div className="d-grid gap-2">
                <button type="button" className="btn btn-primary btn-lg" disabled={ocupado} onClick={sim}>{ocupado ? 'Imprimindo…' : 'Imprimir'}</button>
                <button type="button" className="btn btn-outline-secondary btn-lg" disabled={ocupado} onClick={() => onFim(false)}>Não imprimir</button>
            </div>
        </Folha>
    )
}

/** linhas da prévia do ticket de entrada e do recibo (o mesmo conteúdo do papel) */
export function linhasTicket(r, eu) {
    return [eu.empresa.nome, '-', `#Nº ${String(r.numero).padStart(6, '0')}`, r.servico.toUpperCase(), ['Placa', r.placa + (r.modelo ? ` - ${r.modelo}` : '')], ['Tamanho', r.tipo], ['Entrada', r.entrada],
        ...(r.pago ? [['Pago', `${moeda(r.pago.valor)} (${r.pago.forma})`]] : [])]
}

export function linhasRecibo(r, eu) {
    return [eu.empresa.nome, '-', `#RECIBO · Nº ${String(r.numero).padStart(6, '0')}`, ['Placa', r.placa + (r.modelo ? ` - ${r.modelo}` : '')], ['Entrada', r.entrada],
        ...(r.saida ? [['Saída', r.saida], ['Tempo', r.tempo]] : []), '-',
        ...(r.valor !== null && r.valor !== undefined ? [[r.servico, moeda(r.valor)]] : []),
        ...(r.convenio ? [[`Convênio ${r.convenio.nome || ''}`, `-${moeda(r.convenio.valor)}`]] : []),
        ...(r.pago_entrada > 0 && r.saida ? [['Pago na entrada', `-${moeda(r.pago_entrada)}`]] : []),
        ...(r.pago ? [[`Pago (${r.pago.forma})`, moeda(r.pago.valor)], ...(r.pago.observacao ? [r.pago.observacao] : [])] : r.destino ? [['Situação', r.destino]] : [])]
}
