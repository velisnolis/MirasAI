#!/usr/bin/env bash
# Phase 4 integration tests for theme/extract-to-modules
# Requires: Docker lab running (bootstrap-lab.sh), jq installed
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MCP_TOKEN_FILE="${ROOT_DIR}/.docker-build/mcp-token.txt"

PASS=0
FAIL=0
SKIP=0

# ── Helpers ──────────────────────────────────────────────────────────────────

load_env() {
  if [[ ! -f "${ROOT_DIR}/.env" ]]; then
    echo "Missing ${ROOT_DIR}/.env" >&2
    exit 1
  fi
  set -a
  # shellcheck disable=SC1091
  source "${ROOT_DIR}/.env"
  set +a
}

require_cmd() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Missing required command: $1" >&2
    exit 1
  fi
}

mcp_token() {
  cat "$MCP_TOKEN_FILE"
}

mcp_call() {
  local tool_name="$1"
  local args_json="$2"

  curl -fsS -X POST "http://127.0.0.1:${JOOMLA_HTTP_PORT}/api/v1/mirasai/mcp" \
    -H 'Content-Type: application/json' \
    -H "X-Joomla-Token: $(mcp_token)" \
    -d "$(printf '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"%s","arguments":%s},"id":1}' "$tool_name" "$args_json")"
}

db_exec() {
  docker compose exec -T db sh -eu -c \
    "mysql -N -u\"${MYSQL_USER}\" -p\"${MYSQL_PASSWORD}\" \"${MYSQL_DATABASE}\" -e \"$1\""
}

# Extract the tool result content from MCP JSON-RPC response.
# tools/call returns: { result: { content: [{ type: "text", text: "<json>" }] } }
extract_result() {
  local response="$1"
  printf '%s' "$response" | jq -r '.result.content[0].text // empty' 2>/dev/null \
    || printf '%s' "$response"
}

assert_json_field() {
  local json="$1"
  local path="$2"
  local expected="$3"
  local actual

  actual="$(printf '%s' "$json" | jq -r "$path" 2>/dev/null)"

  if [[ "$actual" == "$expected" ]]; then
    return 0
  fi

  echo "  ASSERT FAILED: $path expected '$expected' got '$actual'" >&2
  return 1
}

assert_json_field_not() {
  local json="$1"
  local path="$2"
  local unexpected="$3"
  local actual

  actual="$(printf '%s' "$json" | jq -r "$path" 2>/dev/null)"

  if [[ "$actual" != "$unexpected" ]]; then
    return 0
  fi

  echo "  ASSERT FAILED: $path should not be '$unexpected'" >&2
  return 1
}

assert_json_numeric_gte() {
  local json="$1"
  local path="$2"
  local min="$3"
  local actual

  actual="$(printf '%s' "$json" | jq -r "$path" 2>/dev/null)"

  if [[ "$actual" -ge "$min" ]] 2>/dev/null; then
    return 0
  fi

  echo "  ASSERT FAILED: $path expected >= $min got '$actual'" >&2
  return 1
}

run_test() {
  local name="$1"
  shift

  echo "── TEST: ${name}"

  if "$@"; then
    PASS=$((PASS + 1))
    echo "   ✓ PASS"
  else
    FAIL=$((FAIL + 1))
    echo "   ✗ FAIL"
  fi
}

skip_test() {
  local name="$1"
  local reason="$2"
  SKIP=$((SKIP + 1))
  echo "── TEST: ${name}"
  echo "   ⊘ SKIP: ${reason}"
}

# ── Fixtures ─────────────────────────────────────────────────────────────────

PREFIX="${JOOMLA_DB_PREFIX}"

ensure_languages() {
  # Make sure ca-ES and es-ES content languages exist.
  # Joomla ships with en-GB by default; we add ca-ES and es-ES if missing.
  for lang_tag in ca-ES es-ES; do
    local exists
    exists="$(db_exec "SELECT COUNT(*) FROM ${PREFIX}languages WHERE lang_code='${lang_tag}';")"
    if [[ "${exists// /}" == "0" ]]; then
      local title
      case "$lang_tag" in
        ca-ES) title="Català" ;;
        es-ES) title="Español" ;;
      esac
      db_exec "INSERT INTO ${PREFIX}languages (lang_code, title, title_native, sef, image, published, access, ordering) VALUES ('${lang_tag}', '${title}', '${title}', '${lang_tag%%\-*}', '${lang_tag%%\-*}', 1, 1, 0);"
    fi
  done
}

