#!/usr/bin/env bash
# Fast local contract checks that do not require a running Joomla Docker lab.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

php "${ROOT_DIR}/docker/test-mcp-schema.php"
php "${ROOT_DIR}/docker/test-mcp-handler.php"
php "${ROOT_DIR}/docker/test-mcp-path-normalizer.php"
php "${ROOT_DIR}/docker/test-tool-registry-resilience.php"
php "${ROOT_DIR}/docker/test-file-read-denylist.php"
php "${ROOT_DIR}/docker/test-sandbox-execute-php-contract.php"
php "${ROOT_DIR}/docker/test-yootheme-summary.php"
php "${ROOT_DIR}/docker/test-yootheme-elements.php"
php "${ROOT_DIR}/docker/test-yootheme-read-modes.php"

find "${ROOT_DIR}/packages/mirasai-joomla" -name '*.php' -print0 \
  | xargs -0 -n 1 php -l >/dev/null

echo "Local contract checks passed."
