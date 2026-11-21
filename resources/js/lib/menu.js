// Itens do menu. "permissao" esconde o item de quem não tem acesso
// (basta uma das permissões da lista). "rapido" coloca o item no menu de baixo do celular.
// Os módulos das próximas etapas entram aqui conforme forem ficando prontos.
export const MENU = [
    {
        grupo: '',
        itens: [
            { rotulo: 'Início', href: '/', icone: 'casa', rapido: true, acende: ['/'] },
        ],
    },
    {
        grupo: 'Clientes',
        itens: [
            { rotulo: 'Clientes', href: '/clientes', icone: 'pessoas', rapido: true, acende: ['/clientes', '/veiculos'] },
            { rotulo: 'Fila de espera', href: '/pendentes', icone: 'fila', permissao: 'aprovar', contador: 'fila', acende: ['/pendentes'] },
            { rotulo: 'Recadastro', href: '/atualizacao-cadastral', icone: 'documento', permissao: 'aprovar', contador: 'atualizacoes', acende: ['/atualizacao-cadastral'] },
        ],
    },
    {
        grupo: 'Operação',
        itens: [
            { rotulo: 'Pátio', href: '/patio', icone: 'relogio', acende: ['/patio', '/estadias', '/lavagens'] },
            { rotulo: 'Gerar mensalidades', href: '/mensalidades/gerar', icone: 'calendario', acende: ['/mensalidades'] },
            { rotulo: 'Ordens de serviço', href: '/ordens', icone: 'lista', acende: ['/ordens'] },
        ],
    },
    {
        grupo: 'Gestão',
        itens: [
            { rotulo: 'Contas em aberto', href: '/contas/abertas', icone: 'dinheiro', permissao: 'contas_aberto', rapido: true, acende: ['/contas'] },
            { rotulo: 'Cobranças', href: '/cobrancas', icone: 'documento', acende: ['/cobrancas'] },
            { rotulo: 'Avisos de cobrança', href: '/avisos', icone: 'alerta', acende: ['/avisos'] },
            { rotulo: 'Contas a pagar', href: '/pagar', icone: 'documento', permissao: 'contas_pagar', acende: ['/pagar', '/fornecedores'] },
            { rotulo: 'Resultado e caixa', href: '/resultado', icone: 'grafico', permissao: 'resultado', acende: ['/resultado'] },
            { rotulo: 'Funcionários', href: '/funcionarios', icone: 'pessoas', permissao: 'contas_pagar', acende: ['/funcionarios'] },
            { rotulo: 'Extrato bancário', href: '/extrato', icone: 'banco', permissao: 'extrato', acende: ['/extrato'] },
            { rotulo: 'Recebimentos', href: '/relatorios/recebimentos', icone: 'grafico', permissao: 'faturamento', acende: ['/relatorios/recebimentos'] },
            { rotulo: 'Notas fiscais', href: '/notas-fiscais', icone: 'documento', permissao: 'nfse', acende: ['/notas-fiscais'] },
            { rotulo: 'Convênios', href: '/convenios', icone: 'aperto', permissao: 'convenios', acende: ['/convenios'] },
        ],
    },
    {
        grupo: 'Sistema',
        itens: [
            { rotulo: 'Configurações', href: '/empresa', icone: 'engrenagem', permissao: 'configuracoes', acende: ['/empresa', '/unidades', '/configuracoes'] },
            { rotulo: 'Níveis de acesso', href: '/niveis', icone: 'escudo', permissao: 'configuracoes', acende: ['/niveis'] },
            { rotulo: 'Usuários', href: '/usuarios', icone: 'cadeado', permissao: 'usuarios', acende: ['/usuarios'] },
            { rotulo: 'Conferência', href: '/conferencia', icone: 'lista', permissao: 'configuracoes', acende: ['/conferencia'] },
            { rotulo: 'Registro de atividades', href: '/atividades', icone: 'lista', permissao: 'usuarios', acende: ['/atividades'] },
            { rotulo: 'Incidentes (LGPD)', href: '/incidentes', icone: 'alerta', permissao: 'usuarios', acende: ['/incidentes'] },
        ],
    },
]

// Abas de Sistema › Configurações da empresa (componente AbasConfiguracoes)
export const CONFIGURACOES = [
    { rotulo: 'Empresa e unidades', href: '/empresa', permissao: 'configuracoes', acende: ['/empresa', '/unidades'] },
    { rotulo: 'Tipos de veículo', href: '/configuracoes/tipos', permissao: 'configuracoes', acende: ['/configuracoes/tipos'] },
    { rotulo: 'Tabelas de estadia', href: '/configuracoes/tarifas', permissao: 'configuracoes', acende: ['/configuracoes/tarifas'] },
    { rotulo: 'Lavagens e serviços', href: '/configuracoes/servicos', permissao: 'configuracoes', acende: ['/configuracoes/servicos'] },
    { rotulo: 'Etapas da lavagem', href: '/configuracoes/etapas', permissao: 'configuracoes', acende: ['/configuracoes/etapas'] },
    { rotulo: 'Plano de contas', href: '/configuracoes/plano-contas', permissao: 'configuracoes', acende: ['/configuracoes/plano-contas'] },
    { rotulo: 'Formas de pagamento', href: '/configuracoes/pagamentos', permissao: 'configuracoes', acende: ['/configuracoes/pagamentos'] },
    { rotulo: 'Banco Inter', href: '/configuracoes/inter', permissao: 'configuracoes', acende: ['/configuracoes/inter'] },
    { rotulo: 'Nota fiscal (NFS-e)', href: '/configuracoes/nfse', permissao: 'nfse', acende: ['/configuracoes/nfse'] },
    { rotulo: 'WhatsApp', href: '/configuracoes/whatsapp', permissao: 'configuracoes', acende: ['/configuracoes/whatsapp'] },
    { rotulo: 'Backup', href: '/configuracoes/backup', permissao: 'configuracoes', acende: ['/configuracoes/backup'] },
    { rotulo: 'Aparelhos (PDV)', href: '/configuracoes/aparelhos', permissao: 'configuracoes', acende: ['/configuracoes/aparelhos'] },
    { rotulo: 'Impressão (PDV)', href: '/configuracoes/impressao', permissao: 'configuracoes', acende: ['/configuracoes/impressao'] },
]

/** item aceso: o endereço que mais combina com a página atual */
export function itemAceso(url, itens) {
    const caminho = url.split('?')[0].replace(/\/+$/, '') || '/'
    let melhor = null
    let tamanho = -1
    for (const it of itens) {
        for (const pre of it.acende) {
            const casa = pre === '/' ? caminho === '/' : caminho === pre || caminho.startsWith(pre + '/')
            if (casa && pre.length > tamanho) {
                melhor = it.href
                tamanho = pre.length
            }
        }
    }
    return melhor
}
