#!/usr/bin/env bash
# Fase 0 lab: does MirasAI's JSON-direct write path corrupt layouts when
# YOOtheme later runs Builder::load(context:save)?
#
# Run on the lab CT from repo root:
#   ./docker/fase0-run-lab.sh
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MCP_TOKEN_FILE="${ROOT_DIR}/.docker-build/mcp-token.txt"
FIXTURE_DIR="${ROOT_DIR}/docker/fixtures/fase0-save-transform"
TEMPLATE_KEY="mirasai-fase0"
TRANSFORM_PHP="${ROOT_DIR}/docker/fase0-yootheme-save-transform.php"
COMPARE_PHP="${ROOT_DIR}/docker/fase0-compare-layouts.php"

load_env() {
  set -a
  # shellcheck disable=SC1091
  source "${ROOT_DIR}/.env"
  set +a
}

mcp_call() {
  local tool_name="$1"
  local args_json="$2"
  local payload

  payload="$(jq -cn \
    --arg name "$tool_name" \
    --argjson args "$args_json" \
    '{jsonrpc:"2.0",method:"tools/call",params:{name:$name,arguments:$args},id:1}')"

  curl -fsS -X POST "http://127.0.0.1:${JOOMLA_HTTP_PORT}/api/v1/mirasai/mcp" \
    -H 'Content-Type: application/json' \
    -H "X-Joomla-Token: $(cat "$MCP_TOKEN_FILE")" \
    -d "$payload"
}

mcp_text() {
  jq -r '.result.content[0].text // empty'
}

joomla_php() {
  docker compose -f "${ROOT_DIR}/docker-compose.yml" exec -T joomla php "$@"
}

copy_into_joomla() {
  local src="$1"
  local dest="$2"
  docker cp "$src" "$(docker compose -f "${ROOT_DIR}/docker-compose.yml" ps -q joomla):${dest}"
}

save_transform() {
  local in_file="$1"
  local out_file="$2"
  copy_into_joomla "$in_file" /tmp/fase0-in.json
  copy_into_joomla "$TRANSFORM_PHP" /tmp/fase0-yootheme-save-transform.php
  joomla_php /tmp/fase0-yootheme-save-transform.php < "$in_file" > "$out_file"
}

dump_layout() {
  local out_file="$1"
  local response result
  response="$(mcp_call 'template/read' "$(jq -cn --arg key "$TEMPLATE_KEY" '{key:$key}')")"
  result="$(printf '%s' "$response" | mcp_text)"
  printf '%s' "$result" | jq '.layout' > "$out_file"
  printf '%s' "$result" | jq -r '.etag'
}

write_layout_to_db() {
  local layout_file="$1"
  copy_into_joomla "$layout_file" /tmp/fase0-write-layout.json
  docker compose -f "${ROOT_DIR}/docker-compose.yml" exec -T joomla \
    env JOOMLA_DB_PREFIX="$JOOMLA_DB_PREFIX" TEMPLATE_KEY="$TEMPLATE_KEY" php <<'PHP'
<?php
declare(strict_types=1);
require '/var/www/html/configuration.php';
$prefix = getenv('JOOMLA_DB_PREFIX');
$key = getenv('TEMPLATE_KEY');
$layout = json_decode((string) file_get_contents('/tmp/fase0-write-layout.json'), true);
if (!is_array($layout)) {
    fwrite(STDERR, "layout file invalid\n");
    exit(1);
}
$config = new JConfig();
$pdo = new PDO('mysql:host=db;dbname=' . $config->db . ';charset=utf8mb4', $config->user, $config->password);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$stmt = $pdo->prepare("SELECT extension_id, custom_data FROM {$prefix}extensions WHERE type='plugin' AND folder='system' AND element='yootheme' LIMIT 1");
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$data = json_decode((string) $row['custom_data'], true) ?: [];
$templates = is_array($data['templates'] ?? null) ? $data['templates'] : [];
$templates[$key]['layout'] = $layout;
$data['templates'] = $templates;
$upd = $pdo->prepare("UPDATE {$prefix}extensions SET custom_data = ? WHERE extension_id = ?");
$upd->execute([json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (int) $row['extension_id']]);
echo "wrote {$key}\n";
PHP
}

