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
        grupo: 'Sistema',
        itens: [
            { rotulo: 'Empresa e unidades', href: '/empresa', icone: 'predio', permissao: 'configuracoes', acende: ['/empresa', '/unidades'] },
            { rotulo: 'Níveis de acesso', href: '/niveis', icone: 'escudo', permissao: 'configuracoes', acende: ['/niveis'] },
            { rotulo: 'Usuários', href: '/usuarios', icone: 'cadeado', permissao: 'usuarios', rapido: true, acende: ['/usuarios'] },
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
