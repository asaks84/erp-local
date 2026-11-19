import { chamar, ErroApi, token, VERSAO } from './offline/servidor'
import { marcarSemInternet, novoCodigo, novoUuid, semInternet } from './offline/estado'
import { agendar, atualizarPacote, pacote } from './offline/fila'
import { lembrarPin } from './offline/pins'
import { responder, sabeResponder } from './offline/local'

export { ErroApi, token, VERSAO }

/*
 * Conversa com a API do PDV (/api/pdv). Com internet, vai ao servidor. Sem internet (8.7), as rotas de estadia
 * são respondidas pelo próprio celular (offline/local.js) e as ações entram numa fila que sobe quando a internet
 * volta. Cada entrada e saída leva um código único: se a internet cair no meio e a ação for parar também na fila,
 * o servidor não lança duas vezes. A entrada leva também o código do QR, gerado aqui, para o ticket impresso valer
 * dos dois jeitos.
 */
const ACOES = [/^\/estadias$/, /^\/estadias\/[^/]+\/saida$/]

let renovar = null
const renovarPacote = () => { clearTimeout(renovar); renovar = setTimeout(() => atualizarPacote(), 1500) }

export async function api(metodo, caminho, dados) {
    const local = sabeResponder(metodo, caminho)
    const acao = metodo === 'POST' && ACOES.some((r) => r.test(caminho))
    if (acao) {
        dados = { ...dados, acao_uuid: dados?.acao_uuid || novoUuid() }
        if (caminho === '/estadias' && !dados.codigo) dados.codigo = novoCodigo()
    }
    // estadia local (feita sem internet e que ainda não subiu): só o celular conhece
    const soLocal = /\/estadias\/l-/.test(caminho)
    if (local && pacote() && (semInternet() || soLocal)) return responder(metodo, caminho, dados)
    try {
        const j = await chamar(metodo, caminho, dados)
        if (caminho === '/pin/conferir') lembrarPin(dados.pin, j.usuario)
        if (acao) renovarPacote()
        return j
    } catch (e) {
        if (e.semInternet) {
            marcarSemInternet(true)
            agendar(20000)
            if (local && pacote()) return responder(metodo, caminho, dados)
            throw new ErroApi('Sem internet. Isso precisa de internet; entrada e saída de estadia funcionam sem.', 0, { semInternet: true })
        }
        throw e
    }
}