seed_template() {
  docker compose -f "${ROOT_DIR}/docker-compose.yml" exec -T joomla \
    env JOOMLA_DB_PREFIX="$JOOMLA_DB_PREFIX" TEMPLATE_KEY="$TEMPLATE_KEY" php <<'PHP'
<?php
declare(strict_types=1);
require '/var/www/html/configuration.php';
$prefix = getenv('JOOMLA_DB_PREFIX');
$key = getenv('TEMPLATE_KEY');
$config = new JConfig();
$pdo = new PDO('mysql:host=db;dbname=' . $config->db . ';charset=utf8mb4', $config->user, $config->password);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$stmt = $pdo->prepare("SELECT extension_id, custom_data FROM {$prefix}extensions WHERE type='plugin' AND folder='system' AND element='yootheme' LIMIT 1");
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$data = [];
if (is_string($row['custom_data'] ?? null) && $row['custom_data'] !== '') {
    $decoded = json_decode($row['custom_data'], true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
}
$templates = is_array($data['templates'] ?? null) ? $data['templates'] : [];
$templates[$key] = [
    'name' => 'MirasAI Fase 0',
    'type' => 'com_content.article',
    'query' => ['option' => 'com_content', 'view' => 'article', 'id' => '1'],
    'layout' => [
        'type' => 'layout',
        'children' => [[
            'type' => 'section',
            'props' => ['title' => 'Fase 0 section'],
            'children' => [[
                'type' => 'row',
                'children' => [[
                    'type' => 'column',
                    'children' => [
                        [
                            'type' => 'headline',
                            'props' => ['content' => 'Original headline'],
                        ],
                        [
                            'type' => 'text',
                            'props' => ['content' => 'Original body copy'],
                        ],
                    ],
                ]],
            ]],
        ]],
    ],
];
$data['templates'] = $templates;
$upd = $pdo->prepare("UPDATE {$prefix}extensions SET custom_data = ? WHERE extension_id = ?");
$upd->execute([json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (int) $row['extension_id']]);
echo "seeded {$key}\n";
PHP
}

compare_pair() {
  local label="$1"
  local before="$2"
  local after="$3"
  local report="$4"
  php "$COMPARE_PHP" "$before" "$after" | tee "$report"
  echo "COMPARE ${label}: $(jq -r 'if .corrupt then "CORRUPT" else "clean" end' "$report")"
}

load_env
mkdir -p "$FIXTURE_DIR"

if [[ ! -f "$MCP_TOKEN_FILE" ]]; then
  echo "Missing MCP token. Run docker/bootstrap-lab.sh first." >&2
  exit 1
fi

echo "== seed L0 =="
seed_template
dump_layout "${FIXTURE_DIR}/00-seeded.json" >/dev/null

echo "== Customizer-equivalent first save (normalize L0) =="
save_transform "${FIXTURE_DIR}/00-seeded.json" "${FIXTURE_DIR}/01-customizer-normalized.json"
compare_pair "seed_vs_first_save" \
  "${FIXTURE_DIR}/00-seeded.json" \
  "${FIXTURE_DIR}/01-customizer-normalized.json" \
  "${FIXTURE_DIR}/01-compare-normalize.json" || true
write_layout_to_db "${FIXTURE_DIR}/01-customizer-normalized.json"

echo "== scenario 1: surgical element-update-props =="
etag="$(dump_layout "${FIXTURE_DIR}/02-baseline-normalized.json")"
headline_path="root>section[0]>row[0]>column[0]>headline[0]"
update_args="$(jq -cn \
  --arg key "$TEMPLATE_KEY" \
  --arg path "$headline_path" \
  --arg etag "$etag" \
  '{
    key:$key,
    path:$path,
    if_match:$etag,
    props:{content:"Fase0 surgical edit"},
    merge:true,
    include_element:true,
    confirm_guarded_write:true
  }')"
