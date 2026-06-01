# MirasAI WordPress Host

This package is the WordPress host plugin for the MirasAI multi-platform architecture.

Current internal version: `0.5.0`.

It is not a wrapper around Novamira or the WordPress MCP Adapter. It implements the MirasAI Host MCP Contract directly so the local `@miras/mirasai-mcp` router can treat WordPress and Joomla hosts consistently.

## Endpoint

```text
/wp-json/mirasai/v1/mcp
```

## Build

```bash
npm run lint:php
npm run build:zip
```

The ZIP is written to:

```text
dist/mirasai-wp-0.5.3.zip
```

Authenticate with:

```text
Authorization: Basic base64(username:application-password)
```

The authenticated WordPress user must have `manage_options`.

This mirrors the Joomla host model: the secret belongs to a CMS user, is revocable/rotatable in the CMS, and access is gated by CMS permissions.

Fallback development auth is still supported:

```text
X-MirasAI-Token: <token>
```

Fallback token sources:

- `MIRASAI_WP_TOKEN` PHP constant
- `MIRASAI_WP_TOKEN` environment variable
- `mirasai_wp_token_hash` WordPress option, checked with `wp_check_password()`

The WordPress admin dashboard includes a compact onboarding surface for:

- generating and revoking MirasAI WordPress Application Passwords;
- copying direct HTTP MCP config snippets for Codex/Claude-style clients and JSON clients;
- checking endpoint, tool, YOOtheme, multilingual, sandbox, and elevation status;
- toggling the dangerous-execution control plane. This toggle records the
  current domain and exposes `sandbox/execute-php` only while the domain lock
  matches.

## Current Tools

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
- `wp/abilities/list`
- `wp/ability-call`
- `template/list`
- `template/read`
- `template/summary`
- `template/element-list`
- `template/element-read`
- `template/element-types`
- `template/element-schema`
- `template/source-types`
- `template/element-source-read`
- `template/element-source-preview`
- `template/element-source-set`
- `template/element-source-delete`
- `template/element-add`
- `template/element-update-props`
- `template/element-move`
- `template/element-clone`
- `template/element-delete`
- `template/translate`
- `template/widget-translate`
- `acf/status`
- `acf/field-groups/list`
- `acf/field-group/read`
- `acf/post-fields/read`
- `acf/cpt/list`
- `acf/taxonomy/list`
- `db/schema`
- `db/query`
- `file/list`
- `file/read`
- `sandbox/status`
- `elevation/status`
- `sandbox/execute-php` (only listed when dangerous execution is enabled for the current domain)

Most tools are read-only. `wp/ability-call` is `safe_write` for explicit
allowlisted abilities, and YOOtheme write tools are `guarded_write`: they require
`if_match` plus `dry_run` or `confirm_guarded_write`.

`sandbox/status` and `elevation/status` report the sandbox directory, safe-mode
marker, PHP lint availability, and domain-locked dangerous-execution control
state.

`sandbox/execute-php` is hidden unless dangerous execution is enabled for the
current domain. It executes PHP in-process through `eval()` with WordPress
runtime access and DB transaction wrapping. This is not an isolated security
sandbox. Each call must pass `confirm_execute_php=true`.

`content/translate` is a `safe_write` tool for WPML/Polylang sites. It creates
or updates a target-language post/page from a source post, requires the source
`if_match` etag from `content/read` or `content/list`, supports `dry_run:true`,
and does not auto-translate. For YOOtheme Builder posts, call `content/read`
first and pass `yootheme_text_replacements` keyed by
`yootheme_translatable_nodes[].replacement_key`.

`content/translate-batch` runs multiple `content/translate` work items in one
call. It defaults to `dry_run:true`; batch writes require `dry_run:false` plus
`confirm_safe_write:true`.

`content/audit-multilingual` is read-only. It detects Polylang or WPML, reports
languages and missing post translations, and returns an explicit warning when no
supported multilingual provider is active.

