import { Link, useForm } from '@inertiajs/react'
import TituloPagina from '../../Components/TituloPagina'
import Campo from '../../Components/Campo'

export default function UnidadeForm({ unidade }) {
    const nova = !unidade
    const { data, setData, post, put, processing, errors } = useForm({
        nome: unidade?.nome || '',
        endereco: unidade?.endereco || '',
        telefone: unidade?.telefone || '',
        ativa: unidade ? unidade.ativa : true,
    })

    function salvar(e) {
        e.preventDefault()
        if (nova) post('/unidades')
        else put(`/unidades/${unidade.id}`)
    }

    return (
        <div className="estreito">
            <TituloPagina titulo={nova ? 'Nova unidade' : `Unidade: ${unidade.nome}`} />
            <form onSubmit={salvar} className="card" noValidate>
                <div className="card-body">
                    <Campo rotulo="Nome da unidade" value={data.nome} erro={errors.nome} maxLength={60}
                        onChange={(e) => setData('nome', e.target.value)} />
                    <Campo rotulo="Endereço" value={data.endereco} erro={errors.endereco} maxLength={160}
                        onChange={(e) => setData('endereco', e.target.value)} />
                    <Campo rotulo="Telefone" value={data.telefone} erro={errors.telefone} maxLength={20} inputMode="tel"
                        onChange={(e) => setData('telefone', e.target.value)} />
                    <div className="form-check form-switch">
                        <input className="form-check-input" type="checkbox" role="switch" id="ativa"
                            checked={data.ativa} onChange={(e) => setData('ativa', e.target.checked)} />
                        <label className="form-check-label" htmlFor="ativa">Ativa</label>
                    </div>
                    {errors.ativa && <div className="text-danger small mt-1">{errors.ativa}</div>}
                </div>
                <div className="card-footer bg-transparent d-flex gap-2 justify-content-end p-3">
                    <Link href="/empresa" className="btn btn-outline-secondary">Cancelar</Link>
                    <button type="submit" className="btn btn-primary" disabled={processing}>Salvar</button>
                </div>
            </form>
        </div>
    )
}
