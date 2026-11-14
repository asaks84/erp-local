import { Link, router, useForm } from '@inertiajs/react'
import { useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import CamposCliente from '../../Components/CamposCliente'
import CamposVeiculo from '../../Components/CamposVeiculo'
import CamposMensalidade from '../../Components/CamposMensalidade'
import Confirmar from '../../Components/Confirmar'
import { dataHora } from '../../lib/formato'

const hoje = () => new Date().toISOString().slice(0, 10)

export default function PendenteRevisar({ pendente, existente, tipos, unidades, periodos, dia_padrao }) {
    const [recusar, setRecusar] = useState(false)
    const { data, setData, post, transform, processing, errors } = useForm({
        modo: existente ? 'vincular' : 'novo',
        documento: pendente.documento || '', nome: pendente.nome || '', celular: pendente.celular || '', telefone: pendente.telefone || '',
        email: pendente.email || '', cep: pendente.cep || '', logradouro: pendente.logradouro || '', numero: pendente.numero || '',
        complemento: pendente.complemento || '', bairro: pendente.bairro || '', cidade: pendente.cidade || '', uf: pendente.uf || '',
        dia_vencimento: dia_padrao, cliente_desde: hoje(), observacoes: '', nao_recebe_avisos: false,
        placa: pendente.placa || '', marca: pendente.marca || '', modelo: pendente.modelo || '', cor: pendente.cor || '',
        tipo_veiculo_id: pendente.tipo_veiculo_id || '',
        mensalista: true, periodo: periodos[0] || '', valor: '', inicio: hoje(), unidade_id: unidades[0]?.id || '',
    })

    function aprovar(modo) {
        transform((d) => ({ ...d, modo }))
        post(`/pendentes/${pendente.id}/aprovar`, { preserveScroll: true })
    }

    return (
        <div className="estreito">
            <TituloPagina titulo="Revisar cadastro" descricao={`Recebido em ${dataHora(pendente.recebido_em)} · autorização de uso dos dados em ${dataHora(pendente.consentimento_em)}`} />
            {existente && (
                <div className="alert alert-warning">
                    Este CPF/CNPJ já é do cliente <Link href={`/clientes/${existente.id}`}>{existente.nome} (cód. {existente.id})</Link>.
                    Se for a mesma pessoa, use <strong>Adicionar veículo ao cliente</strong>.
                </div>
            )}
            {pendente.do_sistema_atual && (
                <div className="alert alert-warning">
                    <strong>Esta ficha veio do link do sistema atual.</strong> Aprove ou recuse lá: aprovar aqui cria o cliente duas vezes (com códigos diferentes). Ela sai desta lista na próxima importação.
                </div>
            )}
            {errors.modo && <div className="alert alert-danger">{errors.modo}</div>}
            <form onSubmit={(e) => e.preventDefault()} className="card" noValidate>
                <div className="card-header">Cliente</div>
                <div className="card-body">
                    <CamposCliente data={data} setData={setData} errors={errors} largo />
                </div>
                <div className="card-header border-top">Veículo</div>
                <div className="card-body">
                    <CamposVeiculo data={data} setData={setData} errors={errors} tipos={tipos} semPlacaPermitido={false} largo />
                    <hr />
                    <CamposMensalidade data={data} setData={setData} errors={errors} periodos={periodos} unidades={unidades} />
                </div>
                <div className="card-footer bg-transparent d-flex flex-wrap gap-2 p-3">
                    {!pendente.do_sistema_atual && <button type="button" className="btn btn-outline-danger me-auto" onClick={() => setRecusar(true)}>Recusar</button>}
                    <Link href="/pendentes" className={`btn btn-outline-secondary ${pendente.do_sistema_atual ? 'ms-auto' : ''}`}>Voltar</Link>
                    {pendente.do_sistema_atual ? null : existente
                        ? <button type="button" className="btn btn-primary" disabled={processing} onClick={() => aprovar('vincular')}>Adicionar veículo ao cliente</button>
                        : <button type="button" className="btn btn-primary" disabled={processing} onClick={() => aprovar('novo')}>Aprovar como cliente novo</button>}
                </div>
            </form>
            <Confirmar aberto={recusar} titulo="Recusar cadastro" perigo rotuloOk="Recusar e apagar" onCancelar={() => setRecusar(false)}
                onOk={() => router.post(`/pendentes/${pendente.id}/recusar`)}>
                O cadastro de <strong>{pendente.nome}</strong> será apagado (os dados não ficam guardados).
            </Confirmar>
        </div>
    )
}
