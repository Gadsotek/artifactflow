@use('Illuminate\Support\Facades\Vite')

<script nonce="{{ Vite::cspNonce() }}" data-external-theme-bootstrap>
    (() => {
        const root = document.documentElement;
        const allowed = new Set(['light', 'dark', 'system']);
        let theme = 'system';

        try {
            const stored = window.localStorage.getItem('artifactflow.external-share.theme');
            theme = allowed.has(stored) ? stored : theme;
        } catch {
            // Use the system preference when storage is unavailable.
        }

        const useDark = theme === 'dark'
            || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        root.dataset.theme = theme;
        root.classList.toggle('dark', useDark);
    })();
</script>