get_active_style_id() {
  local result
  result="$(db_exec "SELECT id FROM ${PREFIX}template_styles WHERE template='yootheme' AND client_id=0 AND home=1 LIMIT 1;")"
  echo "${result// /}"
}

inject_footer_area() {
  local style_id="$1"

  # Read current params, inject a minimal footer area if not present.
  # We do this via a PHP one-liner inside the Joomla container for safe JSON manipulation.
  docker compose exec -T joomla php -r "
    \$db = include '/var/www/html/libraries/vendor/autoload.php';
    require '/var/www/html/configuration.php';
    \$c = new \JConfig;
    \$pdo = new PDO('mysql:host=db;dbname=' . \$c->db, \$c->user, \$c->password);
    \$row = \$pdo->query('SELECT params FROM ${PREFIX}template_styles WHERE id=${style_id}')->fetch();
    \$params = json_decode(\$row['params'], true) ?: [];
    \$config = isset(\$params['config']) ? json_decode(\$params['config'], true) : [];
    if (!isset(\$config['footer']['content'])) {
      \$config['footer'] = ['content' => [
        'type' => 'layout',
        'children' => [[
          'type' => 'section',
          'props' => ['style' => 'default'],
          'children' => [[
            'type' => 'row',
            'children' => [[
              'type' => 'column',
              'children' => [[
                'type' => 'text',
                'props' => ['content' => '© 2026 Test Site', 'title' => 'Footer Title'],
              ]],
            ]],
          ]],
        ]],
      ]];
      \$params['config'] = json_encode(\$config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      \$stmt = \$pdo->prepare('UPDATE ${PREFIX}template_styles SET params = ? WHERE id = ?');
      \$stmt->execute([json_encode(\$params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ${style_id}]);
      echo 'injected';
    } else {
      echo 'exists';
    }
  "
}

cleanup_mirasai_modules() {
  db_exec "DELETE mm FROM ${PREFIX}modules_menu mm INNER JOIN ${PREFIX}modules m ON mm.moduleid = m.id WHERE m.note LIKE 'mirasai:%' OR m.note = 'Created by MirasAI';" 2>/dev/null || true
  db_exec "DELETE FROM ${PREFIX}modules WHERE note LIKE 'mirasai:%' OR note = 'Created by MirasAI';" 2>/dev/null || true
}

cleanup_test_modules() {
  # Remove both MirasAI modules and test fixture modules
  cleanup_mirasai_modules
  db_exec "DELETE mm FROM ${PREFIX}modules_menu mm INNER JOIN ${PREFIX}modules m ON mm.moduleid = m.id WHERE m.title = 'Test Preexisting Footer';" 2>/dev/null || true
  db_exec "DELETE FROM ${PREFIX}modules WHERE title = 'Test Preexisting Footer';" 2>/dev/null || true
}

restore_footer_area() {
  local style_id="$1"
  docker compose exec -T joomla php -r "
    require '/var/www/html/configuration.php';
    \$c = new \JConfig;
    \$pdo = new PDO('mysql:host=db;dbname=' . \$c->db, \$c->user, \$c->password);
    \$row = \$pdo->query('SELECT params FROM ${PREFIX}template_styles WHERE id=${style_id}')->fetch();
    \$params = json_decode(\$row['params'], true) ?: [];
    \$config = isset(\$params['config']) ? json_decode(\$params['config'], true) : [];
    \$config['footer'] = ['content' => [
      'type' => 'layout',
      'children' => [[
        'type' => 'section',
        'props' => ['style' => 'default'],
        'children' => [[
          'type' => 'row',
          'children' => [[
            'type' => 'column',
            'children' => [[
              'type' => 'text',
              'props' => ['content' => '© 2026 Test Site', 'title' => 'Footer Title'],
            ]],
          ]],
        ]],
      ]],
    ]];
    unset(\$params['mirasai_backups']);
    \$params['config'] = json_encode(\$config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    \$stmt = \$pdo->prepare('UPDATE ${PREFIX}template_styles SET params = ? WHERE id = ?');
    \$stmt->execute([json_encode(\$params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ${style_id}]);
  "
}

insert_preexisting_module() {
  local position="$1"
  local lang="$2"

  db_exec "INSERT INTO ${PREFIX}modules (title, note, content, ordering, position, published, module, access, showtitle, params, client_id, language) VALUES ('Test Preexisting Footer', 'manual', 'Some content', 0, '${position}', 1, 'mod_custom', 1, 0, '{}', 0, '${lang}');"
}

# ── Test Cases ───────────────────────────────────────────────────────────────

test_1_single_style_normal() {
  # Test 1: Single YOOtheme style — normal extraction
  local style_id
  style_id="$(get_active_style_id)"
  [[ -n "$style_id" ]] || { echo "  No active YOOtheme style found"; return 1; }

  cleanup_test_modules
  restore_footer_area "$style_id"

  local response result
  response="$(mcp_call 'theme/extract-to-modules' '{
    "area": "footer",
    "languages": ["ca-ES", "es-ES"],
    "translations": {
      "es-ES": {
        "root>section[0]>row[0]>column[0]>text[0].content": "© 2026 Sitio de prueba",
        "root>section[0]>row[0]>column[0]>text[0].title": "Pie de página"
      }
    }
  }')"

  result="$(extract_result "$response")"

  assert_json_field "$result" '.area' 'footer' \
    && assert_json_field "$result" '.template_style_id' "$style_id" \
    && assert_json_field "$result" '.dry_run' 'false' \
    && assert_json_numeric_gte "$result" '.modules_created' 2 \
    && assert_json_field "$result" '.theme_area.status' 'replaced' \
    && assert_json_field_not "$result" '.theme_area.backup_reference' 'null'
}

test_2_multiple_styles() {
  # Test 2: Multiple template_styles — ensure explicit style_id works
  local style_id
  style_id="$(get_active_style_id)"
  [[ -n "$style_id" ]] || { echo "  No active YOOtheme style found"; return 1; }

  cleanup_test_modules
  restore_footer_area "$style_id"

  # Create a second (non-home) YOOtheme style
  db_exec "INSERT INTO ${PREFIX}template_styles (template, client_id, home, title, params) VALUES ('yootheme', 0, 0, 'YOOtheme Test Clone', '{}');"
  local clone_id
  clone_id="$(db_exec "SELECT id FROM ${PREFIX}template_styles WHERE title='YOOtheme Test Clone' LIMIT 1;")"
  clone_id="${clone_id// /}"

  # Call with explicit template_style_id pointing to the active one
  local response result
  response="$(mcp_call 'theme/extract-to-modules' "{
    \"area\": \"footer\",
    \"languages\": [\"ca-ES\"],
    \"template_style_id\": ${style_id}
  }")"

  result="$(extract_result "$response")"

  local ok=true
  assert_json_field "$result" '.template_style_id' "$style_id" || ok=false

  # Call without explicit id — should resolve to the active style, not the clone
  cleanup_mirasai_modules
  restore_footer_area "$style_id"

  response="$(mcp_call 'theme/extract-to-modules' '{
    "area": "footer",
    "languages": ["ca-ES"]
  }')"

  result="$(extract_result "$response")"
  assert_json_field "$result" '.template_style_id' "$style_id" || ok=false

  # Call targeting the clone — should fail (no footer area)
  response="$(mcp_call 'theme/extract-to-modules' "{
    \"area\": \"footer\",
    \"languages\": [\"ca-ES\"],
    \"template_style_id\": ${clone_id}
  }" 2>&1)" || true

  result="$(extract_result "$response")"
  # Either error in result or empty (no footer)
  local has_error
  has_error="$(printf '%s' "$result" | jq -r '.error // empty' 2>/dev/null)"
  [[ -n "$has_error" ]] || ok=false

  # Cleanup clone
  db_exec "DELETE FROM ${PREFIX}template_styles WHERE id=${clone_id};" 2>/dev/null || true

  $ok
}

test_3_preexisting_module_conflict() {
  # Test 3: Non-MirasAI module at same position — should error without force
  local style_id
  style_id="$(get_active_style_id)"
  [[ -n "$style_id" ]] || { echo "  No active YOOtheme style found"; return 1; }

  cleanup_test_modules
  restore_footer_area "$style_id"

  # Insert a non-MirasAI module at position "footer" for ca-ES
  insert_preexisting_module "footer" "ca-ES"

  local response result
  response="$(mcp_call 'theme/extract-to-modules' '{
    "area": "footer",
    "languages": ["ca-ES"]
  }' 2>&1)" || true

  result="$(extract_result "$response")"

  local has_error
  has_error="$(printf '%s' "$result" | jq -r '.error // empty' 2>/dev/null)"

  local has_conflicts
  has_conflicts="$(printf '%s' "$result" | jq -r '.conflicts | length // 0' 2>/dev/null)"

  [[ -n "$has_error" ]] && [[ "$has_conflicts" -ge 1 ]]
}

test_4_idempotent_rerun() {
  # Test 4: Re-run should reuse existing modules, not create new ones
  local style_id
  style_id="$(get_active_style_id)"
  [[ -n "$style_id" ]] || { echo "  No active YOOtheme style found"; return 1; }

  cleanup_test_modules
  restore_footer_area "$style_id"

  # First run: creates modules
  mcp_call 'theme/extract-to-modules' '{
    "area": "footer",
    "languages": ["ca-ES", "es-ES"]
  }' >/dev/null

  # Second run: should reuse
  local response result
  response="$(mcp_call 'theme/extract-to-modules' '{
    "area": "footer",
    "languages": ["ca-ES", "es-ES"]
  }')"

  result="$(extract_result "$response")"

  assert_json_field "$result" '.modules_created' '0' \
    && assert_json_numeric_gte "$result" '.modules_reused' 2 \
    && assert_json_field "$result" '.theme_area.status' 'already_replaced'
}

