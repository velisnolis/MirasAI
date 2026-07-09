#!/usr/bin/env node

/**
 * Spike: import yt-builder-mcp as a library and inject one external MCP tool.
 *
 * Usage:
 *   npm install
 *   npm run spike
 *
 * Optional, to test a local checkout instead of the npm package:
 *   YTB_MCP_DIST_INDEX=/tmp/yt-builder-mcp-repo/packages/mcp/dist/index.js npm run spike
 */

import { pathToFileURL } from 'node:url';
import { z } from 'zod';

process.env.YTB_MCP_NO_AUTORUN = '1';

const ytb = await importYtbPackage();

const registry = new ytb.SiteRegistry({
  schema_version: 1,
  default_site_id: null,
  sites: [],
});
const pool = new ytb.ClientPool(registry, ytb.defaultSecretResolver());

const externalTool = {
  name: 'mirasai_spike_ping',
  description:
    'Spike-only external tool injected by a wrapper outside yt-builder-mcp buildAllTools().',
  inputSchema: {
    message: z.string().optional().describe('Optional message echoed by the spike tool.'),
  },
  annotations: {
    title: 'MirasAI Spike Ping',
    readOnlyHint: true,
    destructiveHint: false,
    openWorldHint: false,
    idempotentHint: true,
  },
  handler: async ({ message }) => {
    const payload = {
      ok: true,
      source: 'mirasai-wrapper-spike',
      message: typeof message === 'string' && message !== '' ? message : 'pong',
    };

    return {
      content: [{ type: 'text', text: JSON.stringify(payload) }],
      structuredContent: payload,
    };
  },
};

const baseTools = ytb.buildAllTools(pool);
const created = ytb.createServer({
  pool,
  tools: [...baseTools, externalTool],
});

const advancedRegistry = created.capturing.getAdvancedRegistry();
const directOrEssentialCount = created.tools.length - advancedRegistry.size;

const result = {
  ytb_server_name: ytb.SERVER_NAME,
  ytb_server_version: ytb.SERVER_VERSION,
  base_tool_definitions: baseTools.length,
  total_tool_definitions_with_spike: created.tools.length,
  external_tool_injected: created.tools.some((tool) => tool.name === externalTool.name),
  external_tool_first_class: !advancedRegistry.has(externalTool.name),
  external_tool_captured_as_advanced: advancedRegistry.has(externalTool.name),
  advanced_registry_size: advancedRegistry.size,
  direct_or_essential_tool_definitions: directOrEssentialCount,
  conclusion:
    advancedRegistry.has(externalTool.name)
      ? 'Library-level injection works, but unknown tools are routed behind yootheme_builder_advanced unless yt-builder-mcp core is changed.'
      : 'Library-level injection works and the external tool is first-class.',
};

console.log(JSON.stringify(result, null, 2));

async function importYtbPackage() {
  const distIndex = process.env.YTB_MCP_DIST_INDEX;
  if (typeof distIndex === 'string' && distIndex !== '') {
    return import(pathToFileURL(distIndex).href);
  }

  return import('@wootsup/yt-builder-mcp');
}
