import { resolveToken } from './secrets.mjs';

const MCP_PROTOCOL_VERSION = '2025-06-18';
const PREFLIGHT_STAGE_NAMES = ['dns_tls', 'http', 'auth', 'initialize', 'tools/list', 'diagnose'];
const BODY_SNIPPET_LIMIT = 300;

class HostRequestError extends Error {
  constructor(message, preflight) {
    super(message);
    this.name = 'HostRequestError';
    this.preflight = preflight;
  }
}

export class MirasaiHostClient {
  constructor(site, options = {}) {
    this.site = site;
    this.protocol = site.protocol ?? 'mirasai';
    this.fetchImpl = options.fetchImpl ?? globalThis.fetch;
    this.tokenResolver = options.tokenResolver ?? resolveToken;
    this.sessionId = null;
    this.protocolVersion = null;
    this.initialized = null;
  }

  async call(method, params = {}, id = 1) {
    if (this.protocol === 'mcp' && method !== 'initialize') {
      await this.ensureInitialized();
    }

    return this.rawCall(method, params, id);
  }

  async rawCall(method, params = {}, id = 1, options = {}) {
    let auth;
    try {
      auth = normalizeAuth(this.tokenResolver(this.site));
    } catch (caught) {
      throw new HostRequestError(
        `Host ${this.site.site_id} credentials could not be resolved: ${caught instanceof Error ? caught.message : String(caught)}`,
        {
          stage: 'auth',
          ...emptyResponseDetails(),
          final_url: this.site.url,
          classification: 'credential_resolution_failed',
          next_action: 'Check the configured credential reference or environment variable, unlock the secret provider, then retry.',
          completed_stages: [],
        },
      );
    }
    let response;

    try {
      response = await this.fetchImpl(this.site.url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          ...(this.protocol === 'mcp' ? { Accept: 'application/json, text/event-stream' } : {}),
          ...(this.sessionId !== null ? { 'Mcp-Session-Id': this.sessionId } : {}),
          ...(this.protocolVersion !== null ? { 'MCP-Protocol-Version': this.protocolVersion } : {}),
          ...authHeadersForSite(this.site, auth),
        },
        body: JSON.stringify({
          jsonrpc: '2.0',
          method,
          params,
          ...(options.notification === true ? {} : { id }),
        }),
      });
    } catch (caught) {
      throw new HostRequestError(
        `Host ${this.site.site_id} could not be reached: ${caught instanceof Error ? caught.message : String(caught)}`,
        {
          stage: 'dns_tls',
          http_status: null,
          content_type: null,
          final_url: this.site.url,
          classification: 'dns_tls_failure',
          next_action: 'Check DNS resolution, TLS certificates, network reachability, and the configured endpoint URL.',
        },
      );
    }

    const sessionId = readSessionId(response);
    if (sessionId !== null) {
      this.sessionId = sessionId;
    }

    const text = await response.text();
    const contentType = readContentType(response);
    const responseDetails = {
      http_status: Number.isInteger(response.status) ? response.status : null,
      content_type: contentType || null,
      final_url: typeof response.url === 'string' && response.url !== '' ? response.url : this.site.url,
    };
    let payload = null;

    try {
      const body = contentType.includes('text/event-stream') ? lastSseData(text) : text;
      payload = body === '' ? null : JSON.parse(body);
    } catch {
      const serviceUnavailable = response.status === 503;
      const cloudflareInterstitial = /cloudflare|\/cdn-cgi\//i.test(`${responseDetails.final_url}\n${text}`);
      throw new HostRequestError(
        `Host ${this.site.site_id} returned non-JSON HTTP ${response.status}.`,
        {
          stage: 'http',
          ...responseDetails,
          classification: serviceUnavailable
            ? 'service_unavailable'
            : (cloudflareInterstitial ? 'cloudflare_interstitial' : 'unexpected_non_json'),
          next_action: serviceUnavailable
            ? 'Check staging locks, maintenance mode, WAF/CDN, and origin health.'
            : (cloudflareInterstitial
                ? 'Allow the authenticated MCP endpoint through Cloudflare Access/WAF, then retry the router preflight.'
                : 'Check the final URL and remove any proxy, WAF, login, or HTML challenge in front of the MCP endpoint.'),
          body_snippet: bodySnippet(text),
        },
      );
    }

    if (!response.ok) {
      const message = payload?.error?.message || `HTTP ${response.status}`;
      const authenticationFailure = response.status === 401 || response.status === 403;
      throw new HostRequestError(
        `Host ${this.site.site_id} request failed: ${message}`,
        {
          stage: authenticationFailure ? 'auth' : 'http',
          ...responseDetails,
          classification: authenticationFailure ? 'authentication_failed' : 'http_error',
          next_action: authenticationFailure
            ? 'Check the configured credentials and required CMS capability, then retry.'
            : 'Check the endpoint, staging locks, maintenance mode, WAF/CDN, and origin health.',
          body_snippet: bodySnippet(text),
        },
      );
    }

    if (options.notification === true && payload === null && options.allowEmptyResponse === true) {
      return options.includeDiagnostics === true
        ? { result: null, diagnostics: responseDetails }
        : null;
    }

    if (!isValidJsonRpcResponse(payload, id)) {
      const stage = stageForCall(method, params);
      throw new HostRequestError(
        `Host ${this.site.site_id} returned an invalid JSON-RPC response for ${method}.`,
        {
          stage,
          ...responseDetails,
          classification: 'invalid_json_rpc_response',
          next_action: `Check that the final endpoint speaks JSON-RPC 2.0 and returns a matching id plus exactly one of result or error for ${stage}.`,
          body_snippet: bodySnippet(text),
        },
      );
    }

    if (Object.hasOwn(payload, 'error')) {
      const message = payload.error.message || JSON.stringify(payload.error);
      throw new HostRequestError(
        `Host ${this.site.site_id} JSON-RPC error: ${message}`,
        {
          stage: stageForCall(method, params),
          ...responseDetails,
          classification: 'json_rpc_error',
          next_action: `Inspect the ${stageForCall(method, params)} JSON-RPC error and retry only after correcting the request or host state.`,
          body_snippet: bodySnippet(text),
        },
      );
    }

    const result = payload?.result ?? null;

    if (options.includeDiagnostics === true) {
      return {
        result,
        diagnostics: responseDetails,
      };
    }

    return result;
  }

  async ensureInitialized() {
    if (this.initialized === null) {
      this.initialized = this.startMcpSession();
    }

    try {
      return await this.initialized;
    } catch (caught) {
      this.initialized = null;
      this.sessionId = null;
      this.protocolVersion = null;
      throw caught;
    }
  }

  async startMcpSession(options = {}) {
    if (options.freshSession === true) {
      this.sessionId = null;
      this.protocolVersion = null;
      this.initialized = null;
    }

    try {
      const initializeCall = await this.rawCall(
        'initialize',
        initializeParamsForProtocol('mcp'),
        1,
        { includeDiagnostics: options.includeDiagnostics === true },
      );
      const initializeResult = options.includeDiagnostics === true
        ? initializeCall.result
        : initializeCall;
      validatePreflightResult('initialize', initializeResult, options.includeDiagnostics === true
        ? initializeCall.diagnostics
        : emptyResponseDetails());
      this.protocolVersion = typeof initializeResult?.protocolVersion === 'string'
        ? initializeResult.protocolVersion
        : MCP_PROTOCOL_VERSION;
      await this.rawCall('notifications/initialized', {}, undefined, {
        notification: true,
        allowEmptyResponse: true,
      });
      return initializeCall;
    } catch (caught) {
      this.sessionId = null;
      this.protocolVersion = null;
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
    const stages = createPreflightStages();

    try {
      const initializeCall = this.protocol === 'mcp'
        ? await this.startMcpSession({ includeDiagnostics: true, freshSession: true })
        : await this.rawCall(
            'initialize',
            initializeParamsForProtocol(this.protocol),
            1,
            { includeDiagnostics: true },
          );
      validatePreflightResult('initialize', initializeCall.result, initializeCall.diagnostics);
      if (this.protocol === 'mcp') {
        this.initialized = Promise.resolve(initializeCall.result);
      }
      markConnectionStagesOk(stages, initializeCall.diagnostics);
      markStage(stages, 'initialize', 'ok', initializeCall.diagnostics, 'initialized', null);

      const toolsListCall = await this.rawCall(
        'tools/list',
        this.protocol === 'mcp' ? {} : { surface: 'essential' },
        2,
        { includeDiagnostics: true },
      );
      validatePreflightResult('tools/list', toolsListCall.result, toolsListCall.diagnostics);
      markStage(stages, 'tools/list', 'ok', toolsListCall.diagnostics, 'tools_listed', null);

      let diagnose = null;
      if (this.protocol === 'mcp') {
        markStage(
          stages,
          'diagnose',
          'skipped',
          emptyResponseDetails(),
          'not_supported_by_protocol',
          'Use the standard MCP host tools; system/diagnose is specific to MirasAI hosts.',
        );
      } else {
        const diagnoseCall = await this.rawCall('tools/call', {
          name: 'system/diagnose',
          arguments: {},
        }, 3, { includeDiagnostics: true });
        validatePreflightResult('diagnose', diagnoseCall.result, diagnoseCall.diagnostics);
        diagnose = diagnoseCall.result;
        markStage(stages, 'diagnose', 'ok', diagnoseCall.diagnostics, 'diagnosed', null);
      }

      return {
        ...this.testIdentity(startedAt),
        ok: true,
        stages,
        serverInfo: initializeCall.result?.serverInfo ?? null,
        essential_tool_count: Array.isArray(toolsListCall.result?.tools) ? toolsListCall.result.tools.length : 0,
        diagnose_is_error: diagnose?.isError === true,
        diagnose: diagnose?.structuredContent ?? null,
      };
    } catch (caught) {
      const failure = caught instanceof HostRequestError
        ? caught.preflight
        : {
            stage: 'dns_tls',
            ...emptyResponseDetails(),
            final_url: this.site.url,
            classification: 'unexpected_preflight_failure',
            next_action: 'Inspect the local router logs and retry after correcting the unexpected client failure.',
          };
      applyPreflightFailure(stages, failure);

      return {
        ...this.testIdentity(startedAt),
        ok: false,
        stages,
        error: {
          stage: failure.stage,
          classification: failure.classification,
        },
      };
    }
  }

  testIdentity(startedAt) {
    return {
      site_id: this.site.site_id,
      label: this.site.label,
      platform: this.site.platform,
      protocol: this.protocol,
      url: this.site.url,
      elapsed_ms: Date.now() - startedAt,
    };
  }
}

