<!DOCTYPE html>
<html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="sistema" content="{{ config('app.name') }}">
        <script nonce="{{ Vite::cspNonce() }}">
            // tema e menu recolhido antes de desenhar a página (evita piscar branco no modo escuro)
            (function () {
                var t = null, m = null;
                try { t = localStorage.getItem('erp-tema'); m = localStorage.getItem('erp-menu'); } catch (e) {}
                if (!t) t = window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                document.documentElement.setAttribute('data-bs-theme', t);
                if (m === 'recolhido') document.documentElement.classList.add('app-recolhido');
            })();
        </script>
        <link rel="icon" href="/imagens/favicon.ico" sizes="any">
        <link rel="icon" href="/imagens/favicon-96.png" type="image/png" sizes="96x96">
        <link rel="apple-touch-icon" href="/imagens/apple-touch-icon.png">
        @viteReactRefresh
        @vite('resources/js/app.jsx')
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>
