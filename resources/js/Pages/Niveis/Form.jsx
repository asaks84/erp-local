import { Link, router, useForm } from '@inertiajs/react'
import { useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import Campo from '../../Components/Campo'
import Confirmar from '../../Components/Confirmar'

export default function NivelForm({ nivel, catalogo }) {
    const novo = !nivel
    const fixo = !!nivel?.fixo
    const [confirmar, setConfirmar] = useState(false)
    const { data, setData, post, put, processing, errors } = useForm({
        nome: nivel?.nome || '',
        permissoes: nivel?.permissoes || [],
    })

    function marcar(chave, ligado) {
        setData('permissoes', ligado ? [...data.permissoes, chave] : data.permissoes.filter((p) => p !== chave))
    }

    function salvar(e) {
        e.preventDefault()
        if (novo) post('/niveis')
        else put(`/niveis/${nivel.id}`)
    }

    function excluir() {
        router.delete(`/niveis/${nivel.id}`, { onFinish: () => setConfirmar(false) })
    }

    return (
        <div className="estreito">
            <TituloPagina titulo={novo ? 'Novo nível de acesso' : `Nível: ${nivel.nome}`} />
            <form onSubmit={salvar} className="card" noValidate>
                <div className="card-body">
                    <Campo rotulo="Nome do nível" value={data.nome} erro={errors.nome} maxLength={40}
                        onChange={(e) => setData('nome', e.target.value)} />

                    {fixo ? (
                        <div className="alert aviso-neutro mb-0">Este nível tem acesso total ao sistema e não pode ser limitado nem excluído.</div>
                    ) : (
                        catalogo.map((g) => (
                            <fieldset key={g.grupo} className="mb-3">
                                <legend className="fs-6 fw-semibold text-uppercase text-muted small mb-2">{g.grupo}</legend>
                                {g.itens.map((p) => (
                                    <div className="form-check mb-2" key={p.chave}>
                                        <input className="form-check-input" type="checkbox" id={`p-${p.chave}`}
                                            checked={data.permissoes.includes(p.chave)}
                                            onChange={(e) => marcar(p.chave, e.target.checked)} />
                                        <label className="form-check-label" htmlFor={`p-${p.chave}`}>
                                            {p.rotulo}
                                            {p.descricao && <span className="d-block text-muted small">{p.descricao}</span>}
                                        </label>
                                    </div>
                                ))}
                            </fieldset>
                        ))
                    )}
                    {errors.permissoes && <div className="text-danger small">{errors.permissoes}</div>}
                </div>
                <div className="card-footer bg-transparent d-flex flex-wrap gap-2 p-3">
                    {!novo && !fixo && (
                        <button type="button" className="btn btn-outline-danger me-auto" onClick={() => setConfirmar(true)}
                            disabled={nivel.usuarios > 0} title={nivel.usuarios > 0 ? 'Há usuários neste nível' : undefined}>
                            Excluir nível
                        </button>
                    )}
                    <Link href="/niveis" className="btn btn-outline-secondary ms-auto">Cancelar</Link>
                    <button type="submit" className="btn btn-primary" disabled={processing}>Salvar</button>
                </div>
            </form>
            {!novo && (
                <Confirmar aberto={confirmar} titulo="Excluir nível" perigo rotuloOk="Excluir"
                    onOk={excluir} onCancelar={() => setConfirmar(false)}>
                    Excluir o nível <strong>{nivel.nome}</strong>? Esta ação não pode ser desfeita.
                </Confirmar>
            )}
        </div>
    )
}
