import { Link } from '@inertiajs/react'

/** Anterior / Próxima de uma lista paginada pelo servidor. */
export default function Paginacao({ pagina }) {
    if (!pagina || (!pagina.prev_page_url && !pagina.next_page_url)) return null
    return (
        <div className="d-flex justify-content-between align-items-center mt-3">
            <span className="text-muted small">
                {pagina.total !== undefined ? `${pagina.from ?? 0}–${pagina.to ?? 0} de ${pagina.total}` : `Página ${pagina.current_page}`}
            </span>
            <div className="d-flex gap-2">
                {pagina.prev_page_url && <Link href={pagina.prev_page_url} preserveState className="btn btn-sm btn-outline-secondary">Anterior</Link>}
                {pagina.next_page_url && <Link href={pagina.next_page_url} preserveState className="btn btn-sm btn-outline-secondary">Próxima</Link>}
            </div>
        </div>
    )
}
