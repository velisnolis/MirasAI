#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MCP_TOKEN_FILE="${ROOT_DIR}/.docker-build/mcp-token.txt"

require_cmd() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Missing required command: $1" >&2
    exit 1
  fi
}

load_env() {
  if [[ ! -f "${ROOT_DIR}/.env" ]]; then
    echo "Missing ${ROOT_DIR}/.env. Copy .env.example first." >&2
    exit 1
  fi

  set -a
  # shellcheck disable=SC1091
  source "${ROOT_DIR}/.env"
  set +a
}

assert_http_ok() {
  local url="$1"
  local code

  code="$(curl -sS -o /dev/null -w '%{http_code}' "$url")"
  case "$code" in
    200|301|302)
      ;;
    *)
      echo "Unexpected HTTP status ${code} for ${url}" >&2
      exit 1
      ;;
  esac
}

assert_mcp_contains() {
  local payload="$1"
  local needle="$2"
  local response

  response="$(curl -fsS -X POST "http://127.0.0.1:${JOOMLA_HTTP_PORT}/api/v1/mirasai/mcp" \
    -H 'Content-Type: application/json' \
    -H "X-Joomla-Token: $(cat "$MCP_TOKEN_FILE")" \
    -d "$payload")"

  if ! printf '%s' "$response" | grep -q "$needle"; then
    echo "MCP response did not contain expected text: ${needle}" >&2
    echo "$response" >&2
    exit 1
  fi
}

main() {
  require_cmd curl
  load_env

  if [[ ! -f "$MCP_TOKEN_FILE" ]]; then
    echo "Missing MCP token file. Run docker/bootstrap-lab.sh first." >&2
    exit 1
  fi

  assert_http_ok "http://127.0.0.1:${JOOMLA_HTTP_PORT}/"
  assert_http_ok "http://127.0.0.1:${JOOMLA_HTTP_PORT}/administrator/index.php"

  assert_mcp_contains '{"jsonrpc":"2.0","method":"tools/list","params":{},"id":1}' 'system/info'
  assert_mcp_contains '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"system/info","arguments":{}},"id":2}' 'yootheme'

  echo "Smoke checks passed."

  if [[ "${RUN_INTEGRATION:-0}" == "1" ]]; then
    echo ""
    echo "Running integration tests..."
    "${ROOT_DIR}/docker/test-extract-to-modules.sh"
  fi
}

main "$@"
