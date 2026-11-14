import { Link, router, useForm } from '@inertiajs/react'
import { useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import Janela from '../../Components/Janela'
import Campo from '../../Components/Campo'
import { dataHora } from '../../lib/formato'
import { cep as mCep, cpfCnpj, telefone } from '../../lib/mascaras'

const FORMATO = { documento: cpfCnpj, celular: telefone, telefone, cep: mCep }
const mostrar = (campo, v) => (v === null || v === undefined || v === '' ? <span className="text-muted">—</span> : (FORMATO[campo] ? FORMATO[campo](String(v)) : v))

/** Revisão do que o cliente mandou na atualização cadastral: antes → depois, aprovar ou recusar. */
export default function RevisarAtualizacao({ atualizacao: a, cliente, linhas, documento_valido: docOk }) {
    const [recusar, setRecusar] = useState(false)
    const [ocupado, setOcupado] = useState(false)
    const form = useForm({ motivo: '' })
    const mudou = linhas.filter((l) => l.mudou).length
    const respondida = a.situacao === 'respondida'

    return (
        <div className="pagina">
            <TituloPagina titulo={`Atualização cadastral · ${cliente.nome}`}
                descricao={a.respondido_em ? `Respondido em ${dataHora(a.respondido_em)} · autorização de uso dos dados em ${dataHora(a.consentimento_em)}` : null} />
            {!respondida && <div className="alert aviso-neutro">Situação: {a.situacao}{a.motivo_recusa && ` · ${a.motivo_recusa}`}{a.revisado_em && ` · revisado em ${dataHora(a.revisado_em)}`}</div>}
            <div className="row g-4">
                <div className="col-xl-8">
                    <div className="card">
                        <div className="card-header d-flex align-items-center">
                            <span className="flex-grow-1">O que o cliente mandou</span>
                            {respondida && <span className="small text-muted">{mudou ? `${mudou} campo(s) diferente(s)` : 'nada diferente do cadastro'}</span>}
                        </div>
                        <div className="table-responsive">
                            <table className="table align-middle mb-0">
                                <thead><tr><th>Campo</th><th>No cadastro</th><th>O cliente mandou</th></tr></thead>
                                <tbody>
                                    {linhas.map((l) => (
                                        <tr key={l.campo} className={l.mudou ? 'table-warning' : undefined}>
                                            <td className="fw-semibold">{l.rotulo}</td>
                                            <td className={l.mudou ? 'text-decoration-line-through text-muted' : undefined}>{mostrar(l.campo, l.antes)}</td>
                                            <td className={l.mudou ? 'fw-semibold' : undefined}>{mostrar(l.campo, l.depois)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div className="col-xl-4">
                    <div className="card">
                        <div className="card-body">
                            <p className="small">{docOk ? 'O CPF/CNPJ enviado tem os dígitos certos.' : 'Atenção: o CPF/CNPJ enviado não confere.'}</p>
                            <p className="small text-muted">Ao aprovar, os campos diferentes passam para o cadastro e ficam valendo pelo ERP: o sistema atual não sobrescreve mais esses campos deste cliente.</p>
                            <div className="d-flex flex-wrap gap-2">
                                <Link href="/atualizacao-cadastral" className="btn btn-outline-secondary">Voltar</Link>
                                {respondida && <button type="button" className="btn btn-outline-danger" onClick={() => setRecusar(true)}>Recusar</button>}
                                {respondida && (
                                    <button type="button" className="btn btn-primary ms-auto" disabled={ocupado}
                                        onClick={() => { setOcupado(true); router.post(`/atualizacao-cadastral/${a.id}/aprovar`, {}, { onFinish: () => setOcupado(false) }) }}>
                                        Aprovar
                                    </button>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <Janela aberta={recusar} titulo="Recusar atualização" perigo rotuloOk="Recusar" onFechar={() => setRecusar(false)} ocupado={form.processing}
                onEnviar={() => form.post(`/atualizacao-cadastral/${a.id}/recusar`, { onSuccess: () => setRecusar(false) })}>
                <p>Nada muda no cadastro. Se precisar, gere um link novo para o cliente.</p>
                <Campo rotulo="Motivo" value={form.data.motivo} onChange={(e) => form.setData('motivo', e.target.value)} erro={form.errors.motivo} maxLength={200} autoFocus />
            </Janela>
        </div>
    )
}
