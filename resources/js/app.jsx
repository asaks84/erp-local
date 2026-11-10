import '../scss/app.scss'
import '@fontsource-variable/inter'
import { createInertiaApp } from '@inertiajs/react'
import { createRoot } from 'react-dom/client'
import AppLayout from './Layouts/AppLayout'

const nomeSistema = document.querySelector('meta[name="sistema"]')?.content || 'Sistema'

createInertiaApp({
    title: (titulo) => (titulo ? `${titulo} · ${nomeSistema}` : nomeSistema),
    resolve: (nome) => {
        const paginas = import.meta.glob('./Pages/**/*.jsx', { eager: true })
        const pagina = paginas[`./Pages/${nome}.jsx`]
        if (!pagina) throw new Error(`Tela não encontrada: ${nome}`)
        // toda tela usa o layout do sistema, menos as que dizem layout = null (login)
        if (pagina.default.layout === undefined) {
            pagina.default.layout = (conteudo) => <AppLayout>{conteudo}</AppLayout>
        }
        return pagina
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />)
    },
    progress: { color: '#c0281b', delay: 200 },
})
