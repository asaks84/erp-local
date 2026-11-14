import { Head, useForm } from '@inertiajs/react'
import Campo from '../../Components/Campo'
import CamposCliente from '../../Components/CamposCliente'
import { placa as mPlaca, telefone } from '../../lib/mascaras'

function Topo({ empresa }) {
    return (
        <>
            <img className="logo-claro d-block mx-auto mb-3" src="/imagens/logo-local.png" alt={empresa.nome} width="150" height="67" />
            <img className="logo-escuro d-block mx-auto mb-3" src="/imagens/logo-local-escuro.png" alt={empresa.nome} width="150" height="67" />
            <h1 className="fs-4 fw-bold text-center">Atualização de cadastro</h1>
        </>
    )
}

function Placa({ token }) {
    const { data, setData, post, processing, errors } = useForm({ placa: '' })
    return (
        <form className="card" noValidate onSubmit={(e) => { e.preventDefault(); post(`/atualizar/${token}/placa`) }}>
            <div className="card-body">
                <p>Para proteger seus dados, confirme a <strong>placa de um veículo seu</strong> cadastrado no estacionamento.</p>
                <Campo rotulo="Placa do veículo" value={mPlaca(data.placa)} onChange={(e) => setData('placa', e.target.value)} erro={errors.placa}
                    autoFocus autoComplete="off" autoCapitalize="characters" style={{ textTransform: 'uppercase' }} />
                <button type="submit" className="btn btn-primary w-100 py-2" disabled={processing || data.placa.length < 5}>{processing ? 'Conferindo…' : 'Continuar'}</button>
            </div>
        </form>
    )
}

function Formulario({ token, empresa, dados, documentoDica, veiculos }) {
    const { data, setData, post, processing, errors } = useForm({ ...dados, aceite: false })
    const temErro = Object.keys(errors).length > 0
    return (
        <form noValidate onSubmit={(e) => { e.preventDefault(); post(`/atualizar/${token}`, { preserveScroll: 'errors' }) }}>
            <p className="text-center text-muted">Confira e corrija o que estiver errado. A equipe revisa antes de atualizar o cadastro.</p>
            {temErro && <div className="alert alert-danger">Confira os campos marcados em vermelho.</div>}
            <div className="card mb-3">
                <div className="card-header">Seus dados</div>
                <div className="card-body">
                    <div className="alert aviso-neutro small">
                        Digite seu CPF (ou o CNPJ da empresa) completo para confirmar.{documentoDica && <> No cadastro está: <strong>{documentoDica}</strong>.</>}
                    </div>
                    <CamposCliente data={data} setData={setData} errors={errors} mostrarExtras={false} />
                </div>
            </div>
            {veiculos.length > 0 && (
                <div className="card mb-3">
                    <div className="card-header">Seus veículos no cadastro</div>
                    <ul className="list-group list-group-flush">
                        {veiculos.map((v, i) => <li key={i} className="list-group-item"><strong>{v.placa}</strong>{v.descricao && <span className="text-muted"> · {v.descricao}</span>}</li>)}
                    </ul>
                    <div className="card-footer small text-muted">Algo errado nos veículos? Avise a equipe no estacionamento.</div>
                </div>
            )}
            <div className="form-check mb-3">
                <input className={`form-check-input${errors.aceite ? ' is-invalid' : ''}`} type="checkbox" id="aceite" checked={data.aceite} onChange={(e) => setData('aceite', e.target.checked)} />
                <label className="form-check-label" htmlFor="aceite">
                    Autorizo {empresa.nome} a usar meus dados para o cadastro, contato e cobrança da mensalidade (LGPD).
                </label>
                {errors.aceite && <div className="invalid-feedback">{errors.aceite}</div>}
            </div>
            <button type="submit" className="btn btn-primary w-100 py-2" disabled={processing}>{processing ? 'Enviando…' : 'Enviar atualização'}</button>
        </form>
    )
}

/** Atualização cadastral pelo cliente (link individual; confirma a placa antes de ver os dados). */
export default function AtualizacaoPublico({ token, estado, empresa_publica: empresa, dados, documento_dica: documentoDica, veiculos = [] }) {
    return (
        <div className="container py-4" style={{ maxWidth: 680 }}>
            <Head title="Atualização de cadastro" />
            <Topo empresa={empresa} />
            {estado === 'placa' && <Placa token={token} />}
            {estado === 'formulario' && <Formulario token={token} empresa={empresa} dados={dados} documentoDica={documentoDica} veiculos={veiculos} />}
            {estado === 'recebida' && (
                <div className="alert alert-success text-center mt-3">
                    <strong>Recebemos sua atualização.</strong> Obrigado! A equipe confere e atualiza o seu cadastro.
                </div>
            )}
            {estado === 'fechada' && (
                <div className="alert aviso-neutro text-center mt-3">
                    Este link não está mais válido. Peça um novo na recepção{empresa.telefone && <> ou pelo telefone {telefone(empresa.telefone)}</>}.
                </div>
            )}
        </div>
    )
}
AtualizacaoPublico.layout = null
