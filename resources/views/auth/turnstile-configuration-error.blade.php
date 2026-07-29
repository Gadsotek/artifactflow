<x-layouts.app title="Authentication unavailable">
    <section class="af-auth-shell" data-auth-shell>
        <div class="af-auth-story">
            <a class="af-auth-brand" href="{{ route('home') }}">
                <x-brand-mark />
                <span>artifact<span class="af-brand-flow">flow</span></span>
            </a>

            <div class="af-auth-story-copy">
                <p class="af-eyebrow">Configuration required</p>
                <h1>Authentication is temporarily unavailable.</h1>
                <p>The deployment administrator needs to finish configuring the authentication challenge.</p>
            </div>
        </div>

        <div class="af-auth-form-panel">
            <div class="af-auth-form-card">
                <p class="af-eyebrow">Operator action required</p>
                <h2>Authentication challenge is not configured correctly.</h2>
                <p class="af-auth-form-intro">
                    Set both TURNSTILE_SITE_KEY and TURNSTILE_SECRET_KEY, or remove both to disable Turnstile.
                </p>
                <p class="af-auth-form-intro">
                    Verify the expected hostname and timeout settings.
                </p>
            </div>
        </div>
    </section>
</x-layouts.app>
