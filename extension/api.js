/**
 * Requests to the site, from inside the sandboxed iframe.
 */
export const base = window.location.pathname.split('/modules/')[0];
export const api = `${base}/canvas/api/v0/config/canvas_view_template`;

const check = async (response) => {
  if (response.ok) {
    return response;
  }
  let detail = '';
  try {
    const body = await response.json();
    detail = body.errors?.map((e) => e.detail).join(' ') || body.message || '';
  } catch {
    // Not JSON; fall back to the status line.
  }
  const failure = new Error(detail || `${response.status} ${response.statusText}`);
  failure.status = response.status;
  throw failure;
};

export const getJson = async (url) => {
  const response = await check(
    await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } }),
  );
  return response.json();
};

export const request = async (url, method, body) => {
  const token = await (await fetch(`${base}/session/token`, { credentials: 'same-origin' })).text();
  const headers = { 'X-CSRF-Token': token, Accept: 'application/json' };
  if (body) {
    headers['Content-Type'] = 'application/json';
  }
  await check(
    await fetch(url, {
      method,
      credentials: 'same-origin',
      headers,
      body: body ? JSON.stringify(body) : undefined,
    }),
  );
};
