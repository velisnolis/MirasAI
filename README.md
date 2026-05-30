# MirasAI

MirasAI is a Joomla extension package that exposes an MCP server for AI agents.

It gives an AI controlled access to Joomla content, multilingual workflows, system inspection, optional YOOtheme tooling, and optional ReReplacer tooling.

Use it on staging first. Use backups. Treat production as a gated environment.

## Multi-Platform Direction

The Joomla package remains the current production host, but the project is moving toward a multi-platform MirasAI ecosystem:

- `pkg_mirasai/`: Joomla host package.
- `packages/mirasai-contract/`: shared host MCP contract for Joomla, WordPress, and the local router.
- `packages/mirasai-mcp/`: local multi-site MCP router. This is the single client-facing MCP that routes to Joomla and WordPress hosts by `site_id`.
- `packages/mirasai-wp/`: WordPress host plugin, inspired by Novamira patterns but using the MirasAI host contract.

The intended architecture is:

```text
AI client -> @miras/mirasai-mcp -> Joomla / WordPress MirasAI host endpoints
```

Hosts remain usable directly over HTTP MCP, while the router provides the preferred multi-site and multi-platform client experience.

## What It Is

MirasAI installs a small Joomla runtime made of:

- a core library
- a system plugin
- a webservices plugin
- an admin component
- optional addons for YOOtheme and ReReplacer

Once installed, Joomla exposes an MCP endpoint:

- `/api/v1/mirasai/mcp`

Your AI client connects to that endpoint with a Joomla API token from a `Super User`.

## What It Does

Out of the box, MirasAI can:

- inspect the Joomla environment
- diagnose MirasAI connectivity, addon registration, and YOOtheme readiness
- list and read articles
- create or update multilingual article translations
- translate categories
- audit multilingual gaps
- inspect the file system
- run guarded read-only database queries
- expose optional tooling for YOOtheme layouts and templates
- expose optional tooling for ReReplacer and Conditions

## What It Does Not Do

MirasAI is deliberately narrow.

It does not:

- implement the full MCP protocol
- auto-translate content by itself
- replace Joomla permissions with its own role system
- make production write access safe by magic
- bundle YOOtheme Pro or Regular Labs extensions

Current MCP surface:

- `initialize`
- `tools/list`
- `tools/call`
- `ping`

Not implemented:

- resources
- prompts
- sampling
- roots

## Tool Model

The package works in three practical modes:

### 1. Core only

Available on plain Joomla sites:

- `system/info`
- `system/diagnose`
- `content/list`
- `content/read`
- `content/translate`
- `content/translate-batch`
- `content/check-links`
- `content/audit-multilingual`
- `taxonomy/term-list`
- `taxonomy/term-translate`
- `category/translate`
- `site/setup-language-switcher`
- `sandbox/status`
- `file/read`
- `file/write`
- `file/edit`
- `file/delete`
- `file/list`
- `sandbox/execute-php`
- `db/query`
- `db/schema`
- `elevation/status`

### 2. Core + YOOtheme addon

When `plg_mirasai_yootheme` is enabled and YOOtheme is installed:

- `theme/extract-to-modules`
- `menu/migrate-theme-to-modules`
- `template/list`
- `template/summary`
- `template/element-types`
- `template/element-schema`
- `template/source-types`
- `template/element-list`
- `template/element-read`
- `template/element-source-read`
- `template/element-source-preview`
- `template/element-source-set`
- `template/element-source-delete`
- `template/element-add`
- `template/element-update-props`
- `template/element-move`
- `template/element-clone`
- `template/element-delete`
- `template/read`
- `template/translate`
- `template/widget-translate`

### 3. Core + ReReplacer addon

When `plg_mirasai_rereplacer` is enabled and ReReplacer is installed:

