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

test('mcp protocol establishes a session, strips surface, and skips system/diagnose', async () => {
  const calls = [];
  const sessionId = 'test-session-1234';
  const server = http.createServer((request, response) => {
    let body = '';
    request.on('data', (chunk) => {
      body += chunk.toString('utf8');
    });
    request.on('end', () => {
      const payload = JSON.parse(body);
      calls.push({
        session: request.headers['mcp-session-id'],
        accept: request.headers.accept,
        payload,
      });

      if (payload.method !== 'initialize' && request.headers['mcp-session-id'] !== sessionId) {
        response.writeHead(200, { 'Content-Type': 'application/json' });
        response.end(JSON.stringify({
          jsonrpc: '2.0',
          error: { code: -32600, message: 'Invalid Request: Missing Mcp-Session-Id header' },
          id: payload.id,
        }));
        return;
      }

      let result;
      if (payload.method === 'initialize') {
        result = {
          protocolVersion: '2025-06-18',
          capabilities: { tools: {} },
          serverInfo: { name: 'MCP Adapter Default Server', version: 'v1.0.0' },
        };
      } else if (payload.method === 'tools/list') {
        result = { tools: [{ name: 'mcp-adapter-discover-abilities' }] };
      } else {
        result = { content: [], structuredContent: { ok: true } };
      }

      response.writeHead(200, {
        'Content-Type': 'application/json',
        ...(payload.method === 'initialize' ? { 'Mcp-Session-Id': sessionId } : {}),
      });
      response.end(JSON.stringify({ jsonrpc: '2.0', result, id: payload.id }));
    });
  });

  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));

  try {
    const address = server.address();
    const client = new MirasaiHostClient(
      {
        site_id: 'wp-adapter',
        label: 'WP adapter',
        platform: 'wordpress',
        protocol: 'mcp',
        url: `http://127.0.0.1:${address.port}/wp-json/mcp/mcp-adapter-default-server`,
        basic_env: 'WP_BASIC',
      },
      {
        tokenResolver: () => ({ type: 'basic', value: 'user:pass' }),
      },
    );

    const result = await client.test();

    assert.equal(result.ok, true);
    assert.equal(result.protocol, 'mcp');
    assert.equal(result.essential_tool_count, 1);
    assert.equal(result.diagnose, null);
    assert.deepEqual(calls.map((call) => call.payload.method), ['initialize', 'tools/list']);
    assert.equal(calls[0].payload.params.protocolVersion, '2025-06-18');
    assert.equal(typeof calls[0].payload.params.clientInfo?.name, 'string');
    assert.equal(calls[0].accept, 'application/json, text/event-stream');
    assert.equal(calls[0].session, undefined);
    assert.equal(calls[1].session, sessionId);
    assert.equal(calls[1].payload.params.surface, undefined);

    const followUp = await client.callTool('mcp-adapter-discover-abilities', {});
    assert.deepEqual(followUp.structuredContent, { ok: true });
    assert.equal(calls.at(-1).session, sessionId);
    assert.equal(calls.filter((call) => call.payload.method === 'initialize').length, 1);
  } finally {
    await new Promise((resolve) => server.close(resolve));
  }
});

test('mcp protocol parses SSE responses from streamable HTTP servers', async () => {
  const server = http.createServer((request, response) => {
    let body = '';
    request.on('data', (chunk) => {
      body += chunk.toString('utf8');
    });
    request.on('end', () => {
      const payload = JSON.parse(body);
      const result = payload.method === 'initialize'
        ? { protocolVersion: '2025-06-18', serverInfo: { name: 'SSE server', version: '1' } }
        : { tools: [] };

      response.writeHead(200, {
        'Content-Type': 'text/event-stream',
        'Mcp-Session-Id': 'sse-session',
      });
      response.end(`event: message\ndata: ${JSON.stringify({ jsonrpc: '2.0', result, id: payload.id })}\n\n`);
    });
  });

  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));

  try {
    const address = server.address();
    const client = new MirasaiHostClient(
      {
        site_id: 'wp-sse',
        label: 'WP SSE',
        platform: 'wordpress',
        protocol: 'mcp',
        url: `http://127.0.0.1:${address.port}/wp-json/mcp/mcp-adapter-default-server`,
        basic_env: 'WP_BASIC',
      },
      {
        tokenResolver: () => ({ type: 'basic', value: 'user:pass' }),
      },
    );

    const toolsList = await client.toolsList();
    assert.deepEqual(toolsList.tools, []);
    assert.equal(client.sessionId, 'sse-session');
  } finally {
    await new Promise((resolve) => server.close(resolve));
  }
});