export function unexpectedPreflightResult(site, startedAt = Date.now()) {
  const stages = createPreflightStages();
  const failure = {
    stage: 'dns_tls',
    ...emptyResponseDetails(),
    final_url: typeof site?.url === 'string' ? site.url : null,
    classification: 'unexpected_preflight_failure',
    next_action: 'Inspect the local router logs and retry after correcting the unexpected client failure.',
  };
  applyPreflightFailure(stages, failure);

  return {
    site_id: site?.site_id ?? null,
    label: site?.label ?? null,
    platform: site?.platform ?? null,
    protocol: site?.protocol ?? 'mirasai',
    url: site?.url ?? null,
    elapsed_ms: Date.now() - startedAt,
    ok: false,
    stages,
    error: {
      stage: failure.stage,
      classification: failure.classification,
    },
  };
}

function createPreflightStages() {
  return PREFLIGHT_STAGE_NAMES.map((name) => ({
    name,
    status: 'skipped',
    ...emptyResponseDetails(),
    classification: 'not_attempted',
    next_action: 'Resolve the earlier failed stage before continuing.',
  }));
}

function emptyResponseDetails() {
  return {
    http_status: null,
    content_type: null,
    final_url: null,
  };
}

function markConnectionStagesOk(stages, diagnostics) {
  markStage(stages, 'dns_tls', 'ok', diagnostics, 'connected', null);
  markStage(stages, 'http', 'ok', diagnostics, 'json_response', null);
  markStage(stages, 'auth', 'ok', diagnostics, 'authenticated', null);
}

