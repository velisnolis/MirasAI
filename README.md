# MirasAI

MirasAI is a Joomla extension package that exposes a focused MCP server for content operations, multilingual workflows, system inspection, and optional YOOtheme-specific tooling.

The current package supports Joomla 5/6 and is designed to work in three modes:

- core-only Joomla sites, without YOOtheme
- Joomla sites with the optional YOOtheme addon enabled
- staging and production sites with different safety rules

## What Changed Recently

The current workspace includes a substantial hardening and packaging pass:

- MCP access is now restricted to `Super Users` via `core.admin`
- environment detection is fail-closed and defaults to `production`
- sandbox writable files are separated from auto-loaded PHP files
- provider loading is centralized in `ToolRegistry`
- the API component packaging/layout is fixed
- the admin dashboard now reflects registry health, configured languages, and addon availability more accurately
- elevation admin actions now enforce ACL checks and the history view no longer uses an `N+1` query

These changes were validated on a private staging/reference Joomla site outside the repository.

## Package Contents

The package installs:

- `lib_mirasai`
- `plg_system_mirasai`
- `plg_webservices_mirasai`
- `com_mirasai`
- optional addon: `plg_mirasai_yootheme`

The example addon in `pkg_mirasai/packages/plg_mirasai_example` is kept as a development reference and is not included in the installable package.

## MCP Surface

MirasAI currently implements a narrow MCP subset:

- `initialize`
- `tools/list`
- `tools/call`
- `ping`

It does not currently implement:

- MCP resources
- MCP prompts
- MCP sampling
- MCP roots

## Core Tools

These tools are available without YOOtheme:

- `system/info`
- `content/list`
- `content/read`
- `content/translate`
- `content/translate-batch`
- `content/check-links`
- `content/audit-multilingual`
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

## Optional YOOtheme Tools

When the YOOtheme addon is installed and available, MirasAI also exposes:

- `theme/extract-to-modules`
- `menu/migrate-theme-to-modules`
- `template/list`
- `template/read`
- `template/translate`

If the addon plugin is installed but YOOtheme is not present, the dashboard should show the addon as `Unavailable` while the core tools remain usable.

## Security Model

### Authentication

MirasAI accepts Joomla API tokens, but only users authorized for `core.admin` are allowed to authenticate to MCP.

Practical effect:

- a valid token from a normal manager/editor is rejected
- `Super Users` can access the MCP endpoint

Relevant code:

- `pkg_mirasai/packages/lib_mirasai/src/Mcp/JoomlaApiTokenAuthenticator.php`

### Environment Gating

Production is the default.

Staging must be configured explicitly through one of:

- MirasAI component config: `environment_override = staging`
- Joomla config: `mirasai_environment_override = staging`
- environment variable: `MIRASAI_ENV=staging`

Relevant code:

- `pkg_mirasai/packages/lib_mirasai/src/Sandbox/EnvironmentGuard.php`
- `pkg_mirasai/packages/com_mirasai/mirasai.xml`

### Sandbox Separation

Writable sandbox files and auto-loaded PHP files are now separated:

- writable workspace: `media/mirasai/sandbox/`
- boot auto-load path: `media/mirasai/autoload/`

Relevant code:

- `pkg_mirasai/packages/lib_mirasai/src/Sandbox/SandboxLoader.php`

### SQL Guarding

`db/query` is still observational only, but it is now stricter:

- only single `SELECT` and `SHOW`
- blocks patterns like `INTO OUTFILE`, `LOAD_FILE`, `SLEEP`, `BENCHMARK`, `FOR UPDATE`, `CALL`, `PREPARE`, and similar dangerous constructs

Relevant code:

- `pkg_mirasai/packages/lib_mirasai/src/Tool/DbQueryTool.php`

## Admin Dashboard

The admin dashboard now aims to describe the real runtime state instead of only extension install state.

It includes:

- global dashboard state: `ACTIVE`, `DEGRADED`, `INACTIVE`
- registry health and warning count
- configured languages, even when a published language has zero articles
- core tools grouped separately from addon tools
- addon/provider accordions with `Active`, `Unavailable`, or `Disabled`
- client connection snippets for common MCP clients

Relevant code:

- `pkg_mirasai/packages/com_mirasai/admin/src/View/Dashboard/HtmlView.php`
- `pkg_mirasai/packages/com_mirasai/admin/tmpl/dashboard/default.php`

## Elevation Admin

The elevation admin UI is meant for production-gated destructive operations.

Recent changes:

- controller actions now enforce ACL checks
- history aggregation no longer performs a query per row
- view strings are much more consistently internationalized

Relevant code:

