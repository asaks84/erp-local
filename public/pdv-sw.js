// PDV: service worker mínimo, só para o app poder ser instalado. Não guarda nada em cache
// (a fila do modo sem internet entra na etapa 8.7).
self.addEventListener('install', () => self.skipWaiting())
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()))
self.addEventListener('fetch', () => {})
