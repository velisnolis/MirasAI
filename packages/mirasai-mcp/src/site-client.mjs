import { resolveToken } from './secrets.mjs';

const MCP_PROTOCOL_VERSION = '2025-06-18';

export class MirasaiHostClient {
  constructor(site, options = {}) {
    this.site = site;
    this.protocol = site.protocol ?? 'mirasai';
    this.fetchImpl = options.fetchImpl ?? globalThis.fetch;
    this.tokenResolver = options.tokenResolver ?? resolveToken;
    this.sessionId = null;
    this.initialized = null;
  }

  async call(method, params = {}, id = 1) {
    if (this.protocol === 'mcp' && method !== 'initialize') {
      await this.ensureInitialized();
    }

    return this.rawCall(method, params, id);
  }

  async rawCall(method, params = {}, id = 1) {
    const auth = normalizeAuth(this.tokenResolver(this.site));
    const response = await this.fetchImpl(this.site.url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        ...(this.protocol === 'mcp' ? { Accept: 'application/json, text/event-stream' } : {}),
        ...(this.sessionId !== null ? { 'Mcp-Session-Id': this.sessionId } : {}),
        ...authHeadersForSite(this.site, auth),
      },
      body: JSON.stringify({
        jsonrpc: '2.0',
        method,
        params,
        id,
      }),
    });

    const sessionId = readSessionId(response);
    if (sessionId !== null) {
      this.sessionId = sessionId;
    }

    const text = await response.text();
    const contentType = readContentType(response);
    let payload = null;

    try {
      const body = contentType.includes('text/event-stream') ? lastSseData(text) : text;
      payload = body === '' ? null : JSON.parse(body);
    } catch {
      throw new Error(`Host ${this.site.site_id} returned non-JSON HTTP ${response.status}: ${text.slice(0, 300)}`);
    }

    if (!response.ok) {
      const message = payload?.error?.message || `HTTP ${response.status}`;
      throw new Error(`Host ${this.site.site_id} request failed: ${message}`);
    }

    if (payload?.error !== undefined) {
      const message = payload.error.message || JSON.stringify(payload.error);
      throw new Error(`Host ${this.site.site_id} JSON-RPC error: ${message}`);
    }

    return payload?.result ?? null;
  }

  async ensureInitialized() {
    if (this.initialized === null) {
      this.initialized = this.rawCall('initialize', initializeParamsForProtocol(this.protocol), 1);
    }

    try {
      return await this.initialized;
    } catch (caught) {
      this.initialized = null;
      throw caught;
    }
  }

  async initialize() {
    if (this.protocol === 'mcp') {
      return this.ensureInitialized();
    }

    return this.rawCall('initialize', {}, 1);
  }

  toolsList(params = {}) {
    if (this.protocol === 'mcp') {
      const { surface, ...rest } = params;
      return this.call('tools/list', rest, 2);
    }

    return this.call('tools/list', params, 2);
  }

  diagnose() {
    return this.call('tools/call', {
      name: 'system/diagnose',
      arguments: {},
    }, 3);
  }

  callTool(name, argumentsObject = {}) {
    return this.call('tools/call', {
      name,
      arguments: argumentsObject,
    }, 4);
  }

  async test() {
    const startedAt = Date.now();
    const initialize = await this.initialize();
    const toolsList = await this.toolsList({ surface: 'essential' });
    const diagnose = this.protocol === 'mcp' ? null : await this.diagnose();
    const elapsedMs = Date.now() - startedAt;

    return {
      ok: true,
      site_id: this.site.site_id,
      label: this.site.label,
      platform: this.site.platform,
      protocol: this.protocol,
      url: this.site.url,
      elapsed_ms: elapsedMs,
      serverInfo: initialize?.serverInfo ?? null,
      essential_tool_count: Array.isArray(toolsList?.tools) ? toolsList.tools.length : 0,
      diagnose_is_error: diagnose?.isError === true,
      diagnose: diagnose?.structuredContent ?? null,
    };
  }
}

function initializeParamsForProtocol(protocol) {
  if (protocol !== 'mcp') {
    return {};
  }

  return {
    protocolVersion: MCP_PROTOCOL_VERSION,
    capabilities: {},
    clientInfo: {
      name: '@miras/mirasai-mcp',
      version: '0',
    },
  };
}

function readSessionId(response) {
  if (typeof response?.headers?.get !== 'function') {
    return null;
  }

  return response.headers.get('mcp-session-id');
}

function readContentType(response) {
  if (typeof response?.headers?.get !== 'function') {
    return '';
  }

  return response.headers.get('content-type') ?? '';
}

function lastSseData(text) {
  const dataLines = text
    .split(/\r?\n/)
    .filter((line) => line.startsWith('data:'))
    .map((line) => line.slice(5).trim());

  return dataLines.at(-1) ?? '';
}

export function authHeadersForSite(site, token) {
  const auth = normalizeAuth(token);

  if (auth.type === 'basic') {
    return {
      Authorization: `Basic ${Buffer.from(auth.value, 'utf8').toString('base64')}`,
    };
  }

  if (site.platform === 'joomla') {
    return {
      'X-MirasAI-Token': auth.value,
      'X-Joomla-Token': auth.value,
    };
  }

  return {
    'X-MirasAI-Token': auth.value,
  };
}

function normalizeAuth(value) {
  if (value !== null && typeof value === 'object' && !Array.isArray(value) && value.type !== undefined) {
    return value;
  }

  return {
    type: 'header',
    value,
  };
}
