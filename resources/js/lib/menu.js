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
        ],
    },
    {
        grupo: 'Contas',
        itens: [
            { rotulo: 'Contas em aberto', href: '/contas/abertas', icone: 'dinheiro', permissao: 'contas_aberto', rapido: true, acende: ['/contas'] },
            { rotulo: 'Gerar mensalidades', href: '/mensalidades/gerar', icone: 'calendario', acende: ['/mensalidades'] },
            { rotulo: 'Cobranças', href: '/cobrancas', icone: 'documento', acende: ['/cobrancas'] },
            { rotulo: 'Ordens de serviço', href: '/ordens', icone: 'lista', acende: ['/ordens'] },
            { rotulo: 'Extrato bancário', href: '/extrato', icone: 'banco', permissao: 'extrato', acende: ['/extrato'] },
        ],
    },
    {
        grupo: 'Gestão',
        itens: [
            { rotulo: 'Recebimentos', href: '/relatorios/recebimentos', icone: 'grafico', permissao: 'faturamento', acende: ['/relatorios/recebimentos'] },
            { rotulo: 'Convênios', href: '/convenios', icone: 'aperto', permissao: 'convenios', acende: ['/convenios'] },
        ],
    },
    {
        grupo: 'Configurações',
        itens: [
            { rotulo: 'Tipos de veículo', href: '/configuracoes/tipos', icone: 'carro', permissao: 'configuracoes', acende: ['/configuracoes/tipos'] },
            { rotulo: 'Tabelas de estadia', href: '/configuracoes/tarifas', icone: 'relogio', permissao: 'configuracoes', acende: ['/configuracoes/tarifas'] },
            { rotulo: 'Lavagens e serviços', href: '/configuracoes/servicos', icone: 'gota', permissao: 'configuracoes', acende: ['/configuracoes/servicos'] },
            { rotulo: 'Formas de pagamento', href: '/configuracoes/pagamentos', icone: 'dinheiro', permissao: 'configuracoes', acende: ['/configuracoes/pagamentos'] },
        ],
    },
    {
        grupo: 'Sistema',
        itens: [
            { rotulo: 'Empresa e unidades', href: '/empresa', icone: 'predio', permissao: 'configuracoes', acende: ['/empresa', '/unidades'] },
            { rotulo: 'Níveis de acesso', href: '/niveis', icone: 'escudo', permissao: 'configuracoes', acende: ['/niveis'] },
            { rotulo: 'Usuários', href: '/usuarios', icone: 'cadeado', permissao: 'usuarios', acende: ['/usuarios'] },
            { rotulo: 'Registro de atividades', href: '/atividades', icone: 'lista', permissao: 'usuarios', acende: ['/atividades'] },
        ],
    },
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
