import { Link, router, usePage } from '@inertiajs/react'
import { useEffect, useState } from 'react'
import Icone from '../Components/Icone'
import Avisos from '../Components/Avisos'
import { MENU, itemAceso } from '../lib/menu'
import { usePode } from '../lib/permissoes'
import { dataExtensa, iniciais } from '../lib/formato'
import { avisarSaida, useInatividade } from '../lib/inatividade'

function guardar(chave, valor) {
    try { localStorage.setItem(chave, valor) } catch (e) { /* navegador sem armazenamento */ }
}

export default function AppLayout({ children }) {
    const { auth, empresa } = usePage().props
    const pagina = usePage()
    const pode = usePode()
    const [menuAberto, setMenuAberto] = useState(false)
    const aviso = useInatividade(auth?.inatividade_minutos || 10)

    // menu filtrado pelo nível de acesso
    const grupos = MENU
        .map((g) => ({ ...g, itens: g.itens.filter((it) => pode(it.permissao)) }))
        .filter((g) => g.itens.length)
    const todos = grupos.flatMap((g) => g.itens)
    const aceso = itemAceso(pagina.url, todos)
    const rapidos = todos.filter((it) => it.rapido).slice(0, 4)
    const maisAceso = aceso && !rapidos.some((it) => it.href === aceso)

    // fecha o menu do celular ao trocar de página
    useEffect(() => router.on('navigate', () => setMenuAberto(false)), [])
    useEffect(() => {
        document.documentElement.classList.toggle('app-menu-aberto', menuAberto)
    }, [menuAberto])

    function alternarTema() {
        const atual = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark'
        document.documentElement.setAttribute('data-bs-theme', atual)
        guardar('erp-tema', atual)
    }

    function alternarRecolhido() {
        const recolhido = document.documentElement.classList.toggle('app-recolhido')
        guardar('erp-menu', recolhido ? 'recolhido' : 'aberto')
    }

    const logo = empresa?.logo_url
    return (
        <div className="app">
            <aside className="app-side" aria-label="Menu principal">
                <div className="app-side-in">
                    <Link href="/" className="app-logo" aria-label="Início">
                        {logo ? (
                            <img src={logo} alt={empresa.nome} />
                        ) : (
                            <>
                                <img className="logo-claro" src="/imagens/logo-local.png" alt={empresa?.nome} width="136" height="61" />
                                <img className="logo-escuro" src="/imagens/logo-local-escuro.png" alt={empresa?.nome} width="136" height="61" />
                                <img className="app-simbolo" src="/imagens/logo-simbolo.png" alt={empresa?.nome} width="30" height="38" />
                            </>
                        )}
                    </Link>

                    <nav className="app-nav">
                        {grupos.map((g) => (
                            <div key={g.grupo || 'inicio'}>
                                {g.grupo && <div className="app-grupo">{g.grupo}</div>}
                                {g.itens.map((it) => (
                                    <Link
                                        key={it.href}
                                        href={it.href}
                                        className={aceso === it.href ? 'ativo' : undefined}
                                        aria-current={aceso === it.href ? 'page' : undefined}
                                        title={it.rotulo}
                                    >
                                        <Icone nome={it.icone} />
                                        <span className="app-txt">{it.rotulo}</span>
                                    </Link>
                                ))}
                            </div>
                        ))}
                    </nav>

                    <div className="app-side-fim">
                        <button type="button" className="app-icone-btn app-recolher" onClick={alternarRecolhido} aria-label="Recolher ou abrir o menu">
                            <Icone nome="recolher" />
                            <span>Recolher menu</span>
                        </button>
                        <div className="app-user">
                            <div className="app-avatar" aria-hidden="true">{iniciais(auth.usuario.nome)}</div>
                            <div className="app-user-nome">
                                <strong>{auth.usuario.nome}</strong>
                                <small>{auth.usuario.nivel}</small>
                            </div>
                            <Link href="/sair" method="post" as="button" className="app-icone-btn" aria-label="Sair do sistema" title="Sair" onClick={avisarSaida}>
                                <Icone nome="sair" />
                            </Link>
                        </div>
                    </div>
                </div>
            </aside>
            <div className="app-veu" onClick={() => setMenuAberto(false)} />

            <nav className="app-bottom" aria-label="Menu rápido">
                {rapidos.map((it) => (
                    <Link key={it.href} href={it.href} className={aceso === it.href ? 'ativo' : undefined}>
                        <Icone nome={it.icone} tamanho={22} />
                        {it.rotulo}
                    </Link>
                ))}
                <button type="button" className={maisAceso ? 'ativo' : undefined} onClick={() => setMenuAberto(true)} aria-expanded={menuAberto}>
                    <Icone nome="menu" tamanho={22} />
                    Mais
                </button>
            </nav>

            <div className="app-main">
                <header className="app-top">
                    <Link href="/" className="app-top-logo" aria-label="Início">
                        <img className="logo-claro" src="/imagens/logo-local.png" alt={empresa?.nome} width="67" height="30" />
                        <img className="logo-escuro" src="/imagens/logo-local-escuro.png" alt={empresa?.nome} width="67" height="30" />
                    </Link>
                    <div className="app-espaco" />
                    <span className="app-data">{dataExtensa(auth.agora)}</span>
                    <button type="button" className="app-icone-btn app-tema" onClick={alternarTema} aria-label="Alternar modo claro / escuro" title="Modo claro / escuro">
                        <Icone nome="lua" tamanho={20} />
                        <Icone nome="sol" tamanho={20} />
                    </button>
                </header>

                <main className="app-content">
                    <Avisos />
                    {children}
                </main>
            </div>

            {aviso.mostrar && (
                <div className="aviso-sessao" role="alertdialog" aria-live="assertive">
                    <Icone nome="alerta" />
                    <span>Sem uso há um tempo. O sistema fecha em {aviso.segundos} s.</span>
                    <button type="button" className="btn btn-primary btn-sm" onClick={aviso.continuar}>Continuar</button>
                </div>
            )}
        </div>
    )
}
