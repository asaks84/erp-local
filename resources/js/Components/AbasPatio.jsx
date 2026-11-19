import { Link, usePage } from '@inertiajs/react'

const ABAS = [['/patio', 'Agora'], ['/patio/historico', 'Histórico']]

/** Abas de Operação › Pátio: quem está dentro agora e o histórico por data (estadias e lavagens juntas). */
export default function AbasPatio() {
    const caminho = usePage().url.split('?')[0]
    const acesa = caminho.startsWith('/patio/historico') ? '/patio/historico' : '/patio'
    return (
        <nav className="abas-config mb-3" aria-label="Pátio">
            <ul className="nav nav-underline flex-nowrap">
                {ABAS.map(([href, rotulo]) => (
                    <li key={href} className="nav-item">
                        <Link href={href} className={`nav-link text-nowrap${acesa === href ? ' active' : ''}`} aria-current={acesa === href ? 'page' : undefined}>{rotulo}</Link>
                    </li>
                ))}
            </ul>
        </nav>
    )
}
