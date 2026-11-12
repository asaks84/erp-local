import { Link, router } from '@inertiajs/react'
import { useEffect, useRef, useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import Icone from '../../Components/Icone'
import Paginacao from '../../Components/Paginacao'
import AbasClientes from '../../Components/AbasClientes'
import { telefone } from '../../lib/mascaras'

export default function ClientesIndex({ clientes, filtros }) {
    const [q, setQ] = useState(filtros.q)
    const primeira = useRef(true)

    // filtra enquanto digita (espera a pessoa parar)
    useEffect(() => {
        if (primeira.current) { primeira.current = false; return }
        const t = setTimeout(() => router.get('/clientes', { q: q || undefined, situacao: filtros.situacao !== 'ativos' ? filtros.situacao : undefined }, { preserveState: true, replace: true }), 300)
        return () => clearTimeout(t)
    }, [q]) // eslint-disable-line react-hooks/exhaustive-deps

    function situacao(s) {
        router.get('/clientes', { q: q || undefined, situacao: s !== 'ativos' ? s : undefined }, { preserveState: true })
    }

    return (
        <div className="pagina">
            <AbasClientes />
            <TituloPagina titulo="Clientes">
                <Link href="/clientes/novo" className="btn btn-primary d-inline-flex align-items-center gap-2"><Icone nome="mais" /> Novo cliente</Link>
            </TituloPagina>

            <div className="row g-2 mb-3">
                <div className="col-md-8">
                    <input type="search" className="form-control" placeholder="Nome, código, CPF/CNPJ ou placa" value={q}
                        onChange={(e) => setQ(e.target.value)} aria-label="Buscar cliente" autoFocus />
                </div>
                <div className="col-md-4">
                    <div className="btn-group w-100" role="group" aria-label="Situação">
                        {[['ativos', 'Ativos'], ['inativos', 'Inativos'], ['todos', 'Todos']].map(([v, r]) => (
                            <button key={v} type="button" className={`btn ${filtros.situacao === v ? 'btn-primary' : 'btn-outline-secondary'}`} onClick={() => situacao(v)}>{r}</button>
                        ))}
                    </div>
                </div>
            </div>

            <div className="card">
                <div className="table-responsive">
                    <table className="table table-hover align-middle">
                        <thead>
                            <tr><th>Cód.</th><th>Nome</th><th className="d-none d-md-table-cell">CPF/CNPJ</th><th>Veículos</th><th className="d-none d-lg-table-cell">Celular</th><th /></tr>
                        </thead>
                        <tbody>
                            {clientes.data.length === 0 && <tr><td colSpan="6" className="text-center text-muted py-4">Nenhum cliente encontrado.</td></tr>}
                            {clientes.data.map((c) => (
                                <tr key={c.id} className={c.ativo ? undefined : 'inativo'} role="link" style={{ cursor: 'pointer' }} onClick={() => router.visit(`/clientes/${c.id}`)}>
                                    <td className="text-muted">{c.id}</td>
                                    <td>
                                        <span className="fw-semibold">{c.nome}</span>
                                        {c.mensalista && <span className="selo selo-marca ms-2">Mensalista</span>}
                                        {!c.ativo && <span className="selo ms-2">Inativo</span>}
                                    </td>
                                    <td className="d-none d-md-table-cell">{c.documento}</td>
                                    <td className="small">{c.veiculos.join(', ')}</td>
                                    <td className="d-none d-lg-table-cell text-nowrap">{telefone(c.celular)}</td>
                                    <td className="text-end"><Link href={`/clientes/${c.id}`} className="btn btn-sm btn-outline-secondary" onClick={(e) => e.stopPropagation()}>Abrir</Link></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
            <Paginacao pagina={clientes} />
        </div>
    )
}
