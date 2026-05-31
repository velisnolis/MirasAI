import { readFileSync } from 'node:fs';
import { findSite } from './config.mjs';
import { MirasaiHostClient } from './site-client.mjs';

const ROUTER_VERSION = JSON.parse(readFileSync(new URL('../package.json', import.meta.url), 'utf8')).version;

export function createRouterHandler(registry, options = {}) {
  const clientFactory = options.clientFactory ?? ((site) => new MirasaiHostClient(site));

  return async function handleJsonRpc(request) {
    const id = request?.id ?? null;
    const method = request?.method ?? '';
    const params = request?.params ?? {};

    try {
      if (method === 'initialize') {
        return response(id, {
          protocolVersion: '2024-11-05',
          capabilities: {
            tools: {
              listChanged: false,
            },
          },
          serverInfo: {
            name: '@miras/mirasai-mcp',
            version: ROUTER_VERSION,
          },
          instructions:
            'MirasAI MCP router. Use mirasai/sites-list to inspect configured sites, mirasai/sites-test to validate one host, and mirasai/host-diagnose to run system/diagnose on a host.',
        });
      }

      if (method === 'notifications/initialized') {
        return null;
      }

      if (method === 'ping') {
        return response(id, { status: 'ok' });
      }

      if (method === 'tools/list') {
        return response(id, await listAllTools(registry, clientFactory, params));
      }

      if (method === 'tools/call') {
        return response(id, await callRouterTool(registry, clientFactory, params));
      }

      return error(id, -32601, `Method not found: ${method}`);
    } catch (caught) {
      return error(id, -32603, caught instanceof Error ? caught.message : String(caught));
    }
  };
}

export function routerTools() {
  return [
    {
      name: 'mirasai/sites-list',
      description: 'List sites configured in the local MirasAI MCP router registry.',
      inputSchema: {
        type: 'object',
        properties: {},
      },
      annotations: readOnlyAnnotations(),
      metadata: {
        risk_level: 'read',
        workflow_hint: 'direct',
        surface: 'essential',
        platforms: ['joomla', 'wordpress'],
      },
    },
    {
      name: 'mirasai/sites-test',
      description: 'Validate one configured MirasAI host by calling initialize, tools/list, and system/diagnose.',
      inputSchema: {
        type: 'object',
        properties: {
          site_id: {
            type: 'string',
            description: 'Configured site id. Defaults to the router default site.',
          },
        },
      },
      annotations: readOnlyAnnotations(),
      metadata: {
        risk_level: 'read',
        workflow_hint: 'direct',
        surface: 'essential',
        platforms: ['joomla', 'wordpress'],
      },
    },
    {
      name: 'mirasai/host-diagnose',
      description: 'Run system/diagnose on one configured MirasAI host.',
      inputSchema: {
        type: 'object',
        properties: {
          site_id: {
            type: 'string',
            description: 'Configured site id. Defaults to the router default site.',
          },
        },
      },
      annotations: readOnlyAnnotations(),
      metadata: {
        risk_level: 'read',
        workflow_hint: 'direct',
        surface: 'essential',
        platforms: ['joomla', 'wordpress'],
      },
    },
  ];
}

async function callRouterTool(registry, clientFactory, params) {
  const name = params?.name ?? '';
  const args = params?.arguments ?? {};

  if (args === null || typeof args !== 'object' || Array.isArray(args)) {
    return callToolResult({ error: 'Tool arguments must be an object.', code: 'invalid_arguments' }, true);
  }

  if (name === 'mirasai/sites-list') {
    return callToolResult({
      default_site_id: registry.default_site_id,
      warnings: registryWarnings(registry),
      sites: registry.sites.map((site) => ({
        site_id: site.site_id,
        label: site.label,
        platform: site.platform,
        url: site.url,
        default: site.default === true,
        token_source: tokenSourceForSite(site),
      })),
    });
  }

  if (name === 'mirasai/sites-test') {
    const site = findSite(registry, args.site_id);
    const result = await clientFactory(site).test();
    return callToolResult(result, result.ok !== true);
  }

  if (name === 'mirasai/host-diagnose') {
    const site = findSite(registry, args.site_id);
    const result = await clientFactory(site).diagnose();
    return result;
  }

  return callRemoteTool(registry, clientFactory, name, args);
}

async function listAllTools(registry, clientFactory, params = {}) {
  const surface = typeof params?.surface === 'string' ? params.surface : undefined;
  const remote = await discoverRemoteTools(registry, clientFactory, surface);
  const warnings = registryWarnings(registry);
  const metadata = {
    ...(remote.warnings.length > 0 ? { discovery_warnings: remote.warnings } : {}),
    ...(warnings.length > 0 ? { registry_warnings: warnings } : {}),
  };

  return {
    tools: [
      ...routerTools(),
      ...remote.tools,
    ],
    ...(Object.keys(metadata).length > 0 ? { metadata } : {}),
  };
}

