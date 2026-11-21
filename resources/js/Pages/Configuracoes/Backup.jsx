import { router, useForm, usePage } from '@inertiajs/react'
import { useState } from 'react'
import AbasConfiguracoes from '../../Components/AbasConfiguracoes'
import Campo from '../../Components/Campo'
import TituloPagina from '../../Components/TituloPagina'

function Copiar({ texto }) {
    const [ok, setOk] = useState(false)
    return <button type="button" className="btn btn-sm btn-outline-secondary text-nowrap" onClick={() => navigator.clipboard?.writeText(texto).then(() => { setOk(true); setTimeout(() => setOk(false), 1500) })}>{ok ? 'Copiado' : 'Copiar'}</button>
}

/** passo a passo para criar o app no Google Cloud (uma vez só) */
function PassoAPasso({ retorno, aberto: inicial }) {
    const [aberto, setAberto] = useState(inicial)
    return (
        <div className="card mb-4">
            <button type="button" className="card-header bg-transparent border-0 text-start w-100" onClick={() => setAberto(!aberto)} aria-expanded={aberto}>
                <strong>Como criar o acesso no Google (uma vez só, uns 10 minutos)</strong>
            </button>
            {aberto && (
                <div className="card-body pt-0 small">
                    <ol className="mb-2">
                        <li className="mb-2">Entre em <strong>console.cloud.google.com</strong> com a conta do Google onde os backups vão ficar e crie um projeto (ex.: "ERP Local").</li>
                        <li className="mb-2">Em <strong>APIs e serviços › Biblioteca</strong>, procure <strong>Google Drive API</strong> e clique em <strong>Ativar</strong>.</li>
                        <li className="mb-2">Em <strong>Tela de consentimento OAuth</strong> (ou "Google Auth Platform"): tipo <strong>Externo</strong>, nome do app "ERP Local", seu e-mail de contato. Não precisa adicionar escopos.</li>
                        <li className="mb-2">Ainda na tela de consentimento, em <strong>Público-alvo</strong>, clique em <strong>Publicar app</strong> (status "Em produção"). Se ficar em "Teste", o Google derruba a conexão a cada 7 dias.</li>
                        <li className="mb-2">Em <strong>Credenciais › Criar credenciais › ID do cliente OAuth</strong>: tipo <strong>Aplicativo da Web</strong>. Em <strong>URIs de redirecionamento autorizados</strong>, cole:
                            <div className="d-flex align-items-center gap-2 mt-1"><code className="text-break">{retorno}</code><Copiar texto={retorno} /></div></li>
                        <li className="mb-2">O Google mostra o <strong>ID do cliente</strong> e a <strong>chave secreta do cliente</strong>. Cole os dois abaixo, crie a senha do backup e salve.</li>
                        <li>Clique em <strong>Conectar Google Drive</strong>. Se o Google avisar que o app não foi verificado, clique em <strong>Avançado › Acessar ERP Local</strong> e permita: é o seu próprio app.</li>
                    </ol>
                    <p className="text-muted mb-0">O ERP pede só a permissão de mexer nos arquivos que ele mesmo cria: ele não vê nada do resto do seu Drive.</p>
                </div>
            )}
        </div>
    )
}

function Dados({ drive }) {
    const form = useForm({ client_id: drive.client_id || '', client_secret: '', senha: '', senha_confirmation: '', dias: drive.dias })
    const e = form.errors
    return (
        <form className="card h-100" noValidate onSubmit={(ev) => { ev.preventDefault(); form.post('/configuracoes/backup/google', { preserveScroll: true, onSuccess: () => form.reset('client_secret', 'senha', 'senha_confirmation') }) }}>
            <div className="card-header">Acesso ao Google e senha do backup</div>
            <div className="card-body">
                <Campo rotulo="ID do cliente" value={form.data.client_id} onChange={(ev) => form.setData('client_id', ev.target.value.trim())} erro={e.client_id} autoComplete="off" />
                <Campo rotulo="Chave secreta do cliente" type="password" value={form.data.client_secret} onChange={(ev) => form.setData('client_secret', ev.target.value)} erro={e.client_secret} autoComplete="new-password"
                    ajuda={drive.tem_client ? 'Guardada. Vazio = mantém.' : 'Fica guardada criptografada; ninguém vê de novo pela tela.'} />
                <div className="alert alert-warning small py-2">
                    <strong>Senha do backup:</strong> os arquivos vão para o Drive trancados com ela. <strong>Anote e guarde fora do computador e do servidor</strong>: sem ela, ninguém (nem você) abre os backups. Se trocar, os backups antigos continuam abrindo só com a senha antiga.
                </div>
                <div className="row g-2">
                    <div className="col-sm-6"><Campo rotulo="Senha do backup" type="password" value={form.data.senha} onChange={(ev) => form.setData('senha', ev.target.value)} erro={e.senha} autoComplete="new-password"
                        ajuda={drive.tem_senha ? 'Guardada. Vazio = mantém.' : 'Pelo menos 12 caracteres; uma frase serve.'} /></div>
                    <div className="col-sm-6"><Campo rotulo="Repita a senha" type="password" value={form.data.senha_confirmation} onChange={(ev) => form.setData('senha_confirmation', ev.target.value)} autoComplete="new-password" /></div>
                </div>
                <Campo rotulo="Guardar no Drive por (dias)" type="number" min="7" max="365" value={form.data.dias} onChange={(ev) => form.setData('dias', ev.target.value)} erro={e.dias}
                    ajuda="Backups mais velhos que isso são apagados da pasta (os 3 mais novos ficam sempre)." className="mb-0" />
            </div>
            <div className="card-footer bg-transparent d-flex justify-content-end">
                <button type="submit" className="btn btn-primary" disabled={form.processing}>Salvar</button>
            </div>
        </form>
    )
}

