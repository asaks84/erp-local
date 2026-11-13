import { Link, router, useForm } from '@inertiajs/react'
import { useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import Campo from '../../Components/Campo'
import CampoData from '../../Components/CampoData'
import CampoValor from '../../Components/CampoValor'
import CampoMinutos from '../../Components/CampoMinutos'
import Icone from '../../Components/Icone'
import Confirmar from '../../Components/Confirmar'
import { moeda } from '../../lib/formato'

// data local (toISOString é UTC: depois das 21h já seria amanhã)
const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
const hoje = () => iso(new Date())

function Simulador({ regras }) {
    const [diaE, setDiaE] = useState(hoje())
    const [horaE, setHoraE] = useState('08:00')
    const [diaS, setDiaS] = useState(hoje())
    const [horaS, setHoraS] = useState('10:30')
    const hora = (v) => v.replace(/[^\d]/g, '').slice(0, 4).replace(/^(\d{2})(\d)/, '$1:$2')
    const [res, setRes] = useState(null)
    const [erro, setErro] = useState(null)

    function calcular() {
        setErro(null)
        const token = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '')
        fetch('/configuracoes/tarifas/simular', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': token },
            body: JSON.stringify({ entrada: `${diaE} ${horaE}`, saida: `${diaS} ${horaS}`, regras }),
        })
            .then(async (r) => { const j = await r.json(); if (!r.ok) throw new Error(Object.values(j.errors || {})[0]?.[0] || j.message); return j })
            .then(setRes)
            .catch((e) => { setRes(null); setErro(e.message || 'Não deu para calcular.') })
    }

    const horas = res ? `${Math.floor(res.minutos / 60)}h${String(res.minutos % 60).padStart(2, '0')}` : ''
    return (
        <div className="card">
            <div className="card-header">Simular (com as regras da tela, mesmo sem salvar)</div>
            <div className="card-body">
                <div className="row g-2">
                    <div className="col-8"><CampoData rotulo="Entrada" value={diaE} onChange={setDiaE} /></div>
                    <div className="col-4"><Campo rotulo="Hora" inputMode="numeric" value={horaE} onChange={(e) => setHoraE(hora(e.target.value))} /></div>
                </div>
                <div className="row g-2">
                    <div className="col-8"><CampoData rotulo="Saída" value={diaS} onChange={setDiaS} /></div>
                    <div className="col-4"><Campo rotulo="Hora" inputMode="numeric" value={horaS} onChange={(e) => setHoraS(hora(e.target.value))} /></div>
                </div>
                <button type="button" className="btn btn-outline-secondary" onClick={calcular}>Calcular</button>
                {erro && <div className="text-danger small mt-2">{erro}</div>}
                {res && (
                    <div className="mt-3">
                        <div className="fs-4 fw-bold">{moeda(res.valor)} <span className="fs-6 fw-normal text-muted">({horas})</span></div>
                        <ul className="small text-muted mb-0">{res.detalhes.map((d, i) => <li key={i}>{d}</li>)}</ul>
                    </div>
                )}
            </div>
        </div>
    )
}

