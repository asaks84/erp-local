import { Head } from '@inertiajs/react'

export default function TituloPagina({ titulo, descricao, children }) {
    return (
        <>
            <Head title={titulo} />
            <div className="titulo-pagina">
                <h1>{titulo}</h1>
                {children}
                {descricao && <p>{descricao}</p>}
            </div>
        </>
    )
}
