import { expect, test as base } from '@playwright/test';
import { createHostCookieServer } from './helpers/host-cookie-server';

const appOrigin = 'https://artifactflow.cookie-prefix.test';
const siblingOrigin = 'https://secure.cookie-prefix.test';
const artifactOrigin = 'https://artifacts.cookie-prefix.test';
const cookieNames = ['__Host-artifactflow_session', '__Host-artifactflow_trusted_device'];

const test = base.extend<{ cookieServer: Awaited<ReturnType<typeof createHostCookieServer>> }>({
  cookieServer: async ({}, use) => {
    const server = await createHostCookieServer([appOrigin, siblingOrigin, artifactOrigin]);
    try {
      await use(server);
    } finally {
      await server.close();
    }
  },
  proxy: async ({ cookieServer }, use) => {
    await use(cookieServer.proxy);
  },
});

test.use({ screenshot: 'off', trace: 'off', video: 'off', ignoreHTTPSErrors: true });

// Controlled HTTPS response headers exercise each engine's cookie acceptance
// and request attachment rules, measured at the receiving server. Intercepted
// WebKit responses may expose only provisional headers without Cookie, so
// route.fulfill()/request.allHeaders() cannot prove this boundary. PHP tests
// separately cover app issuance/authentication; this is not production TLS.
test('host cookie prefixes reject sibling domain injection and invalid attributes @artifact-security', async ({
  page,
  context,
  cookieServer,
}) => {
  async function visit(url: string, cookie?: string) {
    if (cookie !== undefined) {
      cookieServer.responseCookies.set(url, cookie);
    }
    const response = await page.goto(url);
    expect(response).not.toBeNull();
    expect(response!.status()).toBe(200);
    expect(cookieServer.requests.has(url), 'Every probe must reach the HTTPS server').toBe(true);
    return cookieServer.requests.get(url)!;
  }

  let sequence = 0;
  for (const name of cookieNames) {
    await visit(
      `${appOrigin}/issue/${sequence++}`,
      `${name}=legitimate; Secure; HttpOnly; Path=/; SameSite=Lax`,
    );
  }

  // Positive control: a sibling really can issue an ordinary parent-domain
  // cookie. Rejection below must come from prefix enforcement, not the fixture.
  await visit(
    `${siblingOrigin}/control`,
    'unprefixed_control=accepted; Domain=cookie-prefix.test; Secure; Path=/',
  );
  expect(await context.cookies(appOrigin)).toEqual(
    expect.arrayContaining([
      expect.objectContaining({ name: 'unprefixed_control', value: 'accepted' }),
    ]),
  );
  expect((await visit(`${appOrigin}/control-probe`)).cookie).toContain(
    'unprefixed_control=accepted',
  );

  for (const name of cookieNames) {
    for (const [origin, attributes] of [
      [siblingOrigin, 'Secure; Path=/; Domain=cookie-prefix.test'],
      [appOrigin, 'Secure; Path=/; Domain=artifactflow.cookie-prefix.test'],
      [appOrigin, 'Secure; Path=/probe'],
      [appOrigin, 'Path=/'],
      [appOrigin, 'Secure'],
    ]) {
      await visit(`${origin}/issue/${sequence++}`, `${name}=forged; HttpOnly; ${attributes}`);
    }

    // Inspect the whole jar so an illegally accepted non-root cookie cannot
    // disappear from the assertion merely because /probe does not match it.
    const cookies = (await context.cookies()).filter((cookie) => cookie.name === name);
    expect(cookies).toHaveLength(1);
    expect(cookies[0]).toMatchObject({
      value: 'legitimate',
      domain: 'artifactflow.cookie-prefix.test',
      path: '/',
      secure: true,
      httpOnly: true,
    });
  }

  // Expire the control via an actual response as well, preserving the browser's
  // original host-only cookies instead of modifying its cookie jar via the API.
  await visit(
    `${siblingOrigin}/clear-control`,
    'unprefixed_control=; Domain=cookie-prefix.test; Secure; Path=/; Max-Age=0',
  );
  const appRequest = await visit(`${appOrigin}/probe`);
  for (const name of cookieNames) {
    expect(appRequest.cookie).toContain(`${name}=legitimate`);
  }

  for (const origin of [siblingOrigin, artifactOrigin]) {
    const request = await visit(`${origin}/probe`);
    expect(request.cookie).toBeUndefined();
  }
});
