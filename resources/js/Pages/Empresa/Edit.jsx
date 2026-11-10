import { Link, useForm } from '@inertiajs/react'
import TituloPagina from '../../Components/TituloPagina'
import Campo from '../../Components/Campo'
import Icone from '../../Components/Icone'

export default function EmpresaEdit({ empresa, unidades }) {
    const { data, setData, put, processing, errors } = useForm({
        nome: empresa.nome || '',
        razao_social: empresa.razao_social || '',
        documento: empresa.documento || '',
        telefone: empresa.telefone || '',
        email: empresa.email || '',
    })

    function salvar(e) {
        e.preventDefault()
        put('/empresa', { preserveScroll: true })
    }

    return (
        <div className="pagina">
            <TituloPagina titulo="Empresa e unidades" />
            <div className="row g-4">
                <div className="col-lg-6">
                    <form onSubmit={salvar} className="card" noValidate>
                        <div className="card-header">Dados da empresa</div>
                        <div className="card-body">
                            <Campo rotulo="Nome (aparece no sistema)" value={data.nome} erro={errors.nome} maxLength={80}
                                onChange={(e) => setData('nome', e.target.value)} />
                            <Campo rotulo="Razão social" value={data.razao_social} erro={errors.razao_social} maxLength={120}
                                onChange={(e) => setData('razao_social', e.target.value)} />
                            <Campo rotulo="CNPJ ou CPF" value={data.documento} erro={errors.documento} maxLength={18} inputMode="numeric"
                                onChange={(e) => setData('documento', e.target.value)} />
                            <div className="row g-2">
                                <div className="col-sm-6">
                                    <Campo rotulo="Telefone" value={data.telefone} erro={errors.telefone} maxLength={20} inputMode="tel"
                                        onChange={(e) => setData('telefone', e.target.value)} />
                                </div>
                                <div className="col-sm-6">
                                    <Campo rotulo="E-mail" type="email" value={data.email} erro={errors.email} maxLength={120}
                                        onChange={(e) => setData('email', e.target.value)} />
                                </div>
                            </div>
                        </div>
                        <div className="card-footer bg-transparent d-flex justify-content-end p-3">
                            <button type="submit" className="btn btn-primary" disabled={processing}>Salvar</button>
                        </div>
                    </form>
                </div>

                <div className="col-lg-6">
                    <div className="card">
                        <div className="card-header d-flex align-items-center">
                            <span className="flex-grow-1">Unidades (pátios)</span>
                            <Link href="/unidades/nova" className="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1">
                                <Icone nome="mais" tamanho={16} /> Nova unidade
                            </Link>
                        </div>
                        <div className="table-responsive">
                            <table className="table table-hover align-middle">
                                <thead><tr><th>Unidade</th><th>Endereço</th><th>Situação</th><th /></tr></thead>
                                <tbody>
                                    {unidades.map((u) => (
                                        <tr key={u.id}>
                                            <td className="fw-semibold">{u.nome}</td>
                                            <td className="text-muted">{u.endereco}</td>
                                            <td>{u.ativa ? <span className="selo selo-ok">Ativa</span> : <span className="selo">Inativa</span>}</td>
                                            <td className="text-end">
                                                <Link href={`/unidades/${u.id}`} className="btn btn-sm btn-outline-secondary" aria-label={`Editar ${u.nome}`}>
                                                    <Icone nome="lapis" tamanho={16} />
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    )
}
