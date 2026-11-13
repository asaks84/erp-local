import { Link, useForm, usePage } from '@inertiajs/react'
import TituloPagina from '../../Components/TituloPagina'
import Campo from '../../Components/Campo'

export default function UsuarioForm({ usuario, niveis, unidades }) {
    const { auth } = usePage().props
    const novo = !usuario
    const eu = usuario && usuario.id === auth.usuario.id
    const { data, setData, post, put, processing, errors } = useForm({
        nome: usuario?.nome || '',
        login: usuario?.login || '',
        senha: '',
        senha_confirmation: '',
        nivel_id: usuario?.nivel_id || '',
        unidade_id: usuario?.unidade_id || '',
        operador_caixa: usuario?.operador_caixa || false,
        ativo: usuario ? usuario.ativo : true,
    })

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
                    <div className="row g-2">
                        <div className="col-md-6 col-xl-4">
                    <Campo rotulo="Nome completo" value={data.nome} erro={errors.nome} maxLength={80}
                        onChange={(e) => setData('nome', e.target.value)} />
                        </div>
                        <div className="col-md-6 col-xl-4">
                    <Campo rotulo="Login" value={data.login} erro={errors.login} maxLength={40}
                        autoCapitalize="none" autoComplete="off" ajuda="Usado para entrar no sistema. Sem espaços."
                        onChange={(e) => setData('login', e.target.value)} />
                        </div>
                        <div className="col-md-6 col-xl-4">
                    <Campo as="select" rotulo="Nível de acesso" value={data.nivel_id} erro={errors.nivel_id}
                        disabled={eu} ajuda={eu ? 'Você não pode mudar o seu próprio nível.' : undefined}
                        onChange={(e) => setData('nivel_id', e.target.value)}>
                        <option value="">Escolha…</option>
                        {niveis.map((n) => <option key={n.id} value={n.id}>{n.nome}</option>)}
                    </Campo>
                        </div>
                        <div className="col-md-6 col-xl-4">
                            <Campo rotulo={novo ? 'Senha' : 'Nova senha (vazio = não muda)'} type="password" value={data.senha}
                                erro={errors.senha} autoComplete="new-password"
                                onChange={(e) => setData('senha', e.target.value)} />
                        </div>
                        <div className="col-md-6 col-xl-4">
                            <Campo rotulo="Repita a senha" type="password" value={data.senha_confirmation}
                                autoComplete="new-password"
                                onChange={(e) => setData('senha_confirmation', e.target.value)} />
                        </div>
                        <div className="col-md-6 col-xl-4">
                    <Campo as="select" rotulo="Unidade" value={data.unidade_id} erro={errors.unidade_id}
                        onChange={(e) => setData('unidade_id', e.target.value)}>
                        <option value="">Todas as unidades</option>
                        {unidades.map((u) => <option key={u.id} value={u.id}>{u.nome}</option>)}
                    </Campo>
                        </div>
                    </div>
                    <div className="form-check form-switch mb-2">
                        <input className="form-check-input" type="checkbox" role="switch" id="operador_caixa"
                            checked={data.operador_caixa} onChange={(e) => setData('operador_caixa', e.target.checked)} />
                        <label className="form-check-label" htmlFor="operador_caixa">Operador de caixa (PDV)</label>
                    </div>
                    <div className="form-check form-switch">
                        <input className="form-check-input" type="checkbox" role="switch" id="ativo" disabled={eu}
                            checked={data.ativo} onChange={(e) => setData('ativo', e.target.checked)} />
                        <label className="form-check-label" htmlFor="ativo">Ativo (pode entrar no sistema)</label>
                    </div>
                    {errors.ativo && <div className="text-danger small mt-1">{errors.ativo}</div>}
                </div>
                <div className="card-footer bg-transparent d-flex gap-2 justify-content-end p-3">
                    <Link href="/usuarios" className="btn btn-outline-secondary">Cancelar</Link>
                    <button type="submit" className="btn btn-primary" disabled={processing}>Salvar</button>
                </div>
            </form>
        </div>
    )
}
