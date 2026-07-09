import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { createRouterHandler } from '../src/mcp-stdio.mjs';

const packageVersion = JSON.parse(readFileSync(new URL('../package.json', import.meta.url), 'utf8')).version;

const registry = {
  schema_version: 1,
  default_site_id: 'joomla-demo',
  sites: [
    {
      site_id: 'joomla-demo',
      label: 'Joomla demo',
      platform: 'joomla',
      url: 'https://example.test/api/v1/mirasai/mcp',
      token_env: 'JOOMLA_TOKEN',
      default: true,
    },
  ],
};

test('router initialize reports package version', async () => {
  const handler = createRouterHandler(registry);
  const response = await handler({
    jsonrpc: '2.0',
    method: 'initialize',
    id: 1,
  });

  assert.equal(response.jsonrpc, '2.0');
  assert.equal(response.result.serverInfo.version, packageVersion);
});

test('router warns when a remote host reports an unexpected contract version', async () => {
  const handler = createRouterHandler(registry, {
    clientFactory: () => ({
      initialize: async () => ({
        serverInfo: { host_contract_version: '2' },
      }),
      toolsList: async () => ({ tools: [] }),
    }),
  });
  const response = await handler({
    jsonrpc: '2.0',
    method: 'tools/list',
    id: 1,
  });

  assert.equal(response.result.metadata.discovery_warnings[0].code, 'host_contract_version_mismatch');
  assert.equal(response.result.metadata.discovery_warnings[0].expected, '1');
  assert.equal(response.result.metadata.discovery_warnings[0].actual, '2');
});

test('router keeps discovering tools when contract version check fails', async () => {
  const handler = createRouterHandler(registry, {
    clientFactory: () => ({
      initialize: async () => {
        throw new Error('initialize unavailable');
      },
      toolsList: async () => ({
        tools: [
          {
            name: 'content/read',
            inputSchema: { type: 'object', properties: {} },
          },
        ],
      }),
    }),
  });
  const response = await handler({
    jsonrpc: '2.0',
    method: 'tools/list',
    id: 1,
  });

  assert.equal(response.result.metadata.discovery_warnings[0].code, 'host_contract_version_check_failed');
  assert(response.result.tools.some((tool) => tool.name === 'content/read'));
});

test('router surfaces registry warnings in discovery metadata and sites-list', async () => {
  const warningRegistry = {
    ...registry,
    warnings: [
      {
        code: 'registry_permissions_too_open',
        path: '/tmp/sites.json',
        mode: '0644',
      },
    ],
  };
  const handler = createRouterHandler(warningRegistry, {
    clientFactory: () => ({
      toolsList: async () => ({ tools: [] }),
    }),
  });

  const toolsResponse = await handler({
    jsonrpc: '2.0',
    method: 'tools/list',
    id: 1,
  });
  assert.equal(toolsResponse.result.metadata.registry_warnings[0].code, 'registry_permissions_too_open');

  const sitesResponse = await handler({
    jsonrpc: '2.0',
    method: 'tools/call',
    params: {
      name: 'mirasai/sites-list',
      arguments: {},
    },
    id: 2,
  });
  assert.equal(sitesResponse.result.structuredContent.warnings[0].mode, '0644');
});

test('router exposes local tools', async () => {
  const handler = createRouterHandler(registry, {
    clientFactory: () => ({
      toolsList: async () => ({ tools: [] }),
    }),
  });
  const response = await handler({
    jsonrpc: '2.0',
    method: 'tools/list',
    id: 1,
  });

  assert.equal(response.jsonrpc, '2.0');
  assert.equal(response.result.tools.length, 3);
  assert(response.result.tools.some((tool) => tool.name === 'mirasai/sites-list'));
});

test('router sites-list does not expose token values', async () => {
  const handler = createRouterHandler(registry);
  const response = await handler({
    jsonrpc: '2.0',
    method: 'tools/call',
    params: {
      name: 'mirasai/sites-list',
      arguments: {},
    },
    id: 2,
  });

  const payload = response.result.structuredContent;
  assert.equal(payload.default_site_id, 'joomla-demo');
  assert.equal(payload.sites[0].token_source, 'env');
  assert.equal(payload.sites[0].token_env, undefined);
});

test('router can test default site through injected client', async () => {
  const handler = createRouterHandler(registry, {
    clientFactory: (site) => ({
      test: async () => ({
        ok: true,
        site_id: site.site_id,
        platform: site.platform,
      }),
    }),
  });

  const response = await handler({
    jsonrpc: '2.0',
    method: 'tools/call',
    params: {
      name: 'mirasai/sites-test',
      arguments: {},
    },
    id: 3,
  });

  assert.equal(response.result.structuredContent.ok, true);
  assert.equal(response.result.structuredContent.site_id, 'joomla-demo');
});

