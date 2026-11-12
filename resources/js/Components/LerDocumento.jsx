import { useRef, useState } from 'react'
import { lerArquivo, lerCodigo } from '../lib/leitor'
import { data as fData, moeda } from '../lib/formato'

/**
 * "Ler boleto ou guia": PDF, foto ou código de barras colado. Chama aoLer(resultado, arquivo)
 * com os campos sugeridos; a pessoa confere no formulário antes de salvar.
 */
export default function LerDocumento({ url = '/pagar/ler', aoLer }) {
    const [estado, setEstado] = useState(null) // null | {etapa, progresso} | {erro} | {res}
    const [codigo, setCodigo] = useState('')
    const entrada = useRef(null)
    const lendo = estado && 'etapa' in estado

    async function ler(fazer, arquivo) {
        try {
            const res = await fazer()
            setEstado({ res })
            if (res.tipo !== 'holerite') aoLer(res, arquivo)
        } catch (e) {
            setEstado({ erro: e.message || 'Não deu para ler.' })
        }
    }
    const escolher = (e) => {
        const f = e.target.files[0]
        e.target.value = ''
        if (!f) return
        if (f.size > 10 * 1024 * 1024) { setEstado({ erro: 'Arquivo de até 10 MB.' }); return }
        ler(() => lerArquivo(url, f, (etapa, progresso) => setEstado({ etapa, progresso })), f)
    }
    const colar = () => {
        if (codigo.replace(/\D/g, '').length < 44) { setEstado({ erro: 'O código tem 44, 47 ou 48 números.' }); return }
        setEstado({ etapa: 'texto', progresso: 0 })
        ler(() => lerCodigo(url, codigo), null)
    }

    const r = estado?.res
    return (
        <div className="ler-documento">
            <div className="d-flex flex-wrap align-items-center gap-2">
                <button type="button" className="btn btn-outline-primary" disabled={lendo} onClick={() => entrada.current?.click()}>Ler boleto ou guia (PDF ou foto)</button>
                <input ref={entrada} type="file" accept=".pdf,image/*" className="d-none" onChange={escolher} />
                <div className="input-group input-group-sm ler-codigo">
                    <input className="form-control" placeholder="ou cole o código de barras" value={codigo} onChange={(e) => setCodigo(e.target.value)} inputMode="numeric" disabled={lendo}
                        onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); colar() } }} aria-label="Código de barras ou linha digitável" />
                    <button type="button" className="btn btn-outline-secondary" onClick={colar} disabled={lendo}>Ler</button>
                </div>
            </div>
            {lendo && (
                <div className="small text-muted mt-2" role="status">
                    {estado.etapa === 'ocr'
                        ? <>O PDF é uma imagem: lendo com OCR… {Math.round((estado.progresso || 0) * 100)}%<div className="progress mt-1" style={{ height: 4 }}><div className="progress-bar" style={{ width: `${Math.round((estado.progresso || 0) * 100)}%` }} /></div></>
                        : 'Lendo…'}
                </div>
            )}
            {estado?.erro && <div className="alert alert-danger py-2 small mt-2 mb-0">{estado.erro}</div>}
            {r && (
                <div className={`alert ${r.tipo === 'desconhecido' || r.tipo === 'holerite' ? 'alert-warning' : 'alert-info'} py-2 small mt-2 mb-0`}>
                    <div className="fw-semibold">
                        {r.rotulo}{r.valor ? ` · ${moeda(r.valor)}` : ''}{r.vencimento ? ` · vence ${fData(r.vencimento)}` : ''}
                        {r.conferido && <span className="selo selo-ok ms-2">{r.codigo ? 'Valor conferido pelo código de barras' : 'Valor conferido pela soma da guia'}</span>}
                        {r.por_ocr && <span className="selo ms-2">Lido por OCR</span>}
                    </div>
                    {r.fornecedor && <div>Fornecedor: {r.fornecedor}</div>}
                    {r.avisos?.map((a) => <div key={a}>{a}</div>)}
                    {r.tipo !== 'holerite' && r.tipo !== 'desconhecido' && <div className="text-muted">Confira os campos abaixo antes de lançar.</div>}
                </div>
            )}
        </div>
    )
}
