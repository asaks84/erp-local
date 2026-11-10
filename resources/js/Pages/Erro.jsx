import { Link } from '@inertiajs/react'
import TituloPagina from '../Components/TituloPagina'
import AppLayout from '../Layouts/AppLayout'

const TEXTOS = {
    403: ['Sem permissão', 'Seu nível de acesso não permite abrir esta tela. Se precisar, peça ao administrador.'],
    404: ['Página não encontrada', 'O endereço não existe ou o registro foi removido.'],
    419: ['Tela expirada', 'A tela ficou aberta por muito tempo. Volte e tente de novo.'],
    500: ['Erro no sistema', 'Algo deu errado do nosso lado. Tente de novo; se continuar, avise o suporte.'],
    503: ['Em manutenção', 'O sistema está em manutenção. Volte em alguns minutos.'],
}

export default function Erro({ status }) {
    const [titulo, texto] = TEXTOS[status] || TEXTOS[500]
    return (
        <div className="estreito">
            <TituloPagina titulo={titulo} descricao={texto} />
            <Link href="/" className="btn btn-primary">Ir para o Início</Link>
        </div>
    )
}

// logado: com o menu do sistema; sem login: só a mensagem
Erro.layout = (pagina) => (pagina.props.auth?.usuario ? <AppLayout>{pagina}</AppLayout> : <div className="container py-4">{pagina}</div>)
