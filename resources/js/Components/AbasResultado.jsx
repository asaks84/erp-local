import { Link, usePage } from '@inertiajs/react'

const ABAS = [['/resultado', 'Resultado do mês'], ['/resultado/fluxo', 'Fluxo de caixa']]

/** Abas de Gestão › Resultado e caixa. */
export default function AbasResultado() {
    const caminho = usePage().url.split('?')[0]
    const acesa = caminho.startsWith('/resultado/fluxo') ? '/resultado/fluxo' : '/resultado'
    return (
        <nav className="abas-config mb-3" aria-label="Resultado e caixa">
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
