<!doctype html>
<html data-theme="system" lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex,nofollow,noarchive">
        <title>External artifact unavailable · ArtifactFlow</title>
        @include('external-shares.partials.theme-bootstrap')
        @vite(['resources/css/app.css', 'resources/js/external-share-viewer.js'])
    </head>
    <body class="antialiased">
        <div class="af-external-shell">
            @include('external-shares.partials.topbar')
            <main class="af-external-gate">
                <section class="af-external-card">
                    <p class="af-eyebrow">ArtifactFlow</p>
                    <h1>This external artifact is unavailable.</h1>
                    <p>Ask the person who shared it with you for a new link.</p>
                </section>
            </main>
        </div>
    </body>
</html>
