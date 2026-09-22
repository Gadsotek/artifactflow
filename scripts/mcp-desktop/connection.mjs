const URL_ERROR =
  'Enter the HTTPS ArtifactFlow app URL or its /mcp endpoint. HTTP is allowed only on localhost.';
const TOKEN_ERROR =
  'Enter your complete personal MCP token from AI connections, without the Bearer prefix.';

export function connectionConfig(value, token) {
  // Check the original spelling before URL normalizes control characters,
  // backslashes, dot segments, or alternative IPv4 address representations.
  const match =
    typeof value === 'string'
      ? /^(https?):\/\/([^/?#\\\s]+)(\/mcp\/|\/mcp|\/)?$/i.exec(value)
      : null;
  if (!match || match[2].includes('@')) throw new Error(URL_ERROR);
  let url;
  try {
    url = new URL(value);
  } catch {
    throw new Error(URL_ERROR);
  }
  const allowHttp = url.protocol === 'http:';
  if (allowHttp && !/^(localhost|127\.0\.0\.1|\[::1\])(?::[0-9]+)?$/i.test(match[2])) {
    throw new Error(URL_ERROR);
  }
  if (typeof token !== 'string' || !/^af_mcp_[A-Za-z0-9]{64}$/.test(token)) {
    throw new Error(TOKEN_ERROR);
  }
  return { endpoint: `${url.origin}/mcp`, token, allowHttp };
}
