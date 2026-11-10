import { Head } from '@inertiajs/react'

export default function CadastroEnviado({ empresa_publica }) {
    return (
        <div className="tela-acesso">
            <Head title="Cadastro enviado" />
            <div className="card"><div className="card-body p-4 text-center">
                <img className="logo logo-claro" src="/imagens/logo-local.png" alt={empresa_publica.nome} width="160" height="72" />
                <img className="logo logo-escuro" src="/imagens/logo-local-escuro.png" alt={empresa_publica.nome} width="160" height="72" />
                <h1 className="fs-4 fw-bold">Cadastro enviado!</h1>
                <p className="mb-0">Obrigado. A equipe do {empresa_publica.nome} vai conferir seus dados e entrar em contato.</p>
            </div></div>
        </div>
    )
}
CadastroEnviado.layout = null
