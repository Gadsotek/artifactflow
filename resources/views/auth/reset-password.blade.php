<x-layouts.app title="Set password">
    <section class="af-auth-shell" data-auth-shell>
        <div class="af-auth-story">
            <a class="af-auth-brand" href="{{ route('home') }}">
                <x-brand-mark />
                <span>artifact<span class="af-brand-flow">flow</span></span>
            </a>

            <div class="af-auth-story-copy">
                <p class="af-eyebrow">Account recovery</p>
                <h1>Set a new password.</h1>
                <p>Your existing sessions will be invalidated after a successful reset.</p>
            </div>

            <div class="af-auth-proof">
                <span><strong>Single-use</strong> reset token</span>
                <span><strong>Revoked</strong> active sessions</span>
                <span><strong>Audited</strong> reset event</span>
            </div>
        </div>

        <div class="af-auth-form-panel">
            <div class="af-auth-form-card">
                <p class="af-eyebrow">New password</p>
                <h2>Update your password</h2>
                <p class="af-auth-form-intro">Use at least 12 characters.</p>

                <form class="mt-8 space-y-5" method="POST" action="{{ route('password.update') }}">
                    @csrf
                    <input name="token" type="hidden" value="{{ $token }}">

                    <div>
                        <label class="block text-sm font-medium text-zinc-800" for="email">Email</label>
                        <input
                            id="email"
                            name="email"
                            type="email"
                            value="{{ old('email', is_string($email) ? $email : '') }}"
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
                            autocomplete="new-password"
                            minlength="12"
                            required
                            class="af-input mt-2 block w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-base text-zinc-950 shadow-sm outline-none transition"
                        >
                        @error('password')
                            <p class="mt-2 text-sm text-red-700">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-zinc-800" for="password_confirmation">Confirm password</label>
                        <input
                            id="password_confirmation"
                            name="password_confirmation"
                            type="password"
                            autocomplete="new-password"
                            minlength="12"
                            required
                            class="af-input mt-2 block w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-base text-zinc-950 shadow-sm outline-none transition"
                        >
                    </div>

                    <button class="af-auth-submit w-full px-4 py-2.5 text-sm font-semibold text-white transition" type="submit">
                        Reset password
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
