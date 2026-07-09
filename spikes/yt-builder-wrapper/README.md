# yt-builder-mcp wrapper spike

This spike checks whether MirasAI can add tools to `@wootsup/yt-builder-mcp`
without forking the upstream package.

## Run

```bash
cd spikes/yt-builder-wrapper
npm install
npm run spike
npm run hybrid
```

To test a local `yt-builder-mcp` checkout:

```bash
cd spikes/yt-builder-wrapper
YTB_MCP_DIST_INDEX=/tmp/yt-builder-mcp-repo/packages/mcp/dist/index.js npm run spike
```

The local checkout must already be built so `dist/index.js` exists.

## Expected result

The wrapper can import `createServer`, `buildAllTools`, `SiteRegistry`, and
`ClientPool`, append a `mirasai_spike_ping` tool, and construct a server.

The important limitation is that `yt-builder-mcp` routes unknown tool names into
the advanced gateway. That means injected tools are not first-class in
`tools/list` unless upstream exposes an extension hook or changes the hardcoded
essential/direct tool lists.

## Initial conclusion

This is viable as a technical wrapper spike, but not as a clean product-level
plugin system yet. A real integration would need one of these paths:

- upstream extension API for third-party tool providers;
- a MirasAI-owned wrapper server that imports `@wootsup/yt-builder-mcp`;
- a fork of `yt-builder-mcp`;
- a separate MirasAI local multi-site MCP inspired by their registry/client-pool.

## Observed on 2026-05-30

Against both the npm package `@wootsup/yt-builder-mcp@1.1.7` and the local
checkout built from `yt-builder-mcp_v1.1.7.zip`:

```json
{
  "ytb_server_name": "@wootsup/yt-builder-mcp",
  "ytb_server_version": "1.1.7",
  "base_tool_definitions": 26,
  "total_tool_definitions_with_spike": 27,
  "external_tool_injected": true,
  "external_tool_first_class": false,
  "external_tool_captured_as_advanced": true,
  "advanced_registry_size": 8,
  "direct_or_essential_tool_definitions": 19
}
```

This confirms that the package can be used as a library-level building block,
but its public bin does not load third-party tools and its first-class tool
surface is not externally configurable.

## Hybrid wrapper spike

`hybrid-wrapper.mjs` validates a MirasAI-owned local MCP wrapper. It does not use
`yt-builder-mcp`'s `createServer()`; instead, it imports their tool definitions
and registers a curated subset directly in our own MCP server alongside a
simulated MirasAI tool.

Observed output:

```json
{
  "wrapper": "@miras/mirasai-hybrid-wrapper-spike",
  "ytb_version": "1.1.7",
  "selected_yootheme_tools": 9,
  "mirasai_tools": 1,
  "tools_list_count": 10,
  "yootheme_sites_list_first_class": true,
  "mirasai_diagnose_first_class": true
}
```

This proves the cleaner path: a MirasAI-owned wrapper can expose selected
`yt-builder-mcp` tools and MirasAI tools as first-class MCP tools without using
`yootheme_builder_advanced`.
