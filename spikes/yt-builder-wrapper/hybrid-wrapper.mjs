#!/usr/bin/env node

/**
 * Spike: MirasAI-owned MCP wrapper around selected yt-builder-mcp tools.
 *
 * This deliberately does NOT call yt-builder-mcp's createServer(). Instead it:
 * - reuses yt-builder-mcp ToolDefinition builders;
 * - registers a curated subset as first-class tools;
 * - adds one simulated MirasAI tool as a first-class tool.
 *
 * The goal is to validate the hybrid shape:
 *
 *   AI client -> MirasAI local MCP wrapper
 *     -> yootheme_builder_* tools from @wootsup/yt-builder-mcp
 *     -> mirasai_* tools owned by us
 */

import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { z } from 'zod';

process.env.YTB_MCP_NO_AUTORUN = '1';

const ytb = await import('@wootsup/yt-builder-mcp');

const SAMPLE_SITES = {
  schema_version: 1,
  default_site_id: 'joomla-demo',
  sites: [
    {
      site_id: 'joomla-demo',
      url: 'https://joomla.example.test',
      platform: 'joomla',
      bearer: 'ytb_live_demo.demo',
      is_default: true,
      label: 'Joomla demo',
      added_at: '2026-05-30T00:00:00.000Z',
    },
    {
      site_id: 'wp-demo',
      url: 'https://wp.example.test',
      platform: 'wordpress',
      bearer: 'ytb_live_demo.demo',
      is_default: false,
      label: 'WordPress demo',
      added_at: '2026-05-30T00:00:00.000Z',
    },
  ],
};

const FIRST_CLASS_YTB_TOOLS = new Set([
  'yootheme_builder_health',
  'yootheme_builder_diagnose',
  'yootheme_builder_sites_list',
  'yootheme_builder_sites_test',
  'yootheme_builder_pages_list',
  'yootheme_builder_template_summary',
  'yootheme_builder_element_list',
  'yootheme_builder_sources_list',
  'yootheme_builder_element_types_list',
]);

const registry = new ytb.SiteRegistry(SAMPLE_SITES);
const pool = new ytb.ClientPool(registry, ytb.defaultSecretResolver());
const allYtbTools = ytb.buildAllTools(pool);
const selectedYtbTools = allYtbTools.filter((tool) => FIRST_CLASS_YTB_TOOLS.has(tool.name));

const mirasaiTool = {
  name: 'mirasai_system_diagnose',
  description:
    'Simulated MirasAI diagnostic tool. In a real wrapper this would forward to a MirasAI site MCP endpoint.',
  inputSchema: {
    site_id: z.string().optional().describe('MirasAI site id. Defaults to the wrapper default site.'),
    include_tools: z.boolean().optional().describe('Include a compact tool-count summary.'),
  },
  annotations: {
    title: 'MirasAI System Diagnose',
    readOnlyHint: true,
    destructiveHint: false,
    openWorldHint: true,
    idempotentHint: true,
  },
  handler: async ({ site_id, include_tools }) => {
    const id = typeof site_id === 'string' && site_id !== '' ? site_id : SAMPLE_SITES.default_site_id;
    const site = SAMPLE_SITES.sites.find((row) => row.site_id === id);

    if (site === undefined) {
      return {
        isError: true,
        content: [
          {
            type: 'text',
            text: JSON.stringify({
              error: 'unknown_site',
              site_id: id,
              available: SAMPLE_SITES.sites.map((row) => row.site_id),
            }),
          },
        ],
      };
    }

    const payload = {
      ok: true,
      product: 'mirasai',
      mode: 'simulated',
      site_id: site.site_id,
      platform: 'joomla',
      url: site.url,
      ...(include_tools === true
        ? {
            tools: {
              yootheme_first_class: selectedYtbTools.length,
              mirasai_first_class: 1,
            },
          }
        : {}),
    };

    return {
      content: [{ type: 'text', text: JSON.stringify(payload) }],
      structuredContent: payload,
    };
  },
};

const mcp = new McpServer(
  {
    name: '@miras/mirasai-hybrid-wrapper-spike',
    version: '0.0.0-spike',
  },
  {
    instructions:
      'Spike server: curated yt-builder-mcp tools plus simulated MirasAI tools, all registered first-class.',
  },
);

for (const tool of [...selectedYtbTools, mirasaiTool]) {
  registerToolDefinition(mcp, tool);
}

const registeredTools = Object.entries(mcp._registeredTools).map(([name, tool]) => ({
  name,
  description: tool.description,
  annotations: tool.annotations ?? {},
}));

console.log(JSON.stringify({
  wrapper: '@miras/mirasai-hybrid-wrapper-spike',
  ytb_version: ytb.SERVER_VERSION,
  selected_yootheme_tools: selectedYtbTools.length,
  mirasai_tools: 1,
  tools_list_count: registeredTools.length,
  yootheme_sites_list_first_class: registeredTools.some((tool) => tool.name === 'yootheme_builder_sites_list'),
  mirasai_diagnose_first_class: registeredTools.some((tool) => tool.name === 'mirasai_system_diagnose'),
  registered_tools: registeredTools.map((tool) => tool.name),
  conclusion:
    'A MirasAI-owned wrapper can expose selected yt-builder-mcp tools and MirasAI tools as first-class MCP tools without using yootheme_builder_advanced.',
}, null, 2));

function registerToolDefinition(server, tool) {
  const inputSchema = tool.inputObjectSchema ?? tool.inputSchema;

  server.registerTool(
    tool.name,
    {
      description: tool.description,
      inputSchema,
      ...(tool.outputSchema !== undefined ? { outputSchema: tool.outputSchema } : {}),
      annotations: tool.annotations,
    },
    async (args, extra) => tool.handler(args, extra),
  );
}