test('router exposes remote tools with site_id injected', async () => {
  const handler = createRouterHandler(registry, {
    clientFactory: () => ({
      toolsList: async () => ({
        tools: [
          {
            name: 'content/read',
            description: 'Read content',
            inputSchema: {
              type: 'object',
              properties: {
                id: { type: 'integer' },
              },
              required: ['id'],
            },
            annotations: {
              readOnlyHint: true,
            },
            metadata: {
              risk_level: 'read',
              workflow_hint: 'direct',
              surface: 'essential',
            },
          },
        ],
      }),
    }),
  });

  const response = await handler({
    jsonrpc: '2.0',
    method: 'tools/list',
    params: {
      surface: 'essential',
    },
    id: 4,
  });

  const tool = response.result.tools.find((candidate) => candidate.name === 'content/read');
  assert.equal(tool.inputSchema.properties.site_id.type, 'string');
  assert.deepEqual(tool.inputSchema.properties.site_id.enum, ['joomla-demo']);
  assert.deepEqual(tool.inputSchema.required, ['id']);
  assert.deepEqual(tool.metadata.platforms, ['joomla']);
  assert.deepEqual(tool.metadata.site_ids, ['joomla-demo']);
});

test('router merges remote tools from multiple sites', async () => {
  const multiRegistry = {
    schema_version: 1,
    default_site_id: 'joomla-demo',
    sites: [
      registry.sites[0],
      {
        site_id: 'wp-demo',
        label: 'WP demo',
        platform: 'wordpress',
        url: 'https://wp.example.test/wp-json/mirasai/v1/mcp',
        token_env: 'WP_TOKEN',
        default: false,
      },
    ],
  };

  const handler = createRouterHandler(multiRegistry, {
    clientFactory: (site) => ({
      toolsList: async () => ({
        tools: [
          {
            name: 'content/read',
            description: `Read content from ${site.platform}`,
            inputSchema: {
              type: 'object',
              properties: {},
            },
            metadata: {
              risk_level: site.platform === 'wordpress' ? 'safe_write' : 'read',
              workflow_hint: 'direct',
              surface: 'essential',
            },
          },
        ],
      }),
    }),
  });

  const response = await handler({
    jsonrpc: '2.0',
    method: 'tools/list',
    id: 5,
  });

  const tool = response.result.tools.find((candidate) => candidate.name === 'content/read');
  assert.deepEqual(tool.inputSchema.properties.site_id.enum, ['joomla-demo', 'wp-demo']);
  assert.deepEqual(tool.metadata.platforms, ['joomla', 'wordpress']);
  assert.deepEqual(tool.metadata.site_ids, ['joomla-demo', 'wp-demo']);
  assert.equal(tool.metadata.source_count, 2);
  assert.equal(tool.metadata.risk_level, 'safe_write');
});

test('router proxies remote tool calls after checking host support', async () => {
  const calls = [];
  const handler = createRouterHandler(registry, {
    clientFactory: () => ({
      toolsList: async () => ({
        tools: [
          {
            name: 'content/read',
            inputSchema: { type: 'object', properties: {} },
          },
        ],
      }),
      callTool: async (name, args) => {
        calls.push({ name, args });
        return {
          content: [{ type: 'text', text: '{"title":"Hello"}' }],
          structuredContent: { title: 'Hello' },
        };
      },
    }),
  });

  const response = await handler({
    jsonrpc: '2.0',
    method: 'tools/call',
    params: {
      name: 'content/read',
      arguments: {
        site_id: 'joomla-demo',
        id: 42,
      },
    },
    id: 6,
  });

  assert.deepEqual(calls, [{ name: 'content/read', args: { id: 42 } }]);
  assert.equal(response.result.structuredContent.title, 'Hello');
});

test('router returns structured unsupported-platform errors for missing remote tools', async () => {
  const handler = createRouterHandler(registry, {
    clientFactory: () => ({
      toolsList: async () => ({ tools: [] }),
      callTool: async () => {
        throw new Error('should not be called');
      },
    }),
  });

  const response = await handler({
    jsonrpc: '2.0',
    method: 'tools/call',
    params: {
      name: 'template/read',
      arguments: {
        site_id: 'joomla-demo',
      },
    },
    id: 7,
  });

  assert.equal(response.result.isError, true);
  assert.equal(response.result.structuredContent.code, 'tool_not_supported_on_platform');
  assert.equal(response.result.structuredContent.site_id, 'joomla-demo');
});

test('router reuses one client per site across calls and evicts it on failure', async () => {
  let created = 0;
  let failNext = false;
  const handler = createRouterHandler(registry, {
    clientFactory: () => {
      created += 1;
      return {
        initialize: async () => ({ serverInfo: { host_contract_version: '1' } }),
        toolsList: async () => ({ tools: [{ name: 'demo/tool', inputSchema: { type: 'object' } }] }),
        callTool: async () => {
          if (failNext) {
            failNext = false;
            throw new Error('session expired');
          }
          return { content: [], structuredContent: { ok: true } };
        },
      };
    },
  });

  const call = () => handler({
    jsonrpc: '2.0',
    method: 'tools/call',
    params: { name: 'demo/tool', arguments: {} },
    id: 1,
  });

  await call();
  await call();
  assert.equal(created, 1);

  failNext = true;
  const failed = await call();
  assert.equal(failed.result.isError, true);

  await call();
  assert.equal(created, 2);
});