- `pkg_mirasai/packages/com_mirasai/admin/src/Controller/ElevationController.php`
- `pkg_mirasai/packages/com_mirasai/admin/src/View/Elevation/HtmlView.php`
- `pkg_mirasai/packages/com_mirasai/admin/tmpl/elevation/default.php`
- `pkg_mirasai/packages/com_mirasai/admin/tmpl/elevation/confirm.php`

## Installation

### Joomla Admin

1. Build the package zip
2. In Joomla admin, install the generated `pkg_mirasai-lab.zip`
3. Enable:
   - `plg_system_mirasai`
   - `plg_webservices_mirasai`
4. If you use YOOtheme, also enable:
   - `plg_mirasai_yootheme`
5. Open `Components > MirasAI`

### Post-Install Checks

1. Confirm the dashboard loads
2. Confirm the MCP endpoint appears as:
   - `/api/v1/mirasai/mcp`
3. Create a Joomla API token for a `Super User`
4. Test `tools/list`

Example:

```bash
curl -X POST https://your-site.example/api/v1/mirasai/mcp \
  -H "Content-Type: application/json" \
  -H "X-Joomla-Token: YOUR_TOKEN" \
  -d '{"jsonrpc":"2.0","method":"tools/list","params":{},"id":1}'
```

## Building The Installable Package

Build the package with:

```bash
./docker/build-package.sh
```

Output:

- `.docker-build/pkg_mirasai-lab.zip`

The build script assembles the component admin files, API files, library, and plugins into a Joomla package zip.

Relevant file:

- `docker/build-package.sh`

## Multilingual Workflows

### Articles And Builder Layouts

For `com_content` articles built with YOOtheme Pro, MirasAI keeps the Builder structure intact and only replaces translated text. It can also audit or repair internal links after translation.

`content/translate` also supports a strict SEO mode via `require_translated_meta_if_source_has_meta`. When enabled, the tool refuses to create or overwrite a translation unless translated SEO metadata is provided for any source `metadesc` or `metakey` fields that are already filled.

### Theme Areas

For Builder-driven theme areas such as `footer`, use `theme/extract-to-modules` to move the area into per-language `mod_yootheme_builder` modules and replace the inline theme layout with a `module_position` wrapper.

### Builder Templates

YOOtheme Builder templates live in `#__extensions.custom_data.templates`.

MirasAI treats them as multilingual-ready only when:

- no template exists for that assignment, or
- the template is fully dynamic and shared across all languages

If a template contains fixed text, the current strategy is to duplicate it per language and assign the target language directly on the template.

### Header Menus

For Joomla sites that started as single-language YOOtheme installs, the expected multilingual end state is:

- `config.menu.positions.navbar.menu = ""`
- `config.menu.positions.dialog-mobile.menu = ""`
- one published `mod_menu` per language at `navbar`
- one published `mod_menu` per language at `dialog-mobile`

This workflow is handled by `menu/migrate-theme-to-modules`.

The tool:

- supports `dry_run`
- reuses compatible `mod_menu` modules when safe
- allows `navbar` and `dialog-mobile` to resolve different Joomla `menutype` values when the site uses separate desktop/mobile menu trees
- accepts `menutype_map` either as `language => menutype` or `position => { language => menutype }`
- marks managed modules with `note = "mirasai:menu_position=<position>;menutype=<menutype>"`
- clears the YOOtheme menu assignments only after all required per-language modules are resolved

## Reference Validation State

The current package has been validated against a private staging/reference Joomla site for multilingual and dashboard behavior.

Known good reference state:

- core extensions enabled
- MCP endpoint responding
- dashboard loading with grouped tool accordions
- `tools/list` returning the full registered tool set

## Docker Integration Lab

The repository includes a Docker-based integration lab intended to run canonically inside a Proxmox VM.

This lab is primarily for:

- reproducible Joomla 5/6 + MySQL bring-up
- installing YOOtheme Pro from a local package outside the repo
- installing the current MirasAI workspace into a fresh Joomla instance
- running minimal smoke checks against the MCP endpoint

It is not meant to replace staging QA.

### Files

- `docker-compose.yml`
- `.env.example`
- `docker/build-package.sh`
- `docker/bootstrap-lab.sh`
- `docker/smoke.sh`

### Prerequisites

- Docker with Compose available on the host VM
- `curl`, `php`, and `zip`
- a checked-out copy of this repository
- a local YOOtheme Pro package path available on the VM, outside the repo

### Initial Setup

1. Copy `.env.example` to `.env`
2. Set secure MySQL and Joomla admin passwords
3. Set `YOOTHEME_PACKAGE_PATH` to the absolute path of a local YOOtheme Pro zip or extracted folder
4. Run `docker compose up -d`
5. Run `./docker/bootstrap-lab.sh`
6. Run `./docker/smoke.sh`
