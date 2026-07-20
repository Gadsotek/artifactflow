<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Setup required / {{ config('app.name') }}</title>
        <link rel="icon" type="image/svg+xml" href="/favicon.svg">
        @vite('resources/css/app.css')
    </head>
    <body>
        <main class="af-installation-shell">
            <section class="af-installation-card" aria-labelledby="installation-title">
                <p class="af-eyebrow">Setup required</p>
                <h1 id="installation-title">ArtifactFlow is not ready yet</h1>
                <p>Database setup or an upgrade is still pending. Application routes stay unavailable until the schema is current.</p>

                <div class="af-installation-options">
                    <section class="af-installation-command">
                        <h2>Guided first-time setup</h2>
                        <p>Generate missing keys, apply migrations, and create the first System Admin through the guided prompts.</p>
                        <pre><code>make install</code></pre>
                    </section>

                    <section class="af-installation-command">
                        <h2>Apply the database schema only</h2>
                        <p>Use this when keys and administrator provisioning are managed separately.</p>
                        <pre><code>make migrate</code></pre>
                    </section>
                </div>

                <section class="af-installation-command af-installation-admin">
                    <h2>Create or promote a System Admin</h2>
                    <p>A new manually provisioned deployment also needs a login administrator. After <code>make migrate</code>, open the app container:</p>
                    <pre><code>make shell</code></pre>
                    <p>Then provide the password without placing it in shell history and run the bootstrap command:</p>
                    <pre><code>read -rs -p 'System admin password: ' ARTIFACTFLOW_ADMIN_PASSWORD; echo
export ARTIFACTFLOW_ADMIN_PASSWORD
php artisan artifactflow:bootstrap-admin \
  --name='Admin User' \
  --email='admin@example.test'
unset ARTIFACTFLOW_ADMIN_PASSWORD
exit</code></pre>
                </section>

                <p class="af-installation-note">
                    The readiness page clears as soon as all migrations are current. Existing installations that already have an administrator only need their approved migration workflow.
                </p>
            </section>
        </main>
    </body>
</html>
