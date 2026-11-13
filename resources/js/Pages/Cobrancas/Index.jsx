import { Link, router, usePage } from '@inertiajs/react'
import { useEffect, useRef, useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import Paginacao from '../../Components/Paginacao'
import CampoData from '../../Components/CampoData'
import Icone from '../../Components/Icone'
import { SeloCobranca, competencia } from '../../Components/Dinheiro'
import { data as fData, moeda } from '../../lib/formato'

export default function CobrancasIndex({ mes, cobrancas, filtros, resumo }) {
    const { flash } = usePage().props
    const [q, setQ] = useState(filtros.q)
    const primeira = useRef(true)
    const ir = (mudar) => router.get('/cobrancas', { mes, q: q || undefined, situacao: filtros.situacao || undefined, ...mudar }, { preserveState: true, replace: true })
    useEffect(() => {
        if (primeira.current) { primeira.current = false; return }
        const t = setTimeout(() => ir({}), 300)
        return () => clearTimeout(t)
    }, [q]) // eslint-disable-line react-hooks/exhaustive-deps

    const imprimirIds = flash?.imprimir || []
    const urlImprimir = (ids) => '/cobrancas/imprimir?' + ids.map((id) => `ids[]=${id}`).join('&')
    const daPagina = cobrancas.data.filter((c) => c.situacao !== 'cancelada').map((c) => c.id)

    return (
        <div className="pagina">
            <TituloPagina titulo="Cobranças">
                <Link href={`/cobrancas/gerar?mes=${mes}`} className="btn btn-primary d-inline-flex align-items-center gap-2"><Icone nome="mais" /> Emitir cobranças do mês</Link>
            </TituloPagina>
            {imprimirIds.length > 0 && (
                <div className="alert alert-success d-flex flex-wrap align-items-center gap-2">
                    <span className="flex-grow-1">{imprimirIds.length} cobrança(s) prontas para imprimir.</span>
                    <a href={urlImprimir(imprimirIds)} target="_blank" rel="noopener" className="btn btn-sm btn-success d-inline-flex align-items-center gap-1"><Icone nome="impressora" tamanho={16} /> Imprimir</a>
                </div>
            )}
            <div className="row g-2 mb-1 align-items-start">
                <div className="col-6 col-md-2"><CampoData mes rotulo="Mês" value={mes} onChange={(v) => v && ir({ mes: v })} /></div>
                <div className="col-6 col-md-3">
                    <select className="form-select" style={{ height: 58 }} value={filtros.situacao} onChange={(e) => ir({ situacao: e.target.value || undefined })} aria-label="Situação">
                        <option value="">Todas</option><option value="aberta">Em aberto</option><option value="parcial">Parcialmente pagas</option><option value="paga">Pagas</option><option value="levada">Levadas para a próxima</option><option value="cancelada">Canceladas</option>
                    </select>
                </div>
                <div className="col-md-4"><input type="search" className="form-control" style={{ height: 58 }} placeholder="Cliente ou número" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Buscar" /></div>
                <div className="col-md-3 text-md-end small text-muted pt-md-2">{resumo.emitidas} emitida(s) · {moeda(resumo.total)}<br />{resumo.pagas} paga(s)</div>
            </div>
            <div className="card">
                <div className="table-responsive">
                    <table className="table table-hover align-middle mb-0">
                        <thead><tr><th>Nº</th><th>Cliente</th><th className="d-none d-md-table-cell">Vencimento</th><th className="text-end">Total</th><th>Situação</th></tr></thead>
                        <tbody>
                            {cobrancas.data.length === 0 && <tr><td colSpan="5" className="text-center text-muted py-4">Nenhuma cobrança de {competencia(mes)}.</td></tr>}
                            {cobrancas.data.map((c) => (
                                <tr key={c.id} role="link" style={{ cursor: 'pointer' }} onClick={() => router.visit(`/cobrancas/${c.id}`)}>
                                    <td className="text-muted">{c.numero}</td>
                                    <td>{c.cliente.nome}</td>
                                    <td className="d-none d-md-table-cell">{fData(c.vencimento)}</td>
                                    <td className="text-end text-nowrap">{moeda(c.total)}</td>
                                    <td><SeloCobranca situacao={c.situacao} />{c.boleto && <span className={`selo ms-1 ${{ recebido: 'selo-ok', atrasado: 'selo-erro', falha: 'selo-erro' }[c.boleto] || ''}`} title="Boleto do Inter">{{ em_processamento: 'Boleto em emissão', a_receber: 'Boleto', atrasado: 'Boleto vencido', recebido: 'Boleto pago', cancelado: 'Boleto cancelado', expirado: 'Boleto baixado', falha: 'Boleto com falha' }[c.boleto]}</span>}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
            <div className="d-flex justify-content-between align-items-center flex-wrap gap-2">
                {daPagina.length > 0 ? <a href={urlImprimir(daPagina)} target="_blank" rel="noopener" className="btn btn-sm btn-outline-secondary mt-3 d-inline-flex align-items-center gap-1"><Icone nome="impressora" tamanho={16} /> Imprimir as desta página</a> : <span />}
                <Paginacao pagina={cobrancas} />
            </div>
        </div>
    )
}
