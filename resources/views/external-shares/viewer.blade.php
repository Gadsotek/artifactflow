<!doctype html>
<html data-theme="system" lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex,nofollow,noarchive">
        <title>External artifact · ArtifactFlow</title>
        @include('external-shares.partials.theme-bootstrap')
        @vite(['resources/css/app.css', 'resources/js/external-share-viewer.js'])
    </head>
    <body class="antialiased">
        <div class="af-external-shell">
            @include('external-shares.partials.topbar')

            <div
                class="af-external-gate"
                data-external-share-viewer-shell
                data-external-share-uid="{{ $externalShareUid }}"
                data-external-share-content-endpoint="{{ route('external-shares.viewer.content', ['externalShareUid' => $externalShareUid], false) }}"
            >
                <section class="af-external-card">
                    <p class="af-eyebrow">ArtifactFlow external artifact</p>
                    <h1>Opening artifact</h1>
                    <p>Preparing the secured viewer…</p>
                </section>
            </div>
        </div>
    </body>
</html>
