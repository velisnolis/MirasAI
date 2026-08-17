# Agent routes after installing MirasAI

Call `system/diagnose` and read `playbook`. Do not invent a Customizer, WP-CLI, or SQL path when a listed tool already covers the job.

This document matches the MCP playbook on both hosts. Production agents never see the git repo; the live source of truth is `system/diagnose.playbook`.

## Detect the channel from tools you can see

| You have | How you know | Style compiler? |
| --- | --- | --- |
| MirasAI host only | `initialize.serverInfo.name` is `MirasAI`. `tools/list` has `template/style-update` and **no** `mirasai/style-preview`. | No |
| Host + mcp2cli against the CMS URL | `mcp2cli --transport streamable --mcp https://SITE/.../mcp --list` looks like the host. | No |
| Host + local router | `initialize.serverInfo.name` is `@miras/mirasai-mcp`, or `tools/list` includes `mirasai/style-preview`. mcp2cli pointed at `mirasai-mcp serve` (stdio) is this channel. | Yes |
| SSH | Shell on the CMS server. | No |

Having mcp2cli does not mean you have the compiler. It means you can call whichever MCP you pointed it at.

## Jobs

| Job | Host only | Host + mcp2cli → host URL | Host + router / mcp2cli → `mirasai-mcp serve` | SSH |
| --- | --- | --- | --- | --- |
| Inspect site | `system/diagnose` | same | `mirasai/host-diagnose` | unnecessary |
| Content / translation | `content/*` | same | same, proxied | unnecessary |
| YOOtheme **Builder** (layouts, props, sources) | `template/element-*` with `if_match` + dry-run + confirm | same | same | do not edit JSON/SQL |
| YOOtheme **Style** read | `template/style-read` | same | same | optional: first line of `theme.<id>.css` |
| YOOtheme **Style** write / recompile | **STOP** | **STOP** | `mirasai/style-update` (empty vars = recompile current config) | do not write config |
| Prove CSS changed | CSS `compiled on` header via `file/read` | same | `mirasai/style-verify` then the header | `head -n1` of the CSS file |
| Purge HTML cache after CSS write | not implemented on the host | same | same | WP Rocket: `rocket_clean_domain()` via `wp eval` |

Builder JSON and Style `theme.css` are different systems. Changing an element does not compile LESS. Changing `@less` / `custom_less` does not update the Builder.

## Do not enter these loops

1. **Customizer `save()` with `dirty=false`** — returns success and writes nothing. `change()` only marks dirty; it does not start less.js. Touch a real control and undo only as a last-resort browser path when the router is unavailable.
2. **WP-CLI / SQL Style config** — updates the DB; the site keeps serving stale `theme.<id>.css` forever. `style-read.stale_sources` stays `false` because it compares CSS mtime with Less *files*, not with stored config.
3. **Host `template/style-update` without compiled CSS** — that tool stores CSS the router already compiled. It is not a compiler.
4. **Treating mcp2cli → host as the router** — you will see `template/style-*` and miss `mirasai/style-*`.

## Last-resort browser recipe

Only when `mirasai/style-preview` is not in `tools/list`:

1. Do not write Style config first and hope.
2. Open the Customizer (`&site=<url>`), wait for the iframe.
3. Change a real control and undo it.
4. `await window.yootheme.store.useConfigStore().save()`.
5. Check `/* YOOtheme Pro v… compiled on … */` on the CSS file.
6. Purge page cache (WP Rocket: `rocket_clean_domain()`).

Prefer installing `@miras/mirasai-mcp` with a pinned `style_worker_sha256` over this recipe.
