import { Link, router } from '@inertiajs/react'
import { useRef, useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import Janela from '../../Components/Janela'
import Icone from '../../Components/Icone'
import EscolhasNota, { EtiquetasNota } from '../../Components/EscolhasNota'
import { moeda } from '../../lib/formato'
import { postarJson } from '../../lib/json'

// "Mensalidade · Mensalidade Diurno" vira "Mensalidade Diurno"
const nomeServico = (s) => (s.descricao?.toLowerCase().startsWith(s.tipo.split('/')[0].toLowerCase()) ? s.descricao : `${s.tipo} · ${s.descricao}`)

/**
 * Notas fiscais › conferir e emitir: os clientes das cobranças marcadas, cada um com os serviços sem nota delas (dá para tirar
 * um serviço) e as escolhas dele. Emite um cliente (botão na linha) ou em lote: a tela manda uma nota de cada vez,
 * com progresso; recusada fica marcada com o motivo e as outras seguem.
 */
export default function Conferir({ clientes, filtros, cobrancas = [], faltando, ambiente, competenciaAtual, aliquota, retFederal }) {
    const [linhas, setLinhas] = useState(() => clientes.map((c) => ({
        ...c, incluir: true, marcados: new Set(c.servicos.map((s) => s.id)), estado: null,
        esc: { identificar: c.identificar, retido: c.retido && c.pode_reter, retem_federal: retFederal > 0 && c.federal && c.identificar },
    })))
    const [editando, setEditando] = useState(null)
    const [lote, setLote] = useState(null)        // { feitas, total }
    const parar = useRef(false)
    const pode = !faltando.length && aliquota

    const mudar = (id, f) => setLinhas((ls) => ls.map((l) => (l.id === id ? { ...l, ...f(l) } : l)))
    const totalDe = (l) => l.servicos.filter((s) => l.marcados.has(s.id)).reduce((t, s) => t + s.valor, 0)
    const pendente = (l) => l.estado?.situacao !== 'emitida' && l.estado?.situacao !== 'enviando'
    const prontas = linhas.filter((l) => l.incluir && l.marcados.size > 0 && pendente(l))
    const totalLote = prontas.reduce((t, l) => t + totalDe(l), 0)

    const emitirUma = async (l) => {
        mudar(l.id, () => ({ estado: { situacao: 'emitindo' } }))
        const r = await postarJson('/notas-fiscais/emitir', { ordens: [...l.marcados], ...l.esc })
        mudar(l.id, () => ({ estado: r }))
        return r
    }
    const emitirLote = async () => {
        if (!window.confirm(`Emitir ${prontas.length} nota${prontas.length > 1 ? 's' : ''}, total de ${moeda(totalLote)}${ambiente !== 'producao' ? ' (em testes)' : ''}?`)) return
        parar.current = false
        const fila = [...prontas]
        setLote({ feitas: 0, total: fila.length })
        for (let i = 0; i < fila.length; i++) {
            if (parar.current) break
            await emitirUma(fila[i])
            setLote({ feitas: i + 1, total: fila.length })
        }
        setLote((x) => ({ ...x, fim: true }))
    }
    const emitidas = linhas.filter((l) => l.estado?.situacao === 'emitida').length

    return (
        <div className="pagina">
            <Link href={`/notas-fiscais?aba=cobrancas&mes=${filtros.mes}`} className="small d-inline-block mb-2">‹ Voltar para as cobranças</Link>
            <TituloPagina titulo="Conferir e emitir" descricao={`Serviços sem nota ${cobrancas.length === 1 ? `da cobrança ${cobrancas[0]}` : `de ${cobrancas.length} cobranças`}. Competência ${competenciaAtual.slice(5)}/${competenciaAtual.slice(0, 4)} (data da emissão).`} />
            {faltando.length > 0 && <div className="alert alert-warning">Para emitir, falta: {faltando.join(', ')}. <Link href="/configuracoes/nfse">Configurar</Link></div>}
            {!aliquota && <div className="alert alert-warning">Falta informar as alíquotas do mês, no alto de <Link href="/notas-fiscais">Notas fiscais</Link>.</div>}
            {!faltando.length && ambiente !== 'producao' && <div className="alert alert-info py-2">Em <strong>testes</strong>: as notas saem sem valor fiscal.</div>}

            {linhas.map((l) => {
                const total = totalDe(l)
                const st = l.estado?.situacao
                return (
                    <div key={l.id} className={`card mb-2${!l.incluir ? ' opacity-50' : ''}${st === 'emitida' ? ' border-success' : st === 'erro' || st === 'rejeitada' ? ' border-danger' : ''}`}>
                        <div className="card-body py-2">
                            <div className="d-flex flex-wrap align-items-center gap-2">
                                <input className="form-check-input mt-0" type="checkbox" checked={l.incluir} disabled={!pendente(l)} aria-label={`Incluir ${l.nome}`}
                                    onChange={(e) => mudar(l.id, () => ({ incluir: e.target.checked }))} />
                                <div className="me-auto">
                                    <strong>{l.nome}</strong> <span className="small text-muted">{l.documento || 'sem CPF/CNPJ'}</span>
                                    <div><EtiquetasNota esc={l.esc} retFederal={retFederal} /></div>
                                </div>
                                <strong className="text-nowrap">{moeda(total)}</strong>
                                {pendente(l) && (
                                    <>
                                        <button type="button" className="btn btn-sm btn-outline-secondary" title="Escolhas deste cliente" aria-label="Escolhas deste cliente" disabled={!!lote && !lote.fim} onClick={() => setEditando(l)}><Icone nome="engrenagem" /></button>
                                        <button type="button" className="btn btn-sm btn-primary" disabled={!pode || !l.marcados.size || st === 'emitindo' || (lote && !lote.fim)} onClick={() => emitirUma(l)}>{st === 'emitindo' ? 'Emitindo…' : 'Emitir'}</button>
                                    </>
                                )}
                                {st === 'emitida' && <span className="selo selo-ok">{l.estado.mensagem}</span>}
                                {st === 'enviando' && <span className="selo selo-aviso">Enviando: confira em Notas</span>}
                            </div>
                            {(st === 'erro' || st === 'rejeitada') && <div className="alert alert-danger py-1 px-2 small mt-2 mb-0">{l.estado.mensagem}</div>}
                            <ul className="list-unstyled small text-muted mb-0 mt-2 ms-4">
                                {l.servicos.map((s) => (
                                    <li key={s.id} className="d-flex gap-2 align-items-center">
                                        <input className="form-check-input mt-0" type="checkbox" checked={l.marcados.has(s.id)} disabled={!pendente(l)} aria-label={`Incluir OS ${s.numero}`}
                                            onChange={(e) => mudar(l.id, (x) => { const m = new Set(x.marcados); e.target.checked ? m.add(s.id) : m.delete(s.id); return { marcados: m } })} />
                                        <span className="me-auto">{s.data} · OS {s.numero} · {nomeServico(s)}{s.placa && ` · ${s.placa}`}{s.pago ? '' : ' · em aberto'}</span>
                                        <span className="text-nowrap">{moeda(s.valor)}</span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </div>
                )
            })}

            <div className="d-flex flex-wrap align-items-center gap-2 mt-3 p-3 border rounded bg-body sticky-bottom">
                {lote && !lote.fim && (
                    <div className="w-100">
                        <div className="progress mb-1" role="progressbar" aria-valuenow={lote.feitas} aria-valuemin="0" aria-valuemax={lote.total}>
                            <div className="progress-bar" style={{ width: `${(lote.feitas / lote.total) * 100}%` }} />
                        </div>
                        <span className="small">{lote.feitas} de {lote.total}</span>
                    </div>
                )}
                <span><strong>{prontas.length}</strong> nota{prontas.length === 1 ? '' : 's'} · <strong>{moeda(totalLote)}</strong>{emitidas > 0 && <span className="text-success"> · {emitidas} emitida{emitidas > 1 ? 's' : ''}</span>}</span>
                {lote && !lote.fim
                    ? <button type="button" className="btn btn-outline-danger ms-auto" onClick={() => { parar.current = true }}>Parar</button>
                    : <button type="button" className="btn btn-primary ms-auto" disabled={!pode || !prontas.length} onClick={emitirLote}>Emitir em lote</button>}
            </div>

            {editando && (
                <Janela aberta titulo={`Escolhas · ${editando.nome}`} rotuloOk="Usar" onFechar={() => setEditando(null)}
                    onEnviar={() => { mudar(editando.id, () => ({ esc: editando.esc })); setEditando(null) }}>
                    <EscolhasNota cliente={editando} valor={totalDe(editando)} esc={editando.esc} setEsc={(esc) => setEditando({ ...editando, esc })} retFederal={retFederal} aliquota={aliquota} />
                    <p className="small text-muted mb-0">Vale só para esta nota. O padrão de cada cliente fica no cadastro dele (Nota fiscal).</p>
                </Janela>
            )}
        </div>
    )
}
