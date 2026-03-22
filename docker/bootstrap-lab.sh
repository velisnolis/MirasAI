#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="${ROOT_DIR}/.docker-build"
MIRASAI_PACKAGE_ZIP="${BUILD_DIR}/pkg_mirasai-lab.zip"
YOOTHEME_ARCHIVE="${BUILD_DIR}/yootheme-pro-lab.zip"
MCP_TOKEN_FILE="${BUILD_DIR}/mcp-token.txt"
LAB_INFO_FILE="${BUILD_DIR}/lab-info.env"

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

wait_for_db() {
  local retries=60
  until docker compose exec -T db mysqladmin ping -h 127.0.0.1 -u root "-p${MYSQL_ROOT_PASSWORD}" --silent >/dev/null 2>&1; do
    retries=$((retries - 1))
    if [[ "$retries" -le 0 ]]; then
      echo "MySQL did not become ready in time." >&2
      exit 1
    fi
    sleep 2
  done
}

wait_for_http() {
  local url="http://127.0.0.1:${JOOMLA_HTTP_PORT}/"
  local retries=60
  until curl -fsS "$url" >/dev/null 2>&1; do
    retries=$((retries - 1))
    if [[ "$retries" -le 0 ]]; then
      echo "Joomla HTTP endpoint did not become ready: $url" >&2
      exit 1
    fi
    sleep 2
  done
}

prepare_yootheme_archive() {
  if [[ "${WITH_YOOTHEME:-1}" != "1" ]]; then
    return 0
  fi

  if [[ -z "${YOOTHEME_PACKAGE_PATH:-}" ]]; then
    echo "WITH_YOOTHEME=1 but YOOTHEME_PACKAGE_PATH is empty." >&2
    exit 1
  fi

  if [[ ! -e "${YOOTHEME_PACKAGE_PATH}" ]]; then
    echo "YOOtheme package path does not exist: ${YOOTHEME_PACKAGE_PATH}" >&2
    exit 1
  fi

  mkdir -p "$BUILD_DIR"

  if [[ -d "${YOOTHEME_PACKAGE_PATH}" ]]; then
    rm -f "$YOOTHEME_ARCHIVE"
    (
      cd "${YOOTHEME_PACKAGE_PATH}"
      zip -qr "$YOOTHEME_ARCHIVE" .
    )
  else
    cp "${YOOTHEME_PACKAGE_PATH}" "$YOOTHEME_ARCHIVE"
  fi
}

install_joomla_if_needed() {
  if docker compose exec -T joomla test -f /var/www/html/configuration.php; then
    echo "Joomla already installed."
    return 0
  fi

  docker compose exec \
    -T \
    joomla \
    php /var/www/html/installation/joomla.php install \
      --no-interaction \
      --site-name="${JOOMLA_SITE_NAME}" \
      --admin-user="${JOOMLA_ADMIN_NAME}" \
      --admin-username="${JOOMLA_ADMIN_USER}" \
      --admin-password="${JOOMLA_ADMIN_PASSWORD}" \
      --admin-email="${JOOMLA_ADMIN_EMAIL}" \
      --db-type=mysqli \
      --db-host=db \
      --db-user="${MYSQL_USER}" \
      --db-pass="${MYSQL_PASSWORD}" \
      --db-name="${MYSQL_DATABASE}" \
      --db-prefix="${JOOMLA_DB_PREFIX}"

  if ! docker compose exec -T joomla test -f /var/www/html/configuration.php; then
    echo "Joomla installation did not create configuration.php." >&2
    exit 1
  fi
}

install_extension_zip() {
  local archive_path="$1"
  local remote_path="$2"

  docker cp "$archive_path" "$(docker compose ps -q joomla):${remote_path}"
  docker compose exec -T joomla php /var/www/html/cli/joomla.php extension:install --path="${remote_path}"
}

install_extension_zip_optional() {
  local archive_path="$1"
  local remote_path="$2"
  local label="$3"

  if install_extension_zip "$archive_path" "$remote_path"; then
    return 0
  fi

  echo "Warning: optional extension install failed for ${label}; continuing." >&2
}

