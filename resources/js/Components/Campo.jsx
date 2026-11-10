import { useId } from 'react'

/**
 * Campo com o rótulo dentro (form-floating), no padrão do sistema atual.
 * Mostra o erro de validação que vem do servidor.
 */
export default function Campo({ rotulo, erro, ajuda, className = '', as = 'input', children, ...props }) {
    const id = useId()
    const Tag = as
    const classe = as === 'select' ? 'form-select' : 'form-control'
    return (
        <div className={`form-floating mb-3 ${className}`}>
            <Tag
                id={id}
                className={`${classe}${erro ? ' is-invalid' : ''}`}
                placeholder=" "
                aria-invalid={erro ? 'true' : undefined}
                {...props}
            >
                {children}
            </Tag>
            <label htmlFor={id}>{rotulo}</label>
            {erro && <div className="invalid-feedback">{erro}</div>}
            {ajuda && !erro && <div className="form-text">{ajuda}</div>}
        </div>
    )
}
