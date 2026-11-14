import { Link, useForm, usePage } from '@inertiajs/react'
import { useState } from 'react'
import { Dropdown } from 'react-bootstrap'
import Icone from './Icone'
import Janela from './Janela'
import Campo from './Campo'
import CampoData from './CampoData'
import CampoValor from './CampoValor'
import { SeloCobranca, SeloOrdem, ValorOrdem, competencia, descricaoOrdem, quando } from './Dinheiro'
import { data as fData, dataHora, moeda } from '../lib/formato'
import { usePode } from '../lib/permissoes'

const hojeIso = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}` }

/** Bloco "Contas" da ficha do cliente: em aberto, cobranças e pagamentos recentes. */
export default function ContasCliente({ cliente, abertas, total, cobrancas, pagamentos, mesAtual, regrasSugerido = true, estornos = [], creditoEstornavel = 0, formasEstorno = [] }) {
    const pode = usePode()
    const editar = pode('contas_editar')
    const [janela, setJanela] = useState(null) // {tipo: 'alterar'|'cancelar'|'estornar'|'cobranca'|'estorno'|'desfazer_estorno', item}
    const form = useForm({})
    const inter = !!usePage().props.empresa?.inter
    const temContas = abertas.some((o) => o.tipo !== 'credito')

    function abrir(tipo, item = null) {
        form.clearErrors()
        if (tipo === 'alterar') form.setData({ valor: item.valor, vencimento: item.vencimento || '', descricao: item.descricao })
        else if (tipo === 'cobranca') form.setData({ mes: mesAtual, vencimento: '', boleto: inter, regras: regrasSugerido, desconto: '', desconto_motivo: '' })
        else if (tipo === 'estorno') form.setData({ valor: creditoEstornavel, estornado_em: hojeIso(), forma_pagamento_id: formasEstorno.find((f) => f.tipo === 'pix')?.id ?? formasEstorno[0]?.id ?? '', motivo: '' })
        else form.setData({ motivo: '' })
        setJanela({ tipo, item })
    }
    function enviar() {
        const { tipo, item } = janela
        const fim = { preserveScroll: true, onSuccess: () => setJanela(null) }
        if (tipo === 'alterar') form.put(`/ordens/${item.id}`, fim)
        if (tipo === 'cancelar') form.post(`/ordens/${item.id}/cancelar`, fim)
        if (tipo === 'estornar') form.post(`/pagamentos/${item.id}/estornar`, fim)
        if (tipo === 'cobranca') form.post(`/clientes/${cliente.id}/cobrancas`, fim)
        if (tipo === 'estorno') form.post(`/clientes/${cliente.id}/estornos`, fim)
        if (tipo === 'desfazer_estorno') form.post(`/estornos/${item.id}/desfazer`, fim)
    }

    return (
        <div className="card mb-4" id="contas">
            <div className="card-header d-flex flex-wrap align-items-center gap-2">
                <span className="flex-grow-1">Contas</span>
                <Link href={`/clientes/${cliente.id}/extrato`} className="btn btn-sm btn-outline-secondary">Extrato da conta</Link>
                <Dropdown align="end">
                    <Dropdown.Toggle size="sm" variant="outline-secondary">Lançar</Dropdown.Toggle>
                    <Dropdown.Menu>
                        <Dropdown.Item as={Link} href={`/clientes/${cliente.id}/lancar?tipo=servico`}>Lavagem ou serviço</Dropdown.Item>
                        <Dropdown.Item as={Link} href={`/clientes/${cliente.id}/lancar?tipo=estadia`}>Estadia</Dropdown.Item>
                        {editar && <Dropdown.Item as={Link} href={`/clientes/${cliente.id}/lancar?tipo=debito`}>Débito</Dropdown.Item>}
                        {editar && <Dropdown.Item as={Link} href={`/clientes/${cliente.id}/lancar?tipo=credito`}>Crédito</Dropdown.Item>}
                    </Dropdown.Menu>
                </Dropdown>
                {temContas && <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => abrir('cobranca')}>Emitir cobrança</button>}
                {temContas && <Link href={`/clientes/${cliente.id}/receber`} className="btn btn-sm btn-primary d-inline-flex align-items-center gap-1"><Icone nome="dinheiro" tamanho={16} /> Receber</Link>}
            </div>

            <div className="card-body pb-0">
                <div className="d-flex align-items-baseline gap-2 mb-2">
                    <span className="text-muted">Em aberto</span>
                    <strong className={`fs-4 ${total > 0 ? '' : 'text-success'}`}>{moeda(total)}</strong>
                    {total < 0 && <span className="text-muted small">(crédito a favor do cliente)</span>}
                    {editar && creditoEstornavel > 0 && (
                        <button type="button" className="btn btn-sm btn-outline-secondary ms-auto" onClick={() => abrir('estorno')} title="Devolver o crédito ao cliente (o Pix você faz no app do banco)">Estornar</button>
                    )}
                </div>
            </div>
            {abertas.length === 0 ? (
                <div className="card-body pt-0 text-muted">Nenhuma conta em aberto.</div>
            ) : (
                <div className="table-responsive">
                    <table className="table align-middle mb-0">
                        <thead><tr><th>Nº</th><th>Descrição</th><th className="d-none d-md-table-cell">Vencimento</th><th className="text-end">Valor</th><th /></tr></thead>
                        <tbody>
                            {abertas.map((o) => (
                                <tr key={o.id}>
                                    <td className="text-muted">{o.numero}</td>
                                    <td>
                                        <div>{descricaoOrdem(o)}</div>
                                        <div className="small text-muted">{[o.placa, `lançada ${fData(o.data)}`].filter(Boolean).join(' · ')} <span className="d-md-none">· venc. {fData(o.vencimento || o.data)}</span></div>
                                    </td>
                                    <td className="d-none d-md-table-cell text-nowrap">{o.tipo === 'credito' ? '—' : fData(o.vencimento || o.data)} <SeloOrdem o={o} /></td>
                                    <td className="text-end"><ValorOrdem o={o} /></td>
                                    <td className="text-end text-nowrap">
                                        {editar && o.tipo !== 'credito' && <button type="button" className="btn btn-sm btn-outline-secondary" aria-label={`Alterar ordem ${o.numero}`} onClick={() => abrir('alterar', o)}><Icone nome="lapis" tamanho={16} /></button>}
                                        {editar && <button type="button" className="btn btn-sm btn-outline-secondary ms-1" aria-label={`Cancelar ordem ${o.numero}`} onClick={() => abrir('cancelar', o)}><Icone nome="lixeira" tamanho={16} /></button>}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {(cobrancas.length > 0 || pagamentos.length > 0 || estornos.length > 0) && (
                <div className="card-body border-top">
                    <div className="row g-4">
                        <div className="col-md-6">
                            <h3 className="fs-6 fw-semibold">Cobranças</h3>
                            {cobrancas.length === 0 && <p className="text-muted small mb-0">Nenhuma cobrança emitida.</p>}
                            <ul className="list-unstyled mb-0">
                                {cobrancas.map((c) => (
                                    <li key={c.id} className="d-flex flex-wrap gap-2 align-items-center py-1">
                                        <Link href={`/cobrancas/${c.id}`}>Nº {c.numero} · {competencia(c.competencia)}</Link>
                                        <span className="ms-auto">{moeda(c.total)}</span>
                                        <SeloCobranca situacao={c.situacao} />
                                    </li>
                                ))}
                            </ul>
                        </div>
                        <div className="col-md-6">
                            <h3 className="fs-6 fw-semibold">Pagamentos</h3>
                            {pagamentos.length === 0 && <p className="text-muted small mb-0">Nenhum pagamento.</p>}
                            <ul className="list-unstyled mb-0">
                                {pagamentos.map((p) => (
                                    <li key={p.id} className={`d-flex flex-wrap gap-2 align-items-center py-1 ${p.situacao === 'estornado' ? 'text-muted' : ''}`}>
                                        <span>{quando(p.recebido_em)}</span>
                                        <span className="small text-muted">{p.forma || 'Não informada'}</span>
                                        <span className={`ms-auto ${p.situacao === 'estornado' ? 'text-decoration-line-through' : ''}`}>{moeda(p.valor)}</span>
                                        {p.situacao === 'estornado' && <span className="selo" title={p.motivo_estorno || undefined}>Desfeito</span>}
                                        {editar && p.situacao === 'confirmado' && p.origem !== 'importacao' && (
                                            <button type="button" className="btn btn-sm btn-link p-0" onClick={() => abrir('estornar', p)} title="Pagamento lançado errado: as contas voltam a ficar em aberto">Desfazer</button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                            {estornos.length > 0 && (
                                <>
                                    <h3 className="fs-6 fw-semibold mt-3">Estornos</h3>
                                    <ul className="list-unstyled mb-0">
                                        {estornos.map((e) => (
                                            <li key={e.id} className={`d-flex flex-wrap gap-2 align-items-center py-1 ${e.situacao === 'desfeito' ? 'text-muted' : ''}`} title={e.motivo}>
                                                <span>{fData(e.estornado_em)}</span>
                                                <span className="small text-muted text-truncate" style={{ maxWidth: 200 }}>{[e.forma, e.motivo].filter(Boolean).join(' · ')}</span>
                                                <span className={`ms-auto ${e.situacao === 'desfeito' ? 'text-decoration-line-through' : ''}`}>−{moeda(e.valor)}</span>
                                                {e.situacao === 'desfeito' && <span className="selo" title={e.motivo_desfeito || undefined}>Desfeito</span>}
                                                {editar && e.situacao === 'feito' && <button type="button" className="btn btn-sm btn-link p-0" onClick={() => abrir('desfazer_estorno', e)}>Desfazer</button>}
                                            </li>
                                        ))}
                                    </ul>
                                </>
                            )}
                        </div>
                    </div>
                </div>
            )}

            <Janela aberta={janela?.tipo === 'alterar'} titulo={`Alterar ordem ${janela?.item?.numero ?? ''}`} onFechar={() => setJanela(null)} onEnviar={enviar} ocupado={form.processing}>
                <Campo rotulo="Descrição" value={form.data.descricao ?? ''} onChange={(e) => form.setData('descricao', e.target.value)} erro={form.errors.descricao} maxLength={80} />
                <CampoValor rotulo="Valor" value={form.data.valor ?? ''} onChange={(v) => form.setData('valor', v)} erro={form.errors.valor} />
                <CampoData rotulo="Vencimento" value={form.data.vencimento ?? ''} onChange={(v) => form.setData('vencimento', v)} erro={form.errors.vencimento} />
                <p className="small text-muted mb-0">Fica registrado quem alterou e o valor anterior. Uma cobrança já emitida continua com o valor de quando foi emitida.</p>
            </Janela>

            <Janela aberta={janela?.tipo === 'cancelar'} titulo={`Cancelar ordem ${janela?.item?.numero ?? ''}`} perigo rotuloOk="Cancelar ordem" onFechar={() => setJanela(null)} onEnviar={enviar} ocupado={form.processing}>
                <p>{janela?.item && `${descricaoOrdem(janela.item)} · ${moeda(janela.item.valor)}`}</p>
                <Campo rotulo="Motivo" value={form.data.motivo ?? ''} onChange={(e) => form.setData('motivo', e.target.value)} erro={form.errors.motivo} maxLength={200} autoFocus />
                <p className="small text-muted mb-0">A ordem não é apagada: fica cancelada no histórico, com o motivo.</p>
            </Janela>

            <Janela aberta={janela?.tipo === 'estorno'} titulo="Estornar ao cliente" rotuloOk="Registrar estorno" onFechar={() => setJanela(null)} onEnviar={enviar} ocupado={form.processing}>
                <p className="small text-muted">Faça o Pix ou a transferência pelo app do banco e registre aqui. O crédito do cliente ({moeda(creditoEstornavel)}) é baixado e a saída entra no fluxo de caixa.</p>
                <div className="row g-2">
                    <div className="col-sm-6"><CampoValor rotulo="Valor estornado" value={form.data.valor ?? ''} onChange={(v) => form.setData('valor', v)} erro={form.errors.valor} /></div>
                    <div className="col-sm-6"><CampoData rotulo="Data do estorno" value={form.data.estornado_em ?? ''} onChange={(v) => form.setData('estornado_em', v)} erro={form.errors.estornado_em} /></div>
                </div>
                <div className="form-floating mb-3">
                    <select className="form-select" id="est-forma" value={form.data.forma_pagamento_id ?? ''} onChange={(e) => form.setData('forma_pagamento_id', e.target.value)}>
                        <option value="">Não informada</option>
                        {formasEstorno.map((f) => <option key={f.id} value={f.id}>{f.nome}</option>)}
                    </select>
                    <label htmlFor="est-forma">Como foi devolvido</label>
                </div>
                <Campo rotulo="Motivo" value={form.data.motivo ?? ''} onChange={(e) => form.setData('motivo', e.target.value)} erro={form.errors.motivo} maxLength={200} placeholder="Ex.: boleto pago depois do cancelamento" />
            </Janela>

            <Janela aberta={janela?.tipo === 'desfazer_estorno'} titulo="Desfazer estorno" perigo rotuloOk="Desfazer" onFechar={() => setJanela(null)} onEnviar={enviar} ocupado={form.processing}>
                <p>{janela?.item && `${fData(janela.item.estornado_em)} · ${moeda(janela.item.valor)}`}. Use só se o estorno foi registrado errado: o crédito volta para o cliente.</p>
                <Campo rotulo="Motivo" value={form.data.motivo ?? ''} onChange={(e) => form.setData('motivo', e.target.value)} erro={form.errors.motivo} maxLength={200} autoFocus />
            </Janela>

            <Janela aberta={janela?.tipo === 'estornar'} titulo="Desfazer pagamento" perigo rotuloOk="Desfazer" onFechar={() => setJanela(null)} onEnviar={enviar} ocupado={form.processing}>
                <p>{janela?.item && `${dataHora(janela.item.recebido_em)} · ${moeda(janela.item.valor)}`}. As contas pagas por ele voltam a ficar em aberto. Serve para pagamento lançado errado; para devolver dinheiro ao cliente, use "Estornar".</p>
                <Campo rotulo="Motivo" value={form.data.motivo ?? ''} onChange={(e) => form.setData('motivo', e.target.value)} erro={form.errors.motivo} maxLength={200} autoFocus />
            </Janela>

            <Janela aberta={janela?.tipo === 'cobranca'} titulo="Emitir cobrança" rotuloOk="Emitir" onFechar={() => setJanela(null)} onEnviar={enviar} ocupado={form.processing}>
                <p className="small text-muted">Entram todas as contas em aberto ({moeda(total)}). Depois de emitida, a cobrança não muda.</p>
                <CampoData mes rotulo="Mês da cobrança" value={form.data.mes ?? ''} onChange={(v) => form.setData('mes', v)} erro={form.errors.mes || form.errors.competencia || form.errors.cliente} />
                <CampoData rotulo="Vencimento (vazio = o da mensalidade)" value={form.data.vencimento ?? ''} onChange={(v) => form.setData('vencimento', v)} erro={form.errors.vencimento} />
                {pode('desconto') && (
                    <div className="row g-2">
                        <div className="col-sm-5">
                            <CampoValor rotulo="Desconto negociado" value={form.data.desconto ?? ''} onChange={(v) => form.setData('desconto', v)} erro={form.errors.desconto}
                                ajuda={form.data.desconto > 0 ? `A pagar: ${moeda(Math.max(0, total - form.data.desconto))}` : 'Opcional'} />
                        </div>
                        {form.data.desconto > 0 && (
                            <div className="col-sm-7">
                                <Campo rotulo="Motivo do desconto" value={form.data.desconto_motivo ?? ''} onChange={(e) => form.setData('desconto_motivo', e.target.value)}
                                    erro={form.errors.desconto_motivo} maxLength={120} placeholder="Ex.: negociado com o cliente" />
                            </div>
                        )}
                    </div>
                )}
                {inter && (
                    <div className="form-check">
                        <input className="form-check-input" type="checkbox" id="cob-boleto" checked={!!form.data.boleto} onChange={(e) => form.setData('boleto', e.target.checked)} />
                        <label className="form-check-label" htmlFor="cob-boleto">Gerar boleto com Pix (Banco Inter)</label>
                    </div>
                )}
                {inter && form.data.boleto && (
                    <div className="form-check ms-4">
                        <input className="form-check-input" type="checkbox" id="cob-regras" checked={!!form.data.regras} onChange={(e) => form.setData('regras', e.target.checked)} />
                        <label className="form-check-label" htmlFor="cob-regras">Aplicar as regras do boleto (multa, juros e desconto)</label>
                        {!regrasSugerido && <div className="form-text">Desmarcado porque tem a primeira mensalidade (ou uma proporcional).</div>}
                    </div>
                )}
            </Janela>
        </div>
    )
}
