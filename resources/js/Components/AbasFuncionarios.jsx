import { Link, usePage } from '@inertiajs/react'

const ABAS = [
    ['/funcionarios', 'Funcionários'],
    ['/funcionarios/recibos', 'Recibos de salário'],
    ['/funcionarios/diarias', 'Diárias'],
]

/** Abas de Gestão › Funcionários. */
export default function AbasFuncionarios() {
    const { url } = usePage()
    const caminho = url.split('?')[0]
    const acesa = ABAS.map(([h]) => h).filter((h) => caminho.startsWith(h)).pop() || '/funcionarios'
    return (
        <nav className="abas-config mb-3" aria-label="Funcionários">
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
