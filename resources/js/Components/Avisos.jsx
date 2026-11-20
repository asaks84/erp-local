import { router, usePage } from '@inertiajs/react'
import { useEffect, useRef } from 'react'
import AvisosTopo, { useFilaAvisos } from './AvisosTopo'

const TIPOS = [['erro', 'erro'], ['aviso', 'aviso'], ['sucesso', 'ok']]

/**
 * Mensagens que o servidor manda depois de salvar (flash), no mesmo popup do PDV: descem do topo,
 * a verde some sozinha, erro e aviso ficam até fechar. Mostra a cada visita, mesmo se a mensagem se repetir.
 */
export default function Avisos() {
    const { flash } = usePage().props
    const { lista, mostrar, fechar } = useFilaAvisos()
    const parcial = useRef(false)

    useEffect(() => {
        const exibir = (f) => TIPOS.forEach(([chave, tipo]) => f?.[chave] && mostrar(tipo, f[chave]))
        exibir(flash) // a página que abriu já com mensagem
        // recarga parcial (ex.: o pátio se atualiza a cada minuto) não traz flash novo: não repete o antigo
        const tirarInicio = router.on('start', (e) => { parcial.current = (e.detail.visit.only?.length ?? 0) > 0 })
        const tirarSucesso = router.on('success', (e) => { if (!parcial.current) exibir(e.detail.page.props.flash) })
        // mensagem da própria tela, sem visita (ex.: nota emitida por JSON): avisar() em lib/json.js
        const local = (e) => mostrar(e.detail.tipo, e.detail.texto)
        window.addEventListener('aviso-local', local)
        return () => { tirarInicio(); tirarSucesso(); window.removeEventListener('aviso-local', local) }
    }, [mostrar])

    return <AvisosTopo lista={lista} fechar={fechar} />
}
