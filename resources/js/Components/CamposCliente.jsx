import { useState } from 'react'
import Campo from './Campo'
import CampoData from './CampoData'
import { cep as mCep, cpfCnpj, telefone } from '../lib/mascaras'

/** Campos de cliente (usados no cadastro, na edição e na aprovação da fila). Busca o endereço pelo CEP. */
/** largo: tela inteira (campos lado a lado em telas grandes); sem largo: caixa estreita (cadastro público) */
export default function CamposCliente({ data, setData, errors, mostrarExtras = true, largo = false }) {
    const lg = (n) => (largo ? ` col-xl-${n}` : '')
    const [buscandoCep, setBuscandoCep] = useState(false)

    function buscarCep(valor) {
        const d = valor.replace(/\D/g, '')
        if (d.length !== 8) return
        setBuscandoCep(true)
        fetch(`https://viacep.com.br/ws/${d}/json/`)
            .then((r) => r.json())
            .then((e) => {
                if (e.erro) return
                setData((atual) => ({
                    ...atual,
                    logradouro: (e.logradouro || atual.logradouro || '').toUpperCase(),
                    bairro: (e.bairro || atual.bairro || '').toUpperCase(),
                    cidade: (e.localidade || atual.cidade || '').toUpperCase(),
                    uf: e.uf || atual.uf,
                }))
            })
            .catch(() => {})
            .finally(() => setBuscandoCep(false))
    }

    const campo = (nome) => ({ value: data[nome] ?? '', erro: errors[nome], onChange: (e) => setData(nome, e.target.value) })

    return (
        <>
            <div className="row g-2">
                <div className={`col-sm-5${lg(3)}`}><Campo rotulo="CPF ou CNPJ" inputMode="numeric" {...campo('documento')} value={cpfCnpj(data.documento)} /></div>
                <div className={`col-sm-7${lg(5)}`}><Campo rotulo="Nome completo" maxLength={100} {...campo('nome')} /></div>
                <div className={`col-sm-4${lg(4)}`}><Campo rotulo="Celular (WhatsApp)" inputMode="tel" {...campo('celular')} value={telefone(data.celular)} /></div>
                <div className={`col-sm-4${lg(3)}`}><Campo rotulo="Telefone" inputMode="tel" {...campo('telefone')} value={telefone(data.telefone)} /></div>
                <div className={`col-sm-4${lg(5)}`}><Campo rotulo="E-mail" type="email" maxLength={120} {...campo('email')} /></div>
                <div className={`col-sm-4${lg(4)}`}>
                    <Campo rotulo={buscandoCep ? 'CEP (buscando…)' : 'CEP'} inputMode="numeric" {...campo('cep')} value={mCep(data.cep)}
                        onChange={(e) => { setData('cep', e.target.value); buscarCep(e.target.value) }} />
                </div>
                <div className={`col-sm-8${lg(6)}`}><Campo rotulo="Endereço" maxLength={100} {...campo('logradouro')} /></div>
                <div className={`col-4 col-sm-3${lg(2)}`}><Campo rotulo="Número" maxLength={10} {...campo('numero')} /></div>
                <div className={`col-8 col-sm-9${lg(4)}`}><Campo rotulo="Complemento" maxLength={80} {...campo('complemento')} /></div>
                <div className={`col-sm-5${lg(5)}`}><Campo rotulo="Bairro" maxLength={60} {...campo('bairro')} /></div>
                <div className={`col-8 col-sm-5${lg(5)}`}><Campo rotulo="Cidade" maxLength={60} {...campo('cidade')} /></div>
                <div className={`col-4 col-sm-2${lg(2)}`}><Campo rotulo="UF" maxLength={2} {...campo('uf')} value={(data.uf || '').toUpperCase()} /></div>
            </div>
            {mostrarExtras && (
                <>
                    <div className="row g-2">
                        <div className={`col-sm-6${lg(2)}`}>
                            <Campo rotulo="Dia de vencimento" type="number" min="1" max="31" {...campo('dia_vencimento')}
                                ajuda="Dia 31 vira o último dia do mês." />
                        </div>
                        <div className={`col-sm-6${lg(2)}`}>
                            <CampoData rotulo="Cliente desde" value={data.cliente_desde} erro={errors.cliente_desde} onChange={(v) => setData('cliente_desde', v)} />
                        </div>
                        <div className={largo ? 'col-xl-8' : 'col-12'}><Campo as="textarea" rotulo="Observações" style={{ height: 80 }} maxLength={500} {...campo('observacoes')} /></div>
                    </div>
                    <div className="form-check form-switch">
                        <input className="form-check-input" type="checkbox" role="switch" id="nao_recebe_avisos"
                            checked={!!data.nao_recebe_avisos} onChange={(e) => setData('nao_recebe_avisos', e.target.checked)} />
                        <label className="form-check-label" htmlFor="nao_recebe_avisos">Não enviar avisos de cobrança (WhatsApp)</label>
                    </div>
                </>
            )}
        </>
    )
}
