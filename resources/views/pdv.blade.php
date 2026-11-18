<!DOCTYPE html>
<html lang="pt-BR" data-bs-theme="light">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
        <meta name="theme-color" content="#c0281b">
        <title>PDV · {{ config('app.name') }}</title>
        <link rel="manifest" href="/pdv.webmanifest">
        <link rel="icon" href="/imagens/favicon-96.png" type="image/png" sizes="96x96">
        <link rel="apple-touch-icon" href="/imagens/apple-touch-icon.png">
        @viteReactRefresh
        @vite('resources/js/pdv/main.jsx')
    </head>
    <body class="pdv">
        <div id="pdv"></div>
    </body>
</html>
