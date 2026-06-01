# MirasAI

MirasAI is a multi-platform MCP toolkit for controlled AI access to CMS sites.

The current release is `0.5.4`. It includes:

- a production Joomla host package;
- a WordPress host plugin;
- a shared host contract;
- a local multi-site MCP router that can expose many Joomla and WordPress installations through one client-facing MCP server.

Use it on staging first. Use backups. Treat production as a gated environment.

## Install And Update URLs

Public release assets live on GitHub Releases:

```text
https://github.com/velisnolis/MirasAI/releases
```

Latest install/update endpoints:

| Target | Install asset | Automatic update feed |
| --- | --- | --- |
| Joomla host package | `pkg_mirasai-<version>.zip` from the matching GitHub release | `https://raw.githubusercontent.com/velisnolis/MirasAI/main/updates/pkg_mirasai.xml` |
| WordPress host plugin | `mirasai-wp-<version>.zip` from the matching GitHub release | `https://raw.githubusercontent.com/velisnolis/MirasAI/main/updates/mirasai-wp.json` |
| Local MCP router | `miras-mirasai-mcp-<version>.tgz` from the matching GitHub release | No feed; install the release tarball or use the repo checkout. |

Joomla reads its XML feed through the package update server. WordPress reads its JSON feed through the bundled MirasAI updater.

## Architecture

MirasAI is built around a small host contract. Each CMS exposes a local HTTP MCP endpoint, and the optional router gives AI clients one stable MCP server that routes calls by `site_id`.

```text
AI client
  -> @miras/mirasai-mcp
    -> Joomla MirasAI host
    -> WordPress MirasAI host
```

Hosts can still be used directly over HTTP MCP. The router is the preferred operator experience when you manage more than one site or mix Joomla and WordPress.

## Repository Layout

- `packages/mirasai-joomla/`: Joomla installable package.
- `package.json`: npm workspace entrypoint for shared package checks.
- `packages/mirasai-wp/`: WordPress host plugin.
- `packages/mirasai-mcp/`: local multi-site MCP router.
- `packages/mirasai-contract/`: shared host MCP contract, schemas, and fixtures.
- `packages/README.md`: package map and workspace commands.
- `docker/`: Joomla integration lab and packaging scripts.
- `docs/`: addon and operational notes.

## Current Status

| Area | Joomla host | WordPress host | Router |
| --- | --- | --- | --- |
| Version | `0.5.4` | `0.5.4` | `0.5.4` |
| Endpoint | `/api/v1/mirasai/mcp` | `/wp-json/mirasai/v1/mcp` | stdio MCP |
| Auth | Joomla API token, Super User gated | WordPress Application Password, `manage_options` gated | 1Password/env/dev secret refs |
| Dashboard | Full admin dashboard, onboarding, status, elevation | Compact onboarding/status dashboard | CLI registry |
| Automatic updates | XML feed verified on `autovigatana` and `gibaix` | JSON feed verified on `jordifont` and `crisalide` | No feed; install release tarball |
| CMS content | Articles, categories, multilingual workflows | Posts/pages, terms, WPML/Polylang workflows | Routes host tools |
| YOOtheme | Templates, articles, Builder modules | Templates, pages/posts, Builder widgets | Routes host tools |
| Dynamic Sources | Read, preview, set, delete bindings | Read, preview, set, delete bindings | Routes host tools |
| Runtime schema | YOOtheme element schema | YOOtheme element schema | Host discovery |
| ReReplacer | Supported via addon | Not applicable | Routes host tools |
| ACF / WP abilities | Not applicable | Read-only ACF tools and guarded WP abilities bridge | Routes host tools |
| Dangerous execution | Joomla sandbox/elevation tools | Domain-locked `sandbox/execute-php` behind dangerous-exec toggle | Does not add dangerous tools |

## MCP Surface

MirasAI hosts implement the practical subset of MCP used by agents:

- `initialize`
- `tools/list`
- `tools/call`
- `ping`

Not implemented by the hosts:

- resources
- prompts
- sampling
- roots

Tool metadata follows the shared risk model:

- `read`: inspection only.
- `safe_write`: constrained CMS write through a domain-specific tool.
- `guarded_write`: persistent write requiring preview, ETag, or explicit confirmation.
- `dangerous_exec`: file mutation, deletion, PHP execution, or runtime/code persistence.

Every tool also exposes:

- `metadata.workflow_hint`
- `metadata.surface`
- MCP `annotations` for read/destructive/idempotent hints where possible.

Clients can request a smaller discovery set with:

```json
{"surface":"essential"}
```

## Local Router

The router is the single-client MCP for multi-site work.

Default registry:

```text
~/.config/mirasai-mcp/sites.json
```

Example:

