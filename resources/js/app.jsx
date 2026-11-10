import '../scss/app.scss'
import '@fontsource-variable/inter'
import { createInertiaApp } from '@inertiajs/react'
import { createRoot } from 'react-dom/client'
import AppLayout from './Layouts/AppLayout'

const nomeSistema = document.querySelector('meta[name="sistema"]')?.content || 'Sistema'

createInertiaApp({
    title: (titulo) => (titulo ? `${titulo} · ${nomeSistema}` : nomeSistema),
    // cada tela é baixada só quando abre (o sistema fica leve mesmo com muitos módulos)
    resolve: async (nome) => {
        const paginas = import.meta.glob('./Pages/**/*.jsx')
        if (!paginas[`./Pages/${nome}.jsx`]) throw new Error(`Tela não encontrada: ${nome}`)
        const pagina = await paginas[`./Pages/${nome}.jsx`]()
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