test_5_dry_run() {
  # Test 5: dry_run should not create modules or replace theme area
  local style_id
  style_id="$(get_active_style_id)"
  [[ -n "$style_id" ]] || { echo "  No active YOOtheme style found"; return 1; }

  cleanup_test_modules
  restore_footer_area "$style_id"

  # Count modules before
  local count_before
  count_before="$(db_exec "SELECT COUNT(*) FROM ${PREFIX}modules WHERE note LIKE 'mirasai:%';")"
  count_before="${count_before// /}"

  local response result
  response="$(mcp_call 'theme/extract-to-modules' '{
    "area": "footer",
    "languages": ["ca-ES", "es-ES"],
    "dry_run": true
  }')"

  result="$(extract_result "$response")"

  # Count modules after — should be unchanged
  local count_after
  count_after="$(db_exec "SELECT COUNT(*) FROM ${PREFIX}modules WHERE note LIKE 'mirasai:%';")"
  count_after="${count_after// /}"

  assert_json_field "$result" '.dry_run' 'true' \
    && assert_json_field "$result" '.theme_area.status' 'would_replace' \
    && [[ "$count_before" == "$count_after" ]]
}

test_6_replace_theme_area_false() {
  # Test 6: replace_theme_area=false — creates modules but doesn't swap theme area
  local style_id
  style_id="$(get_active_style_id)"
  [[ -n "$style_id" ]] || { echo "  No active YOOtheme style found"; return 1; }

  cleanup_test_modules
  restore_footer_area "$style_id"

  local response result
  response="$(mcp_call 'theme/extract-to-modules' '{
    "area": "footer",
    "languages": ["ca-ES"],
    "replace_theme_area": false
  }')"

  result="$(extract_result "$response")"

  assert_json_numeric_gte "$result" '.modules_created' 1 \
    && assert_json_field "$result" '.theme_area.status' 'skipped'
}