```json
{
  "schema_version": 1,
  "default_site_id": "jordifont-wp",
  "sites": [
    {
      "site_id": "jordifont-wp",
      "label": "Jordi Font WordPress",
      "platform": "wordpress",
      "url": "https://www.jordifont.com/wp-json/mirasai/v1/mcp",
      "basic_ref": "op://vault/item/field",
      "secret_ttl_seconds": 3600
    },
    {
      "site_id": "autovigatana",
      "label": "Autovigatana",
      "platform": "joomla",
      "url": "https://www.autovigatana.cat/api/v1/mirasai/mcp",
      "token_ref": "op://vault/item/field",
      "secret_ttl_seconds": 3600
    }
  ]
}
```

Supported secret sources:

- `token_ref`: 1Password reference for header-token auth.
- `token_env`: environment variable for header-token auth.
- `token_plain_dev`: development-only clear token.
- `basic_ref`: 1Password reference containing `username:application-password`.
- `basic_env`: environment variable containing `username:application-password`.
- `basic_plain_dev`: development-only clear Basic credentials.

1Password references are cached in memory by the running router process. The default TTL is `3600` seconds. Set `secret_ttl_seconds: 0` per site, or `MIRASAI_MCP_SECRET_TTL_SECONDS=0` globally, to disable the cache. Secrets are never written to disk by the router.

Common commands:

```bash
npm run test:mcp

node packages/mirasai-mcp/bin/mirasai-mcp.mjs list-sites
node packages/mirasai-mcp/bin/mirasai-mcp.mjs test-site jordifont-wp
node packages/mirasai-mcp/bin/mirasai-mcp.mjs serve
```

## Joomla Host

The Joomla package installs:

- `lib_mirasai`
- `plg_system_mirasai`
- `plg_webservices_mirasai`
- `com_mirasai`
- `plg_mirasai_yootheme`
- `plg_mirasai_rereplacer`

Joomla endpoint:

```text
/api/v1/mirasai/mcp
```

Authentication uses a Joomla API token. The authenticated user must be authorized for `core.admin`; in practice, use a `Super User` token.

Build:

```bash
npm run build:joomla
```

Build output:

- `.docker-build/pkg_mirasai-<version>.zip`
- `.docker-build/pkg_mirasai-lab.zip`
- `updates/pkg_mirasai.xml`

Install in Joomla:

1. Go to `System > Install > Extensions`.
2. Install `pkg_mirasai-<version>.zip`.
3. Confirm `plg_system_mirasai` and `plg_webservices_mirasai` are enabled.
4. Enable optional addons if needed:
   - `plg_mirasai_yootheme`
   - `plg_mirasai_rereplacer`
5. Open `Components > MirasAI`.
6. Create a Super User API token.
7. Run `tools/list` or `system/diagnose`.

Minimal direct call:

```bash
curl -X POST https://your-site.example/api/v1/mirasai/mcp \
  -H "Content-Type: application/json" \
  -H "X-Joomla-Token: YOUR_TOKEN" \
  -d '{"jsonrpc":"2.0","method":"tools/list","params":{"surface":"essential"},"id":1}'
```

## WordPress Host

The WordPress host is in `packages/mirasai-wp/`.

WordPress endpoint:

```text
/wp-json/mirasai/v1/mcp
```

Preferred authentication is a WordPress Application Password:

```text
Authorization: Basic base64(username:application-password)
```

The authenticated WordPress user must have `manage_options`.

Build and package:

```bash
npm run lint:wp
npm run build:wp
```

ZIP output:

```text
packages/mirasai-wp/dist/mirasai-wp-0.5.4.zip
```

The WordPress admin dashboard includes:

- endpoint/status overview;
- Application Password creation and revocation;
- direct MCP client snippets;
- YOOtheme layout counts for templates, pages/posts, and Builder widgets;
- multilingual, ACF, sandbox, and elevation status;
- a dangerous-execution control-plane toggle that exposes `sandbox/execute-php` only for the locked current domain.

## Tool Highlights

### Content and Multilingual

Joomla:

- list/read articles;
- translate articles and categories;
- batch translation workflows;
- multilingual audit;
- internal link checks.

WordPress:

- list/read posts and pages;
- translate posts/pages with WPML or Polylang;
- translate terms;
- multilingual audit;
- internal link checks.

MirasAI does not auto-translate content. Tools expect translated text from the agent or caller.

### YOOtheme

Both hosts expose YOOtheme Builder tooling with ETag-protected writes.

Joomla targets:

- templates by `key`;
- article layouts by `article_id`;
- Builder modules by `module_id`.

WordPress targets:

- templates by `key`;
- page/post layouts by `post_id`;
- Builder widgets by `widget_id`.

Common workflow:

