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