export default function TarifaForm({ tarifa, versao_de, tipos, unidades }) {
    const editando = !!tarifa?.id
    const [excluir, setExcluir] = useState(false)
    const [imediato, setImediato] = useState(false)
    const inicioSugerido = tarifa?.vigencia_inicio || hoje()
    const { data, setData, post, put, processing, errors } = useForm({
        anterior_id: tarifa?.anterior_id || null,
        nome: tarifa?.nome || '', unidade_id: tarifa?.unidade_id || '',
        tolerancia_min: tarifa?.tolerancia_min ?? 0, fracao_min: tarifa?.fracao_min ?? 0, restante: tarifa?.restante || 'faixas',
        vigencia_inicio: tarifa?.vigencia_inicio || hoje(), vigencia_fim: tarifa?.vigencia_fim || '',
        tipos: tarifa?.tipos || [],
        faixas: tarifa?.faixas?.map((f) => ({ ...f, valor: Number(f.valor) })) || [{ duracao_min: 60, modo: 'fixo', bloco_min: 60, valor: '' }, { duracao_min: '', modo: 'bloco', bloco_min: 60, valor: '' }],
        pacotes: tarifa?.pacotes?.map((p) => ({ ...p, valor: Number(p.valor) })) || [],
    })

    const mudarLista = (lista, i, campo, valor) => setData(lista, data[lista].map((x, j) => (j === i ? { ...x, [campo]: valor } : x)))
    const tirar = (lista, i) => setData(lista, data[lista].filter((_, j) => j !== i))
    const err = (k) => errors[k]

    function salvar(e) {
        e.preventDefault()
        editando ? put(`/configuracoes/tarifas/${tarifa.id}`) : post('/configuracoes/tarifas')
    }

    return (
        <div className="pagina">
            <TituloPagina titulo={versao_de ? `Nova versão: ${versao_de.nome}` : editando ? `Tabela: ${tarifa.nome}` : 'Nova tabela de estadia'}
                descricao={versao_de ? 'A versão atual vale até a véspera da data de início desta. Mude só o que for reajustar.' : null} />
            <div className="row g-4">
                <div className="col-lg-7">
                    <form onSubmit={salvar} className="card" noValidate>
                        <div className="card-body">
                            <div className="row g-2">
                                <div className="col-sm-7"><Campo rotulo="Nome da tabela" value={data.nome} erro={err('nome')} maxLength={60} onChange={(e) => setData('nome', e.target.value)} /></div>
                                <div className="col-sm-5">
                                    <Campo as="select" rotulo="Vale para" value={data.unidade_id} onChange={(e) => setData('unidade_id', e.target.value)}>
                                        <option value="">Todas as unidades</option>
                                        {unidades.map((u) => <option key={u.id} value={u.id}>Só {u.nome}</option>)}
                                    </Campo>
                                </div>
                            </div>
                            <div className="row g-2">
                                <div className="col-sm-6"><CampoData rotulo="Vale a partir de" value={data.vigencia_inicio} erro={err('vigencia_inicio')} onChange={(v) => setData('vigencia_inicio', v)} disabled={imediato} /></div>
                                <div className="col-sm-6"><CampoData rotulo="Até (opcional)" value={data.vigencia_fim} erro={err('vigencia_fim')} onChange={(v) => setData('vigencia_fim', v)} /></div>
                            </div>

                            {versao_de && (
                                <div className="mb-3">
                                    <div className="form-check">
                                        <input className="form-check-input" type="checkbox" id="imediato" checked={imediato}
                                            onChange={(e) => { setImediato(e.target.checked); setData('vigencia_inicio', e.target.checked ? hoje() : inicioSugerido) }} />
                                        <label className="form-check-label" htmlFor="imediato">Começar imediatamente</label>
                                    </div>
                                    {imediato && <div className="form-text">Vale a partir de hoje: veículos que entraram hoje, mesmo antes da mudança, saem pela tabela nova.</div>}
                                </div>
                            )}

                            <fieldset className="mb-3">
                                <legend className="fs-6 fw-semibold">Tipos de veículo</legend>
                                <div className="d-flex flex-wrap gap-3">
                                    {tipos.map((t) => (
                                        <div className="form-check" key={t.id}>
                                            <input className="form-check-input" type="checkbox" id={`tp-${t.id}`} checked={data.tipos.includes(t.id)}
                                                onChange={(e) => setData('tipos', e.target.checked ? [...data.tipos, t.id] : data.tipos.filter((x) => x !== t.id))} />
                                            <label className="form-check-label" htmlFor={`tp-${t.id}`}>{t.nome}</label>
                                        </div>
                                    ))}
                                </div>
                                {err('tipos') && <div className="text-danger small mt-1">{err('tipos')}</div>}
                            </fieldset>

                            <div className="row g-2">
                                <div className="col-sm-6"><CampoMinutos rotulo="Tolerância (minutos grátis)" min="0" value={data.tolerancia_min} erro={err('tolerancia_min')} onChange={(v) => setData('tolerancia_min', v)} /></div>
                                <div className="col-sm-6"><CampoMinutos rotulo="Fração (min de cortesia)" min="0" value={data.fracao_min} erro={err('fracao_min')} onChange={(v) => setData('fracao_min', v)}
                                    ajuda="Passou até X min do bloco, não cobra outro." /></div>
                            </div>

                            <h3 className="fs-6 fw-semibold mt-2">Faixas (em sequência)</h3>
                            {err('faixas') && <div className="text-danger small">{err('faixas')}</div>}
                            {data.faixas.map((f, i) => (
                                <div className="row g-2 align-items-start" key={i}>
                                    <div className="col-6 col-sm">
                                        <Campo as="select" rotulo="Modo" value={f.modo} onChange={(e) => mudarLista('faixas', i, 'modo', e.target.value)}>
                                            <option value="fixo">Valor fixo</option>
                                            <option value="bloco">A cada N min</option>
                                        </Campo>
                                    </div>
                                    <div className="col-6 col-sm">
                                        <CampoMinutos rotulo="Duração (min)" min="1" value={f.duracao_min ?? ''} erro={err(`faixas.${i}.duracao_min`)}
                                            onChange={(v) => mudarLista('faixas', i, 'duracao_min', v)} ajuda={i === data.faixas.length - 1 ? 'Última: até o fim' : 'Vazio = até o fim'} />
                                    </div>
                                    <div className="col-5 col-sm">
                                        {f.modo === 'bloco' && <CampoMinutos rotulo="Bloco (min)" min="1" value={f.bloco_min ?? 60} erro={err(`faixas.${i}.bloco_min`)} onChange={(v) => mudarLista('faixas', i, 'bloco_min', v)} />}
                                    </div>
                                    <div className="col-5 col-sm"><CampoValor rotulo="Valor" value={f.valor} erro={err(`faixas.${i}.valor`)} onChange={(v) => mudarLista('faixas', i, 'valor', v)} /></div>
                                    <div className="col-2 col-sm-auto pt-2"><button type="button" className="btn btn-sm btn-outline-secondary" aria-label="Tirar faixa" onClick={() => tirar('faixas', i)}><Icone nome="lixeira" tamanho={16} /></button></div>
                                </div>
                            ))}
                            <button type="button" className="btn btn-sm btn-outline-secondary mb-3" onClick={() => setData('faixas', [...data.faixas, { duracao_min: '', modo: 'bloco', bloco_min: 60, valor: '' }])}>+ Faixa</button>

                            <h3 className="fs-6 fw-semibold">Pacotes (diárias, pernoite)</h3>
                            <p className="small text-muted">Servem de teto: se as faixas passarem do pacote, cobra o pacote. Com horário, viram pernoite/período.</p>
                            {data.pacotes.map((p, i) => (
                                <div className="row g-2 align-items-start border-bottom mb-3" key={i}>
                                    <div className="col-12 col-sm-4"><Campo rotulo="Nome" value={p.nome} erro={err(`pacotes.${i}.nome`)} maxLength={40} onChange={(e) => mudarLista('pacotes', i, 'nome', e.target.value)} /></div>
                                    <div className="col-6 col-sm-4"><CampoMinutos rotulo="Minutos" min="1" value={p.duracao_min ?? ''} erro={err(`pacotes.${i}.duracao_min`)} onChange={(v) => mudarLista('pacotes', i, 'duracao_min', v)} /></div>
                                    <div className="col-6 col-sm-4"><CampoValor rotulo="Valor" value={p.valor} erro={err(`pacotes.${i}.valor`)} onChange={(v) => mudarLista('pacotes', i, 'valor', v)} /></div>
                                    <div className="col-5 col-sm-4"><Campo rotulo="Início" placeholder="19:00" value={p.hora_inicio ?? ''} erro={err(`pacotes.${i}.hora_inicio`)} maxLength={5} onChange={(e) => mudarLista('pacotes', i, 'hora_inicio', e.target.value)} /></div>
                                    <div className="col-5 col-sm-4"><Campo rotulo="Fim" value={p.hora_fim ?? ''} erro={err(`pacotes.${i}.hora_fim`)} maxLength={5} onChange={(e) => mudarLista('pacotes', i, 'hora_fim', e.target.value)} /></div>
                                    <div className="col-2 col-sm-4 pt-2"><button type="button" className="btn btn-sm btn-outline-secondary" aria-label="Tirar pacote" onClick={() => tirar('pacotes', i)}><Icone nome="lixeira" tamanho={16} /></button></div>
                                </div>
                            ))}
                            <button type="button" className="btn btn-sm btn-outline-secondary mb-3" onClick={() => setData('pacotes', [...data.pacotes, { nome: 'Diária', duracao_min: 720, valor: '', hora_inicio: '', hora_fim: '' }])}>+ Pacote</button>

                            <Campo as="select" rotulo="Acima do maior pacote" value={data.restante} onChange={(e) => setData('restante', e.target.value)}>
                                <option value="faixas">Volta a cobrar pelas faixas (com teto nos pacotes)</option>
                                <option value="pacotes">Só pacotes inteiros (período começado = pacote cheio)</option>
                            </Campo>
                        </div>
                        <div className="card-footer bg-transparent d-flex flex-wrap gap-2 p-3">
                            {editando && <button type="button" className="btn btn-outline-danger me-auto" onClick={() => setExcluir(true)}>Excluir</button>}
                            <Link href="/configuracoes/tarifas" className="btn btn-outline-secondary ms-auto">Cancelar</Link>
                            <button type="submit" className="btn btn-primary" disabled={processing}>Salvar</button>
                        </div>
                    </form>
                </div>
                <div className="col-lg-5"><Simulador regras={data} /></div>
            </div>
            {editando && (
                <Confirmar aberto={excluir} titulo="Excluir tabela" perigo rotuloOk="Excluir" onCancelar={() => setExcluir(false)} onOk={() => router.delete(`/configuracoes/tarifas/${tarifa.id}`)}>
                    Excluir a tabela <strong>{tarifa.nome}</strong>? Para reajuste, use "Reajustar" (nova versão) em vez de excluir.
                </Confirmar>
            )}
        </div>
    )
}