1. `template/list`
2. `template/summary` or `template/element-list`
3. `template/element-read`
4. `template/element-schema` if a write needs runtime prop schema
5. write with `if_match` and either `dry_run:true` or `confirm_guarded_write:true`

Element tools include add, update props, move, clone, delete, Dynamic Source preview/set/delete, and translation-oriented reads.

### ReReplacer

Joomla can expose ReReplacer and Regular Labs Conditions through `plg_mirasai_rereplacer`.

The addon focuses on:

- inspecting existing items;
- creating simple replacements;
- updating simple replacements;
- publishing/unpublishing;
- reusing existing Conditions sets.

Related docs:

- [docs/rereplacer-agent-guide.md](docs/rereplacer-agent-guide.md)
- [docs/rereplacer-phase1-spec.md](docs/rereplacer-phase1-spec.md)

### WordPress ACF and Abilities

The WordPress host exposes optional read-only ACF tools when ACF/ACF PRO is installed.

It also discovers WordPress Abilities API abilities and applies a MirasAI policy before allowing `wp/ability-call`. Read-only abilities are allowed; non-destructive `ai/*` generation abilities can be allowed as `safe_write`; destructive or unknown non-read-only abilities are blocked.

## Safety Model

Production is the default.

Joomla staging can be configured explicitly through one of:

- MirasAI component config: `environment_override = staging`
- Joomla config: `mirasai_environment_override = staging`
- environment variable: `MIRASAI_ENV=staging`

Important safeguards:

- write tools are classified by risk level;
- guarded writes require preview/confirmation and ETags where applicable;
- `file/read` blocks common secret-bearing paths such as CMS config variants, `.env`, private keys, certificates, and SQL dumps;
- Joomla `dangerous_exec` tools require elevation in production;
- WordPress `sandbox/execute-php` is hidden unless dangerous execution is enabled for the current domain.

`sandbox/execute-php` on Joomla and WordPress is transaction-wrapped PHP execution, not an isolated security sandbox. It runs in the CMS PHP worker process and must pass `confirm_execute_php=true`.

## Elevation

Elevation is the production approval layer for `dangerous_exec` operations.

It is not a generic admin mode. It exists for operations that can persist code, delete files, execute PHP, or change runtime behavior in ways that are hard to roll back.

Good reasons to use elevation:

- inspect or patch a sandboxed file as part of a controlled fix;
- run a temporary migration helper;
- test a custom integration against a live Joomla runtime after review.

Bad reasons:

- to skip staging;
- to run arbitrary PHP because it is convenient;
- to treat production as the development environment.

## Update Feeds

The Joomla package includes an update server:

```text
https://raw.githubusercontent.com/velisnolis/MirasAI/main/updates/pkg_mirasai.xml
```

The WordPress plugin includes an updater that reads:

```text
https://raw.githubusercontent.com/velisnolis/MirasAI/main/updates/mirasai-wp.json
```

The package files, update feeds, GitHub release, and release assets should stay aligned. Use:

```bash
npm run release:prepare
```

The script builds the Joomla ZIP, WordPress ZIP, local MCP router tarball, updates both feeds, and writes release helper notes under `.release/v<version>/`.

## Docker Integration Lab

The repo includes a Docker-based Joomla integration lab for reproducible bring-up and smoke testing.

Main files:

- `docker-compose.yml`
- `.env.example`
- `docker/build-package.sh`
- `docker/bootstrap-lab.sh`
- `docker/smoke.sh`
- `docker/test-extract-to-modules.sh`
- `docker/test-template-etag.sh`

Initial setup:

1. Copy `.env.example` to `.env`.
2. Set secure MySQL and Joomla admin passwords.
3. Set `YOOTHEME_PACKAGE_PATH`.
4. Run `docker compose up -d`.
5. Run `./docker/bootstrap-lab.sh`.
6. Run `./docker/smoke.sh`.

## Development

Useful checks:

```bash
./docker/test-local-contracts.sh

npm test
npm run build:joomla
npm run build:wp
npm run check:packages
```

If you are extending MirasAI with a Joomla addon, start with:

- [docs/plugin-developer-guide.md](docs/plugin-developer-guide.md)

Canonical Joomla addon structure:

- `mirasai_*.xml`
- `provider.php`
- `services/provider.php`
- `src/`

Providers register tools through `ToolProviderInterface`.

## Positioning

MirasAI is not “AI inside Joomla” or “AI inside WordPress”.

It is a controlled MCP runtime for CMS operations:

- hosts expose a narrow, explicit tool surface;
- addons expand that surface only when the matching CMS extension exists;
- the local router gives operators a single MCP entrypoint for multiple installations;
- high-risk operations stay gated instead of becoming ambient agent permissions.
