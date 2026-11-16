import { Head } from '@inertiajs/react'

/** Política de privacidade (pública): exigida pela Meta para publicar o aplicativo do WhatsApp. */
export default function Privacidade({ empresa_publica: empresa }) {
    const nome = empresa.nome
    return (
        <div className="container py-4" style={{ maxWidth: 720 }}>
            <Head title="Política de privacidade" />
            <h1 className="fs-3 fw-bold">Política de privacidade</h1>
            <p className="text-muted">{nome}</p>
            <h2 className="fs-5 mt-4">Quais dados guardamos</h2>
            <p>Dados de cadastro dos clientes do estacionamento: nome, CPF ou CNPJ, telefone, e-mail, endereço e os dados dos veículos (placa, marca, modelo). Guardamos também o histórico de serviços, cobranças e pagamentos.</p>
            <h2 className="fs-5 mt-4">Para que usamos</h2>
            <p>Para prestar o serviço de estacionamento, emitir cobranças e boletos, registrar pagamentos, emitir documentos fiscais e falar com o cliente sobre o serviço contratado, inclusive por WhatsApp (avisos de vencimento e de cobrança).</p>
            <h2 className="fs-5 mt-4">Com quem compartilhamos</h2>
            <p>Só com quem é necessário para o serviço: o banco que emite os boletos e o Pix, a prefeitura (nota fiscal) e a Meta, para o envio das mensagens pelo WhatsApp. Não vendemos nem cedemos dados para propaganda de terceiros.</p>
            <h2 className="fs-5 mt-4">Mensagens pelo WhatsApp</h2>
            <p>Enviamos avisos sobre cobranças do serviço contratado. Para deixar de receber, basta pedir ao estacionamento, e o cadastro é marcado para não receber avisos.</p>
            <h2 className="fs-5 mt-4">Seus direitos</h2>
            <p>Você pode pedir para ver, corrigir ou apagar seus dados, conforme a Lei Geral de Proteção de Dados (LGPD). Dados que a lei obriga a guardar, como registros fiscais, são mantidos pelo prazo legal.</p>
            <h2 className="fs-5 mt-4">Contato</h2>
            <p>Fale com o {nome} pelos canais de atendimento do estacionamento{empresa.telefone ? ` (${empresa.telefone})` : ''}{empresa.email ? ` ou pelo e-mail ${empresa.email}` : ''}.</p>
        </div>
    )
}
Privacidade.layout = null
