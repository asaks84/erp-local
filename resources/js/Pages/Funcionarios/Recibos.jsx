import { Link, router, usePage } from '@inertiajs/react'
import { useRef, useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import AbasFuncionarios from '../../Components/AbasFuncionarios'
import Campo from '../../Components/Campo'
import CampoData from '../../Components/CampoData'
import Janela from '../../Components/Janela'
import { lerArquivo } from '../../lib/leitor'
import { data as fData, mesAno, moeda } from '../../lib/formato'
import { SeloDespesa } from '../Pagar/Comum'

export default function Recibos({ mes, meses, recibos, funcionarios }) {
    const { errors } = usePage().props
    const entrada = useRef(null)
    const [arquivo, setArquivo] = useState(null)
    const [lidos, setLidos] = useState(null) // recibos lidos para conferir
    const [estado, setEstado] = useState(null)
    const [enviando, setEnviando] = useState(false)
    const [detalhe, setDetalhe] = useState(null)

    async function escolher(e) {
        const f = e.target.files[0]
        e.target.value = ''
        if (!f) return
        setArquivo(f)
        setLidos(null)
        try {
            const j = await lerArquivo('/funcionarios/recibos/ler', f, (etapa, progresso) => setEstado({ etapa, progresso }))
            setEstado(j.recibos.length ? null : { erro: 'Não achei recibos de salário neste arquivo.' })
            setLidos(j.recibos.map((r) => ({ ...r, funcionario_id: r.funcionario_id ?? '', importar: !r.ja_importado })))
        } catch (err) {
            setEstado({ erro: err.message })
        }
    }
    const mudar = (i, campo, v) => setLidos((l) => l.map((r, k) => (k === i ? { ...r, [campo]: v } : r)))
    const escolhidos = (lidos || []).filter((r) => r.importar)

    function importar() {
        const itens = escolhidos.map(({ conferido, ja_importado, importar: _, ...r }) => r) // eslint-disable-line no-unused-vars
        router.post('/funcionarios/recibos', { arquivo, itens }, {
            forceFormData: true, preserveScroll: true,
            onStart: () => setEnviando(true), onFinish: () => setEnviando(false),
            onSuccess: () => { setLidos(null); setArquivo(null) },
        })
    }

    const total = (campo) => recibos.reduce((s, r) => s + (r[campo] || 0), 0)
    return (
        <div className="pagina">
            <AbasFuncionarios />
            <TituloPagina titulo="Recibos de salário" descricao="Importe o PDF de recibos do contador: o líquido de cada um vira uma conta a pagar no 5º dia útil." />

            <div className="card mb-3">
                <div className="card-body">
                    <button type="button" className="btn btn-primary" onClick={() => entrada.current?.click()} disabled={estado && 'etapa' in estado && !lidos}>Importar recibos (PDF do contador)</button>
                    <input ref={entrada} type="file" accept=".pdf,image/*" className="d-none" onChange={escolher} />
                    {arquivo && <span className="small text-muted ms-2">{arquivo.name}</span>}
                    {estado?.etapa && !lidos && <div className="small text-muted mt-2" role="status">{estado.etapa === 'ocr' ? `O PDF é uma imagem: lendo com OCR… ${Math.round((estado.progresso || 0) * 100)}%` : 'Lendo…'}</div>}
                    {estado?.erro && <div className="alert alert-danger py-2 small mt-2 mb-0">{estado.erro}</div>}
                    {errors && Object.values(errors)[0] && <div className="alert alert-danger py-2 small mt-2 mb-0">{Object.values(errors)[0]}</div>}
                </div>
                {lidos && lidos.length > 0 && (
                    <>
                        <div className="table-responsive border-top">
                            <table className="table align-middle mb-0 recibos-lidos">
                                <thead><tr><th /><th>Funcionário</th><th className="text-end">Bruto</th><th className="text-end">Descontos</th><th className="text-end">Líquido</th><th className="text-end d-none d-md-table-cell">FGTS</th><th style={{ minWidth: 170 }}>Pagar em</th></tr></thead>
                                <tbody>
                                    {lidos.map((r, i) => (
                                        <tr key={r.matricula + r.competencia} className={r.importar ? '' : 'inativo'}>
                                            <td><input type="checkbox" className="form-check-input" checked={r.importar} disabled={r.ja_importado} onChange={(e) => mudar(i, 'importar', e.target.checked)} aria-label={`Importar ${r.nome}`} /></td>
                                            <td>
                                                <div className="fw-semibold">{r.nome}</div>
                                                <div className="small text-muted">{[r.funcao, `matrícula ${r.matricula}`, mesAno(r.competencia + '-01')].filter(Boolean).join(' · ')}</div>
                                                {r.ja_importado
                                                    ? <span className="selo">Já importado</span>
                                                    : <select className="form-select form-select-sm mt-1" value={r.funcionario_id} onChange={(e) => mudar(i, 'funcionario_id', e.target.value)} aria-label="Funcionário">
                                                        <option value="">Cadastrar como novo funcionário</option>
                                                        {funcionarios.map((f) => <option key={f.id} value={f.id}>{f.nome}{f.matricula ? ` (matr. ${f.matricula})` : ''}</option>)}
                                                    </select>}
                                                {!r.conferido && <div className="small text-danger">Bruto − descontos não bate com o líquido: confira.</div>}
                                            </td>
                                            <td className="text-end">{moeda(r.vencimentos)}</td>
                                            <td className="text-end">{moeda(r.descontos)}</td>
                                            <td className="text-end fw-semibold text-nowrap">{moeda(r.liquido)}</td>
                                            <td className="text-end d-none d-md-table-cell">{r.fgts != null ? moeda(r.fgts) : ''}</td>
                                            <td><CampoData rotulo="Data" value={r.vencimento} onChange={(v) => mudar(i, 'vencimento', v)} className="mb-0" /></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <div className="card-footer d-flex flex-wrap gap-2 justify-content-end align-items-center">
                            <span className="small text-muted me-auto">O PDF fica anexado em cada conta. Total líquido: <strong>{moeda(escolhidos.reduce((s, r) => s + r.liquido, 0))}</strong></span>
                            <button type="button" className="btn btn-outline-secondary" onClick={() => { setLidos(null); setArquivo(null) }}>Cancelar</button>
                            <button type="button" className="btn btn-primary" disabled={!escolhidos.length || enviando} onClick={importar}>Importar {escolhidos.length} recibo{escolhidos.length === 1 ? '' : 's'}</button>
                        </div>
                    </>
                )}
            </div>

            <div className="card">
                <div className="card-header d-flex flex-wrap align-items-center gap-2">
                    <span className="me-auto">Recibos importados</span>
                    {meses.length > 0 && (
                        <Campo as="select" rotulo="Mês" value={mes || ''} onChange={(e) => router.get('/funcionarios/recibos', { mes: e.target.value }, { preserveScroll: true })} className="mb-0">
                            {meses.map((m) => <option key={m} value={m}>{mesAno(m + '-01')}</option>)}
                        </Campo>
                    )}
                </div>
                <div className="table-responsive">
                    <table className="table table-hover align-middle mb-0">
                        <thead><tr><th>Funcionário</th><th className="text-end d-none d-md-table-cell">Bruto</th><th className="text-end d-none d-md-table-cell">Descontos</th><th className="text-end">Líquido</th><th className="text-end d-none d-md-table-cell">FGTS</th><th>Conta</th></tr></thead>
                        <tbody>
                            {recibos.length === 0 && <tr><td colSpan="6" className="text-center text-muted py-4">Nenhum recibo importado ainda.</td></tr>}
                            {recibos.map((r) => (
                                <tr key={r.id} role="button" onClick={() => setDetalhe(r)}>
                                    <td><div className="fw-semibold">{r.funcionario}</div><div className="small text-muted">{r.funcao}</div></td>
                                    <td className="text-end d-none d-md-table-cell">{moeda(r.vencimentos)}</td>
                                    <td className="text-end d-none d-md-table-cell">{moeda(r.descontos)}</td>
                                    <td className="text-end fw-semibold text-nowrap">{moeda(r.liquido)}</td>
                                    <td className="text-end d-none d-md-table-cell">{r.fgts != null ? moeda(r.fgts) : ''}</td>
                                    <td>{r.estado && <SeloDespesa estado={r.estado} />}<div className="small text-muted">{r.pago_em ? `pago ${fData(r.pago_em)}` : r.vencimento ? `vence ${fData(r.vencimento)}` : ''}</div></td>
                                </tr>
                            ))}
                        </tbody>
                        {recibos.length > 1 && (
                            <tfoot><tr className="fw-semibold"><td>Total</td><td className="text-end d-none d-md-table-cell">{moeda(total('vencimentos'))}</td><td className="text-end d-none d-md-table-cell">{moeda(total('descontos'))}</td><td className="text-end">{moeda(total('liquido'))}</td><td className="text-end d-none d-md-table-cell">{moeda(total('fgts'))}</td><td /></tr></tfoot>
                        )}
                    </table>
                </div>
            </div>

            <Janela aberta={!!detalhe} titulo={detalhe ? `${detalhe.funcionario} · ${mesAno(mes + '-01')}` : ''} onFechar={() => setDetalhe(null)} onEnviar={() => setDetalhe(null)} rotuloOk="Fechar">
                {detalhe && (
                    <>
                        <table className="table table-sm small">
                            <tbody>
                                {detalhe.itens.map((x, k) => <tr key={k}><td className="text-muted">{x.cod}</td><td>{x.descricao}</td><td className="text-end text-nowrap">{x.valores.map((v) => moeda(v)).join(' · ')}</td></tr>)}
                            </tbody>
                        </table>
                        <div className="d-flex justify-content-between"><span>Líquido</span><strong>{moeda(detalhe.liquido)}</strong></div>
                        {detalhe.despesa_id && <div className="mt-2 small"><Link href={`/pagar?estado=todas&q=${encodeURIComponent(detalhe.funcionario)}`}>Ver a conta em Contas a pagar</Link></div>}
                    </>
                )}
            </Janela>
        </div>
    )
}
