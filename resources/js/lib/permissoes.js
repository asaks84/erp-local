import { usePage } from '@inertiajs/react'

/**
 * Diz se o usuário logado tem a permissão (ou uma delas, se vier uma lista).
 * Só esconde botões e links: quem bloqueia de verdade é o servidor.
 */
export function usePode() {
    const { auth } = usePage().props
    const lista = auth?.permissoes || []
    const tudo = !!auth?.acesso_total
    return (permissao) => {
        if (!permissao) return true
        if (tudo) return true
        const pedidas = Array.isArray(permissao) ? permissao : [permissao]
        return pedidas.some((p) => lista.includes(p))
    }
}
