const host = process.env.REVERB_PROBE_HOST ?? '127.0.0.1';
const port = Number.parseInt(process.env.REVERB_PROBE_PORT ?? '8080', 10);
const appKey = process.env.REVERB_APP_KEY ?? 'artifactflow-smoke-test-key';
const allowedOrigin = process.env.REVERB_ALLOWED_ORIGIN ?? 'https://app.example.test';
const rejectedOrigin = process.env.REVERB_REJECTED_ORIGIN ?? 'https://evil.example.test';

if (!Number.isInteger(port) || port < 1 || port > 65535) {
  throw new Error(`Invalid REVERB_PROBE_PORT [${process.env.REVERB_PROBE_PORT ?? ''}].`);
}

async function probeAllowed(origin) {
  const message = parseJson(await retryHandshake(origin), 'websocket message');
  if (message.event !== 'pusher:connection_established') {
    throw new Error(`Expected ${origin} to establish a Pusher connection, got ${JSON.stringify(message)}.`);
  }

  console.log(`Reverb origin probe passed: ${origin} -> accepted`);
}

async function probeRejected(origin) {
  const message = parseJson(await retryHandshake(origin), 'websocket message');
  const data = parseJson(message.data, 'pusher error data');
  if (message.event !== 'pusher:error' || data.code !== 4009) {
    throw new Error(`Expected ${origin} to receive Pusher error 4009, got ${JSON.stringify(message)}.`);
  }

  console.log(`Reverb origin probe passed: ${origin} -> rejected`);
}

async function retryHandshake(origin) {
  let lastError;

  for (let attempt = 1; attempt <= 60; attempt += 1) {
    try {
      return await handshake(origin);
    } catch (error) {
      lastError = error;
      await new Promise((resolve) => setTimeout(resolve, 250));
    }
  }

  throw lastError;
}

function handshake(origin) {
  return new Promise((resolve, reject) => {
    let finished = false;
    const socket = new WebSocket(websocketUrl(), { headers: { Origin: origin } });

    const finish = (message) => {
      if (finished) {
        return;
      }

      finished = true;
      clearTimeout(timeout);
      socket.close();
      resolve(message);
    };

    const fail = (error) => {
      if (finished) {
        return;
      }

      finished = true;
      clearTimeout(timeout);
      socket.close();
      reject(error);
    };

    const timeout = setTimeout(() => {
      fail(new Error(`Timed out waiting for Reverb handshake from ${origin}.`));
    }, 2000);

    socket.addEventListener('message', (event) => {
      finish(websocketMessageToString(event.data));
    }, { once: true });

    socket.addEventListener('error', () => {
      fail(new Error(`Reverb websocket connection failed for ${origin}.`));
    }, { once: true });

    socket.addEventListener('close', (event) => {
      if (!finished) {
        fail(new Error(`Reverb closed the websocket before sending a message for ${origin}; code ${event.code}.`));
      }
    }, { once: true });
  });
}

function websocketUrl() {
  const query = new URLSearchParams({
    client: 'artifactflow-origin-probe',
    flash: 'false',
    protocol: '7',
    version: '0.0.0',
  });

  return `ws://${host}:${port}/app/${encodeURIComponent(appKey)}?${query.toString()}`;
}

function websocketMessageToString(data) {
  if (typeof data === 'string') {
    return data;
  }

  if (data instanceof ArrayBuffer) {
    return Buffer.from(data).toString('utf8');
  }

  if (ArrayBuffer.isView(data)) {
    return Buffer.from(data.buffer, data.byteOffset, data.byteLength).toString('utf8');
  }

  throw new Error(`Expected websocket message data to be text or bytes, got ${typeof data}.`);
}

function parseJson(value, label) {
  if (typeof value !== 'string') {
    throw new Error(`Expected ${label} to be a JSON string.`);
  }

  try {
    return JSON.parse(value);
  } catch (error) {
    throw new Error(`Could not parse ${label}: ${error.message}`);
  }
}

await probeAllowed(allowedOrigin);
await probeRejected(rejectedOrigin);
