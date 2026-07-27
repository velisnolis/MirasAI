# MirasAI MCP Router

`@miras/mirasai-mcp` is the local MCP router for MirasAI hosts.

It keeps the client-facing MCP surface in one place and routes calls to Joomla or WordPress host endpoints by `site_id`.

Current internal version: `0.7.0`.

This release implements the registry, secret resolution with in-memory TTL,
host probing, remote tool discovery, and a stdio MCP server that can expose
tools from multiple Joomla and WordPress hosts through one local MCP. Hosts
can speak either the MirasAI contract or the standard MCP Streamable HTTP
protocol (for example the WordPress MCP Adapter used by WP Rocket 3.23+,
Rank Math, and the WordPress AI plugin).

## Registry

Default path:

```text
~/.config/mirasai-mcp/sites.json
```

Example:

```json
{
  "schema_version": 1,
  "default_site_id": "joomla-example",
  "sites": [
    {
      "site_id": "joomla-example",
      "label": "Example Joomla",
      "platform": "joomla",
      "url": "https://joomla.example.com/api/v1/mirasai/mcp",
      "token_ref": "op://YOUR_VAULT/Example Joomla/api_token",
      "secret_ttl_seconds": 3600
    },
    {
      "site_id": "demo-wp",
      "label": "Demo WordPress",
      "platform": "wordpress",
      "url": "https://demo.test/wp-json/mirasai/v1/mcp",
      "token_env": "DEMO_WP_MIRASAI_TOKEN",
      "style_worker_sha256": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"
    },
    {
      "site_id": "demo-wp-adapter",
      "label": "Demo WordPress (MCP Adapter)",
      "platform": "wordpress",
      "protocol": "mcp",
      "url": "https://demo.test/wp-json/mcp/mcp-adapter-default-server",
      "basic_ref": "op://vault/item/field"
    }
  ]
}
```

## Host protocols

Each site has an optional `protocol` field:

- `mirasai` (default): MirasAI host contract. `mirasai/sites-test` also runs
  `system/diagnose`, and discovery checks `host_contract_version`.
- `mcp`: standard MCP Streamable HTTP endpoint, such as the WordPress MCP
  Adapter (`/wp-json/mcp/mcp-adapter-default-server`). The router performs the
  spec `initialize` handshake lazily, captures the `Mcp-Session-Id` response
  header, and replays it on every later call from the same client. SSE
  (`text/event-stream`) responses are parsed. `system/diagnose` and
  `host_contract_version` checks are skipped. WordPress Application Passwords
  work as-is through `basic_ref`/`basic_env` (spaces in the password are
  accepted by WordPress).

Supported token sources:

- `token_ref`: 1Password reference resolved with `op read`
- `token_env`: environment variable name
- `token_plain_dev`: development-only clear token
- `basic_ref`: 1Password reference containing `username:application-password`
- `basic_env`: environment variable containing `username:application-password`
- `basic_plain_dev`: development-only clear Basic credentials

YOOtheme Style preview/verification additionally requires
`style_worker_sha256`: the SHA-256 of that site's
`assets/admin/js/worker.js`. The router reads the remote file first and returns
`style_worker_hash_required` with the observed hash when no pin exists. Review
that hash against a trusted installation or vendor package before storing it.
If the remote hash later changes, the router returns
`style_worker_hash_mismatch` and refuses to execute the bundle. This is
deliberate: the bundle runs in a separate permission-restricted process, but
Node's Permission Model is defense in depth rather than a complete sandbox.

1Password references are cached in memory by the running router process for
`secret_ttl_seconds`. The default is 3600 seconds. Set `secret_ttl_seconds: 0`
on a site, or `MIRASAI_MCP_SECRET_TTL_SECONDS=0` globally, to disable this cache.
The cache is never written to disk and is cleared when the router process exits.

## Commands

```bash
mirasai-mcp list-sites
mirasai-mcp add-site --site-id wp-example --label "Example WordPress" --platform wordpress --url https://wp.example.com/wp-json/mirasai/v1/mcp --basic-ref "op://YOUR_VAULT/Example WordPress/application_password" --secret-ttl-seconds 3600 --style-worker-sha256 HASH --default
mirasai-mcp add-site --site-id demo-wp-adapter --label "Demo WP (MCP Adapter)" --platform wordpress --protocol mcp --url https://demo.test/wp-json/mcp/mcp-adapter-default-server --basic-ref op://vault/item/field
mirasai-mcp set-default wp-example
mirasai-mcp test-site joomla-example
mirasai-mcp serve
```

Use `--config /path/to/sites.json` to override the registry path.

`serve` speaks both MCP stdio framings and answers in whichever the client
uses: newline-delimited JSON (the MCP specification, used by Claude Desktop,
Cursor, and mcp2cli) and LSP-style `Content-Length` headers.

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
  - `mirasai/style-preview`
  - `mirasai/style-update`
  - `mirasai/style-verify`
- isolated, timeout-bounded YOOtheme worker execution with a per-site SHA-256 pin
- guarded Style writes with explicit `if_match`, dry-run by default, compiled
  CSS hash binding, private host snapshots, rollback, and post-write verification
- CLI commands for `list-sites`, `add-site`, `set-default`, `test-site`, and `serve`

The Style tools consume a platform-neutral host contract (`worker`, `base_url`,
imports, and overrides). Both the WordPress and Joomla hosts expose the same
read and guarded-write contract while keeping CMS-specific storage, snapshots,
compiled CSS targets, and worker paths inside the host adapter. A real update
requires `dry_run: false`, `confirm_guarded_write: true`, and the fresh Style ETag returned by
`template/style-read` or `mirasai/style-preview`.

The WordPress host additionally exposes guarded `template/style-create`. It
scaffolds a YOOtheme child theme when needed and writes a versionable
`less/theme.<id>.less` source after `dry_run` + `if_match` + confirmation. It
does not activate the child theme, select the new Style, or compile live CSS;
those remain explicit follow-up operations. Joomla supports the shared
read/sources/update contract, but not child-template creation.

Next steps:

- add live contract fixtures from Joomla and WordPress hosts
- add installer/onboarding helpers for common MCP clients
- add a one-command installer for the GitHub release tarball