function markStage(stages, name, status, diagnostics, classification, nextAction, body = undefined) {
  const index = stages.findIndex((stage) => stage.name === name);
  if (index === -1) {
    return;
  }

  stages[index] = {
    name,
    status,
    http_status: diagnostics.http_status ?? null,
    content_type: diagnostics.content_type ?? null,
    final_url: diagnostics.final_url ?? null,
    classification,
    next_action: nextAction,
    ...(typeof body === 'string' && body !== '' ? { body_snippet: body } : {}),
  };
}

function applyPreflightFailure(stages, failure) {
  const responseDetails = {
    http_status: failure.http_status ?? null,
    content_type: failure.content_type ?? null,
    final_url: failure.final_url ?? null,
  };

  const completedStages = Array.isArray(failure.completed_stages)
    ? failure.completed_stages
    : stagesBefore(failure.stage);
  for (const completedStage of completedStages) {
    const existing = stages.find((stage) => stage.name === completedStage);
    if (existing?.status !== 'skipped') {
      continue;
    }
    markStage(
      stages,
      completedStage,
      'ok',
      responseDetails,
      classificationForCompletedStage(completedStage),
      null,
    );
  }

  markStage(
    stages,
    failure.stage,
    'failed',
    responseDetails,
    failure.classification,
    failure.next_action,
    failure.body_snippet,
  );
}

