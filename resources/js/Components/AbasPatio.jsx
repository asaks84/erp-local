import { Link, usePage } from '@inertiajs/react'

const ABAS = [['/patio', 'Agora'], ['/patio/historico', 'Histórico'], ['/patio/pendencias', 'Pendências do PDV']]

/** Abas de Operação › Pátio: quem está dentro agora, o histórico por data e o que o PDV fez sem internet e não fechou. */
export default function AbasPatio() {
    const { url, props } = usePage()
    const caminho = url.split('?')[0]
    const acesa = ABAS.map(([h]) => h).filter((h) => h !== '/patio').find((h) => caminho.startsWith(h)) || '/patio'
    const pendentes = props.pdv_pendencias || 0
    return (
        <nav className="abas-config mb-3" aria-label="Pátio">
            <ul className="nav nav-underline flex-nowrap">
                {ABAS.filter(([href]) => href !== '/patio/pendencias' || pendentes > 0 || acesa === href).map(([href, rotulo]) => (
                    <li key={href} className="nav-item">
                        <Link href={href} className={`nav-link text-nowrap${acesa === href ? ' active' : ''}`} aria-current={acesa === href ? 'page' : undefined}>
                            {rotulo}{href === '/patio/pendencias' && pendentes > 0 && <span className="badge text-bg-danger ms-1">{pendentes}</span>}
                        </Link>
                    </li>
                ))}
            </ul>
        </nav>
    )
}
