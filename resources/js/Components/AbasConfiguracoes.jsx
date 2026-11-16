import { Link, usePage } from '@inertiajs/react'
import { CONFIGURACOES, itemAceso } from '../lib/menu'
import { usePode } from '../lib/permissoes'

/** Abas de Sistema › Configurações da empresa (cada tela de configuração é uma aba). */
export default function AbasConfiguracoes() {
    const { url } = usePage()
    const pode = usePode()
    const abas = CONFIGURACOES.filter((a) => pode(a.permissao))
    const acesa = itemAceso(url, abas)
    // as abas quebram linha: todas ficam à vista, sem rolar para o lado
    return (
        <nav className="abas-config mb-3" aria-label="Configurações da empresa">
            <ul className="nav">
                {abas.map((a) => (
                    <li key={a.href} className="nav-item">
                        <Link href={a.href} className={`nav-link text-nowrap${acesa === a.href ? ' active' : ''}`} aria-current={acesa === a.href ? 'page' : undefined}>{a.rotulo}</Link>
                    </li>
                ))}
            </ul>
        </nav>
    )
}