function registryWarnings(registry) {
  return Array.isArray(registry.warnings) ? registry.warnings : [];
}

async function discoverRemoteTools(registry, clientFactory, surface = undefined) {
  const toolsByName = new Map();
  const warnings = [];

  await Promise.all(registry.sites.map(async (site) => {
    try {
      const client = clientFactory(site);
      const contractWarning = await checkHostContractVersion(client, site);
      if (contractWarning !== null) {
        warnings.push(contractWarning);
      }

      const result = await client.toolsList(surface === undefined ? {} : { surface });
      const tools = Array.isArray(result?.tools) ? result.tools : [];

      for (const tool of tools) {
        if (!isRemoteToolCandidate(tool) || isRouterToolName(tool.name)) {
          continue;
        }

        const merged = toolsByName.get(tool.name);
        if (merged === undefined) {
          toolsByName.set(tool.name, addSiteIdToTool(tool, site));
          continue;
        }

        toolsByName.set(tool.name, mergeRemoteTool(merged, tool, site));
      }
    } catch (caught) {
      warnings.push({
        site_id: site.site_id,
        platform: site.platform,
        message: caught instanceof Error ? caught.message : String(caught),
      });
    }
  }));

  return {
    tools: [...toolsByName.values()].sort((left, right) => left.name.localeCompare(right.name)),
    warnings,
  };
}

async function checkHostContractVersion(client, site) {
  if (typeof client?.initialize !== 'function') {
    return null;
  }

  let result;
  try {
    result = await client.initialize();
  } catch (caught) {
    return {
      site_id: site.site_id,
      platform: site.platform,
      code: 'host_contract_version_check_failed',
      expected: '1',
      actual: null,
      message: `Could not check host_contract_version for ${site.site_id}: ${caught instanceof Error ? caught.message : String(caught)}`,
    };
  }

  const actual = result?.serverInfo?.host_contract_version;

  if (actual === '1') {
    return null;
  }

  return {
    site_id: site.site_id,
    platform: site.platform,
    code: 'host_contract_version_mismatch',
    expected: '1',
    actual: actual ?? null,
    message: `Host ${site.site_id} reports host_contract_version ${actual ?? 'missing'}, expected 1.`,
  };
}

async function callRemoteTool(registry, clientFactory, name, args) {
  if (typeof name !== 'string' || name.trim() === '') {
    return callToolResult({ error: 'Tool name is required.', code: 'invalid_arguments' }, true);
  }

  const site = findSite(registry, args.site_id);
  const remoteArgs = { ...args };
  delete remoteArgs.site_id;

  const client = clientFactory(site);

  try {
    const available = await client.toolsList();
    const tools = Array.isArray(available?.tools) ? available.tools : [];

    if (!tools.some((tool) => tool?.name === name)) {
      return callToolResult({
        error: `Tool ${name} is not exposed by site ${site.site_id}.`,
        code: 'tool_not_supported_on_platform',
        tool: name,
        site_id: site.site_id,
        platform: site.platform,
      }, true);
    }

    return await client.callTool(name, remoteArgs);
  } catch (caught) {
    return callToolResult({
      error: caught instanceof Error ? caught.message : String(caught),
      code: 'remote_tool_call_failed',
      tool: name,
      site_id: site.site_id,
      platform: site.platform,
    }, true);
  }
}

function isRemoteToolCandidate(tool) {
  return tool !== null
    && typeof tool === 'object'
    && !Array.isArray(tool)
    && typeof tool.name === 'string'
    && tool.name.trim() !== ''
    && tool.inputSchema !== null
    && typeof tool.inputSchema === 'object'
    && !Array.isArray(tool.inputSchema);
}

function addSiteIdToTool(tool, site) {
  const inputSchema = clonePlainObject(tool.inputSchema);
  const properties = clonePlainObject(inputSchema.properties ?? {});
  const required = Array.isArray(inputSchema.required) ? [...inputSchema.required] : [];

  properties.site_id = {
    type: 'string',
    description: 'Configured MirasAI site id. Defaults to the router default site.',
    enum: [site.site_id],
  };

  inputSchema.type = 'object';
  inputSchema.properties = properties;
  inputSchema.required = required.filter((field) => field !== 'site_id');

  return {
    ...tool,
    description: `[${site.platform}] ${tool.description ?? tool.name}`,
    inputSchema,
    metadata: mergeMetadata(tool.metadata, site),
  };
}

