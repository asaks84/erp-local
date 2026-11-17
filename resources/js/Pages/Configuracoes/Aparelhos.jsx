import { router, useForm } from '@inertiajs/react'
import { useState } from 'react'
import AbasConfiguracoes from '../../Components/AbasConfiguracoes'
import TituloPagina from '../../Components/TituloPagina'
import Campo from '../../Components/Campo'
import { dataHora } from '../../lib/formato'

const SITUACAO = {
    pareado: ['Pareado', 'selo-ok'], aguardando: ['Aguardando o código', 'selo-aviso'], sem_codigo: ['Sem código', ''], desativado: ['Desativado', ''],
}

/** Configurações › Aparelhos (PDV): o celular do balcão é pareado com um código de 6 dígitos. */
export default function Aparelhos({ aparelhos, unidades, codigo, pin_memoria_min: memoria, endereco_pdv: enderecoPdv }) {
    const novo = useForm({ nome: '', unidade_id: '' })
    const cfg = useForm({ pin_memoria_min: memoria })
    const [desativar, setDesativar] = useState(null)

    return (
        <div className="pagina">
            <AbasConfiguracoes />
            <TituloPagina titulo="Aparelhos (PDV)" descricao="Celulares do balcão. Cada um é pareado com um código de uso único e pode ser desativado a qualquer momento." />

            {codigo && (
                <div className="alert alert-success">
                    <div className="mb-1">Código de pareamento de <strong>{codigo.aparelho}</strong> (vale {codigo.minutos} minutos, uma vez só):</div>
                    <div className="display-6 fw-bold font-monospace" style={{ letterSpacing: '.3em' }}>{codigo.codigo}</div>
                    <div className="small mt-1">No celular, abra <strong>{enderecoPdv}</strong> e digite o código.</div>
                </div>
            )}

            <div className="row g-4">
                <div className="col-lg-8">
                    <div className="card">
                        <div className="card-header">Aparelhos</div>
                        <div className="table-responsive">
                            <table className="table align-middle mb-0">
                                <thead><tr><th>Nome</th><th>Situação</th><th className="d-none d-md-table-cell">Último acesso</th><th /></tr></thead>
                                <tbody>
                                    {aparelhos.length === 0 && <tr><td colSpan="4" className="text-center text-muted py-4">Nenhum aparelho ainda.</td></tr>}
                                    {aparelhos.map((a) => (
                                        <tr key={a.id} className={a.situacao === 'desativado' ? 'inativo' : undefined}>
                                            <td><div className="fw-semibold">{a.nome}</div><div className="small text-muted">{a.unidade || 'Unidade padrão'}{a.versao_app ? ` · app ${a.versao_app}` : ''}</div></td>
                                            <td><span className={`selo ${SITUACAO[a.situacao][1]}`}>{SITUACAO[a.situacao][0]}</span>{a.situacao === 'aguardando' && <div className="small text-muted">até {dataHora(a.codigo_expira_em)}</div>}</td>
                                            <td className="d-none d-md-table-cell small text-muted">{a.ultimo_acesso_em ? dataHora(a.ultimo_acesso_em) : '—'}</td>
                                            <td className="text-end text-nowrap">
                                                <button type="button" className="btn btn-sm btn-outline-secondary me-1" onClick={() => router.post(`/configuracoes/aparelhos/${a.id}/codigo`)}>
                                                    {a.situacao === 'pareado' ? 'Parear de novo' : 'Gerar código'}
                                                </button>
                                                {a.situacao !== 'desativado' && (desativar === a.id
                                                    ? <button type="button" className="btn btn-sm btn-danger" onClick={() => router.post(`/configuracoes/aparelhos/${a.id}/desativar`, {}, { onFinish: () => setDesativar(null) })}>Confirmar</button>
                                                    : <button type="button" className="btn btn-sm btn-outline-danger" onClick={() => setDesativar(a.id)}>Desativar</button>)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <div className="card-footer small text-muted">"Parear de novo" gera outro código e desliga o celular que estava pareado (ex.: celular trocado ou perdido).</div>
                    </div>
                </div>
                <div className="col-lg-4">
                    <form className="card mb-4" onSubmit={(e) => { e.preventDefault(); novo.post('/configuracoes/aparelhos', { onSuccess: () => novo.reset() }) }}>
                        <div className="card-header">Novo aparelho</div>
                        <div className="card-body">
                            <Campo rotulo="Nome (ex.: Celular do balcão)" value={novo.data.nome} maxLength={60} onChange={(e) => novo.setData('nome', e.target.value)} erro={novo.errors.nome} />
                            {unidades.length > 1 && (
                                <Campo as="select" rotulo="Unidade" value={novo.data.unidade_id} onChange={(e) => novo.setData('unidade_id', e.target.value)}>
                                    <option value="">Unidade padrão</option>
                                    {unidades.map((u) => <option key={u.id} value={u.id}>{u.nome}</option>)}
                                </Campo>
                            )}
                            <button type="submit" className="btn btn-primary w-100" disabled={novo.processing}>Cadastrar e gerar código</button>
                        </div>
                    </form>
                    <form className="card" onSubmit={(e) => { e.preventDefault(); cfg.put('/configuracoes/aparelhos/config', { preserveScroll: true }) }}>
                        <div className="card-header">PIN</div>
                        <div className="card-body">
                            <Campo as="select" rotulo="Pedir o PIN" value={cfg.data.pin_memoria_min} onChange={(e) => cfg.setData('pin_memoria_min', Number(e.target.value))}>
                                <option value={0}>A cada ação</option>
                                <option value={1}>Lembrar por 1 minuto</option>
                                <option value={2}>Lembrar por 2 minutos</option>
                                <option value={5}>Lembrar por 5 minutos</option>
                                <option value={10}>Lembrar por 10 minutos</option>
                            </Campo>
                            <p className="small text-muted">Cada pessoa cadastra o próprio PIN no PDV, com o login e a senha dela. O PIN aparece em Usuários (cadastrado ou não) e pode ser apagado lá.</p>
                            <button type="submit" className="btn btn-outline-primary w-100" disabled={cfg.processing}>Salvar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    )
}