# ── Main ─────────────────────────────────────────────────────────────────────

main() {
  require_cmd curl
  require_cmd jq
  require_cmd docker
  load_env

  if [[ ! -f "$MCP_TOKEN_FILE" ]]; then
    echo "Missing MCP token. Run docker/bootstrap-lab.sh first." >&2
    exit 1
  fi

  echo "Phase 4: ThemeExtractToModulesTool integration tests"
  echo "════════════════════════════════════════════════════"
  echo ""

  local style_id
  style_id="$(get_active_style_id)"

  if [[ -z "$style_id" ]]; then
    echo "No active YOOtheme template style found. Is YOOtheme Pro installed?" >&2
    exit 1
  fi

  echo "Active template_style id: ${style_id}"
  echo ""

  ensure_languages

  run_test "1. Single style — normal extraction"          test_1_single_style_normal
  run_test "2. Multiple styles — explicit style_id"       test_2_multiple_styles
  run_test "3. Pre-existing module — conflict detection"  test_3_preexisting_module_conflict
  run_test "4. Idempotent re-run — module reuse"          test_4_idempotent_rerun
  run_test "5. dry_run — no mutations"                    test_5_dry_run
  run_test "6. replace_theme_area=false — modules only"   test_6_replace_theme_area_false

  # Final cleanup
  cleanup_test_modules

  echo ""
  echo "════════════════════════════════════════════════════"
  echo "Results: ${PASS} passed, ${FAIL} failed, ${SKIP} skipped"

  if [[ "$FAIL" -gt 0 ]]; then
    exit 1
  fi
}

main "$@"
