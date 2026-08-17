import assert from 'node:assert/strict';
import http from 'node:http';
import test from 'node:test';
import { MirasaiHostClient, authHeadersForSite } from '../src/site-client.mjs';

const PREFLIGHT_STAGE_NAMES = ['dns_tls', 'http', 'auth', 'initialize', 'tools/list', 'diagnose'];

function preflightStage(result, name) {
  return result.stages.find((stage) => stage.name === name);
}

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
    assert.deepEqual(result.stages.map((stage) => stage.name), PREFLIGHT_STAGE_NAMES);
    assert.deepEqual(result.stages.map((stage) => stage.status), ['ok', 'ok', 'ok', 'ok', 'ok', 'ok']);
    for (const stage of result.stages) {
      assert.equal(Object.hasOwn(stage, 'http_status'), true);
      assert.equal(Object.hasOwn(stage, 'content_type'), true);
      assert.equal(Object.hasOwn(stage, 'final_url'), true);
      assert.equal(typeof stage.classification, 'string');
      assert.equal(Object.hasOwn(stage, 'next_action'), true);
    }
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
        protocolVersion: request.headers['mcp-protocol-version'],
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

      if (payload.method !== 'initialize' && request.headers['mcp-protocol-version'] !== '2025-06-18') {
        response.writeHead(400, { 'Content-Type': 'application/json' });
        response.end(JSON.stringify({
          jsonrpc: '2.0',
          error: { code: -32600, message: 'Missing MCP-Protocol-Version header' },
          id: payload.id,
        }));
        return;
      }

      if (payload.method === 'notifications/initialized') {
        assert.equal(Object.hasOwn(payload, 'id'), false);
        response.writeHead(202);
        response.end();
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
    assert.equal(preflightStage(result, 'diagnose').status, 'skipped');
    assert.equal(preflightStage(result, 'diagnose').classification, 'not_supported_by_protocol');
    assert.deepEqual(
      calls.map((call) => call.payload.method),
      ['initialize', 'notifications/initialized', 'tools/list'],
    );
    assert.equal(calls[0].payload.params.protocolVersion, '2025-06-18');
    assert.equal(typeof calls[0].payload.params.clientInfo?.name, 'string');
    assert.equal(calls[0].accept, 'application/json, text/event-stream');
    assert.equal(calls[0].session, undefined);
    assert.equal(calls[1].session, sessionId);
    assert.equal(calls[1].protocolVersion, '2025-06-18');
    assert.equal(calls[2].session, sessionId);
    assert.equal(calls[2].protocolVersion, '2025-06-18');
    assert.equal(calls[2].payload.params.surface, undefined);

    const followUp = await client.callTool('mcp-adapter-discover-abilities', {});
    assert.deepEqual(followUp.structuredContent, { ok: true });
    assert.equal(calls.at(-1).session, sessionId);
    assert.equal(calls.filter((call) => call.payload.method === 'initialize').length, 1);

    const secondPreflight = await client.test();
    assert.equal(secondPreflight.ok, true);
    const initializeCalls = calls.filter((call) => call.payload.method === 'initialize');
    assert.equal(initializeCalls.length, 2);
    assert.equal(initializeCalls[1].session, undefined);
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
      if (payload.method === 'notifications/initialized') {
        response.writeHead(202, { 'Mcp-Session-Id': 'sse-session' });
        response.end();
        return;
      }
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

test('site preflight returns a structured auth failure for a 401 JSON response', async () => {
  const server = http.createServer((request, response) => {
    response.writeHead(401, {
      'Content-Type': 'application/json; charset=utf-8',
      'Set-Cookie': 'session=must-not-leak',
    });
    response.end(JSON.stringify({ error: { message: 'Invalid application password' } }));
  });

  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));

  try {
    const address = server.address();
    const url = `http://127.0.0.1:${address.port}/wp-json/mirasai/v1/mcp`;
    const client = new MirasaiHostClient(
      {
        site_id: 'wp-auth-failure',
        label: 'WP auth failure',
        platform: 'wordpress',
        url,
        basic_env: 'WP_BASIC',
      },
      {
        tokenResolver: () => ({ type: 'basic', value: 'admin:super-secret' }),
      },
    );

    const result = await client.test();

    assert.equal(result.ok, false);
    assert.deepEqual(result.stages.map((stage) => stage.name), PREFLIGHT_STAGE_NAMES);
    assert.equal(preflightStage(result, 'dns_tls').status, 'ok');
    assert.equal(preflightStage(result, 'http').status, 'ok');
    assert.deepEqual(preflightStage(result, 'auth'), {
      name: 'auth',
      status: 'failed',
      http_status: 401,
      content_type: 'application/json; charset=utf-8',
      final_url: url,
      classification: 'authentication_failed',
      next_action: 'Check the configured credentials and required CMS capability, then retry.',
      body_snippet: '{"error":{"message":"Invalid application password"}}',
    });
    assert.equal(preflightStage(result, 'initialize').status, 'skipped');
    assert.equal(JSON.stringify(result).includes('super-secret'), false);
    assert.equal(JSON.stringify(result).includes('must-not-leak'), false);
  } finally {
    await new Promise((resolve) => server.close(resolve));
  }
});

