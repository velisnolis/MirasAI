import assert from 'node:assert/strict';
import test from 'node:test';
import { createRouterHandler } from '../src/mcp-stdio.mjs';
import { clearSecretCache, resolveToken } from '../src/secrets.mjs';

const registry = {
  default_site_id: 'unrelated',
  sites: ['unrelated', 'task-site'].map((site_id) => ({
    site_id, platform: 'wordpress', token_ref: `op://fake/${site_id}/token`,
  })),
};

test('scoped discovery and calls never construct an unrelated client', async () => {
  const contacted = [];
  const handler = createRouterHandler(registry, {
    siteId: 'task-site',
    clientFactory: (site) => {
      contacted.push(site.site_id);
      return {
        toolsList: async () => ({ tools: [{ name: 'content/read', inputSchema: { type: 'object', properties: {} } }] }),
        callTool: async () => ({ ok: true }),
      };
    },
  });
  const discovery = await handler({ id: 1, method: 'tools/list' });
  assert.deepEqual(contacted, ['task-site']);
  assert.deepEqual(discovery.result.tools.find((t) => t.name === 'content/read').metadata.site_ids, ['task-site']);
  const call = (arguments_) => handler({ id: 2, method: 'tools/call', params: { name: 'content/read', arguments: arguments_ } });
  assert.equal((await call({ site_id: 'unrelated' })).error.code, -32603);
  await call({}); // The scoped default must also point at the task site.
  await handler({ id: 3, method: 'tools/call', params: { name: 'mirasai/sites-test', arguments: { site_id: 'unrelated' } } });
  assert.deepEqual(contacted, ['task-site']);
});

test('unknown or empty scope fails instead of using the registry default', () => {
  for (const siteId of ['unknown', '']) {
    assert.throws(() => createRouterHandler(registry, { siteId }));
  }
});

test('a task socket is checked on every resolution, even after a cache fill', () => {
  clearSecretCache();
  const site = { ...registry.sites[1], secret_ttl_seconds: 3600 };
  let reads = 0;
  const options = { readOnePassword: () => { if (++reads > 1) throw new Error('Task expired'); return 'SYNTHETIC'; } };
  resolveToken(site, { AI_SECRET_TASK_SOCKET: '/fake/task.sock' }, options);
  assert.throws(() => resolveToken(site, { AI_SECRET_TASK_SOCKET: '/fake/task.sock' }, options), /Task expired/);
});

test('task mode cannot reuse a non-task cached value', () => {
  clearSecretCache();
  const site = { ...registry.sites[1], secret_ttl_seconds: 3600 };
  resolveToken(site, {}, { readOnePassword: () => 'SYNTHETIC_GLOBAL' });
  assert.throws(() => resolveToken(site, { AI_SECRET_TASK_SOCKET: '/fake/task.sock' }, {
    readOnePassword: () => { throw new Error('Secret not approved'); },
  }), /Secret not approved/);
});

test('required task mode refuses missing scope or a missing compatible reader before any read', () => {
  let reads = 0;
  const options = { readOnePassword: () => { reads++; return 'SYNTHETIC'; } };
  for (const env of [
    { MIRASAI_MCP_REQUIRE_TASK: '1' },
    { MIRASAI_MCP_REQUIRE_TASK: '1', AI_SECRET_TASK_SOCKET: '/fake/task.sock' },
  ]) {
    assert.throws(() => resolveToken(registry.sites[1], env, options), /secret-task|reader/i);
  }
  assert.equal(reads, 0);
});

test('an explicit task never falls through to direct op when the reader is missing', () => {
  assert.throws(() => resolveToken(registry.sites[1], {
    AI_SECRET_TASK_SOCKET: '/fake/task.sock',
  }), /direct op is disabled/);
});

test('required task mode rejects credentials that bypass exact-reference approval', () => {
  for (const source of [{ token_env: 'TOKEN' }, { basic_env: 'BASIC' }, { token_plain_dev: 'SYNTHETIC' }]) {
    assert.throws(() => resolveToken({ site_id: 'demo', ...source }, {
      MIRASAI_MCP_REQUIRE_TASK: '1', AI_SECRET_TASK_SOCKET: '/fake/task.sock',
      MIRASAI_MCP_SECRET_READER: '/fake/reader', TOKEN: 'SYNTHETIC', BASIC: 'user:SYNTHETIC',
    }), /exact 1Password reference/);
  }
});
