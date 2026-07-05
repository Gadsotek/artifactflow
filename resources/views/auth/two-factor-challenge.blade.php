<x-layouts.app title="Two-factor challenge">
    <section class="af-auth-shell" data-auth-shell>
        <div class="af-auth-story">
            <a class="af-auth-brand" href="{{ route('home') }}">
                <x-brand-mark />
                <span>artifact<span class="af-brand-flow">flow</span></span>
            </a>

            <div class="af-auth-story-copy">
                <p class="af-eyebrow">Account security</p>
                <h1>Enter your authentication code.</h1>
                <p>Use your authenticator app or one of your recovery codes to finish signing in.</p>
            </div>
        </div>

        <div class="af-auth-form-panel">
            <div class="af-auth-form-card">
                <p class="af-eyebrow">Two-factor authentication</p>
                <h2>Finish sign in</h2>

                <form class="mt-8 space-y-5" method="POST" action="{{ route('login.two-factor.store') }}">
                    @csrf

                    <div>
                        <label class="block text-sm font-medium text-zinc-800" for="code">Authentication code</label>
                        <input
                            id="code"
                            name="code"
                            type="text"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            class="af-input mt-2 block w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-base text-zinc-950 shadow-sm outline-none transition"
                        >
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-zinc-800" for="recovery_code">Recovery code</label>
                        <input
                            id="recovery_code"
                            name="recovery_code"
                            type="text"
                            autocomplete="off"
                            class="af-input mt-2 block w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-base text-zinc-950 shadow-sm outline-none transition"
                        >
                    </div>

                    @error('code')
                        <p class="text-sm text-red-700">{{ $message }}</p>
                    @enderror

                    <label class="flex items-center gap-2 text-sm text-zinc-700">
                        <input name="remember_device" type="checkbox" value="1" class="af-checkbox h-4 w-4 rounded border-zinc-300">
                        Remember this device for {{ $trustedDeviceDays }} {{ $trustedDeviceDays === 1 ? 'day' : 'days' }}
                    </label>

                    <button class="af-auth-submit w-full px-4 py-2.5 text-sm font-semibold text-white transition" type="submit">
                        Verify
                    </button>
                </form>
            </div>
        </div>
    </section>
</x-layouts.app>