test('site preflight classifies a 503 HTML response without leaking an unbounded body', async () => {
  const body = `<html><title>Maintenance</title><body>${'locked '.repeat(80)}</body></html>`;
  const server = http.createServer((request, response) => {
    response.writeHead(503, { 'Content-Type': 'text/html; charset=utf-8' });
    response.end(body);
  });

  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));

  try {
    const address = server.address();
    const url = `http://127.0.0.1:${address.port}/wp-json/mirasai/v1/mcp`;
    const client = new MirasaiHostClient(
      {
        site_id: 'wp-staging-lock',
        label: 'WP staging lock',
        platform: 'wordpress',
        url,
        token_env: 'WP_TOKEN',
      },
      { tokenResolver: () => 'secret' },
    );

    const result = await client.test();
    const httpStage = preflightStage(result, 'http');

    assert.equal(result.ok, false);
    assert.equal(httpStage.status, 'failed');
    assert.equal(httpStage.http_status, 503);
    assert.equal(httpStage.content_type, 'text/html; charset=utf-8');
    assert.equal(httpStage.final_url, url);
    assert.equal(httpStage.classification, 'service_unavailable');
    assert.equal(httpStage.next_action, 'Check staging locks, maintenance mode, WAF/CDN, and origin health.');
    assert.equal(httpStage.body_snippet.length, 300);
    assert.equal(preflightStage(result, 'auth').status, 'skipped');
  } finally {
    await new Promise((resolve) => server.close(resolve));
  }
});

test('site preflight identifies a Cloudflare-like HTML interstitial after redirect', async () => {
  const server = http.createServer((request, response) => {
    if (request.url === '/wp-json/mirasai/v1/mcp') {
      response.writeHead(302, { Location: '/cdn-cgi/access/login' });
      response.end();
      return;
    }

    response.writeHead(200, { 'Content-Type': 'text/html' });
    response.end('<html><title>Cloudflare Access</title><body>Authentication required</body></html>');
  });

  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));

  try {
    const address = server.address();
    const origin = `http://127.0.0.1:${address.port}`;
    const client = new MirasaiHostClient(
      {
        site_id: 'wp-cloudflare',
        label: 'WP Cloudflare',
        platform: 'wordpress',
        url: `${origin}/wp-json/mirasai/v1/mcp`,
        token_env: 'WP_TOKEN',
      },
      { tokenResolver: () => 'secret' },
    );

    const result = await client.test();
    const httpStage = preflightStage(result, 'http');

    assert.equal(result.ok, false);
    assert.equal(httpStage.status, 'failed');
    assert.equal(httpStage.http_status, 200);
    assert.equal(httpStage.content_type, 'text/html');
    assert.equal(httpStage.final_url, `${origin}/cdn-cgi/access/login`);
    assert.equal(httpStage.classification, 'cloudflare_interstitial');
    assert.equal(
      httpStage.next_action,
      'Allow the authenticated MCP endpoint through Cloudflare Access/WAF, then retry the router preflight.',
    );
    assert.match(httpStage.body_snippet, /Cloudflare Access/);
  } finally {
    await new Promise((resolve) => server.close(resolve));
  }
});

test('site preflight returns a structured dns_tls failure when fetch cannot connect', async () => {
  const url = 'https://missing.example.test/wp-json/mirasai/v1/mcp';
  const client = new MirasaiHostClient(
    {
      site_id: 'wp-dns-failure',
      label: 'WP DNS failure',
      platform: 'wordpress',
      url,
      token_env: 'WP_TOKEN',
    },
    {
      tokenResolver: () => 'secret',
      fetchImpl: async () => {
        const error = new TypeError('fetch failed');
        error.cause = { code: 'ENOTFOUND' };
        throw error;
      },
    },
  );

  const result = await client.test();

  assert.equal(result.ok, false);
  assert.deepEqual(preflightStage(result, 'dns_tls'), {
    name: 'dns_tls',
    status: 'failed',
    http_status: null,
    content_type: null,
    final_url: url,
    classification: 'dns_tls_failure',
    next_action: 'Check DNS resolution, TLS certificates, network reachability, and the configured endpoint URL.',
  });
  assert.equal(preflightStage(result, 'http').status, 'skipped');
  assert.equal(preflightStage(result, 'diagnose').status, 'skipped');
});

test('site preflight rejects an empty 2xx body as an invalid JSON-RPC response', async () => {
  const client = new MirasaiHostClient(
    {
      site_id: 'empty-response',
      label: 'Empty response',
      platform: 'wordpress',
      url: 'https://empty.example.test/mcp',
      token_env: 'WP_TOKEN',
    },
    {
      tokenResolver: () => 'secret',
      fetchImpl: async () => new Response(null, {
        status: 204,
        headers: { 'Content-Type': 'application/json' },
      }),
    },
  );

  const result = await client.test();

  assert.equal(result.ok, false);
  assert.equal(preflightStage(result, 'initialize').status, 'failed');
  assert.equal(preflightStage(result, 'initialize').classification, 'invalid_json_rpc_response');
});