function mergeRemoteTool(existing, tool, site) {
  const existingSiteIds = existing.metadata?.site_ids ?? [];
  const siteIds = uniqueStrings([...existingSiteIds, site.site_id]);
  const platforms = uniqueStrings([...(existing.metadata?.platforms ?? []), site.platform]);
  const siteEnum = uniqueStrings([
    ...(existing.inputSchema?.properties?.site_id?.enum ?? []),
    site.site_id,
  ]);

  return {
    ...existing,
    description: stripPlatformPrefix(existing.description),
    inputSchema: {
      ...existing.inputSchema,
      properties: {
        ...existing.inputSchema.properties,
        site_id: {
          ...existing.inputSchema.properties.site_id,
          enum: siteEnum,
        },
      },
    },
    metadata: {
      ...existing.metadata,
      platforms,
      site_ids: siteIds,
      source_count: siteIds.length,
      risk_level: highestRisk(existing.metadata?.risk_level, tool.metadata?.risk_level),
    },
  };
}

function mergeMetadata(metadata, site) {
  const base = metadata !== null && typeof metadata === 'object' && !Array.isArray(metadata)
    ? clonePlainObject(metadata)
    : {};

  return {
    ...base,
    risk_level: base.risk_level ?? 'read',
    workflow_hint: base.workflow_hint ?? 'direct',
    surface: base.surface ?? 'advanced',
    platforms: uniqueStrings([...(Array.isArray(base.platforms) ? base.platforms : []), site.platform]),
    site_ids: [site.site_id],
    source_count: 1,
  };
}

function clonePlainObject(value) {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) {
    return {};
  }

  return { ...value };
}

function uniqueStrings(values) {
  return [...new Set(values.filter((value) => typeof value === 'string' && value !== ''))];
}

function highestRisk(left = 'read', right = 'read') {
  const order = ['read', 'safe_write', 'guarded_write', 'dangerous_exec'];
  return order[Math.max(order.indexOf(left), order.indexOf(right), 0)];
}

function stripPlatformPrefix(description) {
  return typeof description === 'string' ? description.replace(/^\[[^\]]+\]\s+/, '') : description;
}

function isRouterToolName(name) {
  return typeof name === 'string' && name.startsWith('mirasai/');
}

function tokenSourceForSite(site) {
  if (site.token_ref !== undefined) {
    return '1password';
  }
  if (site.token_env !== undefined) {
    return 'env';
  }
  if (site.token_plain_dev !== undefined) {
    return 'plain-dev';
  }
  if (site.basic_ref !== undefined) {
    return 'basic-1password';
  }
  if (site.basic_env !== undefined) {
    return 'basic-env';
  }
  if (site.basic_plain_dev !== undefined) {
    return 'basic-plain-dev';
  }
  return 'none';
}

function callToolResult(payload, isError = false) {
  return {
    content: [
      {
        type: 'text',
        text: JSON.stringify(payload, null, 2),
      },
    ],
    structuredContent: payload,
    ...(isError ? { isError: true } : {}),
  };
}

function readOnlyAnnotations() {
  return {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: true,
  };
}

function response(id, result) {
  return {
    jsonrpc: '2.0',
    result,
    id,
  };
}

function error(id, code, message) {
  return {
    jsonrpc: '2.0',
    error: {
      code,
      message,
    },
    id,
  };
}

export function writeMcpMessage(stream, payload) {
  const body = JSON.stringify(payload);
  stream.write(`Content-Length: ${Buffer.byteLength(body, 'utf8')}\r\n\r\n${body}`);
}

export async function serveStdio(handler, input = process.stdin, output = process.stdout) {
  let buffer = Buffer.alloc(0);

  for await (const chunk of input) {
    buffer = Buffer.concat([buffer, chunk]);

    while (true) {
      const headerEnd = buffer.indexOf('\r\n\r\n');
      if (headerEnd === -1) {
        break;
      }

      const header = buffer.subarray(0, headerEnd).toString('utf8');
      const match = /^Content-Length:\s*(\d+)$/im.exec(header);
      if (match === null) {
        throw new Error('Missing Content-Length header.');
      }

      const length = Number.parseInt(match[1], 10);
      const messageStart = headerEnd + 4;
      const messageEnd = messageStart + length;

      if (buffer.length < messageEnd) {
        break;
      }

      const body = buffer.subarray(messageStart, messageEnd).toString('utf8');
      buffer = buffer.subarray(messageEnd);
      const request = JSON.parse(body);
      const result = await handler(request);

      if (result !== null) {
        writeMcpMessage(output, result);
      }
    }
  }
}
