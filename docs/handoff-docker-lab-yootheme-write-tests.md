# Handoff: Docker Lab for YOOtheme Write Tests

## Context

This checkout has local MirasAI changes inspired by `wootsup/yt-builder-mcp`:

- `template/summary` for compact YOOtheme template summaries.
- `content/read` now returns `yootheme_layout_summary` for article layouts.
- `template/list`, `template/read`, and `template/summary` expose template `etag`.
- `template/list` accepts `fields` projections.
- `template/translate` accepts optional `if_match` and returns `stale_etag` when the source template changed.
- `system/diagnose` returns compact readiness diagnostics for core tools, addon registration, YOOtheme counts, provider warnings, and elevation state.

Read-only production validation was done on `autovigatana`:

- Joomla 5.4.5, YOOtheme 5.0.34, MirasAI 0.4.8.
- `template/summary` registered successfully in `tools/list`.
- `content/read` on article `1` (`Inici`) returned `yootheme_layout_summary`.
- `template/list` returned `count=0` because that site stores YOOtheme layouts in articles, not in YOOtheme templates.
- No content write was performed on `autovigatana`.

Server-side backup made before installing the modified package:

```text
/home/autovigatana/application_backups/mirasai-pre-summary-20260525-094156
```

## Goal for Next Session

Recreate the Docker lab locally and test the pending write path safely:

1. `template/list` returns `etag` and `collection_etag`.
2. `template/summary` works on a real YOOtheme template.
3. `template/translate` succeeds with a correct `if_match`.
4. `template/translate` rejects a stale/fake `if_match` with `code=stale_etag`.
5. Existing smoke tests still pass.
6. `system/diagnose` returns `status=ok` in the lab.

## Lab Bootstrap

`docker/bootstrap-lab.sh` now installs:

- `lib_mirasai`
- `plg_system_mirasai`
- `plg_webservices_mirasai`
- `com_mirasai` best-effort
- `plg_mirasai_yootheme` automatically when `WITH_YOOTHEME=1`

It also enables the relevant system/webservices/mirasai plugins directly in the database for repeatable lab bootstrap.

The script is intended to be idempotent on an already-installed lab: it skips Joomla installation when `configuration.php` exists and only waits for the Joomla installer CLI on first install.

## Recreate Lab

From repo root:

```bash
cp .env.example .env
```

Edit `.env`:

- Set strong local passwords.
- Set a free `JOOMLA_HTTP_PORT`, for example `8080`.
- Set `WITH_YOOTHEME=1`.
- Set `YOOTHEME_PACKAGE_PATH` to a local YOOtheme Pro zip or extracted directory.

Then:

```bash
docker compose up -d
./docker/bootstrap-lab.sh
./docker/smoke.sh
```

If Docker image pulls fail from the local ISP, use WARP/VPN and retry.

Confirm the addon is registered:

```bash
TOKEN="$(cat .docker-build/mcp-token.txt)"
curl -fsS -X POST "http://127.0.0.1:${JOOMLA_HTTP_PORT:-8080}/api/v1/mirasai/mcp" \
  -H 'Content-Type: application/json' \
  -H "X-Joomla-Token: $TOKEN" \
  -d '{"jsonrpc":"2.0","method":"tools/list","params":{},"id":1}' \
  | grep 'template/summary'
```

## Create or Import a Real YOOtheme Template

The lab must have at least one YOOtheme template in system plugin `custom_data.templates`.

Options:

- Use YOOtheme admin UI in the lab to create a simple template manually.
- Import/seed a minimal template row into YOOtheme custom data with a known fixture.

Prefer the UI first if available, because it exercises YOOtheme's expected storage shape.

## Read Tests

List templates with token-efficient fields:

```bash
TOKEN="$(cat .docker-build/mcp-token.txt)"
curl -fsS -X POST "http://127.0.0.1:${JOOMLA_HTTP_PORT:-8080}/api/v1/mirasai/mcp" \
  -H 'Content-Type: application/json' \
  -H "X-Joomla-Token: $TOKEN" \
  -d '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"template/list","arguments":{"fields":["key","name","etag","language"]}},"id":2}'
```

Pick a `key`, then:

```bash
curl -fsS -X POST "http://127.0.0.1:${JOOMLA_HTTP_PORT:-8080}/api/v1/mirasai/mcp" \
  -H 'Content-Type: application/json' \
  -H "X-Joomla-Token: $TOKEN" \
  -d '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"template/summary","arguments":{"key":"REPLACE_KEY"}},"id":3}'
```

Expected:

- no `isError`
- response contains `etag`
- response contains `layout_summary.total_elements`

## Write Tests

Use a target language installed and published in the lab. If the target language does not exist, install/publish one first.

Successful guarded write:

```bash
curl -fsS -X POST "http://127.0.0.1:${JOOMLA_HTTP_PORT:-8080}/api/v1/mirasai/mcp" \
  -H 'Content-Type: application/json' \
  -H "X-Joomla-Token: $TOKEN" \
  -d '{
    "jsonrpc":"2.0",
    "method":"tools/call",
    "params":{
      "name":"template/translate",
      "arguments":{
        "key":"REPLACE_KEY",
        "target_language":"es-ES",
        "translated_name":"MirasAI test template (es-ES)",
        "if_match":"REPLACE_CURRENT_ETAG",
        "overwrite":true,
        "yootheme_text_replacements":{}
      }
    },
    "id":4
  }'
```

Stale ETag rejection:

```bash
curl -fsS -X POST "http://127.0.0.1:${JOOMLA_HTTP_PORT:-8080}/api/v1/mirasai/mcp" \
  -H 'Content-Type: application/json' \
  -H "X-Joomla-Token: $TOKEN" \
  -d '{
    "jsonrpc":"2.0",
    "method":"tools/call",
    "params":{
      "name":"template/translate",
      "arguments":{
        "key":"REPLACE_KEY",
        "target_language":"es-ES",
        "if_match":"definitely-stale",
        "overwrite":true,
        "yootheme_text_replacements":{}
      }
    },
    "id":5
  }'
```

Expected stale response:

```json
{
  "code": "stale_etag"
}
```

## Verification Before Commit or Release

Run locally:

```bash
php docker/test-yootheme-summary.php
php docker/test-mcp-schema.php
php docker/test-mcp-path-normalizer.php
./docker/build-package.sh
./docker/smoke.sh
./docker/test-template-etag.sh
```

`docker/test-template-etag.sh` seeds a minimal YOOtheme template fixture and validates:

- `template/list`
- `template/summary`
- `template/translate` with a correct `if_match`
- `template/translate` with a stale `if_match`

The Proxmox CT lab validation passed after the latest changes:

```text
Smoke checks passed.
Template ETag integration checks passed.
system/diagnose -> status=ok
```

## Suggested Next Code Improvements

1. Consider adding `system/diagnose` to the normal smoke checks once the output shape is stable.
2. Consider stronger per-template locks for future granular YOOtheme writes.
3. Add read-only granular YOOtheme element tools before adding element write tools.
4. Only after read-only element tooling is useful, add guarded element writes with `if_match` and explicit confirmation.