- `rereplacer/capabilities`
- `rereplacer/list-items`
- `rereplacer/read-item`
- `rereplacer/create-item-simple`
- `rereplacer/update-item-simple`
- `rereplacer/publish-item`
- `rereplacer/preview-match-scope`
- `conditions/list`
- `conditions/read`

When ReReplacer PRO and Conditions are both available:

- `rereplacer/attach-condition`

## Package Contents

The installable package includes:

- `lib_mirasai`
- `plg_system_mirasai`
- `plg_webservices_mirasai`
- `com_mirasai`
- `plg_mirasai_yootheme`
- `plg_mirasai_rereplacer`

Development reference only, not shipped in the package:

- `pkg_mirasai/packages/plg_mirasai_example`

## Safety Model

### Authentication

MirasAI accepts Joomla API tokens, but only users authorized for `core.admin` can authenticate to MCP.

Practical effect:

- editor or manager tokens are rejected
- `Super User` tokens are accepted

### Environment gating

Production is the default.

Staging must be configured explicitly through one of:

- MirasAI component config: `environment_override = staging`
- Joomla config: `mirasai_environment_override = staging`
- environment variable: `MIRASAI_ENV=staging`

### Risk levels

Every tool exposes a canonical `risk_level` in MCP metadata:

- `read`: inspection only; no persistent change.
- `safe_write`: constrained Joomla write through a domain-specific tool.
- `guarded_write`: persistent layout/replacement/migration write that needs preview, ETag, or explicit human review in the client workflow.
- `dangerous_exec`: file mutation, deletion, PHP execution, or runtime/code persistence. These are Smart Sudo gated on production.

Each tool also exposes `workflow_hint`:

- `direct` for `read`
- `validate_then_apply` for `safe_write`
- `dry_run_confirm_if_match` for `guarded_write`
- `elevation_required` for `dangerous_exec`

`guarded_write` calls are blocked unless either `dry_run=true` or `confirm_guarded_write=true` is present. Tools that expose ETags, such as YOOtheme template writes, also require a fresh `if_match`.

Every tool also exposes `metadata.surface`:

- `essential`: first-call discovery and orientation tools.
- `advanced`: deeper write or specialized tools.

By default, `tools/list` returns all tools for compatibility. Clients that support filtered discovery can call `tools/list` with `{"surface":"essential"}` or `{"surface":"advanced"}`.

`file/read` is a read tool, but it intentionally blocks common secret-bearing files such as Joomla `configuration.php`, `.env` variants, private keys, and certificate bundles.

### Elevation

Only `dangerous_exec` tools are gated behind elevation in production.

That split exists so normal content operations and guarded domain-specific writes can remain available, while high-risk file and PHP execution tools still require explicit production unlock.

## What Elevation Is

Elevation is the production approval layer for `dangerous_exec` operations.

In practice, it means:

- the AI can still inspect the site normally
- low-risk operations can remain available when explicitly designed that way
- dangerous execution operations are blocked until a human enables them

This is not a generic “admin mode”.
It is a deliberate gate for actions that can persist code, delete files, execute PHP, or change runtime behavior in ways that are hard to roll back.

## When Elevation Matters

Elevation matters mainly on production sites.

Typical examples:

- writing or editing files in the sandbox
- deleting files
- executing PHP
- any future advanced addon flow classified as `dangerous_exec`

By contrast, `read`, `safe_write`, and `guarded_write` tools do not force Smart Sudo by default. `guarded_write` is still a higher-risk class and should be paired with preview/ETag/human confirmation in the client workflow.

## Elevation Use Cases

Good reasons to use elevation:

- prototype a one-off PHP helper on staging-like infrastructure
- inspect or patch a sandboxed file as part of debugging
- build a temporary migration script
- test a custom integration against a live Joomla runtime
- run a controlled dangerous execution operation after human review

Bad reasons to use elevation:

- because the AI “might know what it is doing”
- to skip proper staging validation
- to make production the default development environment
- to run arbitrary PHP when a normal Joomla edit or safe tool already solves the task