`taxonomy/term-list` and `taxonomy/term-translate` provide the WordPress-side
equivalent of Joomla category translation for categories, tags, and custom
taxonomies. `taxonomy/term-translate` requires the source `if_match` etag from
`taxonomy/term-list` and supports `dry_run:true`.

`content/check-links` is read-only in the WordPress host. It reports internal
links that resolve to missing/unpublished content, unresolved internal URLs, or
wrong-language targets when Polylang/WPML is active.

`wp/abilities/list` is the first native-capabilities bridge. It discovers
WordPress Abilities API abilities registered by core, plugins, or themes. It
also reports `mirasai_policy` for each ability so agents can see whether
`wp/ability-call` will allow it before attempting execution.

`wp/ability-call` is classified as `safe_write`. It executes abilities that
declare `meta.annotations.readonly=true`, plus a narrow MirasAI allowlist of
non-destructive `ai/*` generation abilities. Destructive abilities and unknown
non-readonly abilities are blocked even if WordPress itself knows how to execute
them. Pass `dry_run:true` to validate the policy and inspect schemas without
executing the ability.

The `template/*` tools cover YOOtheme Builder discovery plus guarded writes.
Unlike `yt-builder-mcp`'s template-first surface, MirasAI models a layout target
as one of `key`, `post_id`, or `widget_id` from the start:

- `key`: a YOOtheme template in `wp_option('yootheme').templates`
- `post_id`: a WordPress post/page layout when YOOtheme stores one in known post
  meta or an embedded JSON comment
- `widget_id`: a YOOtheme Builder widget instance from `widget_builderwidget`

This keeps the WordPress host aligned with the Joomla host, where the same
element tools can operate on templates, articles, and Builder modules.

`template/source-types` introspects the installed YOOtheme Dynamic Source
GraphQL runtime when available, including WordPress, ACF, filesystem and other
source packages registered by the active theme/plugins. `template/element-source-read`
summarizes existing element bindings from `source`, `props.source`, or
`source_extended` without exposing the raw payload unless `include_raw:true` is
passed.

`template/element-source-preview`, `template/element-source-set`, and
`template/element-source-delete` are guarded Dynamic Source write tools.
Real writes require `if_match` plus `confirm_guarded_write:true`; `dry_run:true`
returns the same before/after shape without changing the YOOtheme layout.

`template/element-add`, `template/element-update-props`,
`template/element-move`, `template/element-clone`, and
`template/element-delete` are the general guarded element write tools. They use
the same target model (`key`, `post_id`, or `widget_id`) and the same
`if_match` + `dry_run` + `confirm_guarded_write` workflow as the Dynamic Source
tools, so they can operate on templates, page/post Builder layouts, and
YOOtheme Builder widgets without changing unrelated layout storage.

`template/translate` creates or updates a target-language copy of a YOOtheme
template (`key` storage only). It does not auto-translate: call `template/read`
first, use its `translatable_nodes[].replacement_key` values to provide
`yootheme_text_replacements`, and then apply with the guarded write workflow.
It requires WPML or Polylang language metadata so the target language can be
validated.

`template/widget-translate` does the same for YOOtheme Builder widgets stored in
`widget_builderwidget`. It creates or updates a translated widget instance,
marks it with the target language, and can place a new widget after the source
widget in the same sidebar. It uses the guarded write workflow and supports
`dry_run:true`.

The `acf/*` tools are read-only and optional. On sites without ACF they return
`available:false`; on sites with ACF/ACF PRO they expose field group schemas,
post field values, ACF-managed CPTs/taxonomies, and any ACF abilities registered
in the WordPress Abilities API.

## Next Steps

- Extend YOOtheme parity with save transforms and stronger cache invalidation.
- Add a separate guarded ability execution path only after mapping WordPress
  ability permissions into the MirasAI risk model.
- Add sandbox/dangerous execution tools only after the audit model and Smart Sudo
  style elevation workflow are specified for WordPress.
