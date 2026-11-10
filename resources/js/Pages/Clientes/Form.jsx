import { Link, useForm } from '@inertiajs/react'
import TituloPagina from '../../Components/TituloPagina'
import CamposCliente from '../../Components/CamposCliente'

export default function ClienteForm({ cliente, dia_padrao }) {
    const novo = !cliente
    const { data, setData, post, put, processing, errors } = useForm({
        documento: cliente?.documento || '', nome: cliente?.nome || '', celular: cliente?.celular || '', telefone: cliente?.telefone || '',
        email: cliente?.email || '', cep: cliente?.cep || '', logradouro: cliente?.logradouro || '', numero: cliente?.numero || '',
        complemento: cliente?.complemento || '', bairro: cliente?.bairro || '', cidade: cliente?.cidade || '', uf: cliente?.uf || '',
        dia_vencimento: cliente?.dia_vencimento ?? dia_padrao, cliente_desde: cliente?.cliente_desde || '',
        observacoes: cliente?.observacoes || '', nao_recebe_avisos: cliente?.nao_recebe_avisos || false,
    })

    function salvar(e) {
        e.preventDefault()
        novo ? post('/clientes') : put(`/clientes/${cliente.id}`)
    }

    return (
        <div className="estreito">
            <TituloPagina titulo={novo ? 'Novo cliente' : 'Editar cliente'} />
            <form onSubmit={salvar} className="card" noValidate>
                <div className="card-body">
                    <CamposCliente data={data} setData={setData} errors={errors} />
                </div>
                <div className="card-footer bg-transparent d-flex gap-2 justify-content-end p-3">
                    <Link href={novo ? '/clientes' : `/clientes/${cliente.id}`} className="btn btn-outline-secondary">Cancelar</Link>
                    <button type="submit" className="btn btn-primary" disabled={processing}>Salvar</button>
                </div>
            </form>
        </div>
    )
}
