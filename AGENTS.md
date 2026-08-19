## Learned User Preferences
- Prefer mcp2cli when available; it is not the Style compiler unless pointed at the local router (`mirasai-mcp serve`), not the host MCP URL. After host schema changes, pass `--refresh`.
- After install, agents should follow `system/diagnose` playbook / `docs/agent-routes.md` instead of inventing Customizer, WP-CLI, or SQL paths.
- PRs and release notes should state real capabilities per environment (host-only, router, SSH, cloud), not imply full Style compile from a host install.
- Use `/handoff` for a Codex second opinion on product bets before implementing.
- Prefer a local agent when the work needs SSH or 1Password; cloud agents without those cannot compile YOOtheme Style.

## Learned Workspace Facts
- MirasAI is a controlled MCP contract for Joomla + WordPress with YOOtheme as the specialty; hosts do not compile LESS. The local router `@miras/mirasai-mcp` is the only Style compiler (`mirasai/style-preview` / `mirasai/style-update`).
- YOOtheme Pro compiles LESS in the Customizer (less.js). WP-CLI or SQL Style writes leave stale `theme.<id>.css`; Customizer `save()` with `dirty=false` is a silent no-op.
- WordPress Style JSON lives in `theme_mods_{stylesheet}.config`, not Builder’s `yootheme` option. Do not write `theme_mods_yootheme` on a child theme; `write_safe=false` / inherited parent config means stop.
- Guarded writes use dry-run, confirm, and ETag/CAS. Builder JSON and Style CSS are separate systems; changing an element does not compile LESS.
- NovaMira (`use-novamira/novamira`, AGPL-3.0) and YT Builder MCP/API Mapper are reference only: never port code (no PHP-first eval, Gutenberg, in-CMS Chat, or dynamize). Fossat is guarded Builder writes plus the local Style compiler.
- Production agents’ live source of truth is `system/diagnose.playbook`, not the git repo. Keep MCP `initialize` short.
- CageFS accounts need WP-CLI via the cPanel PHP binary; the plain `wp` wrapper can fail. SSH as the cPanel user with IdentitiesOnly; never the `server.miras.pro` Host alias (it forces root). SSH cannot regenerate YOOtheme CSS.
- `template/clone-rebind` is deferred, not implemented; do not call it, and do not call it «dynamize». The 2026-08-19 review cut its two `scope` modes in favour of a fail-closed batch form of `template/element-source-set`; see `docs/template-clone-rebind.md`.
- `template/element-clone` renames a cloned `props.id` that would collide and repoints `#anchors` inside the copy, reporting `renamed_ids`. Check that field before trusting an anchor in a clone.
- `template/read` and `template/element-list` support `mode=full|outline|bindings_only`; ETag is always the full layout. Both carry `status` on a disabled element, `outline` adds `has_source_binding`, and `bindings_only` adds `disabled_by` for a binding sitting inside a disabled ancestor. Each is omitted when it would say nothing.
- Never rsync or `docker cp` the full repo onto the Joomla lab with `--delete`.
