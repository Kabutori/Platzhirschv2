import { test } from 'node:test';
import assert from 'node:assert/strict';
import { McpServer } from './server.mjs';
const token = 'ph_00000000-0000-4000-8000-000000000001.' + 'a'.repeat(64);
const operation = {
  id: 'support.get.support',
  method: 'GET',
  path: '/api/external/v1/support/support',
  parameters: [],
  mcp: true,
  confirmation: false,
};
async function ready(fetchImpl) {
  const s = new McpServer({ baseUrl: 'https://platzhirsch.example', token, fetchImpl });
  await s.handle({ jsonrpc: '2.0', id: 1, method: 'initialize', params: { protocolVersion: '2025-11-25' } });
  await s.handle({ jsonrpc: '2.0', method: 'notifications/initialized' });
  return s;
}
test('MCP negotiates, lists current permitted tools and rechecks on each call', async () => {
  let enabled = true,
    calls = [];
  const s = await ready(async (url, options) => {
    calls.push({ url, options });
    return Response.json(
      url.endsWith('/catalog')
        ? { audience: 'mcp', operations: enabled ? [operation] : [] }
        : { data: [{ id: 1 }] },
    );
  });
  const list = await s.handle({ jsonrpc: '2.0', id: 2, method: 'tools/list' });
  assert.equal(list.result.tools[0].name, 'support_get_support');
  const call = await s.handle({
    jsonrpc: '2.0',
    id: 3,
    method: 'tools/call',
    params: { name: 'support_get_support', arguments: { parameters: {} } },
  });
  assert.match(call.result.content[0].text, /"id":1/);
  assert.equal(calls[0].options.redirect, 'error');
  assert.equal(calls[0].options.headers.Authorization, 'Bearer ' + token);
  enabled = false;
  const denied = await s.handle({
    jsonrpc: '2.0',
    id: 4,
    method: 'tools/call',
    params: { name: 'support_get_support', arguments: { parameters: {} } },
  });
  assert.equal(denied.result.isError, true);
});
test('write cannot execute without a confirmation and idempotency key', async () => {
  let writes = 0;
  const write = { ...operation, id: 'support.post.support', method: 'POST' };
  const s = await ready(async (url, o) => {
    if (o.method === 'POST') writes++;
    return Response.json({ audience: 'mcp', operations: [write] });
  });
  const r = await s.handle({
    jsonrpc: '2.0',
    id: 2,
    method: 'tools/call',
    params: { name: 'support_post_support', arguments: { parameters: {}, body: {} } },
  });
  assert.equal(r.result.isError, true);
  assert.equal(writes, 0);
});
test('transport refuses insecure destinations, API-only credentials and malformed input', async () => {
  assert.throws(() => new McpServer({ baseUrl: 'http://localhost', token }));
  assert.throws(() => new McpServer({ baseUrl: 'https://user:secret@host', token }));
  const s = await ready(async () => Response.json({ audience: 'api', operations: [operation] }));
  const r = await s.handle({ jsonrpc: '2.0', id: 2, method: 'tools/list' });
  assert.ok(r.error);
  assert.equal((await s.handle([])).error.code, -32600);
});
