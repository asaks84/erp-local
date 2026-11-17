import '../../scss/app.scss'
import '@fontsource-variable/inter'
import { createRoot } from 'react-dom/client'
import App from './App'

createRoot(document.getElementById('pdv')).render(<App />)

// permite instalar o PDV como app (sem cache por enquanto)
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/pdv-sw.js', { scope: '/pdv' }).catch(() => {}))
}
