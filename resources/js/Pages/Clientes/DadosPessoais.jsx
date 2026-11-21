import { Head } from '@inertiajs/react'
import { moeda } from '../../lib/formato'

const SITUACAO = { aberta: 'Em aberto', paga: 'Paga', cancelada: 'Cancelada', confirmado: 'Confirmado', estornado: 'Estornado', emitida: 'Emitida', rejeitada: 'Rejeitada', enviando: 'Enviando', enviado: 'Enviado', entregue: 'Entregue', lido: 'Lido', falha: 'Falha', aprovada: 'Aprovada', recusada: 'Recusada', respondida: 'Respondida' }
const sit = (s) => SITUACAO[s] || s
const fone = (t) => {
    const d = String(t || '').replace(/\D/g, '')
    if (d.length === 11) return `(${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7)}`
    if (d.length === 10) return `(${d.slice(0, 2)}) ${d.slice(2, 6)}-${d.slice(6)}`
    return t
}

function Bloco({ titulo, vazio = 'Nada registrado.', linhas, colunas }) {
    return (
        <section className="mb-4" style={{ breakInside: 'avoid-page' }}>
            <h2 className="fs-6 fw-bold border-bottom pb-1">{titulo}{linhas && <span className="text-muted fw-normal"> ({linhas.length})</span>}</h2>
            {!linhas?.length ? <p className="small text-muted mb-0">{vazio}</p> : (
                <div className="table-responsive">
                    <table className="table table-sm small mb-0">
                        <thead><tr>{colunas.map(([, r, d]) => <th key={r} className={d ? 'text-end' : undefined}>{r}</th>)}</tr></thead>
                        <tbody>
                            {linhas.map((l, i) => (
                                <tr key={i}>{colunas.map(([k, r, d]) => <td key={r} className={d ? 'text-end text-nowrap' : undefined}>{d ? moeda(l[k]) : (typeof l[k] === 'boolean' ? (l[k] ? 'Sim' : 'Não') : (l[k] ?? '—'))}</td>)}</tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    )
}

/**
 * LGPD: tudo o que o sistema guarda sobre o cliente, para mandar a ele (Art. 18, II e V). Abre em aba própria;
 * "Imprimir" salva em PDF pelo navegador e "Baixar arquivo" entrega o mesmo conteúdo em JSON (portabilidade).
 */
export default function DadosPessoais({ dados, cliente_id: id }) {
    const c = dados.cadastro
    const campos = [['Código', c.codigo], ['Nome', c.nome], ['CPF/CNPJ', c.documento], ['Celular', c.celular && fone(c.celular)], ['Telefone', c.telefone && fone(c.telefone)], ['E-mail', c.email],
        ['Endereço', c.endereco], ['Cidade', c.cidade], ['CEP', c.cep], ['Cliente desde', c.cliente_desde], ['Vencimento', c.dia_vencimento && `todo dia ${c.dia_vencimento}`],
        ['Observações', c.observacoes], ['Recebe avisos por WhatsApp', c.recebe_avisos_whatsapp ? 'Sim' : 'Não'], ['Origem do cadastro', c.origem], ['Situação', c.ativo ? 'Ativo' : 'Inativo']]
    return (
        <div className="container py-4" style={{ maxWidth: 900 }}>
            <Head title={`Dados pessoais · ${c.nome}`} />
            <div className="d-flex flex-wrap gap-2 mb-3 d-print-none">
                <button type="button" className="btn btn-primary" onClick={() => window.print()}>Imprimir ou salvar em PDF</button>
                <a className="btn btn-outline-secondary" href={`/clientes/${id}/dados-pessoais?formato=json`}>Baixar arquivo (JSON)</a>
            </div>
            <h1 className="fs-4 fw-bold mb-1">Dados pessoais de {c.nome}</h1>
            <p className="small text-muted">
                {dados.controlador.nome}{dados.controlador.cnpj && ` · CNPJ ${dados.controlador.cnpj}`} · gerado em {dados.gerado_em}.
                Relação dos dados pessoais guardados sobre você, conforme a LGPD (art. 18 e 19).
            </p>

            <section className="mb-4">
                <h2 className="fs-6 fw-bold border-bottom pb-1">Cadastro</h2>
                <dl className="row small mb-0">
                    {campos.map(([r, v]) => <div key={r} className="col-sm-6 d-flex gap-2"><dt className="fw-semibold text-nowrap">{r}:</dt><dd className="mb-1">{v || '—'}</dd></div>)}
                </dl>
            </section>

            <Bloco titulo="Veículos" linhas={dados.veiculos} colunas={[['placa', 'Placa'], ['marca', 'Marca'], ['modelo', 'Modelo'], ['cor', 'Cor'], ['tamanho', 'Tamanho'], ['ativo', 'Ativo']]} />
            {dados.trocas_de_placa.length > 0 && <Bloco titulo="Trocas de placa" linhas={dados.trocas_de_placa} colunas={[['data', 'Data'], ['de', 'De'], ['para', 'Para']]} />}
            <Bloco titulo="Mensalidades" linhas={dados.mensalidades} colunas={[['periodo', 'Período'], ['inicio', 'Início'], ['fim', 'Fim'], ['valor', 'Valor', true]]} />
            <Bloco titulo="Serviços e contas" linhas={dados.servicos.map((s) => ({ ...s, situacao: sit(s.situacao) }))} colunas={[['numero', 'Nº'], ['data', 'Data'], ['tipo', 'Tipo'], ['descricao', 'Descrição'], ['placa', 'Placa'], ['situacao', 'Situação'], ['valor', 'Valor', true]]} />
            <Bloco titulo="Estadias" linhas={dados.estadias} colunas={[['numero', 'Ticket'], ['entrada', 'Entrada'], ['saida', 'Saída'], ['placa', 'Placa'], ['valor', 'Valor', true]]} />
            <Bloco titulo="Cobranças" linhas={dados.cobrancas} colunas={[['numero', 'Nº'], ['competencia', 'Mês'], ['vencimento', 'Vencimento'], ['situacao', 'Situação'], ['total', 'Total', true]]} />
            <Bloco titulo="Pagamentos" linhas={dados.pagamentos.map((p) => ({ ...p, situacao: sit(p.situacao) }))} colunas={[['data', 'Data'], ['forma', 'Forma'], ['situacao', 'Situação'], ['valor', 'Valor', true]]} />
            <Bloco titulo="Notas fiscais" linhas={dados.notas_fiscais.map((n) => ({ ...n, situacao: sit(n.situacao) }))} colunas={[['numero', 'Nº'], ['data', 'Data'], ['tomador_na_nota', 'Em nome de'], ['situacao', 'Situação'], ['valor', 'Valor', true]]} />
            {dados.pagadores_vinculados.length > 0 && <Bloco titulo="Quem também paga por você" linhas={dados.pagadores_vinculados} colunas={[['nome', 'Nome'], ['documento', 'CPF/CNPJ']]} />}
            <Bloco titulo="Avisos por WhatsApp" linhas={dados.avisos_whatsapp.map((a) => ({ ...a, celular: fone(a.celular), situacao: sit(a.situacao) }))} colunas={[['enviado_em', 'Enviado em'], ['celular', 'Celular'], ['situacao', 'Situação']]} />
            {dados.atualizacoes_pelo_link.length > 0 && <Bloco titulo="Atualizações do cadastro pelo link" linhas={dados.atualizacoes_pelo_link.map((a) => ({ ...a, situacao: sit(a.situacao) }))} colunas={[['respondido_em', 'Respondido em'], ['situacao', 'Situação'], ['ip', 'IP']]} />}

            <section className="mb-4">
                <h2 className="fs-6 fw-bold border-bottom pb-1">Com quem os dados são compartilhados</h2>
                <ul className="small mb-0">{dados.compartilhamento.map((t) => <li key={t}>{t}</li>)}</ul>
            </section>
            <p className="small text-muted">Finalidades, bases legais e prazos de guarda: veja a política de privacidade em {dados.politica}.</p>
        </div>
    )
}
DadosPessoais.layout = null
