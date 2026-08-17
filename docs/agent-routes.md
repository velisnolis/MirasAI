# Agent routes after installing MirasAI

Call `system/diagnose` and read `playbook`. Do not invent a Customizer, WP-CLI, or SQL path when a listed tool already covers the job.

This document matches the MCP playbook on both hosts (`playbook.version` 3). Production agents never see the git repo; the live source of truth is `system/diagnose.playbook`.

## Detect the channel from tools you can see

| You have | How you know | Style compiler? |
| --- | --- | --- |
| MirasAI host only | `initialize.serverInfo.name` is `MirasAI`. `tools/list` has `template/style-update` and **no** `mirasai/style-preview`. | No |
| Host + mcp2cli against the CMS URL | `mcp2cli --transport streamable --mcp https://SITE/.../mcp --list` looks like the host. | No |
| Host + local router | `initialize.serverInfo.name` is `@miras/mirasai-mcp`, or `tools/list` includes `mirasai/style-preview`. mcp2cli pointed at `mirasai-mcp serve` (stdio) is this channel. | Yes |
| SSH | Shell on the CMS server. | No |

Having mcp2cli does not mean you have the compiler. It means you can call whichever MCP you pointed it at.

## What each environment can actually do

Capabilities are not “MirasAI is installed ⇒ everything works”. They depend on the channel, auth, and (for Style) a pinned local worker.

| Job | Host HTTP only | mcp2cli → host URL | Router / mcp2cli → `mirasai-mcp serve` | SSH | Cloud agent with no 1Password/SSH |
| --- | --- | --- | --- | --- | --- |
| Inspect site | `system/diagnose` if authenticated | same | `mirasai/host-diagnose` | unnecessary | Host URL only, if you have a token in that environment |
| Content / translation | `content/*` | same | same, proxied | unnecessary | same as host, if authenticated |
| YOOtheme **Builder** | `template/element-*` with `if_match` + dry-run + confirm | same | same | do not edit JSON/SQL | same as host |
| YOOtheme **Style** read | `template/style-read` | same | same | optional: first line of `theme.<id>.css` | same as host |
| YOOtheme **Style** write / recompile | **STOP** | **STOP** | `mirasai/style-update` | do not write config | **Cannot compile.** Stop. |
| Prove CSS changed | CSS `compiled on` header via `file/read` | same | `mirasai/style-verify` then the header | `head -n1` of the CSS file | same as host |
| Purge HTML cache after CSS write | not implemented on the host | same | same | WP Rocket: `rocket_clean_domain()` via `wp eval` | not from this environment |

Builder JSON and Style `theme.css` are different systems. Changing an element does not compile LESS. Changing `@less` / `custom_less` does not update the Builder.

## Depends on

**Host HTTP**

- Authenticated MCP. WordPress: Application Password + `manage_options`. Joomla: Super User API token.
- Anonymous `401`/`503` is a lock or missing auth, not “plugin missing”. Locked staging sites often 503 the frontend and unauthenticated REST while authenticated MCP still works.
- The files on disk. A GitHub Release ZIP older than this playbook will not mention it.

**Router (the only Style compiler)**

- A **local** Node.js 20+ process running `@miras/mirasai-mcp`.
- `sites.json` entry for **this** `site_id`. `default_site_id` may be a different site — pass `site_id` on every call.
- `style_worker_sha256` pinned to **that** site’s `assets/admin/js/worker.js`. Re-pin after a YOOtheme upgrade (`style_worker_hash_mismatch` is a stop, not a prompt to skip the pin).
- Host credentials (`op://` or env). A pin without credentials still cannot call the host.

**mcp2cli**

- Same as whichever URL or stdio target you passed.
- `--dry-run` is `store_true`. Omitting it still sends `dry_run=true` to the tool. A real write needs JSON with `dry_run=false` and `confirm_guarded_write=true` (typically `--stdin`).

**SSH**

- A working **user** key, not a `Host` alias that forces root.
- CageFS WP-CLI when the account is jailed.
- Can backup, read the CSS header, purge page cache. Cannot regenerate YOOtheme CSS.

**WordPress Style storage**

- Live Style JSON is `theme_mods_{get_stylesheet()}.config` once that stylesheet has initialized its own config.
- A child theme is **not** `theme_mods_yootheme`. Writing the parent row while `get_theme_mod('config')` reads the child aborts with `stale_etag` / “Style config changed at the write gate”.
- A freshly activated child can have no config yet and read the parent as a fallback. In that state `style-read.storage` reports `inherited_from_parent=true` and `write_safe=false`; stop rather than mutating the parent implicitly.
- The `yootheme` option holds Builder templates, not Style.

**Proof of a Style write**

- `style-read` shows the new var and `storage.option`.
- `style-read.compiled.config_freshness.state` is `fresh` after a router-controlled write. `stale` proves later config drift; `unknown` means no usable provenance or CSS changed outside the router.
- CSS first line: `/* YOOtheme Pro v… compiled on <ISO8601> */` moved.
- `#fff` vs `#FFFFFF` (and similar hex minify) may leave CSS bytes unchanged. That is not a failed write. Do not use a screenshot as the test.

## Do not enter these loops

1. **Customizer `save()` with `dirty=false`** — returns success and writes nothing. `change()` only marks dirty; it does not start less.js. Touch a real control and undo only as a last-resort browser path when the router is unavailable.
2. **WP-CLI / SQL Style config** — updates the DB; the site keeps serving stale `theme.<id>.css` forever. `style-read.stale_sources` stays `false`, but router provenance now makes `compiled.config_freshness.state=stale` when the CSS artefacts still match the last controlled compile.
3. **Host `template/style-update` without compiled CSS** — that tool stores CSS the router already compiled. It is not a compiler.
4. **Treating mcp2cli → host as the router** — you will see `template/style-*` and miss `mirasai/style-*`.
5. **Writing `theme_mods_yootheme` on a child theme** — CAS compares the wrong option. Use `storage.option` from `style-read`.
6. **Omitting `site_id` or skipping a worker re-pin** — compile/write hits the default site, or refuses the worker.
7. **Omitting `dry_run=false` in JSON** — the call succeeds as a dry-run; disk unchanged.
8. **Writing while `storage.write_safe=false`** — the child has no initialized config and reads the parent fallback. Stop and initialize the child through a separate guarded workflow.

## Last-resort browser recipe

Only when `mirasai/style-preview` is not in `tools/list`:

1. Do not write Style config first and hope.
2. Open the Customizer (`&site=<url>`), wait for the iframe.
3. Change a real control and undo it.
4. `await window.yootheme.store.useConfigStore().save()`.
5. Check `/* YOOtheme Pro v… compiled on … */` on the CSS file.
6. Purge page cache (WP Rocket: `rocket_clean_domain()`).

Prefer installing `@miras/mirasai-mcp` with a pinned `style_worker_sha256` over this recipe.
