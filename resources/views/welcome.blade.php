<x-layouts.app>
    <div class="af-welcome">
        <nav class="af-welcome-nav">
            <a class="af-auth-brand" href="{{ route('home') }}">
                <x-brand-mark />
                <span>artifact<span class="af-brand-flow">flow</span></span>
            </a>
            <a class="af-welcome-login" href="{{ route('login') }}">Sign in <span aria-hidden="true">→</span></a>
        </nav>

        <section class="af-welcome-hero">
            <div class="af-welcome-copy">
                <p class="af-eyebrow">Your team’s executable knowledge base</p>
                <h1>Store, search, and safely run generated HTML artifacts.</h1>
                <p class="af-welcome-lede">
                    A security-first home for Markdown, Mermaid diagrams, and interactive artifacts, organized into workspaces and protected by explicit access controls.
                </p>
                <div class="af-welcome-actions">
                    <a class="af-welcome-primary" href="{{ route('login') }}">Open your workspace <span aria-hidden="true">→</span></a>
                    <a class="af-welcome-secondary" href="{{ config('app.source_url') }}" rel="noopener noreferrer">View source</a>
                </div>
            </div>

            <div class="af-welcome-visual" aria-label="Artifactflow workspace preview">
                <div class="af-visual-window">
                    <div class="af-visual-toolbar"><i></i><i></i><i></i><span>Architecture knowledge</span></div>
                    <div class="af-visual-body">
                        <aside>
                            <span class="is-active"></span>
                            <span></span>
                            <span></span>
                            <span></span>
                        </aside>
                        <div class="af-visual-document">
                            <small>PLATFORM / ARCHITECTURE</small>
                            <strong>Artifact isolation model</strong>
                            <p></p>
                            <p></p>
                            <div class="af-visual-diagram">
                                <b>APP</b><em>signed preview</em><b>ARTIFACT HOST</b>
                            </div>
                            <div class="af-visual-tags"><span>Security</span><span>Architecture</span><span>Approved</span></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="af-welcome-foundation">
            <div>
                <span>01</span>
                <h2>Knowledge that stays portable</h2>
                <p>Rich editing with clean Markdown source, inline Mermaid, immutable versions, tags, hierarchy, and full-text discovery.</p>
            </div>
            <div>
                <span>02</span>
                <h2>Executable, without blind trust</h2>
                <p>Untrusted HTML runs only on an isolated origin with strict sandboxing, CSP, short-lived URLs, and no application cookies.</p>
            </div>
            <div>
                <span>03</span>
                <h2>Built for accountable teams</h2>
                <p>Workspace roles, page-level overrides, durable domain events, and audit entries keep access and change history explainable.</p>
            </div>
        </section>

        <footer class="af-welcome-footer">
            <p>Laravel · PostgreSQL · Caddy · FrankenPHP</p>
        </footer>
    </div>
</x-layouts.app>