## PHP Execution Limits

`sandbox/execute-php` is transaction-wrapped PHP execution, not an isolated security sandbox.

It runs PHP in the Joomla worker via `eval()`. The code has access to the Joomla runtime and the same process resources as the request handling it.

What it does provide:

- DB transaction wrapping around the call when the database driver supports it
- rollback on caught PHP exceptions and errors handled by the tool
- warning/notice capture
- a best-effort `set_time_limit(30)`
- production elevation and audit logging when the site is treated as production

What it does not guarantee:

- isolation from the Joomla PHP process
- rollback for MySQL DDL such as `CREATE TABLE` or `ALTER TABLE`
- rollback for non-transactional tables
- protection from code that overrides the time limit or exhausts process resources
- safety for arbitrary PHP on production

Every call must pass `confirm_execute_php=true` to acknowledge those limits.

## Elevation Workflow

The intended workflow is:

1. inspect first
2. decide if the task really needs a gated operation
3. request elevation only for that step
4. execute the risky action
5. return to normal operation

The goal is not convenience. The goal is controlled exceptions.

### Sandbox separation

Writable files and auto-loaded PHP are separated:

- writable workspace: `media/mirasai/sandbox/`
- boot autoload path: `media/mirasai/autoload/`

## Quick Start

This is the shortest path from zero to a working MCP connection.

### 1. Build the package

```bash
./docker/build-package.sh
```

Build output:

- `.docker-build/pkg_mirasai-<version>.zip`
- `.docker-build/pkg_mirasai-lab.zip`
- `updates/pkg_mirasai.xml`

### 2. Install it in Joomla

In Joomla admin:

1. Go to `System > Install > Extensions`
2. Install `pkg_mirasai-<version>.zip`
3. Confirm these are enabled:
   - `plg_system_mirasai`
   - `plg_webservices_mirasai`
4. If needed, enable optional addons:
   - `plg_mirasai_yootheme`
   - `plg_mirasai_rereplacer`
5. Open `Components > MirasAI`

### 3. Confirm the dashboard works

You should see:

- the MCP endpoint
- Joomla and PHP versions
- detected languages
- grouped core tools
- addon groups with `Active`, `Unavailable`, or `Disabled`

### 4. Create a Joomla API token

Create a Joomla API token for a `Super User`.

### 5. Call the MCP endpoint

```bash
curl -X POST https://your-site.example/api/v1/mirasai/mcp \
  -H "Content-Type: application/json" \
  -H "X-Joomla-Token: YOUR_TOKEN" \
  -d '{"jsonrpc":"2.0","method":"tools/list","params":{},"id":1}'
```

If that returns a tool list, the system is live.

## Zero-To-Working Checklist

If you want the slightly less minimal version:

1. Build the package zip.
2. Install it in Joomla.
3. Enable the core plugins.
4. Open `Components > MirasAI`.
5. Verify the endpoint is `/api/v1/mirasai/mcp`.
6. Create a `Super User` API token.
7. Run `tools/list`.
8. If you use YOOtheme, enable the YOOtheme addon.
9. If you use ReReplacer, enable the ReReplacer addon.
10. If production writes are needed, use the elevation flow instead of assuming staging behavior.

## Multilingual Workflows

### Articles and Builder layouts

For `com_content` articles built with YOOtheme Pro, MirasAI keeps the Builder structure intact and only patches translated text.

`content/translate` also supports strict SEO mode through `require_translated_meta_if_source_has_meta`.

### Theme areas

For theme areas such as `footer`, use `theme/extract-to-modules` to move Builder content into per-language Joomla modules.

### Builder templates

YOOtheme Builder templates live in `#__extensions.custom_data.templates`.

If a template contains fixed text, the expected strategy is to duplicate it per language rather than trying to keep one shared static template.

### Builder element operations

The YOOtheme addon can inspect and edit Builder elements at three storage levels:

