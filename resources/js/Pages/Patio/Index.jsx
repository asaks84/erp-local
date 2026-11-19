import { router, useForm } from '@inertiajs/react'
import { useEffect, useMemo, useState } from 'react'
import { Dropdown } from 'react-bootstrap'
import TituloPagina from '../../Components/TituloPagina'
import AbasPatio from '../../Components/AbasPatio'
import Janela from '../../Components/Janela'
import Campo from '../../Components/Campo'
import CampoDataHora from '../../Components/CampoDataHora'
import ContaDe from '../../Components/ContaDe'
import { Como, Entrada, SeloTipo, Servico, Valor, Veiculo } from '../../Components/PatioLinha'
import { moeda } from '../../lib/formato'
import { placa as mPlaca } from '../../lib/mascaras'
import { usePode } from '../../lib/permissoes'

const agoraTexto = () => {
    const d = new Date()
    const p = (n) => String(n).padStart(2, '0')
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}
const TIPOS = [['todos', 'Tudo'], ['estadia', 'Estadias'], ['lavagem', 'Lavagens']]

function Total({ rotulo, valor, sub }) {
    return <div className="col-6 col-md-3"><div className="card h-100"><div className="card-body py-2"><div className="small text-muted">{rotulo}</div><div className="fs-5 fw-semibold">{valor}</div>{sub && <div className="small text-muted">{sub}</div>}</div></div></div>
}

