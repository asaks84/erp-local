import TituloPagina from '../../Components/TituloPagina'
import { dataHora } from '../../lib/formato'

const SELO = { ok: 'selo-ok', diferente: 'selo-erro', erro: 'selo-erro' }

function duracao(s) {
    if (s == null) return ''
    return s < 60 ? `${s}s` : `${Math.floor(s / 60)}min ${s % 60}s`
}

/** Sistema › Conferência com o sistema atual (paralelo da etapa 5). */
export default function Conferencia({ ultima, historico, dias_ok, espelho_ativo, situacoes }) {
    return (
        <div className="pagina">
            <TituloPagina titulo="Conferência com o sistema atual"
                descricao="Durante o paralelo, o ERP importa os dados do sistema atual de hora em hora (7h às 23h) e compara os números. A virada só acontece depois de algumas semanas sem diferença." />

            {!espelho_ativo && <div className="alert alert-warning">O espelho automático está desligado neste servidor (ERP_ESPELHO_ATIVO).</div>}

            <div className="row g-3 mb-4">
                <div className="col-6 col-lg-3">
                    <div className="card h-100"><div className="card-body">
                        <div className="text-muted small">Última conferência</div>
                        <div className="fs-5 fw-bold">{ultima ? dataHora(ultima.executada_em) : '—'}</div>
                        {ultima && <span className={`selo ${SELO[ultima.situacao]}`}>{situacoes[ultima.situacao]}</span>}
                    </div></div>
                </div>
                <div className="col-6 col-lg-3">
                    <div className="card h-100"><div className="card-body">
                        <div className="text-muted small">Dias seguidos sem diferença</div>
                        <div className={`fs-3 fw-bold ${dias_ok > 0 ? 'text-success' : ''}`}>{dias_ok}</div>
                    </div></div>
                </div>
            </div>

            {ultima?.mensagem && <div className="alert alert-danger"><strong>Erro na última importação:</strong> <span className="text-break">{ultima.mensagem}</span></div>}

            {ultima?.linhas?.length > 0 && (
                <div className="card mb-4">
                    <div className="card-header">Números da última conferência</div>
                    <div className="table-responsive">
                        <table className="table mb-0 align-middle">
                            <thead><tr><th>Item</th><th className="text-end">Sistema atual</th><th className="text-end">ERP</th><th>Situação</th></tr></thead>
                            <tbody>
                                {ultima.linhas.map((l, i) => (
                                    <tr key={i} className={l[3] === 'DIFERENTE' ? 'table-danger' : ''}>
                                        <td>{l[0]}</td><td className="text-end">{l[1]}</td><td className="text-end">{l[2]}</td>
                                        <td>{l[3] === 'DIFERENTE' ? <span className="selo selo-erro">Diferente</span> : l[3] === 'ok' ? <span className="selo selo-ok">Igual</span> : <span className="small text-muted">{l[3]}</span>}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            <div className="card">
                <div className="card-header">Histórico</div>
                <div className="table-responsive">
                    <table className="table mb-0 align-middle">
                        <thead><tr><th>Quando</th><th>Resultado</th><th className="d-none d-md-table-cell">Detalhe</th><th className="text-end">Tempo</th></tr></thead>
                        <tbody>
                            {historico.length === 0 && <tr><td colSpan={4} className="text-muted">Nenhuma conferência ainda.</td></tr>}
                            {historico.map((c) => (
                                <tr key={c.id}>
                                    <td className="text-nowrap">{dataHora(c.executada_em)}</td>
                                    <td><span className={`selo ${SELO[c.situacao]}`}>{situacoes[c.situacao]}</span></td>
                                    <td className="d-none d-md-table-cell small text-muted text-break">{c.mensagem || (c.diferencas ? `${c.diferencas} item(ns) diferente(s)` : '')}</td>
                                    <td className="text-end small text-muted">{duracao(c.duracao_seg)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    )
}
