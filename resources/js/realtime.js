const shell = document.querySelector('[data-realtime-enabled="true"][data-realtime-config]');

if (shell) {
  let config = null;

  try {
    config = JSON.parse(shell.dataset.realtimeConfig ?? 'null');
  } catch {
    // Malformed config: leave it null so the realtime block below stays disabled.
  }

  if (config?.enabled === true && config.key && config.host && config.port && config.scheme) {
    const [{ default: Echo }, { default: Pusher }] = await Promise.all([
      import('laravel-echo'),
      import('pusher-js'),
    ]);

    window.Pusher = Pusher;
    window.Echo = new Echo({
      broadcaster: 'reverb',
      key: config.key,
      wsHost: config.host,
      wsPort: config.port,
      wssPort: config.port,
      forceTLS: config.scheme === 'https',
      enabledTransports: ['ws', 'wss'],
    });
  }
}
