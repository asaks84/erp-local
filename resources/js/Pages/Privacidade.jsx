import { Head } from '@inertiajs/react'

// (13) 99999-9999 / (13) 3333-3333; outro formato fica como veio
const fone = (t) => {
    const d = String(t || '').replace(/\D/g, '')
    if (d.length === 11) return `(${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7)}`
    if (d.length === 10) return `(${d.slice(0, 2)}) ${d.slice(2, 6)}-${d.slice(6)}`
    return t
}

const ATUALIZADA = '20/11/2026'

function Secao({ titulo, children }) {
    return (
        <>
            <h2 className="fs-5 mt-4">{titulo}</h2>
            {children}
        </>
    )
}

/**
 * Política de privacidade (pública). Link dos formulários públicos (cadastro e atualização) e exigida pela Meta para
 * o app do WhatsApp. Os dados do controlador e o canal de privacidade vêm de Configurações › Empresa.
 * O texto segue .lgpd/gaps.md (P0) e deve ser revisto quando mudar o que o sistema guarda ou com quem compartilha.
 */
export default function Privacidade({ empresa_publica: empresa }) {
    const nome = empresa.nome
    const contatos = [empresa.contato_privacidade, empresa.email, empresa.telefone && fone(empresa.telefone)].filter(Boolean)
    const canal = [...new Set(contatos)].join(' · ')
    return (
        <div className="container py-4" style={{ maxWidth: 760 }}>
            <Head title="Política de privacidade" />
            <h1 className="fs-3 fw-bold">Política de privacidade</h1>
            <p className="text-muted mb-1">{nome}</p>
            <p className="small text-muted">Última atualização: {ATUALIZADA}</p>

            <p>Esta política explica quais dados pessoais o {nome} usa, para quê, com quem compartilha, por quanto tempo guarda e como você exerce seus direitos, conforme a Lei Geral de Proteção de Dados (Lei nº 13.709/2018, LGPD).</p>

            <Secao titulo="Quem é o responsável pelos dados">
                <p>
                    {empresa.razao_social || nome}{empresa.cnpj && <>, CNPJ {empresa.cnpj}</>}{empresa.endereco && <>, {empresa.endereco}</>}.
                    É quem decide sobre o uso dos dados (controlador).
                </p>
            </Secao>

            <Secao titulo="Quais dados usamos">
                <ul>
                    <li><strong>Clientes e mensalistas:</strong> nome, CPF ou CNPJ, telefone, e-mail, endereço, dados dos veículos (placa, marca, modelo, cor) e o histórico de serviços, mensalidades, cobranças, pagamentos e notas fiscais.</li>
                    <li><strong>Atendimento no balcão:</strong> placa e modelo do veículo, horário de entrada e saída e, se você quiser, o celular, para receber o ticket ou o aviso de lavagem pronta. Se o ticket for perdido, anotamos o nome e o número de um documento de quem retira o veículo, sem foto do documento.</li>
                    <li><strong>Quem paga por outra pessoa:</strong> nome, CPF ou CNPJ e conta de quem faz o pagamento, para identificar a quem ele se refere.</li>
                    <li><strong>Formulários pelo link:</strong> os dados que você preenche no cadastro ou na atualização, a data do envio e o endereço IP.</li>
                </ul>
                <p>Não pedimos dados sensíveis (saúde, religião, biometria e outros) e o serviço não é dirigido a menores de idade.</p>
            </Secao>

            <Secao titulo="Para que usamos e com qual base legal">
                <ul>
                    <li><strong>Prestar o serviço contratado</strong> (estacionamento, lavagem, mensalidade), cobrar e registrar pagamentos, inclusive com avisos de vencimento por WhatsApp: execução de contrato (art. 7º, V, da LGPD).</li>
                    <li><strong>Emitir nota fiscal e guardar registros fiscais e contábeis:</strong> cumprimento de obrigação legal (art. 7º, II).</li>
                    <li><strong>Segurança do atendimento e do sistema</strong> (identificar quem retira um veículo sem ticket, registros de acesso e de uso do sistema): legítimo interesse e exercício regular de direitos (art. 7º, IX e VI).</li>
                </ul>
                <p>Não usamos seus dados para propaganda de terceiros, não vendemos dados e não tomamos decisões automáticas sobre você.</p>
            </Secao>

            <Secao titulo="Com quem compartilhamos">
                <ul>
                    <li><strong>Banco Inter:</strong> emite os boletos e o Pix das cobranças.</li>
                    <li><strong>Sistema Nacional da NFS-e</strong> (governo federal): recebe os dados da nota fiscal de serviço.</li>
                    <li><strong>Meta (WhatsApp):</strong> envia os avisos de cobrança.</li>
                    <li><strong>Hostinger:</strong> hospeda o sistema e o banco de dados.</li>
                    <li><strong>Cloudflare:</strong> protege o site e faz a ligação entre o seu aparelho e o sistema.</li>
                    <li><strong>Google (Drive):</strong> guarda as cópias de segurança, criptografadas com uma senha que só nós temos.</li>
                </ul>
                <p>Essas empresas usam os dados só para prestar esses serviços. Meta, Cloudflare e Google podem tratar dados fora do Brasil, inclusive nos Estados Unidos; essa transferência segue o art. 33 da LGPD, nos termos de tratamento de dados dessas empresas.</p>
            </Secao>

            <Secao titulo="Por quanto tempo guardamos">
                <ul>
                    <li>Cadastro e histórico: enquanto você for cliente. Depois de 5 anos sem nenhum movimento, os dados que identificam você são anonimizados (sem pendência em aberto). Valores e notas fiscais ficam pelo prazo legal.</li>
                    <li>Cadastro enviado pelo link (fila de espera) que não virou cliente: apagado na recusa ou depois de 120 dias.</li>
                    <li>Cópias de segurança (backup): 14 dias no servidor e 30 dias na cópia criptografada fora dele.</li>
                </ul>
            </Secao>

            <Secao titulo="Seus direitos">
                <p>Você pode pedir: confirmação de que usamos seus dados e acesso a eles; correção de dados errados ou desatualizados; anonimização, bloqueio ou eliminação de dados desnecessários; cópia dos dados para levar a outro fornecedor; informação sobre com quem compartilhamos; e se opor a um uso que considere irregular. Dados que a lei obriga a guardar, como os fiscais, são mantidos pelo prazo legal.</p>
                <p>Peça pelo canal abaixo. Respondemos em até 15 dias. Você também pode reclamar à Autoridade Nacional de Proteção de Dados (ANPD).</p>
                <p>Para deixar de receber os avisos por WhatsApp, basta pedir: o cadastro é marcado para não receber.</p>
            </Secao>

            <Secao titulo="Canal de privacidade">
                <p>
                    {canal ? <>Pedidos e dúvidas sobre dados pessoais: <strong>{canal}</strong>.</> : <>Pedidos e dúvidas sobre dados pessoais: fale com a equipe no estacionamento.</>}
                </p>
                <p className="small text-muted">Como agente de tratamento de pequeno porte (Resolução CD/ANPD nº 2/2022), o {nome} não tem encarregado nomeado; este canal atende os pedidos sobre dados pessoais.</p>
            </Secao>

            <Secao titulo="Segurança">
                <p>O acesso ao sistema é por usuário e senha, com permissões por função. As conexões são criptografadas (HTTPS), as senhas e chaves de acesso são guardadas protegidas, e os registros de uso mostram quem fez o quê. Se houver um incidente que traga risco a você, avisamos você e a ANPD.</p>
            </Secao>

            <Secao titulo="Mudanças nesta política">
                <p>Quando esta política mudar, a data no topo é atualizada.</p>
            </Secao>
        </div>
    )
}
Privacidade.layout = null
