import { Link, useForm, usePage } from '@inertiajs/react'
import TituloPagina from '../../Components/TituloPagina'
import Campo from '../../Components/Campo'
import JanelaCodigoPin, { BotaoCodigoPin } from '../../Components/CodigoPin'

export default function UsuarioForm({ usuario, niveis, unidades }) {
    const { auth } = usePage().props
    const novo = !usuario
    const eu = usuario && usuario.id === auth.usuario.id
    const { data, setData, post, put, processing, errors } = useForm({
        nome: usuario?.nome || '',
        login: usuario?.login || '',
        senha: '',
        senha_confirmation: '',
        pin: '',
        pin_confirmation: '',
        nivel_id: usuario?.nivel_id || '',
        unidade_id: usuario?.unidade_id || '',
        operador_caixa: usuario?.operador_caixa || false,
        ativo: usuario ? usuario.ativo : true,
        acessa_gestao: usuario ? usuario.acessa_gestao : true,
    })
    const gestao = data.acessa_gestao

    function salvar(e) {
        e.preventDefault()
        if (novo) post('/usuarios')
        else put(`/usuarios/${usuario.id}`)
    }

    return (
        <div className="estreito">
            <TituloPagina titulo={novo ? 'Novo usuário' : 'Editar usuário'} />
            <form onSubmit={salvar} className="card" noValidate>
                <div className="card-body">
                    <div className="form-check form-switch mb-1">
                        <input className="form-check-input" type="checkbox" role="switch" id="acessa_gestao" disabled={eu}
                            checked={gestao} onChange={(e) => setData('acessa_gestao', e.target.checked)} />
                        <label className="form-check-label fw-semibold" htmlFor="acessa_gestao">Acessa a gestão (entra no sistema com login e senha)</label>
                    </div>
                    <div className="form-text mt-0 mb-3">
                        {gestao ? 'Desligue para quem só opera o PDV: fica sem login e sem senha, só com o PIN.'
                            : 'Só PDV: sem login e sem senha. Entra no balcão pelo PIN, que ele mesmo escolhe no PDV com um código de cadastro (gerado aqui) ou que você digita abaixo.'}
                    </div>
                    {errors.acessa_gestao && <div className="text-danger small mb-2">{errors.acessa_gestao}</div>}
                    <div className="row g-2">
                        <div className="col-md-6 col-xl-4">
                    <Campo rotulo="Nome completo" value={data.nome} erro={errors.nome} maxLength={80}
                        onChange={(e) => setData('nome', e.target.value)} />
                        </div>
                        {gestao && (
                        <div className="col-md-6 col-xl-4">
                    <Campo rotulo="Login" value={data.login} erro={errors.login} maxLength={40}
                        autoCapitalize="none" autoComplete="off" ajuda="Usado para entrar no sistema. Sem espaços."
                        onChange={(e) => setData('login', e.target.value)} />
                        </div>
                        )}
                        <div className="col-md-6 col-xl-4">
                    <Campo as="select" rotulo="Nível de acesso" value={data.nivel_id} erro={errors.nivel_id}
                        disabled={eu} ajuda={eu ? 'Você não pode mudar o seu próprio nível.' : undefined}
                        onChange={(e) => setData('nivel_id', e.target.value)}>
                        <option value="">Escolha…</option>
                        {niveis.map((n) => <option key={n.id} value={n.id}>{n.nome}</option>)}
                    </Campo>
                        </div>
                        {gestao && (<>
                        <div className="col-md-6 col-xl-4">
                            <Campo rotulo={novo || !usuario?.tem_senha ? 'Senha' : 'Nova senha (vazio = não muda)'} type="password" value={data.senha}
                                erro={errors.senha} autoComplete="new-password"
                                onChange={(e) => setData('senha', e.target.value)} />
                        </div>
                        <div className="col-md-6 col-xl-4">
                            <Campo rotulo="Repita a senha" type="password" value={data.senha_confirmation}
                                autoComplete="new-password"
                                onChange={(e) => setData('senha_confirmation', e.target.value)} />
                        </div>
                        </>)}
                        <div className="col-md-6 col-xl-4">
                    <Campo as="select" rotulo="Unidade" value={data.unidade_id} erro={errors.unidade_id}
                        onChange={(e) => setData('unidade_id', e.target.value)}>
                        <option value="">Todas as unidades</option>
                        {unidades.map((u) => <option key={u.id} value={u.id}>{u.nome}</option>)}
                    </Campo>
                        </div>
                    </div>
                    <fieldset className="mb-3">
                        <legend className="fs-6 fw-semibold mb-1">PIN do PDV</legend>
                        <div className="form-text mt-0 mb-2">
                            {usuario?.pin ? 'Esta pessoa já tem PIN. Digite outro só para trocar.'
                                : gestao ? 'Opcional. A própria pessoa digita os 4 números (também dá para cadastrar depois, no PDV, com login e senha).'
                                    : 'Melhor: salve e gere um código de cadastro, para a pessoa escolher o PIN no PDV sem você ficar sabendo. Ou ela digita aqui agora.'}
                            {' '}Sequências (1234) e números repetidos (1111) não valem.
                        </div>
                        <div className="row g-2">
                            <div className="col-6 col-md-3">
                                <Campo rotulo={usuario?.pin ? 'Novo PIN' : 'PIN'} className="campo-pin" inputMode="numeric" pattern="[0-9]*" maxLength={4} autoComplete="off"
                                    value={data.pin} erro={errors.pin} onChange={(e) => setData('pin', e.target.value.replace(/\D/g, '').slice(0, 4))} />
                            </div>
                            <div className="col-6 col-md-3">
                                <Campo rotulo="Repita o PIN" className="campo-pin" inputMode="numeric" pattern="[0-9]*" maxLength={4} autoComplete="off"
                                    value={data.pin_confirmation} onChange={(e) => setData('pin_confirmation', e.target.value.replace(/\D/g, '').slice(0, 4))} />
                            </div>
                        </div>
                        {usuario && !usuario.pin && usuario.ativo && (
                            <div className="mt-2 d-flex flex-wrap align-items-center gap-2">
                                <BotaoCodigoPin usuario={usuario} />
                                {usuario.codigo_ate && <span className="small text-muted">Há um código válido até {usuario.codigo_ate}.</span>}
                            </div>
                        )}
                    </fieldset>
                    <div className="form-check form-switch mb-2">
                        <input className="form-check-input" type="checkbox" role="switch" id="operador_caixa"
                            checked={data.operador_caixa} onChange={(e) => setData('operador_caixa', e.target.checked)} />
                        <label className="form-check-label" htmlFor="operador_caixa">Operador de caixa (PDV)</label>
                    </div>
                    <div className="form-check form-switch">
                        <input className="form-check-input" type="checkbox" role="switch" id="ativo" disabled={eu}
                            checked={data.ativo} onChange={(e) => setData('ativo', e.target.checked)} />
                        <label className="form-check-label" htmlFor="ativo">Ativo (pode entrar no sistema{gestao ? '' : ' e usar o PIN'})</label>
                    </div>
                    {errors.ativo && <div className="text-danger small mt-1">{errors.ativo}</div>}
                </div>
                <div className="card-footer bg-transparent d-flex gap-2 justify-content-end p-3">
                    <Link href="/usuarios" className="btn btn-outline-secondary">Cancelar</Link>
                    <button type="submit" className="btn btn-primary" disabled={processing}>Salvar</button>
                </div>
            </form>
            <JanelaCodigoPin />
        </div>
    )
}
