# MirasAI

MirasAI is a Joomla extension package that exposes a focused MCP server for multilingual content operations, with first-class support for YOOtheme Pro content and theme configuration.

## Current MCP Subset

MirasAI implements a narrow MCP v1 subset:

- `initialize`
- `tools/list`
- `tools/call`
- `ping`

It does not currently implement:

- MCP resources
- MCP prompts
- MCP sampling
- MCP roots

## Current Built-In Tools

- `system/info`
- `content/list`
- `content/read`
- `content/translate`
- `content/translate-batch`
- `content/check-links`
- `content/audit-multilingual`
- `category/translate`
- `site/setup-language-switcher`
- `theme/extract-to-modules`
- `menu/migrate-theme-to-modules`
- `template/list`
- `template/read`
- `template/translate`

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

If a template contains fixed text, the v1 strategy is to duplicate it per language and assign the target language directly on the template.

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

## Boira Reference State

The staging site at [boiraesdeveniments.com](https://www.boiraesdeveniments.com/) is the reference validation case for the header-menu workflow.

Correct state on that site:

- YOOtheme menu assignments for `navbar` and `dialog-mobile` are already empty
- per-language `mod_menu` modules exist for `ca-ES`, `es-ES`, and `en-GB`
- the migration tool should return a no-op style dry run (`already_cleared` plus module reuse, no creation)

## Notes

- Module translation outside the header-menu workflow, such as multilingual footer or other shared modules, remains a separate concern from article translation.
- The standalone endpoint at `mcp-endpoint.php` is kept aligned with the same tool registry as the Joomla plugin entrypoints.

## Docker Integration Lab

The repository includes a Docker-based integration lab intended to run canonically inside a Proxmox VM.

This lab is primarily for:

- reproducible Joomla 5 + MySQL bring-up
- installing YOOtheme Pro from a local package outside the repo
- installing the current MirasAI workspace into a fresh Joomla instance
- running minimal smoke checks against the MCP endpoint

It is not meant to replace staging QA. The primary goal is integration safety and a reproducible local-ish lab.

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
- if the host sits behind the Spanish Cloudflare/R2 ISP blocks, Docker may need Cloudflare WARP or equivalent egress bypass to pull `mysql:8.4` and `joomla:5-apache`

### Initial Setup

1. Copy `.env.example` to `.env`
2. Set secure MySQL and Joomla admin passwords
3. Keep `.env` values shell-safe. In particular, avoid unquoted spaces in values such as `JOOMLA_SITE_NAME` or `JOOMLA_ADMIN_NAME`
4. Set `YOOTHEME_PACKAGE_PATH` to the absolute path of a local YOOtheme Pro zip or extracted folder
5. Run `docker compose up -d`
6. Run `./docker/bootstrap-lab.sh`
7. Run `./docker/smoke.sh`

The bootstrap script is designed to be idempotent:

- it skips Joomla install if `configuration.php` already exists
- it rebuilds the current MirasAI package from the workspace
- it installs the MirasAI runtime pieces needed for MCP (`lib_mirasai`, `plg_system_mirasai`, `plg_webservices_mirasai`) and attempts `com_mirasai` as a best-effort optional step
- it provisions a Joomla API token for the configured admin user and writes the MCP token to `.docker-build/mcp-token.txt`

### Proxmox Host Model

The canonical deployment model for this lab is:

- Proxmox host provides the persistent VM
- Docker runs inside that VM
- the repository defines the lab with Compose and scripts
- YOOtheme Pro is provided via a host-local path configured in `.env`

This keeps the repo portable while still optimizing for a shared, persistent integration host.
