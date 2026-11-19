import '../../scss/app.scss'
import '@fontsource-variable/inter'
import { createRoot } from 'react-dom/client'
import App from './App'
import { registrarSw } from './offline/prontidao'

createRoot(document.getElementById('pdv')).render(<App />)

// instala o PDV como app e guarda os arquivos no celular para abrir sem internet (8.7)
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        registrarSw().catch((e) => { window.pdvSwErro = e?.message || String(e) })
        // abriu com internet: guarda de novo (pega a versão publicada agora)
        if (navigator.onLine) navigator.serviceWorker.ready.then((reg) => reg.active?.postMessage('guardar')).catch(() => {})
    })
}
