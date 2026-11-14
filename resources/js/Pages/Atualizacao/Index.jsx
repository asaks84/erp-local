import { Link, router } from '@inertiajs/react'
import { useMemo, useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import { data as fData, dataHora } from '../../lib/formato'
import { telefone } from '../../lib/mascaras'

const SITUACAO = {
    aberta: ['Link aberto', 'selo-aviso'], respondida: ['Para revisar', 'selo-marca'], aprovada: ['Atualizado', 'selo-ok'],
    recusada: ['Recusado', 'selo-erro'], expirada: ['Expirado', ''], cancelada: ['Cancelado', ''],
}
const CANAL = { whatsapp: 'WhatsApp', cartao: 'cartão', link: 'link copiado' }

function Situacao({ a }) {
    if (!a) return <span className="text-muted small">Sem link</span>
    const [r, cor] = SITUACAO[a.situacao] || [a.situacao, '']
    return (
        <>
            <span className={`selo ${cor}`}>{r}</span>
            <div className="small text-muted">
                {a.situacao === 'aberta' && (a.enviado_em ? `enviado ${dataHora(a.enviado_em)} (${CANAL[a.canal] || a.canal})` : 'ainda não enviado')}
                {a.situacao === 'aberta' && ` · até ${fData(a.expira_em)}`}
                {a.situacao === 'respondida' && `respondido ${dataHora(a.respondido_em)}`}
            </div>
        </>
    )
}

function Resumo({ titulo, valor, detalhe, ativo, onClick, cor }) {
    return (
        <div className="col-6 col-lg">
            <button type="button" className={`card h-100 w-100 text-start ${ativo ? 'border-primary' : ''}`} onClick={onClick}>
                <div className="card-body py-3">
                    <div className="small text-muted">{titulo}</div>
                    <div className={`fs-3 fw-bold ${cor || ''}`}>{valor}</div>
                    {detalhe && <div className="small text-muted">{detalhe}</div>}
                </div>
            </button>
        </div>
    )
}

/** Clientes › Atualização cadastral: problemas no cadastro, envio do link e revisão. */
export default function AtualizacaoIndex({ linhas, resumo, empresa_nome: empresa }) {
    const [aba, setAba] = useState('envio')
    const [filtro, setFiltro] = useState('todos')
    const [busca, setBusca] = useState('')
    const [marcados, setMarcados] = useState([])
    const [copiado, setCopiado] = useState(null)

    const problema = (l) => l.problema_documento || l.problema_celular
    const lista = useMemo(() => {
        const b = busca.trim().toLowerCase()
        return linhas.filter((l) => {
            if (b && !(l.nome.toLowerCase().includes(b) || l.placas.join(' ').toLowerCase().includes(b) || String(l.id) === b)) return false
            const s = l.atualizacao?.situacao
            return { todos: true, problema: !!problema(l), sem_link: !s || ['expirada', 'cancelada', 'recusada'].includes(s), aberta: s === 'aberta', respondida: s === 'respondida', aprovada: s === 'aprovada' }[filtro]
        })
    }, [linhas, filtro, busca])
    const cpf = linhas.filter((l) => l.problema_documento)
    const cel = linhas.filter((l) => l.problema_celular)
    const revisar = linhas.filter((l) => l.atualizacao?.situacao === 'respondida')

    const todosMarcados = lista.length > 0 && lista.every((l) => marcados.includes(l.id))
    const marcar = (id) => setMarcados((m) => (m.includes(id) ? m.filter((x) => x !== id) : [...m, id]))
    const marcarTodos = () => setMarcados(todosMarcados ? marcados.filter((id) => !lista.some((l) => l.id === id)) : [...new Set([...marcados, ...lista.map((l) => l.id)])])
    const escolhidos = linhas.filter((l) => marcados.includes(l.id))
    const comLink = escolhidos.filter((l) => l.atualizacao?.link)
    // cartões: os marcados que têm link; sem marcação, todos os links abertos (na tela dos cartões dá para escolher)
    const paraCartao = comLink.length ? comLink : linhas.filter((l) => l.atualizacao?.link)

    const gerar = (ids, renovar = false) => router.post('/atualizacao-cadastral/gerar', { clientes: ids, renovar }, { preserveScroll: true })
    const enviado = (ids, canal) => router.post('/atualizacao-cadastral/enviado', { ids, canal }, { preserveScroll: true, preserveState: true })
    const mensagem = (l) => `Olá, ${l.nome.split(' ')[0].charAt(0) + l.nome.split(' ')[0].slice(1).toLowerCase()}! Aqui é do ${empresa}. Estamos atualizando o cadastro dos clientes. Confira e atualize seus dados (leva 1 minuto): ${l.atualizacao.link}\nPara abrir, confirme a placa do seu veículo.`
    const whatsapp = (l) => {
        const n = l.celular.replace(/\D/g, '')
        window.open(`https://wa.me/55${n}?text=${encodeURIComponent(mensagem(l))}`, '_blank', 'noopener')
        enviado([l.atualizacao.id], 'whatsapp')
    }
    const copiar = (l) => navigator.clipboard?.writeText(mensagem(l)).then(() => { setCopiado(l.id); setTimeout(() => setCopiado(null), 1500); enviado([l.atualizacao.id], 'link') })

    return (
        <div className="pagina">
            <TituloPagina titulo="Atualização cadastral" descricao="Envie a cada cliente um link para conferir e corrigir CPF, celular e endereço. Para abrir, ele confirma a placa de um veículo; o que mandar passa pela sua revisão antes de mudar o cadastro." />

            <div className="row g-3 mb-4">
                <Resumo titulo="Clientes ativos" valor={resumo.clientes} ativo={aba === 'envio'} onClick={() => setAba('envio')} />
                <Resumo titulo="CPF/CNPJ com problema" valor={resumo.documento} detalhe="inválido, em branco ou repetido" cor={resumo.documento ? 'text-danger' : ''} ativo={aba === 'cpf'} onClick={() => setAba('cpf')} />
                <Resumo titulo="Sem celular válido" valor={resumo.celular} detalhe="recebem o cartão impresso" cor={resumo.celular ? 'text-danger' : ''} ativo={aba === 'celular'} onClick={() => setAba('celular')} />
                <Resumo titulo="Para revisar" valor={resumo.respondidas} detalhe="o cliente já respondeu" ativo={aba === 'revisar'} onClick={() => setAba('revisar')} />
                <Resumo titulo="Atualizados" valor={resumo.aprovadas} />
            </div>

            {aba === 'envio' && (
                <div className="card">
                    <div className="card-header d-flex flex-wrap gap-2 align-items-center">
                        <input type="search" className="form-control form-control-sm" style={{ maxWidth: 260 }} placeholder="Buscar nome, placa ou código" value={busca} onChange={(e) => setBusca(e.target.value)} />
                        <select className="form-select form-select-sm" style={{ maxWidth: 220 }} value={filtro} onChange={(e) => setFiltro(e.target.value)}>
                            <option value="todos">Todos</option>
                            <option value="problema">Com problema no cadastro</option>
                            <option value="sem_link">Sem link válido</option>
                            <option value="aberta">Link aberto</option>
                            <option value="respondida">Para revisar</option>
                            <option value="aprovada">Atualizados</option>
                        </select>
                        <span className="flex-grow-1 small text-muted">{marcados.length > 0 && `${marcados.length} marcado(s)`}</span>
                        <button type="button" className="btn btn-sm btn-outline-primary" disabled={!marcados.length} onClick={() => gerar(marcados)}>Gerar links</button>
                        <button type="button" className="btn btn-sm btn-outline-secondary" disabled={!paraCartao.length} title="Para quem não tem celular válido. Na próxima tela dá para escolher quais imprimir."
                            onClick={() => router.get('/atualizacao-cadastral/cartoes', comLink.length ? { ids: comLink.map((l) => l.atualizacao.id).join(',') } : {})}>
                            Imprimir cartões ({paraCartao.length})</button>
                    </div>
                    <div className="table-responsive">
                        <table className="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style={{ width: 32 }}><input type="checkbox" className="form-check-input" checked={todosMarcados} onChange={marcarTodos} aria-label="Marcar todos" /></th>
                                    <th>Cliente</th><th>CPF/CNPJ</th><th>Celular</th><th>Situação</th><th className="text-end">Enviar</th>
                                </tr>
                            </thead>
                            <tbody>
                                {lista.length === 0 && <tr><td colSpan="6" className="text-center text-muted py-4">Nenhum cliente neste filtro.</td></tr>}
                                {lista.map((l) => {
                                    const a = l.atualizacao
                                    return (
                                        <tr key={l.id}>
                                            <td><input type="checkbox" className="form-check-input" checked={marcados.includes(l.id)} onChange={() => marcar(l.id)} aria-label={`Marcar ${l.nome}`} /></td>
                                            <td><Link href={`/clientes/${l.id}`}>{l.nome}</Link><div className="small text-muted">cód. {l.id}{l.placas.length > 0 && ` · ${l.placas.join(', ')}`}</div></td>
                                            <td className="text-nowrap">{l.documento || '—'}{l.problema_documento && <div><span className="selo selo-erro">{l.problema_documento}</span></div>}</td>
                                            <td className="text-nowrap">{l.celular ? telefone(l.celular) : '—'}{l.problema_celular && <div><span className="selo selo-erro">{l.problema_celular}</span></div>}</td>
                                            <td><Situacao a={a} /></td>
                                            <td className="text-end text-nowrap">
                                                {(!a || ['expirada', 'cancelada', 'recusada', 'aprovada'].includes(a.situacao)) && (
                                                    <button type="button" className="btn btn-sm btn-outline-primary" onClick={() => gerar([l.id], !!a)}>{a?.situacao === 'aprovada' ? 'Novo link' : 'Gerar link'}</button>
                                                )}
                                                {a?.situacao === 'aberta' && (
                                                    <div className="d-inline-flex gap-1">
                                                        {!l.problema_celular && <button type="button" className="btn btn-sm btn-success" onClick={() => whatsapp(l)}>WhatsApp</button>}
                                                        <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => copiar(l)}>{copiado === l.id ? 'Copiado' : 'Copiar'}</button>
                                                        <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => router.get('/atualizacao-cadastral/cartoes', { ids: String(a.id) })}>Cartão</button>
                                                    </div>
                                                )}
                                                {a?.situacao === 'respondida' && <Link href={`/atualizacao-cadastral/${a.id}`} className="btn btn-sm btn-primary">Revisar</Link>}
                                            </td>
                                        </tr>
                                    )
                                })}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            {aba === 'cpf' && (
                <div className="card">
                    <div className="card-header">CPF/CNPJ com problema ({cpf.length})</div>
                    <div className="table-responsive">
                        <table className="table align-middle mb-0">
                            <thead><tr><th>Cliente</th><th>CPF/CNPJ no cadastro</th><th>Problema</th><th>Atualização</th></tr></thead>
                            <tbody>
                                {cpf.length === 0 && <tr><td colSpan="4" className="text-center text-muted py-4">Nenhum CPF/CNPJ com problema.</td></tr>}
                                {cpf.map((l) => (
                                    <tr key={l.id}>
                                        <td><Link href={`/clientes/${l.id}`}>{l.nome}</Link><div className="small text-muted">cód. {l.id}</div></td>
                                        <td className="text-nowrap">{l.documento || '—'}</td>
                                        <td><span className="selo selo-erro">{l.problema_documento}</span></td>
                                        <td><Situacao a={l.atualizacao} /></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <div className="card-footer small text-muted">A conferência pega CPF com dígitos que não batem, sequências como 111.111.111-11 e números repetidos em mais de um cliente. CPF válido de outra pessoa só aparece quando o cliente confere no formulário.</div>
                </div>
            )}

            {aba === 'celular' && (
                <div className="card">
                    <div className="card-header">Sem celular válido ({cel.length}) — recebem o cartão impresso ou o QR Code na cobrança</div>
                    <div className="table-responsive">
                        <table className="table align-middle mb-0">
                            <thead><tr><th>Cliente</th><th>Celular no cadastro</th><th>Problema</th><th>Telefone fixo</th><th>Veículos</th><th>Atualização</th></tr></thead>
                            <tbody>
                                {cel.length === 0 && <tr><td colSpan="6" className="text-center text-muted py-4">Todos têm celular válido.</td></tr>}
                                {cel.map((l) => (
                                    <tr key={l.id}>
                                        <td><Link href={`/clientes/${l.id}`}>{l.nome}</Link><div className="small text-muted">cód. {l.id}</div></td>
                                        <td className="text-nowrap">{l.celular ? telefone(l.celular) : '—'}</td>
                                        <td><span className="selo selo-erro">{l.problema_celular}</span></td>
                                        <td className="text-nowrap">{l.telefone ? telefone(l.telefone) : '—'}</td>
                                        <td>{l.placas.join(', ') || '—'}</td>
                                        <td><Situacao a={l.atualizacao} /></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            {aba === 'revisar' && (
                <div className="card">
                    <div className="card-header">Para revisar ({revisar.length})</div>
                    <ul className="list-group list-group-flush">
                        {revisar.length === 0 && <li className="list-group-item text-muted">Nenhuma resposta esperando revisão.</li>}
                        {revisar.map((l) => (
                            <li key={l.id} className="list-group-item d-flex align-items-center gap-3">
                                <span className="flex-grow-1">{l.nome} <span className="small text-muted">· respondido {dataHora(l.atualizacao.respondido_em)}</span></span>
                                <Link href={`/atualizacao-cadastral/${l.atualizacao.id}`} className="btn btn-sm btn-primary">Revisar</Link>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    )
}
