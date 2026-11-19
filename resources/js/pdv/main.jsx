import '../../scss/app.scss'
import '@fontsource-variable/inter'
import { createRoot } from 'react-dom/client'
import App from './App'

createRoot(document.getElementById('pdv')).render(<App />)

// instala o PDV como app e guarda os arquivos no celular para abrir sem internet (8.7)
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/pdv-sw.js', { scope: '/pdv' }).catch(() => {})
        // abriu com internet: guarda de novo (pega a versão publicada agora)
        if (navigator.onLine) navigator.serviceWorker.ready.then((reg) => reg.active?.postMessage('guardar')).catch(() => {})
    })
}
