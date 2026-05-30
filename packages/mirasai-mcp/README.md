# MirasAI MCP Router

`@miras/mirasai-mcp` is the local MCP router for MirasAI hosts.

It keeps the client-facing MCP surface in one place and routes calls to Joomla or WordPress MirasAI host endpoints by `site_id`.

Current internal version: `0.5.0`.

This release implements the registry, secret resolution with in-memory TTL,
host probing, remote tool discovery, and a stdio MCP server that can expose
tools from multiple Joomla and WordPress MirasAI hosts through one local MCP.

## Registry

Default path:

```text
~/.config/mirasai-mcp/sites.json
```

Example:

```json
{
  "schema_version": 1,
  "default_site_id": "autovigatana",
  "sites": [
    {
      "site_id": "autovigatana",
      "label": "Autovigatana",
      "platform": "joomla",
      "url": "https://www.autovigatana.cat/api/v1/mirasai/mcp",
      "token_ref": "op://feina/autovigatana-mirasai/token",
      "secret_ttl_seconds": 3600
    },
    {
      "site_id": "demo-wp",
      "label": "Demo WordPress",
      "platform": "wordpress",
      "url": "https://demo.test/wp-json/mirasai/v1/mcp",
      "token_env": "DEMO_WP_MIRASAI_TOKEN"
    }
  ]
}
```

Supported token sources:

- `token_ref`: 1Password reference resolved with `op read`
- `token_env`: environment variable name
- `token_plain_dev`: development-only clear token
- `basic_ref`: 1Password reference containing `username:application-password`
- `basic_env`: environment variable containing `username:application-password`
- `basic_plain_dev`: development-only clear Basic credentials

1Password references are cached in memory by the running router process for
`secret_ttl_seconds`. The default is 3600 seconds. Set `secret_ttl_seconds: 0`
on a site, or `MIRASAI_MCP_SECRET_TTL_SECONDS=0` globally, to disable this cache.
The cache is never written to disk and is cleared when the router process exits.

## Commands

```bash
mirasai-mcp list-sites
mirasai-mcp add-site --site-id jordifont-wp --label "Jordi Font WordPress" --platform wordpress --url https://www.jordifont.com/wp-json/mirasai/v1/mcp --basic-ref op://vault/item/field --secret-ttl-seconds 3600 --default
mirasai-mcp set-default jordifont-wp
mirasai-mcp test-site autovigatana
mirasai-mcp serve
```

Use `--config /path/to/sites.json` to override the registry path.

## Current Scope

Implemented now:

- local site registry parsing
- secret resolution with process-local TTL for 1Password references
- authenticated host JSON-RPC calls
- `initialize`, `tools/list`, and `system/diagnose` host checks
- remote host tool discovery
- first-class remote tools with router-owned `site_id` argument injection
- remote `tools/call` proxying after host capability checks
- stdio MCP router with:
  - `mirasai/sites-list`
  - `mirasai/sites-test`
  - `mirasai/host-diagnose`
- CLI commands for `list-sites`, `add-site`, `set-default`, `test-site`, and `serve`

Next steps:

- add live contract fixtures from Joomla and WordPress hosts
- add installer/onboarding helpers for common MCP clients
- package the router for local installation
