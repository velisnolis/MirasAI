import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { createRouterHandler } from '../src/mcp-stdio.mjs';
import { sha256 } from '../src/style-preview.mjs';

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
  assert.match(response.result.instructions, /mirasai\/style-preview/);
  assert.match(response.result.instructions, /playbook/);
  assert.match(response.result.instructions, /do not compile/i);
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

  // Assert the actual surface rather than a count: a bare number tells you
  // nothing about which tool went missing when it changes.
  assert.deepEqual(
    response.result.tools.map((tool) => tool.name).sort(),
    [
      'mirasai/host-diagnose',
      'mirasai/sites-list',
      'mirasai/sites-test',
      'mirasai/style-preview',
      'mirasai/style-update',
      'mirasai/style-verify',
    ]
  );

  // Style update is the only router-local write and must expose the guarded
  // workflow. Every other local tool stays read-only.
  for (const tool of response.result.tools) {
    if (tool.name === 'mirasai/style-update') {
      assert.equal(tool.annotations.readOnlyHint, false);
      assert.equal(tool.annotations.destructiveHint, true);
      assert.equal(tool.metadata.risk_level, 'guarded_write');
      assert.equal(tool.metadata.workflow_hint, 'dry_run_confirm_if_match');
    } else {
      assert.equal(tool.annotations.readOnlyHint, true, `${tool.name} must be read-only`);
      assert.equal(tool.metadata.risk_level, 'read', `${tool.name} must declare read risk`);
    }
  }
});

test('router surfaces the observed hash without executing an unpinned style worker', async () => {
  const worker = `self.addEventListener('message', function () {});`;
  const handler = createRouterHandler(registry, {
    clientFactory: () => ({
      callTool: async (name) => {
        if (name === 'template/style-sources') {
          return {
            structuredContent: {
              filename: 'entry.less',
              filepath: '/less/',
              desturl: '/css',
              imports: { 'entry.less': '' },
              vars: {},
              overrides: {},
              compile_contract: {
                platform: 'joomla',
                worker: 'templates/yootheme/assets/admin/js/worker.js',
                base_url: 'https://example.test/administrator/index.php',
              },
            },
          };
        }

        if (name === 'file/read') {
          return { structuredContent: { content: worker } };
        }

        throw new Error(`Unexpected tool: ${name}`);
      },
    }),
  });

  const response = await handler({
    jsonrpc: '2.0',
    method: 'tools/call',
    params: {
      name: 'mirasai/style-preview',
      arguments: { site_id: 'joomla-demo' },
    },
    id: 20,
  });

  assert.equal(response.result.isError, true);
  assert.equal(response.result.structuredContent.code, 'style_worker_hash_required');
  assert.equal(response.result.structuredContent.observed_sha256, sha256(worker));
  assert.equal(response.result.structuredContent.site_id, 'joomla-demo');
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

test('router contains an unexpected sites-test exception as a structured tool failure', async () => {
  const handler = createRouterHandler(registry, {
    clientFactory: () => ({
      test: async () => {
        throw new Error('unexpected client failure');
      },
    }),
  });

  const response = await handler({
    jsonrpc: '2.0',
    method: 'tools/call',
    params: {
      name: 'mirasai/sites-test',
      arguments: {},
    },
    id: 31,
  });

  assert.equal(response.error, undefined);
  assert.equal(response.result.isError, true);
  assert.equal(response.result.structuredContent.ok, false);
  assert.equal(response.result.structuredContent.error.classification, 'unexpected_preflight_failure');
  assert.deepEqual(
    response.result.structuredContent.stages.map((stage) => stage.name),
    ['dns_tls', 'http', 'auth', 'initialize', 'tools/list', 'diagnose'],
  );
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

test('serveStdio speaks newline-delimited JSON when the client does', async () => {
  const { serveStdio } = await import('../src/mcp-stdio.mjs');
  const handler = createRouterHandler(registry);

  async function* clientInput() {
    yield Buffer.from('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}\n');
    yield Buffer.from('{"jsonrpc":"2.0","id":2,"method":"ping","params":{}}\n');
  }

  const written = [];
  await serveStdio(handler, clientInput(), { write: (text) => written.push(text) });

  assert.equal(written.length, 2);
  for (const message of written) {
    assert.ok(message.endsWith('\n'));
    assert.ok(!message.includes('Content-Length'));
  }
  assert.equal(JSON.parse(written[0]).result.serverInfo.name, '@miras/mirasai-mcp');
  assert.equal(JSON.parse(written[1]).result.status, 'ok');
});

test('serveStdio still speaks Content-Length framing when the client does', async () => {
  const { serveStdio } = await import('../src/mcp-stdio.mjs');
  const handler = createRouterHandler(registry);

  const body = '{"jsonrpc":"2.0","id":1,"method":"ping","params":{}}';
  async function* clientInput() {
    yield Buffer.from(`Content-Length: ${Buffer.byteLength(body)}\r\n\r\n${body}`);
  }

  const written = [];
  await serveStdio(handler, clientInput(), { write: (text) => written.push(text) });

  assert.equal(written.length, 1);
  assert.match(written[0], /^Content-Length: \d+\r\n\r\n/);
});

test('router rejects an argument its own tool does not declare', async () => {
  const handler = createRouterHandler(registry);
  const response = await handler({
    jsonrpc: '2.0',
    method: 'tools/call',
    id: 90,
    params: {
      name: 'mirasai/style-preview',
      arguments: { site_id: 'joomla-demo', style_variation: 'white-blue' },
    },
  });

  const payload = response.result.structuredContent;

  assert.equal(response.result.isError, true);
  assert.equal(payload.code, 'unknown_argument');
  assert.equal(payload.issues[0].argument, 'style_variation');
  assert.equal(payload.issues[0].did_you_mean, 'style_id');
  assert.ok(payload.accepted_arguments.includes('variation'));
});

test('router rejects a bad argument before it reaches a host', async () => {
  const handler = createRouterHandler(registry, {
    clientFactory: () => {
      throw new Error('the router must not reach a host for an invalid argument');
    },
  });
  const response = await handler({
    jsonrpc: '2.0',
    method: 'tools/call',
    id: 91,
    params: {
      name: 'mirasai/host-diagnose',
      arguments: { site_id: 'joomla-demo', include: 'everything' },
    },
  });

  assert.equal(response.result.isError, true);
  assert.equal(response.result.structuredContent.code, 'unknown_argument');
});