- page templates via `key`
- article layouts via `article_id`
- YOOtheme Builder modules via `module_id`

Use `template/element-list` first to find a stable element path, then `template/element-read` or `template/element-source-read` to inspect the target. Mutating element tools require a fresh `if_match` ETag and either `dry_run=true` or `confirm_guarded_write=true`.

`template/element-schema` reads the installed YOOtheme runtime element definition. It resolves `${builder.*}` references when possible and returns derived `props_schema`, `element_schema`, and `source_binding_schema` data for agents that need to validate proposed writes before applying them.

Dynamic Source bindings use `props.source` as the canonical storage location. The source workflow is:

1. `template/source-types` to discover provider/query fields.
2. `template/element-source-preview` to preview a binding.
3. `template/element-source-set` to write it with ETag confirmation.
4. `template/element-source-delete` to remove it with ETag confirmation.

### Header menus

`menu/migrate-theme-to-modules` is the intended migration path for YOOtheme-driven single-language menus that need to become multilingual Joomla modules.

## ReReplacer Model

The ReReplacer addon is intentionally conservative.

Phase 1 is meant for:

- inspecting existing items
- creating simple replacements
- updating simple replacements
- publishing or unpublishing items
- reusing existing Conditions sets

It is not meant to expose the full Regular Labs admin UI one-to-one.

Related docs:

- [docs/rereplacer-agent-guide.md](/Users/alexmiras/Documents/Claude%20Code%20Default/MovaMiraAI/docs/rereplacer-agent-guide.md)
- [docs/rereplacer-phase1-spec.md](/Users/alexmiras/Documents/Claude%20Code%20Default/MovaMiraAI/docs/rereplacer-phase1-spec.md)

## Admin UI

The MirasAI dashboard is the human-facing control surface.

It shows:

- overall runtime state
- registry health
- languages and article counts
- grouped tools
- addon status
- client connection snippets

The elevation admin is the separate control surface for production-gated writes.

## Joomla Auto-Updates

The package includes a Joomla update server:

- `https://raw.githubusercontent.com/velisnolis/MirasAI/main/updates/pkg_mirasai.xml`

On install and update, MirasAI also migrates the stored Joomla update site URL to that feed.

Practical effect:

- future package updates can be discovered through normal Joomla update checks
- the package, update feed, GitHub release, and release asset are expected to stay aligned

## Docker Integration Lab

The repo includes a Docker-based integration lab for reproducible bring-up and smoke testing.

Main files:

- `docker-compose.yml`
- `.env.example`
- `docker/build-package.sh`
- `docker/bootstrap-lab.sh`
- `docker/smoke.sh`
- `docker/test-extract-to-modules.sh`
- `docker/test-template-etag.sh`

### Prerequisites

- Docker with Compose
- `curl`
- `php`
- `zip`
- a checkout of this repo
- a local YOOtheme Pro package outside the repo

### Initial setup

1. Copy `.env.example` to `.env`
2. Set secure MySQL and Joomla admin passwords
3. Set `YOOTHEME_PACKAGE_PATH`
4. Run `docker compose up -d`
5. Run `./docker/bootstrap-lab.sh`
6. Run `./docker/smoke.sh`

## Developer Notes

If you are extending MirasAI with a new addon, start here:

- [docs/plugin-developer-guide.md](/Users/alexmiras/Documents/Claude%20Code%20Default/MovaMiraAI/docs/plugin-developer-guide.md)

Canonical addon structure:

- `mirasai_*.xml`
- `provider.php`
- `services/provider.php`
- `src/`

Providers register tools through `ToolProviderInterface`.

## Current Positioning

The mental model should stay simple:

- MirasAI is not “AI inside Joomla”
- it is a Joomla MCP runtime
- the core package gives the AI a small, explicit tool surface
- optional addons expand that surface only when the matching extension exists

That is the product.
