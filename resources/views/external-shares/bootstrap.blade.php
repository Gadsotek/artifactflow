<!doctype html>
<html data-theme="system" lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex,nofollow,noarchive">
        <title>External artifact · ArtifactFlow</title>
        @include('external-shares.partials.theme-bootstrap')
        @vite(['resources/css/app.css', 'resources/js/external-share-bootstrap.js'])
    </head>
    <body class="antialiased">
        <div class="af-external-shell">
            @include('external-shares.partials.topbar')
            <main
                class="af-external-gate"
                data-external-share-bootstrap
                data-external-share-uid="{{ $externalShareUid }}"
            >
                <section class="af-external-card">
                    <p class="af-eyebrow">ArtifactFlow external artifact</p>
                    <h1>Opening external artifact</h1>
                    <p data-external-share-bootstrap-status>
                        Validating this external link…
                    </p>
                    <noscript>
                        <p class="af-external-warning">
                            JavaScript is required to open this external artifact securely.
                        </p>
                    </noscript>
                </section>
            </main>
        </div>
    </body>
</html>
