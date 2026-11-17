import { useEffect, useState } from 'react'
import QRCode from 'qrcode'
import { moeda } from './impressao/ticket'
import { cursorNoFim, deDigitos, paraTela } from '../Components/CampoValor'
import { imprimir, preferencia, NOMES_VIA } from './impressao/preferencia'

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
