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
test('MCP advertises module fields and canonicalizes query values for approval', async () => {
  const contract = {
    parameters: { type: 'object', properties: {}, required: [], additionalProperties: false },
    query: { type: 'object', properties: { page: { type: 'integer', minimum: 1 } }, required: ['page'] },
    body: {
      type: 'object',
      properties: { subject: { type: 'string', maxLength: 200 } },
      required: ['subject'],
    },
  };
  let prepared;
  const write = { ...operation, id: 'support.post.support', method: 'POST', contract };
  const s = await ready(async (url, o) => {
    if (url.endsWith('/confirmations')) {
      prepared = JSON.parse(o.body);
      return Response.json({ id: 'approval' });
    }
    return Response.json({ audience: 'mcp', operations: [write] });
  });
  const list = await s.handle({ jsonrpc: '2.0', id: 2, method: 'tools/list' });
  assert.deepEqual(list.result.tools[0].inputSchema.properties.body, contract.body);
  assert.ok(list.result.tools[0].inputSchema.required.includes('query'));
  await s.handle({
    jsonrpc: '2.0',
    id: 3,
    method: 'tools/call',
    params: {
      name: 'platzhirsch_prepare',
      arguments: { operation: write.id, parameters: {}, query: { page: 2 }, body: { subject: 'Test' } },
    },
  });
  assert.deepEqual(prepared.query, { page: '2' });
});

test('MCP exposes typed structured JSON output', async () => {
  const responseSchema = {
    type: 'array',
    items: { type: 'object', properties: { id: { type: 'integer' } }, required: ['id'] },
  };
  const op = {
    ...operation,
    contract: { responses: { 200: { content: { 'application/json': { schema: responseSchema } } } } },
  };
  const server = await ready(async (url) =>
    Response.json(url.endsWith('/catalog') ? { audience: 'mcp', operations: [op] } : [{ id: 7 }]),
  );
  const list = await server.handle({ jsonrpc: '2.0', id: 2, method: 'tools/list' });
  assert.deepEqual(list.result.tools[0].outputSchema.properties.data.anyOf, [responseSchema]);
  const result = await server.handle({
    jsonrpc: '2.0',
    id: 3,
    method: 'tools/call',
    params: { name: 'support_get_support', arguments: { parameters: {} } },
  });
  assert.deepEqual(result.result.structuredContent, { data: [{ id: 7 }] });
});
