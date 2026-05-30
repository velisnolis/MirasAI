import assert from 'node:assert/strict';
import http from 'node:http';
import test from 'node:test';
import { MirasaiHostClient, authHeadersForSite } from '../src/site-client.mjs';

test('joomla auth sends both MirasAI and Joomla token headers', () => {
  assert.deepEqual(authHeadersForSite({ platform: 'joomla' }, 'secret'), {
    'X-MirasAI-Token': 'secret',
    'X-Joomla-Token': 'secret',
  });
});

test('basic auth sends Authorization header for WordPress application passwords', () => {
  assert.deepEqual(authHeadersForSite({ platform: 'wordpress' }, { type: 'basic', value: 'user:pass' }), {
    Authorization: 'Basic dXNlcjpwYXNz',
  });
});

test('host client test calls initialize, tools/list, and system/diagnose', async () => {
  const calls = [];
  const server = http.createServer((request, response) => {
    let body = '';
    request.on('data', (chunk) => {
      body += chunk.toString('utf8');
    });
    request.on('end', () => {
      calls.push({
        token: request.headers['x-mirasai-token'],
        payload: JSON.parse(body),
      });

      const payload = calls.at(-1).payload;
      let result;

      if (payload.method === 'initialize') {
        result = {
          protocolVersion: '2024-11-05',
          capabilities: { tools: { listChanged: false } },
          serverInfo: { name: 'MirasAI', version: '0.5.0', host_platform: 'wordpress' },
        };
      } else if (payload.method === 'tools/list') {
        result = { tools: [{ name: 'system/diagnose' }] };
      } else {
        result = {
          content: [{ type: 'text', text: '{"ok":true}' }],
          structuredContent: { ok: true },
        };
      }

      response.writeHead(200, { 'Content-Type': 'application/json' });
      response.end(JSON.stringify({ jsonrpc: '2.0', result, id: payload.id }));
    });
  });

  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));

  try {
    const address = server.address();
    const client = new MirasaiHostClient(
      {
        site_id: 'wp-demo',
        label: 'WP demo',
        platform: 'wordpress',
        url: `http://127.0.0.1:${address.port}/wp-json/mirasai/v1/mcp`,
        token_env: 'WP_TOKEN',
      },
      {
        tokenResolver: () => 'secret',
      },
    );

    const result = await client.test();

    assert.equal(result.ok, true);
    assert.equal(result.platform, 'wordpress');
    assert.equal(result.essential_tool_count, 1);
    assert.deepEqual(calls.map((call) => call.payload.method), ['initialize', 'tools/list', 'tools/call']);
    assert.deepEqual(calls.map((call) => call.token), ['secret', 'secret', 'secret']);
    assert.equal(calls[1].payload.params.surface, 'essential');
    assert.equal(calls[2].payload.params.name, 'system/diagnose');
  } finally {
    await new Promise((resolve) => server.close(resolve));
  }
});