configure_admin_api_token() {
  local admin_user_id
  local token_seed
  local site_secret
  local mcp_token

  admin_user_id="$(docker compose exec -T db sh -eu -c "mysql -N -u\"${MYSQL_USER}\" -p\"${MYSQL_PASSWORD}\" \"${MYSQL_DATABASE}\" -e \"SELECT id FROM ${JOOMLA_DB_PREFIX}users WHERE username = '${JOOMLA_ADMIN_USER}' LIMIT 1;\"")"

  if [[ -z "$admin_user_id" ]]; then
    echo "Could not resolve Joomla admin user id for ${JOOMLA_ADMIN_USER}." >&2
    exit 1
  fi

  token_seed="$(php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;')"

  docker compose exec -T db sh -eu -c "mysql -u\"${MYSQL_USER}\" -p\"${MYSQL_PASSWORD}\" \"${MYSQL_DATABASE}\" <<SQL
DELETE FROM ${JOOMLA_DB_PREFIX}user_profiles WHERE user_id = ${admin_user_id} AND profile_key IN ('joomlatoken.enabled', 'joomlatoken.token');
INSERT INTO ${JOOMLA_DB_PREFIX}user_profiles (user_id, profile_key, profile_value, ordering) VALUES
  (${admin_user_id}, 'joomlatoken.enabled', '1', 1),
  (${admin_user_id}, 'joomlatoken.token', '${token_seed}', 2);
SQL"

  site_secret="$(docker compose exec -T joomla php -r 'include "/var/www/html/configuration.php"; $c = new JConfig; echo $c->secret, PHP_EOL;')"

  if [[ -z "$site_secret" ]]; then
    echo "Could not read Joomla site secret." >&2
    exit 1
  fi

  mcp_token="$(
    env \
      TOKEN_SEED="$token_seed" \
      SITE_SECRET="$site_secret" \
      ADMIN_USER_ID="$admin_user_id" \
      php -r '$seed = getenv("TOKEN_SEED"); $secret = getenv("SITE_SECRET"); $uid = getenv("ADMIN_USER_ID"); $hmac = hash_hmac("sha256", base64_decode($seed, true), $secret); echo base64_encode("sha256:$uid:$hmac"), PHP_EOL;'
  )"

  mkdir -p "$BUILD_DIR"
  printf '%s\n' "$mcp_token" > "$MCP_TOKEN_FILE"
  cat > "$LAB_INFO_FILE" <<EOF
JOOMLA_BASE_URL=http://127.0.0.1:${JOOMLA_HTTP_PORT}
JOOMLA_ADMIN_URL=http://127.0.0.1:${JOOMLA_HTTP_PORT}/administrator/index.php
MCP_URL=http://127.0.0.1:${JOOMLA_HTTP_PORT}/api/v1/mirasai/mcp
MCP_TOKEN_FILE=${MCP_TOKEN_FILE}
EOF
}

main() {
  require_cmd docker
  require_cmd curl
  require_cmd php
  require_cmd zip

  load_env
  mkdir -p "$BUILD_DIR"

  docker compose up -d db joomla
  wait_for_db

  install_joomla_if_needed
  wait_for_http

  prepare_yootheme_archive

  if [[ "${WITH_YOOTHEME:-1}" == "1" ]]; then
    install_extension_zip "$YOOTHEME_ARCHIVE" /tmp/yootheme-pro-lab.zip
  fi

  "${ROOT_DIR}/docker/build-package.sh" >/dev/null
  install_extension_zip "${BUILD_DIR}/package-stage/pkg_mirasai/packages/lib_mirasai.zip" /tmp/lib_mirasai.zip
  install_extension_zip "${BUILD_DIR}/package-stage/pkg_mirasai/packages/plg_system_mirasai.zip" /tmp/plg_system_mirasai.zip
  install_extension_zip "${BUILD_DIR}/package-stage/pkg_mirasai/packages/plg_webservices_mirasai.zip" /tmp/plg_webservices_mirasai.zip
  install_extension_zip_optional "${BUILD_DIR}/package-stage/pkg_mirasai/packages/com_mirasai.zip" /tmp/com_mirasai.zip "com_mirasai"

  configure_admin_api_token

  echo "Lab ready."
  echo "Base URL: http://127.0.0.1:${JOOMLA_HTTP_PORT}"
  echo "MCP URL:  http://127.0.0.1:${JOOMLA_HTTP_PORT}/api/v1/mirasai/mcp"
  echo "Token:    ${MCP_TOKEN_FILE}"
}

main "$@"