function stagesBefore(stage) {
  const index = PREFLIGHT_STAGE_NAMES.indexOf(stage);
  return index > 0 ? PREFLIGHT_STAGE_NAMES.slice(0, index) : [];
}

function classificationForCompletedStage(stage) {
  return {
    dns_tls: 'connected',
    http: 'json_response',
    auth: 'authenticated',
    initialize: 'initialized',
    'tools/list': 'tools_listed',
  }[stage] ?? 'completed';
}

function validatePreflightResult(stage, result, diagnostics) {
  const objectResult = result !== null && typeof result === 'object' && !Array.isArray(result);
  const valid = stage === 'initialize'
    ? objectResult && result.serverInfo !== null && typeof result.serverInfo === 'object'
    : stage === 'tools/list'
      ? objectResult && Array.isArray(result.tools)
      : objectResult && result.isError !== true;

  if (valid) {
    return;
  }

  throw new HostRequestError(
    `Host ${stage} returned an invalid preflight result.`,
    {
      stage,
      ...diagnostics,
      classification: stage === 'diagnose' && result?.isError === true
        ? 'diagnose_failed'
        : 'invalid_stage_result',
      next_action: `Inspect the ${stage} result shape and host contract before retrying the preflight.`,
    },
  );
}

function isValidJsonRpcResponse(payload, id) {
  if (payload === null || typeof payload !== 'object' || Array.isArray(payload)) {
    return false;
  }

  const hasResult = Object.hasOwn(payload, 'result');
  const hasError = Object.hasOwn(payload, 'error');
  return payload.jsonrpc === '2.0'
    && Object.hasOwn(payload, 'id')
    && payload.id === id
    && hasResult !== hasError;
}

function stageForCall(method, params) {
  if (method === 'tools/list') {
    return 'tools/list';
  }
  if (method === 'tools/call' && params?.name === 'system/diagnose') {
    return 'diagnose';
  }

  return 'initialize';
}

function bodySnippet(text) {
  return typeof text === 'string' ? text.slice(0, BODY_SNIPPET_LIMIT) : '';
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
