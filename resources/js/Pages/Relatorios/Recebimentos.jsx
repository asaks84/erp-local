import { Link, router, useForm } from '@inertiajs/react'
import { useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import CampoData from '../../Components/CampoData'
import Campo from '../../Components/Campo'
import Janela from '../../Components/Janela'
import { dataHora, data as fData, moeda } from '../../lib/formato'
import { quando } from '../../Components/Dinheiro'
import { usePode } from '../../lib/permissoes'

const ORIGEM = { gestao: 'Gestão', extrato: 'Extrato', importacao: 'Sistema atual', pdv: 'PDV', inter: 'Inter' }

export default function Recebimentos({ filtros, resumo, por_forma, por_categoria, formas, pagamentos, limitado, periodo }) {
    const pode = usePode()
    const [f, setF] = useState(filtros)
    const [estornar, setEstornar] = useState(null)
    const form = useForm({ motivo: '' })
    const filtrar = (novo) => {
        const x = { ...f, ...novo }
        setF(x)
        if (x.de && x.ate) router.get('/relatorios/recebimentos', Object.fromEntries(Object.entries(x).filter(([, v]) => v)), { preserveState: true, replace: true })
    }
    const csv = '/relatorios/recebimentos?csv=1&' + new URLSearchParams(Object.fromEntries(Object.entries(filtros).filter(([, v]) => v))).toString()

    return (
        <div className="pagina">
            <TituloPagina titulo="Recebimentos" descricao={periodo}>
                <a href={csv} className="btn btn-outline-secondary">Baixar planilha</a>
            </TituloPagina>
            <div className="row g-2 mb-2">
                <div className="col-6 col-md-3"><CampoData rotulo="De" value={f.de} onChange={(v) => filtrar({ de: v })} /></div>
                <div className="col-6 col-md-3"><CampoData rotulo="Até" value={f.ate} onChange={(v) => filtrar({ ate: v })} /></div>
                <div className="col-md-3">
                    <Campo as="select" rotulo="Forma" value={f.forma} onChange={(e) => filtrar({ forma: e.target.value })}>
                        <option value="">Todas</option>
                        {formas.map((x) => <option key={x.id} value={x.id}>{x.nome}</option>)}
                    </Campo>
                </div>
            </div>

            <div className="row g-3 mb-4">
                <div className="col-6 col-md-3"><div className="card card-body"><div className="text-muted small">Recebido</div><div className="fs-4 fw-bold">{moeda(resumo.total)}</div><div className="small text-muted">{resumo.qtd} pagamento(s)</div></div></div>
                <div className="col-6 col-md-3"><div className="card card-body"><div className="text-muted small">Taxas</div><div className="fs-4 fw-bold">{moeda(resumo.taxa)}</div></div></div>
                <div className="col-6 col-md-3"><div className="card card-body"><div className="text-muted small">Líquido</div><div className="fs-4 fw-bold">{moeda(resumo.liquido)}</div></div></div>
            </div>

            <div className="row g-4 mb-4">
                <div className="col-md-6">
                    <div className="card h-100">
                        <div className="card-header">Por forma de pagamento</div>
                        <ul className="list-group list-group-flush">
                            {por_forma.length === 0 && <li className="list-group-item text-muted">Nada no período.</li>}
                            {por_forma.map((x) => <li key={x.nome} className="list-group-item d-flex justify-content-between"><span>{x.nome} <span className="small text-muted">({x.qtd})</span></span><strong>{moeda(x.total)}</strong></li>)}
                        </ul>
                    </div>
                </div>
                <div className="col-md-6">
                    <div className="card h-100">
                        <div className="card-header">Pelo que foi pago</div>
                        <ul className="list-group list-group-flush">
                            {por_categoria.length === 0 && <li className="list-group-item text-muted">Nada no período.</li>}
                            {por_categoria.map((x) => <li key={x.tipo} className="list-group-item d-flex justify-content-between"><span>{x.rotulo} <span className="small text-muted">({x.qtd})</span></span><strong className={x.total < 0 ? 'text-success' : ''}>{moeda(x.total)}</strong></li>)}
                        </ul>
                        <div className="card-footer small text-muted">Soma das contas baixadas, como no sistema atual (crédito usado entra negativo). Pode diferir do dinheiro recebido quando sobrou crédito ou houve desconto.</div>
                    </div>
                </div>
            </div>

            <div className="card">
                <div className="table-responsive">
                    <table className="table align-middle mb-0">
                        <thead><tr><th>Data</th><th>Cliente</th><th className="d-none d-md-table-cell">Forma</th><th className="d-none d-lg-table-cell">Ordens</th><th className="text-end">Valor</th><th /></tr></thead>
                        <tbody>
                            {pagamentos.length === 0 && <tr><td colSpan="6" className="text-center text-muted py-4">Nenhum pagamento no período.</td></tr>}
                            {pagamentos.map((p) => (
                                <tr key={p.id} className={p.situacao === 'estornado' ? 'text-muted' : undefined}>
                                    <td className="text-nowrap">{quando(p.recebido_em)}<div className="small text-muted">{ORIGEM[p.origem] || p.origem}</div></td>
                                    <td>{p.cliente ? <Link href={`/clientes/${p.cliente.id}`}>{p.cliente.nome}</Link> : '—'}</td>
                                    <td className="d-none d-md-table-cell">{p.forma || 'Não informada'}{p.taxa > 0 && <div className="small text-muted">taxa {moeda(p.taxa)} · cai {fData(p.previsto_para)}</div>}</td>
                                    <td className="d-none d-lg-table-cell small">{p.itens.map((i) => `#${i.numero} ${i.descricao}`).join(', ')}</td>
                                    <td className="text-end text-nowrap">
                                        <span className={p.situacao === 'estornado' ? 'text-decoration-line-through' : ''}>{moeda(p.valor)}</span>
                                        {p.situacao === 'estornado' && <div><span className="selo" title={p.motivo_estorno || undefined}>Estornado</span></div>}
                                    </td>
                                    <td className="text-end">{pode('contas_editar') && p.situacao === 'confirmado' && p.origem !== 'importacao' && <button type="button" className="btn btn-sm btn-link" onClick={() => { form.reset(); setEstornar(p) }}>Estornar</button>}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
            {limitado && <p className="small text-muted mt-2">Mostrando os 500 mais recentes. Baixe a planilha para ver todos.</p>}

            <Janela aberta={!!estornar} titulo="Estornar pagamento" perigo rotuloOk="Estornar" onFechar={() => setEstornar(null)}
                onEnviar={() => form.post(`/pagamentos/${estornar.id}/estornar`, { preserveScroll: true, onSuccess: () => setEstornar(null) })} ocupado={form.processing}>
                <p>{estornar && `${dataHora(estornar.recebido_em)} · ${moeda(estornar.valor)}${estornar.cliente ? ` · ${estornar.cliente.nome}` : ''}`}. As contas pagas por ele voltam a ficar em aberto.</p>
                <Campo rotulo="Motivo" value={form.data.motivo} onChange={(e) => form.setData('motivo', e.target.value)} erro={form.errors.motivo} maxLength={200} autoFocus />
            </Janela>
        </div>
    )
}
