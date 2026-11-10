import { useForm } from '@inertiajs/react'
import { useState } from 'react'
import AbasConfiguracoes from '../../Components/AbasConfiguracoes'
import TituloPagina from '../../Components/TituloPagina'
import Icone from '../../Components/Icone'
import Janela from '../../Components/Janela'
import Campo from '../../Components/Campo'
import CampoValor from '../../Components/CampoValor'
import { moeda } from '../../lib/formato'

const FORMA_VAZIA = { nome: '', tipo: 'pix', conta_financeira_id: '', taxa_pct: 0, taxa_fixa: 0, parcelas_max: 1, taxas_parcelas: {}, prazo_dias: 0, ativa: true }

export default function Pagamentos({ contas, formas, tipos_forma, tipos_conta }) {
    const [janela, setJanela] = useState(null) // {tipo: 'forma'|'conta', item}
    const form = useForm({})

    function abrir(tipo, item = null) {
        form.clearErrors()
        if (tipo === 'forma') form.setData(item ? { ...FORMA_VAZIA, ...item, conta_financeira_id: item.conta_financeira_id ?? '', taxas_parcelas: { ...item.taxas_parcelas } } : { ...FORMA_VAZIA, conta_financeira_id: contas[0]?.id ?? '' })
        else form.setData(item ? { nome: item.nome, tipo: item.tipo, ativa: item.ativa } : { nome: '', tipo: 'banco', ativa: true })
        setJanela({ tipo, item })
    }
    function enviar() {
        const { tipo, item } = janela
        const base = tipo === 'forma' ? '/configuracoes/formas' : '/configuracoes/contas'
        const fim = { preserveScroll: true, onSuccess: () => setJanela(null) }
        item ? form.put(`${base}/${item.id}`, fim) : form.post(base, fim)
    }
    const d = form.data

    return (
        <div className="pagina">
            <AbasConfiguracoes />
            <div style={{ maxWidth: 900 }}>
            <TituloPagina titulo="Formas de pagamento" descricao="Como os clientes pagam, em que conta o dinheiro entra, a taxa cobrada e em quantos dias cai. Nada é apagado: desative o que não usa mais." />

            <div className="card mb-4">
                <div className="card-header d-flex align-items-center">
                    <span className="flex-grow-1">Formas de pagamento</span>
                    <button type="button" className="btn btn-sm btn-primary d-inline-flex align-items-center gap-1" onClick={() => abrir('forma')}><Icone nome="mais" tamanho={16} /> Nova forma</button>
                </div>
                <div className="table-responsive">
                    <table className="table align-middle mb-0">
                        <thead><tr><th>Forma</th><th className="d-none d-md-table-cell">Entra em</th><th>Taxa</th><th className="d-none d-md-table-cell">Cai em</th><th /></tr></thead>
                        <tbody>
                            {formas.map((f) => (
                                <tr key={f.id} className={f.ativa ? undefined : 'inativo'}>
                                    <td>{f.nome} {!f.ativa && <span className="selo ms-1">Inativa</span>}<div className="small text-muted">{tipos_forma[f.tipo]}{f.parcelas_max > 1 ? ` · até ${f.parcelas_max}x` : ''}</div></td>
                                    <td className="d-none d-md-table-cell">{f.conta || '—'}</td>
                                    <td className="text-nowrap">{f.taxa_pct || f.taxa_fixa ? [f.taxa_pct ? `${String(f.taxa_pct).replace('.', ',')}%` : null, f.taxa_fixa ? moeda(f.taxa_fixa) : null].filter(Boolean).join(' + ') : 'sem taxa'}</td>
                                    <td className="d-none d-md-table-cell">{f.prazo_dias ? `${f.prazo_dias} dia(s)` : 'na hora'}</td>
                                    <td className="text-end"><button type="button" className="btn btn-sm btn-outline-secondary" aria-label={`Editar ${f.nome}`} onClick={() => abrir('forma', f)}><Icone nome="lapis" tamanho={16} /></button></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <div className="card">
                <div className="card-header d-flex align-items-center">
                    <span className="flex-grow-1">Contas (onde o dinheiro fica)</span>
                    <button type="button" className="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1" onClick={() => abrir('conta')}><Icone nome="mais" tamanho={16} /> Nova conta</button>
                </div>
                <ul className="list-group list-group-flush">
                    {contas.map((c) => (
                        <li key={c.id} className={`list-group-item d-flex align-items-center gap-2 ${c.ativa ? '' : 'inativo'}`}>
                            <span className="flex-grow-1">{c.nome} <span className="small text-muted">· {tipos_conta[c.tipo]}</span> {!c.ativa && <span className="selo ms-1">Inativa</span>}</span>
                            <button type="button" className="btn btn-sm btn-outline-secondary" aria-label={`Editar ${c.nome}`} onClick={() => abrir('conta', c)}><Icone nome="lapis" tamanho={16} /></button>
                        </li>
                    ))}
                </ul>
            </div>

            <Janela aberta={janela?.tipo === 'forma'} titulo={janela?.item ? 'Editar forma de pagamento' : 'Nova forma de pagamento'} onFechar={() => setJanela(null)} onEnviar={enviar} ocupado={form.processing}>
                <Campo rotulo="Nome" value={d.nome ?? ''} onChange={(e) => form.setData('nome', e.target.value)} erro={form.errors.nome} maxLength={40} />
                <div className="row g-2">
                    <div className="col-sm-6"><Campo as="select" rotulo="Tipo" value={d.tipo ?? ''} onChange={(e) => form.setData('tipo', e.target.value)} erro={form.errors.tipo}>{Object.entries(tipos_forma).map(([k, r]) => <option key={k} value={k}>{r}</option>)}</Campo></div>
                    <div className="col-sm-6"><Campo as="select" rotulo="Entra na conta" value={d.conta_financeira_id ?? ''} onChange={(e) => form.setData('conta_financeira_id', e.target.value)} erro={form.errors.conta_financeira_id}><option value="">Nenhuma</option>{contas.filter((c) => c.ativa || c.id === d.conta_financeira_id).map((c) => <option key={c.id} value={c.id}>{c.nome}</option>)}</Campo></div>
                    <div className="col-6"><CampoValor rotulo="Taxa" prefixo="%" value={d.taxa_pct ?? 0} onChange={(v) => form.setData('taxa_pct', v === '' ? 0 : v)} erro={form.errors.taxa_pct} /></div>
                    <div className="col-6"><CampoValor rotulo="Taxa fixa" value={d.taxa_fixa ?? 0} onChange={(v) => form.setData('taxa_fixa', v === '' ? 0 : v)} erro={form.errors.taxa_fixa} /></div>
                    <div className="col-6"><Campo type="number" min="0" max="365" rotulo="Dias até cair na conta" value={d.prazo_dias ?? 0} onChange={(e) => form.setData('prazo_dias', e.target.value)} erro={form.errors.prazo_dias} /></div>
                    <div className="col-6"><Campo type="number" min="1" max="24" rotulo="Parcelas até" value={d.parcelas_max ?? 1} onChange={(e) => form.setData('parcelas_max', e.target.value)} erro={form.errors.parcelas_max} /></div>
                </div>
                {Number(d.parcelas_max) > 1 && (
                    <fieldset className="mb-3">
                        <legend className="fs-6 fw-semibold">Taxa por parcelas (vazio = a taxa acima)</legend>
                        <div className="row g-2">
                            {Array.from({ length: Number(d.parcelas_max) - 1 }, (_, i) => i + 2).map((n) => (
                                <div key={n} className="col-4">
                                    <CampoValor rotulo={`${n}x`} prefixo="%" semMargem value={d.taxas_parcelas?.[n] ?? ''} onChange={(v) => form.setData('taxas_parcelas', { ...d.taxas_parcelas, [n]: v })} />
                                </div>
                            ))}
                        </div>
                    </fieldset>
                )}
                <div className="form-check form-switch">
                    <input className="form-check-input" type="checkbox" id="forma-ativa" checked={!!d.ativa} onChange={(e) => form.setData('ativa', e.target.checked)} />
                    <label className="form-check-label" htmlFor="forma-ativa">Ativa (aparece na hora de receber)</label>
                </div>
            </Janela>

            <Janela aberta={janela?.tipo === 'conta'} titulo={janela?.item ? 'Editar conta' : 'Nova conta'} onFechar={() => setJanela(null)} onEnviar={enviar} ocupado={form.processing}>
                <Campo rotulo="Nome" value={d.nome ?? ''} onChange={(e) => form.setData('nome', e.target.value)} erro={form.errors.nome} maxLength={60} />
                <Campo as="select" rotulo="Tipo" value={d.tipo ?? ''} onChange={(e) => form.setData('tipo', e.target.value)} erro={form.errors.tipo}>{Object.entries(tipos_conta).map(([k, r]) => <option key={k} value={k}>{r}</option>)}</Campo>
                <div className="form-check form-switch">
                    <input className="form-check-input" type="checkbox" id="conta-ativa" checked={!!d.ativa} onChange={(e) => form.setData('ativa', e.target.checked)} />
                    <label className="form-check-label" htmlFor="conta-ativa">Ativa</label>
                </div>
            </Janela>
            </div>
        </div>
    )
}
