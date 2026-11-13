import assert from 'node:assert/strict';
import test from 'node:test';
import { createRouterHandler } from '../src/mcp-stdio.mjs';

const registry = {
  default_site_id: 'wp-demo',
  sites: ['wp-demo', 'other'].map((site_id) => ({
    site_id, platform: 'wordpress', url: `https://${site_id}.test/wp-json/mirasai/v1/mcp`, token_env: 'TOKEN',
  })),
};

// A host that knows two tools and counts what it is asked.
function fakeHost(counters, tools = ['template/read', 'template/element-delete']) {
  return {
    toolsList: async () => {
      counters.lists += 1;
      return { tools: tools.map((name) => ({ name, inputSchema: { type: 'object' } })) };
    },
    callTool: async (name, args) => {
      counters.calls.push([name, args]);
      if (args.post_id === 404) {
        return { content: [{ type: 'text', text: '{"error":"Post 404 not found.","code":"post_not_found"}' }], isError: true };
      }
      return { content: [], structuredContent: { post_id: args.post_id, etag: `etag-${args.post_id}` } };
    },
  };
}

function router(counters, tools) {
  const handler = createRouterHandler(registry, { clientFactory: () => fakeHost(counters, tools) });
  return async (args) => (await handler({
    jsonrpc: '2.0', id: 1, method: 'tools/call', params: { name: 'mirasai/batch', arguments: args },
  })).result;
}

const read = (post_id) => ({ name: 'template/read', arguments: { post_id, mode: 'outline' } });

test('a batch runs its calls in order in one process and lists the host tools once', async () => {
  const counters = { lists: 0, calls: [] };
  const result = await router(counters)({ site_id: 'wp-demo', calls: [read(1), read(2), read(3)] });

  assert.equal(result.isError, undefined);
  assert.deepEqual(result.structuredContent.results.map((entry) => entry.result.etag), ['etag-1', 'etag-2', 'etag-3']);
  assert.deepEqual(result.structuredContent.results.map((entry) => entry.ok), [true, true, true]);
  assert.equal(result.structuredContent.completed, 3);
  // Seven layouts used to cost seven tools/list round trips on top of the reads.
  assert.equal(counters.lists, 1);
  // site_id is the router's argument: the host never sees it.
  assert.deepEqual(counters.calls[0], ['template/read', { post_id: 1, mode: 'outline' }]);
});

test('a batch stops at the first failed call and says what it did not send', async () => {
  const counters = { lists: 0, calls: [] };
  const result = await router(counters)({ calls: [read(1), read(404), read(3)] });

  assert.equal(result.isError, true);
  assert.equal(result.structuredContent.completed, 2);
  assert.equal(result.structuredContent.failed, 1);
  assert.equal(result.structuredContent.not_run, 1);
  assert.equal(result.structuredContent.results[1].ok, false);
  assert.equal(result.structuredContent.results[1].result.code, 'post_not_found');
  assert.equal(counters.calls.length, 2);
});

test('stop_on_error=false runs every call and reports each failure', async () => {
  const counters = { lists: 0, calls: [] };
  const result = await router(counters)({ calls: [read(404), read(2)], stop_on_error: false });

  assert.equal(result.isError, true);
  assert.deepEqual(result.structuredContent.results.map((entry) => entry.ok), [false, true]);
  assert.equal(result.structuredContent.not_run, undefined);
});

test('a batch stays on one site and cannot nest', async () => {
  const counters = { lists: 0, calls: [] };
  const call = router(counters);
  const otherSite = await call({ site_id: 'wp-demo', calls: [{ name: 'template/read', arguments: { site_id: 'other', post_id: 1 } }] });
  assert.equal(otherSite.structuredContent.results[0].result.code, 'invalid_batch_call');
  assert.match(otherSite.structuredContent.results[0].result.error, /one site/);

  const nested = await call({ calls: [{ name: 'mirasai/batch', arguments: { calls: [read(1)] } }] });
  assert.equal(nested.structuredContent.results[0].result.code, 'invalid_batch_call');
  assert.equal(counters.calls.length, 0);
});

test('a batch rejects an empty or oversized list before calling the host', async () => {
  const counters = { lists: 0, calls: [] };
  const call = router(counters);
  assert.equal((await call({ calls: [] })).structuredContent.code, 'invalid_arguments');
  assert.equal((await call({ calls: Array.from({ length: 51 }, () => read(1)) })).structuredContent.code, 'too_many_calls');
  assert.equal((await call({ calls: [read(1)], sites: 'x' })).structuredContent.code, 'unknown_argument');
  assert.equal(counters.lists, 0);
});

test('a tool the host does not list is still reported, and one installed later is found', async () => {
  const counters = { lists: 0, calls: [] };
  const tools = ['template/read'];
  const call = router(counters, tools);

  const missing = await call({ calls: [read(1), { name: 'template/element-batch', arguments: {} }] });
  assert.equal(missing.structuredContent.results[1].result.code, 'tool_not_supported_on_platform');

  tools.push('template/element-batch');
  const found = await call({ calls: [{ name: 'template/element-batch', arguments: { post_id: 5 } }] });
  assert.equal(found.structuredContent.results[0].ok, true);
});

test('a batch keeps every part of a result that is more than one text', async () => {
  const parts = [
    { type: 'text', text: '{"note":"first"}' },
    { type: 'image', data: 'AAAA', mimeType: 'image/png' },
    { type: 'text', text: 'second' },
  ];
  const handler = createRouterHandler(registry, {
    clientFactory: () => ({
      toolsList: async () => ({ tools: [{ name: 'demo/shot', inputSchema: { type: 'object' } }] }),
      callTool: async (name, args) => (args.plain ? { content: [parts[0]] } : { content: parts }),
    }),
  });
  const result = (await handler({
    jsonrpc: '2.0', id: 1, method: 'tools/call',
    params: { name: 'mirasai/batch', arguments: { calls: [{ name: 'demo/shot' }, { name: 'demo/shot', arguments: { plain: true } }] } },
  })).result.structuredContent.results;

  assert.deepEqual(result[0].result, { note: 'first' });
  assert.deepEqual(result[0].content, parts);
  // One text part is the payload itself: repeating it would only double the answer.
  assert.equal(result[1].content, undefined);
});
