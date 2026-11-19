import { chamar } from './servidor'
import { avisar, gravar, ler, marcarSemInternet, semInternet } from './estado'
import { podarPins } from './pins'

/*
 * Fila do modo sem internet (8.7) e a cópia do servidor (pacote).
 *
 * Cada ação feita sem internet entra na fila com um código único e a hora do celular. Quando a internet volta,
 * a fila sobe na ordem; o servidor responde ok ou pendente (vai para o gerente conferir) e o item sai da fila
 * nos dois casos: a fila nunca trava. Depois de subir, o pacote é baixado de novo antes de tirar os itens, para o
 * pátio do celular não "piscar" (carro que entrou sem internet sumir até o pacote novo chegar).
 */

export const pacote = () => ler('pacote')
export const fila = () => ler('fila', []) || []
const gravarFila = (f) => { gravar('fila', f); avisar() }

/** itens que ainda contam no pátio do celular (não subiram, ou subiram mas o pacote ainda é anterior) */
export const filaAtiva = () => fila()

export function enfileirar(item) {
    gravarFila([...fila(), item])
    agendar(1500)
}

/** quantos esperam para subir */
export const aEnviar = () => fila().filter((i) => !i.enviada).length

let baixando = null
/** baixa a cópia do servidor; devolve true se conseguiu. novo: não aproveita um download que já estava no meio */
export function atualizarPacote(novo = false) {
    if (baixando) return novo ? baixando.then(() => atualizarPacote()) : baixando
    const inicio = Date.now()
    baixando = chamar('GET', '/pacote').then((p) => {
        gravar('pacote', p)
        podarPins(p.usuarios)
        // o que já tinha subido quando o pacote foi pedido está nele: sai da fila
        const f = fila()
        if (f.some((i) => i.enviada && i.enviada_em <= inicio)) gravarFila(f.filter((i) => !(i.enviada && i.enviada_em <= inicio)))
        avisar()
        return true
    }).catch((e) => {
        if (e.semInternet) marcarSemInternet(true)
        return false
    }).finally(() => { baixando = null })
    return baixando
}

let subindo = null
/** sobe a fila (em lotes de 50, na ordem). Devolve { ok, pendentes: [mensagens] } ou null se não deu */
export function sincronizar() {
    if (subindo) return subindo
    subindo = (async () => {
        const pendentes = []
        let ok = 0
        for (;;) {
            const lote = fila().filter((i) => !i.enviada).slice(0, 50)
            if (!lote.length) break
            let r
            try {
                r = await chamar('POST', '/sincronizar', { acoes: lote.map(({ uuid, tipo, feita_em, usuario_id, dados }) => ({ uuid, tipo, feita_em, usuario_id, dados })) }, { espera: 30000 })
            } catch (e) {
                if (e.semInternet) marcarSemInternet(true)
                return null
            }
            const res = new Map((r.resultados || []).map((x) => [x.uuid, x]))
            for (const x of res.values()) {
                if (x.situacao === 'ok') ok++
                else pendentes.push(x.mensagem)
            }
            // marcadas como enviadas: continuam valendo no pátio do celular até o pacote novo chegar
            gravarFila(fila().map((i) => (res.has(i.uuid) ? { ...i, enviada: true, enviada_em: Date.now() } : i)))
            if (lote.some((i) => !res.has(i.uuid))) break // servidor recusou algum (formato): não insiste em loop
        }
        if (pendentes.length) gravar('recusadas', [...(ler('recusadas', []) || []), ...pendentes].slice(-20))
        await atualizarPacote(true)
        return { ok, pendentes }
    })().finally(() => { subindo = null })
    return subindo
}

/** mensagens de ações que foram para o gerente conferir (a tela mostra uma vez e limpa) */
export function tirarRecusadas() {
    const r = ler('recusadas', []) || []
    if (r.length) gravar('recusadas', null)
    return r
}

// ---------- relógio da fila: tenta subir de tempos em tempos ----------
let timer = null
export function agendar(ms = 20000) {
    if (timer) clearTimeout(timer)
    timer = setTimeout(rodada, ms)
}

/** uma rodada: sobe a fila; deu certo e não sobrou nada, sai do modo sem internet */
export async function rodada() {
    timer = null
    let r = null
    if (aEnviar() > 0) r = await sincronizar()
    else r = (await atualizarPacote()) ? { ok: 0, pendentes: [] } : null
    if (r && aEnviar() === 0) marcarSemInternet(false)
    // sem internet: tenta a cada 20 s; com internet: renova o pacote a cada 2 min
    agendar(semInternet() || aEnviar() > 0 ? 20000 : 120000)
    return r
}

/** começa o relógio (chamado uma vez quando o app abre) */
let iniciada = false
export function iniciarFila() {
    if (typeof window === 'undefined' || iniciada) return
    iniciada = true
    // sobrou fila de antes (app fechado sem internet): fica no modo sem internet até subir tudo
    if (aEnviar() > 0) marcarSemInternet(true)
    window.addEventListener('online', () => agendar(500))
    window.addEventListener('offline', () => marcarSemInternet(true))
    agendar(aEnviar() > 0 ? 500 : 3000)
}
