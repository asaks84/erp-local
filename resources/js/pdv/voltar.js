import { useEffect, useRef } from 'react'

/*
 * Botão voltar do celular dentro do PDV. O app troca de tela por dentro, então para o navegador ele é uma
 * página só e o voltar tentaria sair (com a tela fixada, o Android insiste e o app trava). Aqui o voltar
 * faz o mesmo que a setinha do topo: volta uma etapa ou fecha a janela aberta (PIN, confirmação).
 * No Início não faz nada: o app nunca fecha pelo voltar.
 */
const pilha = [] // quem responde ao voltar agora: a última tela ou janela que abriu

/** registra fn como resposta ao voltar enquanto o componente estiver na tela (sem fn, não registra) */
export function useVoltar(fn) {
    const atual = useRef(fn)
    atual.current = fn
    const tem = !!fn
    useEffect(() => {
        if (!tem) return undefined
        const item = () => atual.current?.()
        pilha.push(item)
        return () => { const i = pilha.lastIndexOf(item); if (i >= 0) pilha.splice(i, 1) }
    }, [tem])
}

let instalado = false
/** chamado uma vez quando o app abre: deixa sempre um passo "a mais" no histórico e segura o voltar */
export function instalarVoltar() {
    if (instalado || typeof window === 'undefined') return
    instalado = true
    const marcar = () => window.history.pushState({ pdv: true }, '')
    if (!window.history.state?.pdv) marcar()
    window.addEventListener('popstate', () => {
        marcar() // repõe o passo, para o próximo voltar também cair aqui
        const fazer = pilha[pilha.length - 1]
        if (fazer) fazer()
    })
}
