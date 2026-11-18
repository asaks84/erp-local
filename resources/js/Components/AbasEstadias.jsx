import { Link, usePage } from '@inertiajs/react'

const ABAS = [['/estadias', 'Pátio'], ['/estadias/historico', 'Histórico']]

/** Abas de Operação › Estadias: o pátio (entrada e saída) e o histórico por data. */
export default function AbasEstadias() {
    const caminho = usePage().url.split('?')[0]
    const acesa = caminho.startsWith('/estadias/historico') ? '/estadias/historico' : '/estadias'
    return (
        <nav className="abas-config mb-3" aria-label="Estadias">
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
