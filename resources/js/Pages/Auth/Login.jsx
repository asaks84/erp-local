import { Head, useForm, usePage } from '@inertiajs/react'
import Campo from '../../Components/Campo'

export default function Login({ aviso }) {
    const { empresa } = usePage().props
    const { data, setData, post, processing, errors } = useForm({ login: '', password: '', lembrar: false })

    function entrar(e) {
        e.preventDefault()
        post('/login', { onFinish: () => setData('password', '') })
    }

    return (
        <div className="tela-acesso">
            <Head title="Entrar" />
            <div className="card">
                <div className="card-body p-4">
                    <img className="logo logo-claro" src="/imagens/logo-local.png" alt={empresa?.nome || 'Sistema'} width="160" height="72" />
                    <img className="logo logo-escuro" src="/imagens/logo-local-escuro.png" alt={empresa?.nome || 'Sistema'} width="160" height="72" />
                    {aviso && <div className="alert alert-warning py-2">{aviso}</div>}
                    {errors.login && <div className="alert alert-danger py-2" role="alert">{errors.login}</div>}
                    <form onSubmit={entrar} noValidate>
                        <Campo
                            rotulo="Login"
                            name="login"
                            autoComplete="username"
                            autoCapitalize="none"
                            autoFocus
                            value={data.login}
                            onChange={(e) => setData('login', e.target.value)}
                        />
                        <Campo
                            rotulo="Senha"
                            type="password"
                            name="password"
                            autoComplete="current-password"
                            value={data.password}
                            erro={errors.password}
                            onChange={(e) => setData('password', e.target.value)}
                        />
                        <button type="submit" className="btn btn-primary w-100 py-2" disabled={processing}>
                            {processing ? 'Entrando…' : 'Entrar'}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    )
}
Login.layout = null
