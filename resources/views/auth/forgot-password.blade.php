<x-layouts.app title="Reset password">
    <section class="af-auth-shell" data-auth-shell>
        <div class="af-auth-story">
            <a class="af-auth-brand" href="{{ route('home') }}">
                <x-brand-mark />
                <span>artifact<span class="af-brand-flow">flow</span></span>
            </a>

            <div class="af-auth-story-copy">
                <p class="af-eyebrow">Account recovery</p>
                <h1>Return to your workspace.</h1>
                <p>Request a reset link for the account provided by your deployment administrator.</p>
            </div>

            <div class="af-auth-proof">
                <span><strong>Time-limited</strong> reset links</span>
                <span><strong>Audited</strong> password changes</span>
                <span><strong>Isolated</strong> artifact runtime</span>
            </div>
        </div>

        <div class="af-auth-form-panel">
            <div class="af-auth-form-card">
                <p class="af-eyebrow">Password reset</p>
                <h2>Request a reset link</h2>
                <p class="af-auth-form-intro">Enter your email address.</p>

                @if (session('status'))
                    <div class="af-callout mt-6">
                        {{ session('status') }}
                    </div>
                @endif

                <form class="mt-8 space-y-5" method="POST" action="{{ route('password.email') }}">
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

                    <button class="af-auth-submit w-full px-4 py-2.5 text-sm font-semibold text-white transition" type="submit">
                        Send reset link
                        <span aria-hidden="true">→</span>
                    </button>
                </form>

                <p class="af-auth-security-note">
                    <a class="af-accent-link font-medium" href="{{ route('login') }}">Back to sign in</a>
                </p>
            </div>
        </div>
    </section>
</x-layouts.app>