function Situacao({ drive, locais, diasLocais }) {
    const [ocupado, setOcupado] = useState(false)
    const { errors } = usePage().props
    const pronto = drive.tem_client && drive.tem_senha
    const post = (url) => { setOcupado(true); router.post(url, {}, { preserveScroll: true, onFinish: () => setOcupado(false) }) }
    return (
        <div className="card h-100">
            <div className="card-header d-flex align-items-center">Situação
                <span className={`selo ms-auto ${drive.conectado ? (drive.ultimo_erro ? 'selo-aviso' : 'selo-ok') : ''}`}>{drive.conectado ? 'Drive conectado' : 'Drive não conectado'}</span>
            </div>
            <div className="card-body small">
                {errors.drive && <div className="alert alert-warning py-2">{errors.drive}</div>}
                <p className="mb-2"><strong>No servidor:</strong> todo dia às 03:10, guardado por {diasLocais} dias.{locais[0] && <> Último: {locais[0].em} ({locais.filter((l) => l.em === locais[0].em).reduce((t, l) => t + l.kb, 0)} KB).</>}</p>
                {drive.conectado ? (
                    <>
                        <p className="mb-2"><strong>No Google Drive:</strong> {drive.conta || 'conta conectada'}, pasta "{drive.pasta}".</p>
                        <p className="mb-2">{drive.ultimo_ok ? <>Último envio: <strong>{drive.ultimo_ok}</strong>{drive.ultimo_envio && <> · {drive.ultimo_envio.arquivos.length} arquivo(s), {drive.ultimo_envio.kb} KB</>}</> : 'Ainda não enviou nenhum backup.'}</p>
                    </>
                ) : <p className="mb-2 text-muted">{pronto ? 'Dados salvos. Falta conectar a conta do Google.' : 'Preencha os dados ao lado e salve; depois conecte.'}</p>}
                {drive.ultimo_erro && <div className="alert alert-danger py-2 mb-2">Última falha: {drive.ultimo_erro}</div>}
                <p className="text-muted mb-0">Para abrir um backup baixado do Drive: no computador, <code>php artisan backup:abrir arquivo.sql.gz.enc</code> (pede a senha), ou com o OpenSSL: <code className="text-break">openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -md sha256 -in arquivo.sql.gz.enc -out arquivo.sql.gz</code></p>
            </div>
            <div className="card-footer bg-transparent d-flex flex-wrap gap-2">
                {drive.conectado
                    ? <button type="button" className="btn btn-link text-danger px-0 me-auto" disabled={ocupado} onClick={() => post('/configuracoes/backup/google/desconectar')}>Desconectar</button>
                    : <button type="button" className="btn btn-outline-primary me-auto" disabled={ocupado || !pronto} onClick={() => post('/configuracoes/backup/google/conectar')}>Conectar Google Drive</button>}
                <button type="button" className="btn btn-primary" disabled={ocupado} onClick={() => post('/configuracoes/backup/agora')}>{ocupado ? 'Aguarde…' : 'Fazer backup agora'}</button>
            </div>
        </div>
    )
}

/** Configurações › Backup: backup diário no servidor + cópia criptografada no Google Drive */
export default function Backup({ drive, locais, dias_locais: diasLocais }) {
    return (
        <>
            <TituloPagina titulo="Configurações" descricao="Backup do banco: todo dia no servidor e, com o Google Drive conectado, uma cópia criptografada fora dele." />
            <AbasConfiguracoes />
            <PassoAPasso retorno={drive.retorno} aberto={!drive.conectado} />
            <div className="row g-4">
                <div className="col-lg-6"><Dados drive={drive} /></div>
                <div className="col-lg-6"><Situacao drive={drive} locais={locais} diasLocais={diasLocais} /></div>
            </div>
        </>
    )
}
