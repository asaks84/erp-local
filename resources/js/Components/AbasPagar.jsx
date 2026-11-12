import { Link, usePage } from '@inertiajs/react'

const ABAS = [
    ['/pagar', 'Contas'],
    ['/pagar/fixas', 'Contas fixas'],
    ['/fornecedores', 'Fornecedores'],
]

/** Abas de Gestão › Contas a pagar. */
export default function AbasPagar() {
    const { url } = usePage()
    const caminho = url.split('?')[0]
    const acesa = caminho.startsWith('/pagar/fixas') ? '/pagar/fixas' : caminho.startsWith('/fornecedores') ? '/fornecedores' : '/pagar'
    return (
        <nav className="abas-config mb-3" aria-label="Contas a pagar">
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
