import { Head, useForm } from '@inertiajs/react'
import CamposCliente from '../../Components/CamposCliente'
import CamposVeiculo from '../../Components/CamposVeiculo'

/** Cadastro público de mensalista (aberto pelo QR Code do balcão, sem login). */
export default function CadastroPublico({ codigo, empresa_publica, tipos }) {
    const { data, setData, post, processing, errors } = useForm({
        documento: '', nome: '', celular: '', telefone: '', email: '', cep: '', logradouro: '', numero: '', complemento: '',
        bairro: '', cidade: '', uf: '', placa: '', marca: '', modelo: '', cor: '', tipo_veiculo_id: '', aceite: false, site: '',
    })

    function enviar(e) {
        e.preventDefault()
        post(`/cadastro/${codigo}`, { preserveScroll: 'errors' })
    }

    const temErro = Object.keys(errors).length > 0
    return (
        <div className="container py-4" style={{ maxWidth: 680 }}>
            <Head title="Cadastro de mensalista" />
            <img className="logo-claro d-block mx-auto mb-3" src="/imagens/logo-local.png" alt={empresa_publica.nome} width="150" height="67" />
            <img className="logo-escuro d-block mx-auto mb-3" src="/imagens/logo-local-escuro.png" alt={empresa_publica.nome} width="150" height="67" />
            <h1 className="fs-4 fw-bold text-center">Cadastro de mensalista</h1>
            <p className="text-center text-muted">Preencha seus dados. A equipe confere e entra em contato para combinar período e valor.</p>
            {temErro && <div className="alert alert-danger">Confira os campos marcados em vermelho.</div>}
            <form onSubmit={enviar} noValidate>
                <div className="card mb-3">
                    <div className="card-header">Seus dados</div>
                    <div className="card-body"><CamposCliente data={data} setData={setData} errors={errors} mostrarExtras={false} /></div>
                </div>
                <div className="card mb-3">
                    <div className="card-header">Seu veículo</div>
                    <div className="card-body"><CamposVeiculo data={data} setData={setData} errors={errors} tipos={tipos} semPlacaPermitido={false} /></div>
                </div>
                {/* campo escondido contra robôs */}
                <input type="text" name="site" value={data.site} onChange={(e) => setData('site', e.target.value)} tabIndex="-1" autoComplete="off"
                    style={{ position: 'absolute', left: '-9999px' }} aria-hidden="true" />
                <div className="form-check mb-3">
                    <input className={`form-check-input${errors.aceite ? ' is-invalid' : ''}`} type="checkbox" id="aceite" checked={data.aceite}
                        onChange={(e) => setData('aceite', e.target.checked)} />
                    <label className="form-check-label" htmlFor="aceite">
                        Autorizo {empresa_publica.nome} a usar meus dados para o cadastro, contato e cobrança da mensalidade (LGPD).
                    </label>
                    {errors.aceite && <div className="invalid-feedback">{errors.aceite}</div>}
                </div>
                <button type="submit" className="btn btn-primary w-100 py-2" disabled={processing}>{processing ? 'Enviando…' : 'Enviar cadastro'}</button>
            </form>
        </div>
    )
}
CadastroPublico.layout = null