test('site preflight classifies credential resolution failures as auth configuration errors', async () => {
  const client = new MirasaiHostClient(
    {
      site_id: 'missing-secret',
      label: 'Missing secret',
      platform: 'wordpress',
      url: 'https://secret.example.test/mcp',
      token_env: 'MISSING_TOKEN',
    },
    {
      tokenResolver: () => {
        throw new Error('Missing environment variable MISSING_TOKEN');
      },
      fetchImpl: async () => {
        throw new Error('fetch must not be called');
      },
    },
  );

  const result = await client.test();

  assert.equal(result.ok, false);
  assert.equal(preflightStage(result, 'auth').status, 'failed');
  assert.equal(preflightStage(result, 'auth').classification, 'credential_resolution_failed');
  assert.match(preflightStage(result, 'auth').next_action, /credential/i);
  assert.equal(preflightStage(result, 'dns_tls').status, 'skipped');
});

test('site preflight preserves completed stages when tools/list returns a JSON-RPC error', async () => {
  const server = http.createServer((request, response) => {
    let body = '';
    request.on('data', (chunk) => {
      body += chunk.toString('utf8');
    });
    request.on('end', () => {
      const payload = JSON.parse(body);
      response.writeHead(200, { 'Content-Type': 'application/json' });
      if (payload.method === 'initialize') {
        response.end(JSON.stringify({
          jsonrpc: '2.0',
          id: payload.id,
          result: { serverInfo: { name: 'MirasAI', version: '1' } },
        }));
        return;
      }
      response.end(JSON.stringify({
        jsonrpc: '2.0',
        id: payload.id,
        error: { code: -32603, message: 'Tool discovery failed' },
      }));
    });
  });

  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));

  try {
    const address = server.address();
    const client = new MirasaiHostClient(
      {
        site_id: 'tools-list-failure',
        label: 'Tools list failure',
        platform: 'wordpress',
        url: `http://127.0.0.1:${address.port}/mcp`,
        token_env: 'WP_TOKEN',
      },
      { tokenResolver: () => 'secret' },
    );

    const result = await client.test();

    assert.equal(result.ok, false);
    assert.deepEqual(
      result.stages.map((stage) => stage.status),
      ['ok', 'ok', 'ok', 'ok', 'failed', 'skipped'],
    );
    assert.equal(preflightStage(result, 'tools/list').classification, 'json_rpc_error');
  } finally {
    await new Promise((resolve) => server.close(resolve));
  }
});

test('site preflight classifies generic HTML as an unexpected non-JSON HTTP response', async () => {
  const client = new MirasaiHostClient(
    {
      site_id: 'generic-html',
      label: 'Generic HTML',
      platform: 'wordpress',
      url: 'https://html.example.test/mcp',
      token_env: 'WP_TOKEN',
    },
    {
      tokenResolver: () => 'secret',
      fetchImpl: async () => new Response('<html><title>Login</title></html>', {
        status: 200,
        headers: { 'Content-Type': 'text/html' },
      }),
    },
  );

  const result = await client.test();

  assert.equal(result.ok, false);
  assert.equal(preflightStage(result, 'http').status, 'failed');
  assert.equal(preflightStage(result, 'http').classification, 'unexpected_non_json');
});

test('site preflight classifies a non-auth JSON HTTP failure at the HTTP stage', async () => {
  const client = new MirasaiHostClient(
    {
      site_id: 'upstream-error',
      label: 'Upstream error',
      platform: 'wordpress',
      url: 'https://upstream.example.test/mcp',
      token_env: 'WP_TOKEN',
    },
    {
      tokenResolver: () => 'secret',
      fetchImpl: async () => new Response(JSON.stringify({ error: { message: 'Bad gateway' } }), {
        status: 502,
        headers: { 'Content-Type': 'application/json' },
      }),
    },
  );

  const result = await client.test();

  assert.equal(result.ok, false);
  assert.equal(preflightStage(result, 'dns_tls').status, 'ok');
  assert.equal(preflightStage(result, 'http').status, 'failed');
  assert.equal(preflightStage(result, 'http').classification, 'http_error');
});

test('site preflight classifies an initialize JSON-RPC error without advancing later stages', async () => {
  const client = new MirasaiHostClient(
    {
      site_id: 'initialize-error',
      label: 'Initialize error',
      platform: 'wordpress',
      url: 'https://rpc.example.test/mcp',
      token_env: 'WP_TOKEN',
    },
    {
      tokenResolver: () => 'secret',
      fetchImpl: async () => new Response(JSON.stringify({
        jsonrpc: '2.0',
        id: 1,
        error: { code: -32602, message: 'Bad initialize params' },
      }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    },
  );

  const result = await client.test();

  assert.equal(result.ok, false);
  assert.equal(preflightStage(result, 'auth').status, 'ok');
  assert.equal(preflightStage(result, 'initialize').status, 'failed');
  assert.equal(preflightStage(result, 'initialize').classification, 'json_rpc_error');
  assert.equal(preflightStage(result, 'tools/list').status, 'skipped');
});