/** janelas de correção (gerente): corrigir, cancelar, encerrar estadia esquecida, marcar lavagem entregue, receber lavagem */
function Acoes({ acao, tipos, formas, onFechar }) {
    const l = acao?.linha
    const base = l?.tipo === 'estadia' ? `/patio/estadias/${l?.id}` : `/patio/lavagens/${l?.id}`
    const form = useForm({})
    const [contaDe, setContaDe] = useState(null)
    useEffect(() => {
        if (!acao) return
        form.clearErrors()
        if (acao.tipo === 'corrigir') form.setData({ placa: l.placa === 'Sem placa' ? '' : l.placa, modelo: l.modelo || '', tipo_veiculo_id: l.tipo_id || '' })
        else if (acao.tipo === 'encerrar') form.setData({ saida: agoraTexto(), como: 'sem_receber', conta_cliente_id: '', motivo: '' })
        else if (acao.tipo === 'receber') form.setData({ forma_pagamento_id: (formas.find((f) => f.tipo === 'pix') || formas[0])?.id ?? '', parcelas: 1 })
        else form.setData({ motivo: '' })
        setContaDe(null)
    }, [acao])
    if (!acao) return null
    const d = form.data
    const enviar = (url) => form.post(url, { preserveScroll: true, onSuccess: onFechar })
    const motivo = (ajuda) => <Campo rotulo="Motivo" value={d.motivo || ''} onChange={(e) => form.setData('motivo', e.target.value)} erro={form.errors.motivo} maxLength={200} ajuda={ajuda} />
    const forma = formas.find((f) => f.id === Number(d.forma_pagamento_id))

    const janelas = {
        corrigir: [`Corrigir · ${l.placa}`, 'Salvar correção', () => enviar(`${base}/corrigir`), (
            <>
                {l.placa !== 'Sem placa' && <Campo rotulo="Placa" value={d.placa || ''} onChange={(e) => form.setData('placa', mPlaca(e.target.value))} erro={form.errors.placa} />}
                <Campo rotulo="Modelo" value={d.modelo || ''} onChange={(e) => form.setData('modelo', e.target.value)} erro={form.errors.modelo} maxLength={40} />
                {l.tipo === 'estadia' && (
                    <Campo as="select" rotulo="Tamanho" value={d.tipo_veiculo_id || ''} onChange={(e) => form.setData('tipo_veiculo_id', e.target.value)} erro={form.errors.tipo_veiculo_id}
                        ajuda="Muda o valor da estadia: a saída é calculada com o novo tamanho.">
                        {tipos.map((t) => <option key={t.id} value={t.id}>{t.nome}</option>)}
                    </Campo>
                )}
                {l.tipo === 'lavagem' && <p className="small text-muted mb-0">O tamanho da lavagem não muda aqui: o preço já foi feito com ele. Se estiver errado, cancele e lance de novo no PDV.</p>}
            </>
        )],
        cancelar: [`Cancelar ${l.tipo} · ${l.placa}`, 'Cancelar', () => enviar(`${base}/cancelar`), (
            <>
                <p>{l.tipo === 'estadia' ? 'A entrada some do pátio e não gera cobrança.' : 'Os serviços em aberto desta lavagem são cancelados.'}</p>
                {motivo('Ex.: entrada lançada duas vezes, placa errada no lugar de outra.')}
            </>
        ), true],
        encerrar: [`Encerrar estadia esquecida · ${l.placa}`, 'Encerrar', () => enviar(`${base}/encerrar`), (
            <>
                <p className="small text-muted">Para o carro que já foi embora sem ninguém dar a saída no PDV.</p>
                <CampoDataHora rotulo="Saiu em" value={d.saida} onChange={(v) => form.setData('saida', v)} erro={form.errors.saida} />
                <div className="mb-3">
                    <div className="small fw-semibold mb-1">E a cobrança?</div>
                    <div className="form-check"><input className="form-check-input" type="radio" id="enc-sem" checked={d.como === 'sem_receber'} onChange={() => form.setData('como', 'sem_receber')} /><label className="form-check-label" htmlFor="enc-sem">Encerrar sem receber <span className="text-muted small">(o valor fica registrado para conferência)</span></label></div>
                    <div className="form-check"><input className="form-check-input" type="radio" id="enc-conta" checked={d.como === 'conta'} onChange={() => form.setData('como', 'conta')} /><label className="form-check-label" htmlFor="enc-conta">Lançar na conta de um cliente</label></div>
                </div>
                {d.como === 'conta' && <ContaDe cliente={contaDe} erro={form.errors.conta_cliente_id} onEscolher={(c) => { setContaDe(c); form.setData('conta_cliente_id', c.id) }} />}
                {motivo()}
            </>
        )],
        entregar: [`Marcar como entregue · ${l.placa}`, 'Marcar entregue', () => enviar(`${base}/entregar`), (
            <>
                <p className="small text-muted">Para a lavagem que já saiu sem baixa no PDV. O que está em aberto continua a receber.</p>
                {motivo()}
            </>
        )],
        receber: [`Receber · ${l.placa}`, 'Confirmar recebimento', () => form.post(`/lavagens/${l.id}/receber`, { preserveScroll: true, onSuccess: onFechar }), (
            <>
                <p className="fs-4 fw-bold mb-3">{moeda(l.a_receber)}</p>
                <div className="row g-2">
                    <div className="col-8">
                        <Campo as="select" rotulo="Forma de pagamento" value={d.forma_pagamento_id || ''} onChange={(e) => form.setData('forma_pagamento_id', e.target.value)} erro={form.errors.forma_pagamento_id}>
                            {formas.map((f) => <option key={f.id} value={f.id}>{f.nome}</option>)}
                        </Campo>
                    </div>
                    {forma?.parcelas_max > 1 && (
                        <div className="col-4">
                            <Campo as="select" rotulo="Parcelas" value={d.parcelas} onChange={(e) => form.setData('parcelas', e.target.value)}>
                                {Array.from({ length: forma.parcelas_max }, (_, i) => <option key={i} value={i + 1}>{i + 1}x</option>)}
                            </Campo>
                        </div>
                    )}
                </div>
            </>
        )],
    }
    const [titulo, ok, onEnviar, corpo, perigo] = janelas[acao.tipo]
    return <Janela aberta titulo={titulo} rotuloOk={ok} perigo={perigo} onFechar={onFechar} onEnviar={onEnviar} ocupado={form.processing}>{corpo}</Janela>
}

