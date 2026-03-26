#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="${ROOT_DIR}/.docker-build"
STAGE_DIR="${BUILD_DIR}/package-stage"
PACKAGE_DIR="${STAGE_DIR}/pkg_mirasai"
VERSION="$(grep -m1 -oE '<version>[^<]+' "${ROOT_DIR}/pkg_mirasai/pkg_mirasai.xml" | sed 's/<version>//')"
OUTPUT_ZIP="${BUILD_DIR}/pkg_mirasai-${VERSION}.zip"
LATEST_ZIP="${BUILD_DIR}/pkg_mirasai-lab.zip"
UPDATE_DIR="${ROOT_DIR}/updates"
UPDATE_FEED="${UPDATE_DIR}/pkg_mirasai.xml"
REPO_SLUG="velisnolis/MirasAI"

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
  mkdir -p "${stage_dir}/language" "${stage_dir}/services" "${stage_dir}/src" "${stage_dir}/tmpl" "${stage_dir}/api/services" "${stage_dir}/api/src" "${stage_dir}/sql"

  cp "${ROOT_DIR}/pkg_mirasai/packages/com_mirasai/mirasai.xml" "${stage_dir}/mirasai.xml"
  cp -R "${ROOT_DIR}/pkg_mirasai/packages/com_mirasai/admin/language/." "${stage_dir}/language/"
  cp -R "${ROOT_DIR}/pkg_mirasai/packages/com_mirasai/services/." "${stage_dir}/services/"
  cp -R "${ROOT_DIR}/pkg_mirasai/packages/com_mirasai/admin/src/." "${stage_dir}/src/"
  cp -R "${ROOT_DIR}/pkg_mirasai/packages/com_mirasai/admin/tmpl/." "${stage_dir}/tmpl/"
  cp -R "${ROOT_DIR}/pkg_mirasai/packages/com_mirasai/admin/sql/." "${stage_dir}/sql/"
  cp -R "${ROOT_DIR}/pkg_mirasai/packages/com_mirasai/api/services/." "${stage_dir}/api/services/"
  cp -R "${ROOT_DIR}/pkg_mirasai/packages/com_mirasai/api/src/." "${stage_dir}/api/src/"

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

rm -f "$OUTPUT_ZIP" "$LATEST_ZIP"
(
  cd "$PACKAGE_DIR"
  zip -qr "$OUTPUT_ZIP" .
)

cp "$OUTPUT_ZIP" "$LATEST_ZIP"

mkdir -p "$UPDATE_DIR"
SHA256="$(shasum -a 256 "$OUTPUT_ZIP" | awk '{print $1}')"
RELEASE_URL="https://github.com/${REPO_SLUG}/releases/download/v${VERSION}/pkg_mirasai-${VERSION}.zip"
INFO_URL="https://github.com/${REPO_SLUG}/releases/tag/v${VERSION}"

cat > "$UPDATE_FEED" <<EOF
<?xml version="1.0" encoding="utf-8"?>
<updates>
  <update>
    <name>MirasAI</name>
    <description>MirasAI package for Joomla. MCP server, admin dashboard, and optional YOOtheme tools.</description>
    <element>pkg_mirasai</element>
    <type>package</type>
    <version>${VERSION}</version>
    <infourl title="MirasAI">${INFO_URL}</infourl>
    <downloads>
      <downloadurl type="full" format="zip">${RELEASE_URL}</downloadurl>
    </downloads>
    <tags>
      <tag>stable</tag>
    </tags>
    <maintainer>Alex Miras</maintainer>
    <maintainerurl>https://miras.pro</maintainerurl>
    <targetplatform name="joomla" version="[56]\.[0-9]+"/>
    <sha256>${SHA256}</sha256>
  </update>
</updates>
EOF

echo "$OUTPUT_ZIP"