update_response="$(mcp_call 'template/element-update-props' "$update_args")"
printf '%s' "$update_response" | mcp_text | jq '{old_etag,new_etag,element}'
etag="$(dump_layout "${FIXTURE_DIR}/03-after-update-props.json")"
save_transform "${FIXTURE_DIR}/03-after-update-props.json" "${FIXTURE_DIR}/04-after-update-then-save.json"
compare_pair "scenario1_mcp_vs_save" \
  "${FIXTURE_DIR}/03-after-update-props.json" \
  "${FIXTURE_DIR}/04-after-update-then-save.json" \
  "${FIXTURE_DIR}/04-compare-scenario1.json" || true

echo "== scenario 2: element-add with minimal props =="
add_args="$(jq -cn \
  --arg key "$TEMPLATE_KEY" \
  --arg path "root>section[0]>row[0]>column[0]" \
  --arg etag "$etag" \
  '{
    key:$key,
    parent_path:$path,
    if_match:$etag,
    element:{type:"headline", props:{content:"minimal add"}},
    include_element:true,
    confirm_guarded_write:true
  }')"
add_response="$(mcp_call 'template/element-add' "$add_args")"
printf '%s' "$add_response" | mcp_text | jq '{old_etag,new_etag,path,element}'
etag="$(dump_layout "${FIXTURE_DIR}/05-after-element-add.json")"
save_transform "${FIXTURE_DIR}/05-after-element-add.json" "${FIXTURE_DIR}/06-after-add-then-save.json"
compare_pair "scenario2_mcp_vs_save" \
  "${FIXTURE_DIR}/05-after-element-add.json" \
  "${FIXTURE_DIR}/06-after-add-then-save.json" \
  "${FIXTURE_DIR}/06-compare-scenario2.json" || true

echo "== extra: unknown element type (Fase 1 risk probe) =="
jq '.children[0].children[0].children[0].children += [{"type":"mirasai_unknown_probe","props":{"content":"should this survive?"}}]' \
  "${FIXTURE_DIR}/01-customizer-normalized.json" > "${FIXTURE_DIR}/07-unknown-type-before.json"
save_transform "${FIXTURE_DIR}/07-unknown-type-before.json" "${FIXTURE_DIR}/08-unknown-type-after.json" || true
if [[ -s "${FIXTURE_DIR}/08-unknown-type-after.json" ]]; then
  compare_pair "unknown_type" \
    "${FIXTURE_DIR}/07-unknown-type-before.json" \
    "${FIXTURE_DIR}/08-unknown-type-after.json" \
    "${FIXTURE_DIR}/08-compare-unknown.json" || true
else
  echo '{"corrupt":true,"note":"Builder::load returned failure for unknown type"}' \
    | tee "${FIXTURE_DIR}/08-compare-unknown.json"
fi

echo "== scenario 3: version migration =="
cat > "${FIXTURE_DIR}/09-scenario3.txt" <<EOF
Not tested. Lab has a single YOOtheme Pro package (5.0.24). No older zip is
available to pin, so a real upgrade cycle cannot be run. Not simulated by
hand-editing version fields.
EOF
cat "${FIXTURE_DIR}/09-scenario3.txt"

jq -n \
  --slurpfile s1 "${FIXTURE_DIR}/04-compare-scenario1.json" \
  --slurpfile s2 "${FIXTURE_DIR}/06-compare-scenario2.json" \
  --slurpfile unk "${FIXTURE_DIR}/08-compare-unknown.json" \
  '{
    lab: "joomla-lxc-103",
    yootheme: "5.0.24",
    joomla: "5.4.5",
    mirasai: "0.8.2",
    customizer_path: "Builder::withParams(context=save)->load, same as TemplateController::saveTemplate",
    scenario1_surgical_update: $s1[0],
    scenario2_minimal_add: $s2[0],
    unknown_type_probe: $unk[0],
    scenario3_version_migration: "untested_no_older_zip"
  }' > "${FIXTURE_DIR}/report.json"

echo "== report =="
jq '{
  scenario1_corrupt: .scenario1_surgical_update.corrupt,
  scenario2_corrupt: .scenario2_minimal_add.corrupt,
  unknown_corrupt: .unknown_type_probe.corrupt,
  scenario3: .scenario3_version_migration
}' "${FIXTURE_DIR}/report.json"