/** Operação › Pátio › Agora: estadias e lavagens em andamento numa lista só. Entrada e saída são do PDV. */
export default function PatioIndex({ linhas, totais, tipos, formas }) {
    const pode = usePode()
    const corrige = pode('patio_corrigir')
    const [tipo, setTipo] = useState('todos')
    const [busca, setBusca] = useState('')
    const [acao, setAcao] = useState(null)
    // atualiza tempos e valores a cada minuto
    useEffect(() => {
        const t = setInterval(() => router.reload({ only: ['linhas', 'totais'], preserveScroll: true }), 60000)
        return () => clearInterval(t)
    }, [])
    const lista = useMemo(() => {
        const q = busca.trim().toUpperCase().replace(/[^A-Z0-9]/g, '')
        return linhas.filter((l) => (tipo === 'todos' || l.tipo === tipo)
            && (!q || l.placa.replace(/[^A-Z0-9]/gi, '').toUpperCase().includes(q) || String(l.numero) === q || (l.modelo || '').toUpperCase().includes(busca.trim().toUpperCase())))
    }, [linhas, tipo, busca])
    const abrir = (t, l) => setAcao({ tipo: t, linha: l })

    return (
        <div className="pagina">
            <AbasPatio />
            <TituloPagina titulo="Pátio" descricao="Estadias e lavagens em andamento. A entrada e a saída são feitas no PDV; aqui é para acompanhar e corrigir." />
            <div className="row g-2 mb-3">
                <Total rotulo="Estadias no pátio" valor={totais.estadias} sub={`${moeda(totais.estadias_valor)} se saíssem agora`} />
                <Total rotulo="Lavagens em andamento" valor={totais.lavagens} sub={moeda(totais.lavagens_valor)} />
            </div>
            <div className="d-flex flex-wrap gap-2 align-items-center mb-3">
                <div className="btn-group" role="group" aria-label="Tipo">
                    {TIPOS.map(([k, r]) => <button key={k} type="button" className={`btn ${tipo === k ? 'btn-primary' : 'btn-outline-secondary'}`} onClick={() => setTipo(k)}>{r}</button>)}
                </div>
                <input type="search" className="form-control" style={{ maxWidth: 320 }} placeholder="Placa, modelo ou ticket" value={busca} onChange={(e) => setBusca(e.target.value)} aria-label="Buscar no pátio" />
            </div>
            <div className="card">
                <div className="table-responsive">
                    <table className="table align-middle mb-0">
                        <thead><tr><th>Ticket</th><th>Veículo</th><th>O quê</th><th>Entrada</th><th className="text-end">Valor</th><th>Situação</th><th aria-label="Ações" /></tr></thead>
                        <tbody>
                            {lista.length === 0 && <tr><td colSpan="7" className="text-center text-muted py-4">{linhas.length ? 'Nada com esse filtro.' : 'Pátio vazio.'}</td></tr>}
                            {lista.map((l) => (
                                <tr key={l.chave}>
                                    <td className="text-muted">{l.numero}<div><SeloTipo tipo={l.tipo} /></div></td>
                                    <td><Veiculo l={l} /></td>
                                    <td><Servico l={l} /></td>
                                    <td><Entrada l={l} /></td>
                                    <td className="text-end text-nowrap"><Valor l={l} /></td>
                                    <td><Como l={l} /></td>
                                    <td className="text-end">
                                        {(corrige || l.a_receber > 0) && (
                                            <Dropdown align="end">
                                                <Dropdown.Toggle variant="outline-secondary" size="sm">Ações</Dropdown.Toggle>
                                                <Dropdown.Menu>
                                                    {l.a_receber > 0 && <Dropdown.Item onClick={() => abrir('receber', l)}>Receber {moeda(l.a_receber)}</Dropdown.Item>}
                                                    {corrige && <Dropdown.Item onClick={() => abrir('corrigir', l)}>Corrigir placa ou modelo</Dropdown.Item>}
                                                    {corrige && l.tipo === 'estadia' && <Dropdown.Item onClick={() => abrir('encerrar', l)}>Encerrar (carro já saiu)</Dropdown.Item>}
                                                    {corrige && l.tipo === 'lavagem' && <Dropdown.Item onClick={() => abrir('entregar', l)}>Marcar entregue (já saiu)</Dropdown.Item>}
                                                    {corrige && l.pode_cancelar && <><Dropdown.Divider /><Dropdown.Item className="text-danger" onClick={() => abrir('cancelar', l)}>Cancelar</Dropdown.Item></>}
                                                </Dropdown.Menu>
                                            </Dropdown>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
            <Acoes acao={acao} tipos={tipos} formas={formas} onFechar={() => setAcao(null)} />
        </div>
    )
}
