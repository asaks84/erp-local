import { router } from '@inertiajs/react'
import { useCallback, useEffect, useRef, useState } from 'react'

// Fecha o sistema depois de X minutos sem uso, considerando TODAS as abas abertas:
// o último uso fica guardado no navegador (localStorage), compartilhado entre as abas.
// No sistema atual, uma aba parada derrubava as outras; aqui só fecha se todas estiverem paradas.
// Enquanto a pessoa usa o sistema, uma consulta leve ao servidor mantém a sessão viva.

const CHAVE_USO = 'erp-ultimo-uso'
const CHAVE_SAIU = 'erp-saiu'
const AVISO_SEGUNDOS = 60
const PING_MS = 4 * 60 * 1000

function ler(chave) {
    try { return Number(localStorage.getItem(chave)) || 0 } catch (e) { return 0 }
}
function gravar(chave, valor) {
    try { localStorage.setItem(chave, String(valor)) } catch (e) { /* sem armazenamento: vale só esta aba */ }
}

/** Saída pelo botão Sair: as outras abas abertas vão junto para a tela de acesso. */
export function avisarSaida() {
    gravar(CHAVE_SAIU, Date.now())
}

export function useInatividade(minutos) {
    const limite = minutos * 60 * 1000
    const ultimoLocal = useRef(Date.now())
    const ultimoPing = useRef(Date.now())
    const saindo = useRef(false)
    const [segundos, setSegundos] = useState(null)

    const marcarUso = useCallback(() => {
        const agora = Date.now()
        // grava no máximo a cada 5 s, para não pesar
        if (agora - ultimoLocal.current < 5000) return
        ultimoLocal.current = agora
        gravar(CHAVE_USO, agora)
        if (agora - ultimoPing.current > PING_MS) {
            ultimoPing.current = agora
            fetch('/sessao/ping', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then((r) => { if (r.status === 401) window.location.href = '/login?expirou=1' })
                .catch(() => {})
        }
    }, [])

    const continuar = useCallback(() => {
        ultimoLocal.current = 0
        ultimoPing.current = 0
        marcarUso()
        setSegundos(null)
    }, [marcarUso])

    useEffect(() => {
        gravar(CHAVE_USO, Date.now())
        const eventos = ['pointerdown', 'keydown', 'scroll', 'touchstart']
        eventos.forEach((e) => window.addEventListener(e, marcarUso, { passive: true }))
        // a navegação do Inertia também conta como uso
        const tirar = router.on('navigate', () => { ultimoLocal.current = 0; marcarUso() })

        const relogio = setInterval(() => {
            const parado = Date.now() - Math.max(ler(CHAVE_USO), ultimoLocal.current)
            const resta = Math.ceil((limite - parado) / 1000)
            if (resta <= 0 && !saindo.current) {
                saindo.current = true
                gravar(CHAVE_SAIU, Date.now())
                router.post('/sair', { motivo: 'inatividade' })
            } else if (resta <= AVISO_SEGUNDOS) {
                setSegundos(resta)
            } else {
                setSegundos(null)
            }
        }, 1000)

        // outra aba saiu do sistema: esta vai junto
        const outraAba = (e) => {
            if (e.key === CHAVE_SAIU && !saindo.current) {
                saindo.current = true
                window.location.href = '/login?expirou=1'
            }
        }
        window.addEventListener('storage', outraAba)

        return () => {
            eventos.forEach((e) => window.removeEventListener(e, marcarUso))
            window.removeEventListener('storage', outraAba)
            clearInterval(relogio)
            tirar()
        }
    }, [limite, marcarUso])

    return { mostrar: segundos !== null && segundos > 0, segundos, continuar }
}
