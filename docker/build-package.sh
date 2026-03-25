#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="${ROOT_DIR}/.docker-build"
STAGE_DIR="${BUILD_DIR}/package-stage"
PACKAGE_DIR="${STAGE_DIR}/pkg_mirasai"
OUTPUT_ZIP="${BUILD_DIR}/pkg_mirasai-lab.zip"

require_cmd() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Missing required command: $1" >&2
    exit 1
  fi
}

zip_dir_contents() {
  local src_dir="$1"
  local zip_path="$2"

  rm -f "$zip_path"
  (
    cd "$src_dir"
    zip -qr "$zip_path" .
  )
}

build_component_package() {
  local stage_dir="$1"
  local zip_path="$2"

  rm -rf "$stage_dir"
  mkdir -p "${stage_dir}/services" "${stage_dir}/src" "${stage_dir}/tmpl" "${stage_dir}/api" "${stage_dir}/sql"

  cp "${ROOT_DIR}/pkg_mirasai/packages/com_mirasai/mirasai.xml" "${stage_dir}/mirasai.xml"
  cp -R "${ROOT_DIR}/pkg_mirasai/packages/com_mirasai/services/." "${stage_dir}/services/"
  cp -R "${ROOT_DIR}/pkg_mirasai/packages/com_mirasai/admin/src/." "${stage_dir}/src/"
  cp -R "${ROOT_DIR}/pkg_mirasai/packages/com_mirasai/admin/tmpl/." "${stage_dir}/tmpl/"
  cp -R "${ROOT_DIR}/pkg_mirasai/packages/com_mirasai/admin/sql/." "${stage_dir}/sql/"
  cp -R "${ROOT_DIR}/pkg_mirasai/packages/com_mirasai/src/." "${stage_dir}/src/"
  cp -R "${ROOT_DIR}/pkg_mirasai/packages/com_mirasai/src" "${stage_dir}/api/"

  zip_dir_contents "$stage_dir" "$zip_path"
}

require_cmd zip

rm -rf "$STAGE_DIR"
mkdir -p "${PACKAGE_DIR}/packages" "${STAGE_DIR}/component-stage"

cp "${ROOT_DIR}/pkg_mirasai/pkg_mirasai.xml" "${PACKAGE_DIR}/pkg_mirasai.xml"
cp "${ROOT_DIR}/pkg_mirasai/script.php" "${PACKAGE_DIR}/script.php"

build_component_package "${STAGE_DIR}/component-stage" "${PACKAGE_DIR}/packages/com_mirasai.zip"
zip_dir_contents "${ROOT_DIR}/pkg_mirasai/packages/lib_mirasai" "${PACKAGE_DIR}/packages/lib_mirasai.zip"
zip_dir_contents "${ROOT_DIR}/pkg_mirasai/packages/plg_system_mirasai" "${PACKAGE_DIR}/packages/plg_system_mirasai.zip"
zip_dir_contents "${ROOT_DIR}/pkg_mirasai/packages/plg_webservices_mirasai" "${PACKAGE_DIR}/packages/plg_webservices_mirasai.zip"
zip_dir_contents "${ROOT_DIR}/pkg_mirasai/packages/plg_mirasai_yootheme" "${PACKAGE_DIR}/packages/plg_mirasai_yootheme.zip"

rm -f "$OUTPUT_ZIP"
(
  cd "$PACKAGE_DIR"
  zip -qr "$OUTPUT_ZIP" .
)

echo "$OUTPUT_ZIP"
