<x-layouts.app title="Login">
    <section class="af-auth-shell" data-auth-shell>
        <div class="af-auth-story">
            <a class="af-auth-brand" href="{{ route('home') }}">
                <x-brand-mark />
                <span>artifact<span class="af-brand-flow">flow</span></span>
            </a>

            <div class="af-auth-story-copy">
                <p class="af-eyebrow">The internal knowledge layer</p>
                <h1>Secure knowledge, beautifully organized.</h1>
                <p>Bring Markdown, Mermaid diagrams, and interactive HTML artifacts into one searchable workspace, without weakening the browser security boundary.</p>
            </div>

            <div class="af-auth-proof">
                <span><strong>Isolated</strong> artifact execution</span>
                <span><strong>Audited</strong> important changes</span>
                <span><strong>Portable</strong> Markdown source</span>
            </div>
        </div>

        <div class="af-auth-form-panel">
            <div class="af-auth-form-card">
                <p class="af-eyebrow">Welcome back</p>
                <h2>Sign in to your workspace</h2>
                <p class="af-auth-form-intro">Use the account provided by your deployment administrator.</p>

                @if (session('status'))
                    <div class="af-callout mt-6">
                        {{ session('status') }}
                    </div>
                @endif

                <form class="mt-8 space-y-5" method="POST" action="{{ route('login') }}">
                    @csrf

                    <div>
                        <label class="block text-sm font-medium text-zinc-800" for="email">Email</label>
                        <input
                            id="email"
                            name="email"
                            type="email"
                            value="{{ old('email') }}"
                            autocomplete="email"
                            required
                            autofocus
                            class="af-input mt-2 block w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-base text-zinc-950 shadow-sm outline-none transition"
                        >
                        @error('email')
                            <p class="mt-2 text-sm text-red-700">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-zinc-800" for="password">Password</label>
                        <input
                            id="password"
                            name="password"
                            type="password"
                            autocomplete="current-password"
                            required
                            class="af-input mt-2 block w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-base text-zinc-950 shadow-sm outline-none transition"
                        >
                        @error('password')
                            <p class="mt-2 text-sm text-red-700">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex items-center justify-end">
                        <a class="af-accent-link text-sm font-medium" href="{{ route('password.request') }}">Forgot password?</a>
                    </div>

                    <button class="af-auth-submit w-full px-4 py-2.5 text-sm font-semibold text-white transition" type="submit">
                        Sign in
                        <span aria-hidden="true">→</span>
                    </button>
                </form>

                <p class="af-auth-security-note">Public registration is disabled. Authentication is protected by rate limiting.</p>
            </div>
        </div>
    </section>
</x-layouts.app>
