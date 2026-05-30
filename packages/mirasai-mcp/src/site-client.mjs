import { resolveToken } from './secrets.mjs';

export class MirasaiHostClient {
  constructor(site, options = {}) {
    this.site = site;
    this.fetchImpl = options.fetchImpl ?? globalThis.fetch;
    this.tokenResolver = options.tokenResolver ?? resolveToken;
  }

  async call(method, params = {}, id = 1) {
    const auth = normalizeAuth(this.tokenResolver(this.site));
    const response = await this.fetchImpl(this.site.url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        ...authHeadersForSite(this.site, auth),
      },
      body: JSON.stringify({
        jsonrpc: '2.0',
        method,
        params,
        id,
      }),
    });

    const text = await response.text();
    let payload = null;

    try {
      payload = text === '' ? null : JSON.parse(text);
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

  initialize() {
    return this.call('initialize', {}, 1);
  }

  toolsList(params = {}) {
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
    const diagnose = await this.diagnose();
    const elapsedMs = Date.now() - startedAt;

    return {
      ok: true,
      site_id: this.site.site_id,
      label: this.site.label,
      platform: this.site.platform,
      url: this.site.url,
      elapsed_ms: elapsedMs,
      serverInfo: initialize?.serverInfo ?? null,
      essential_tool_count: Array.isArray(toolsList?.tools) ? toolsList.tools.length : 0,
      diagnose_is_error: diagnose?.isError === true,
      diagnose: diagnose?.structuredContent ?? null,
    };
  }
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
