export class ApiError extends Error {
  constructor(
    public status: number,
    message: string,
  ) {
    super(message);
  }
}
export async function api<T = any>(
  path: string,
  method = 'GET',
  body?: unknown,
  tenant?: string,
  signal?: AbortSignal,
): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (tenant) headers['X-Tenant-ID'] = tenant;
  if (method !== 'GET') {
    const csrf = await fetch('/api/csrf', {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      signal,
    });
    if (!csrf.ok) throw new ApiError(csrf.status, 'Sitzung konnte nicht vorbereitet werden.');
    headers['X-CSRF-TOKEN'] = (await csrf.json()).token;
    headers['Content-Type'] = 'application/json';
    if (path === 'bootstrap/first-admin' && body && typeof body === 'object' && 'setup_token' in body) {
      const { setup_token, ...rest } = body as Record<string, unknown>;
      headers['X-Setup-Token'] = String(setup_token);
      body = rest;
    }
  }
  const response = await fetch('/api/' + path, {
    method,
    headers,
    credentials: 'same-origin',
    body: body === undefined ? undefined : JSON.stringify(body),
    signal,
  });
  if (response.status === 204) return undefined as T;
  const json = await response.json().catch(() => ({ message: 'Serverantwort konnte nicht gelesen werden.' }));
  if (!response.ok)
    throw new ApiError(
      response.status,
      json.errors ? Object.values(json.errors).flat().join(' ') : json.message || 'Anfrage fehlgeschlagen.',
    );
  return json;
}
