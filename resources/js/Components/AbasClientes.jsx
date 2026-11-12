import { Link, usePage } from '@inertiajs/react'
import { usePode } from '../lib/permissoes'

/** Abas de Clientes: a lista e o relatório de mensalistas (só para quem tem a permissão). */
export default function AbasClientes() {
    const pode = usePode()
    const caminho = usePage().url.split('?')[0]
    if (!pode('rel_mensalistas')) return null
    const acesa = caminho.startsWith('/clientes/mensalistas') ? '/clientes/mensalistas' : '/clientes'
    return (
        <nav className="abas-config mb-3 nao-imprimir" aria-label="Clientes">
            <ul className="nav nav-underline flex-nowrap">
                {[['/clientes', 'Clientes'], ['/clientes/mensalistas', 'Mensalistas']].map(([href, rotulo]) => (
                    <li key={href} className="nav-item">
                        <Link href={href} className={`nav-link text-nowrap${acesa === href ? ' active' : ''}`} aria-current={acesa === href ? 'page' : undefined}>{rotulo}</Link>
                    </li>
                ))}
            </ul>
        </nav>
    )
}
