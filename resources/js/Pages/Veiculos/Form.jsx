import { Link, useForm } from '@inertiajs/react'
import TituloPagina from '../../Components/TituloPagina'
import CamposVeiculo from '../../Components/CamposVeiculo'
import CamposMensalidade from '../../Components/CamposMensalidade'

const hoje = () => new Date().toISOString().slice(0, 10)

export default function VeiculoForm({ cliente, veiculo, tipos, unidades, periodos }) {
    const novo = !veiculo
    const { data, setData, post, put, processing, errors } = useForm({
        placa: veiculo?.placa || '', identificacao: veiculo?.identificacao || '', sem_placa: veiculo ? !veiculo.placa : false,
        tipo_veiculo_id: veiculo?.tipo_veiculo_id || '', marca: veiculo?.marca || '', modelo: veiculo?.modelo || '', cor: veiculo?.cor || '',
        mensalista: false, periodo: periodos[0] || '', valor: '', inicio: hoje(), unidade_id: unidades[0]?.id || '',
    })
    const voltar = cliente ? `/clientes/${cliente.id}` : '/clientes'

    function salvar(e) {
        e.preventDefault()
        novo ? post(`/clientes/${cliente.id}/veiculos`) : put(`/veiculos/${veiculo.id}`)
    }

    return (
        <div className="estreito">
            <TituloPagina titulo={novo ? 'Novo veículo' : 'Editar veículo'} descricao={cliente ? `Cliente: ${cliente.nome}` : null} />
            <form onSubmit={salvar} className="card" noValidate>
                <div className="card-body">
                    <CamposVeiculo data={data} setData={setData} errors={errors} tipos={tipos} largo />
                    {!novo && data.placa !== (veiculo.placa || '') && veiculo.placa && (
                        <div className="alert aviso-neutro small">A troca de placa fica registrada no histórico do veículo.</div>
                    )}
                    {novo && (<><hr /><CamposMensalidade data={data} setData={setData} errors={errors} periodos={periodos} unidades={unidades} /></>)}
                    {!novo && <p className="text-muted small mb-0">A mensalidade é alterada pela ficha do cliente (reajustar, pausar, encerrar).</p>}
                </div>
                <div className="card-footer bg-transparent d-flex gap-2 justify-content-end p-3">
                    <Link href={voltar} className="btn btn-outline-secondary">Cancelar</Link>
                    <button type="submit" className="btn btn-primary" disabled={processing}>Salvar</button>
                </div>
            </form>
        </div>
    )
}
