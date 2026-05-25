#!/usr/bin/env bash
# Integration checks for YOOtheme template list/summary/translate ETag handling.
# Requires: Docker lab running (bootstrap-lab.sh), jq installed.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MCP_TOKEN_FILE="${ROOT_DIR}/.docker-build/mcp-token.txt"
TEMPLATE_KEY="mirasai-etag-lab"
TARGET_LANGUAGE="es-ES"

require_cmd() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Missing required command: $1" >&2
    exit 1
  fi
}

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

mcp_request() {
  local payload="$1"

  curl -fsS -X POST "http://127.0.0.1:${JOOMLA_HTTP_PORT}/api/v1/mirasai/mcp" \
    -H 'Content-Type: application/json' \
    -H "X-Joomla-Token: $(cat "$MCP_TOKEN_FILE")" \
    -d "$payload"
}

mcp_call() {
  local tool_name="$1"
  local args_json="$2"
  local payload

  payload="$(jq -cn \
    --arg name "$tool_name" \
    --argjson args "$args_json" \
    '{jsonrpc:"2.0",method:"tools/call",params:{name:$name,arguments:$args},id:1}')"

  mcp_request "$payload"
}

extract_result() {
  local response="$1"
  local result

  result="$(printf '%s' "$response" | jq -r '.result.content[0].text // empty' 2>/dev/null || true)"

  if [[ -n "$result" ]]; then
    printf '%s' "$result"
    return 0
  fi

  printf '%s' "$response"
}

db_exec() {
  docker compose exec -T db sh -eu -c \
    "mysql -N -u\"${MYSQL_USER}\" -p\"${MYSQL_PASSWORD}\" \"${MYSQL_DATABASE}\" -e \"$1\""
}

ensure_language() {
  local lang_tag="$1"
  local title="$2"
  local exists

  exists="$(db_exec "SELECT COUNT(*) FROM ${JOOMLA_DB_PREFIX}languages WHERE lang_code='${lang_tag}';")"

  if [[ "${exists// /}" == "0" ]]; then
    db_exec "INSERT INTO ${JOOMLA_DB_PREFIX}languages (lang_code, title, title_native, sef, image, description, metadesc, published, access, ordering) VALUES ('${lang_tag}', '${title}', '${title}', '${lang_tag%%-*}', '${lang_tag%%-*}', '${title}', '', 1, 1, 0);"
  else
    db_exec "UPDATE ${JOOMLA_DB_PREFIX}languages SET published=1 WHERE lang_code='${lang_tag}';" >/dev/null
  fi
}

seed_template_fixture() {
  docker compose exec -T joomla env JOOMLA_DB_PREFIX="$JOOMLA_DB_PREFIX" php <<'PHP'
<?php
declare(strict_types=1);

require '/var/www/html/configuration.php';

$prefix = getenv('JOOMLA_DB_PREFIX');
$config = new JConfig();
$pdo = new PDO('mysql:host=db;dbname=' . $config->db . ';charset=utf8mb4', $config->user, $config->password);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stmt = $pdo->prepare("SELECT extension_id, custom_data FROM {$prefix}extensions WHERE type = 'plugin' AND folder = 'system' AND element = 'yootheme' LIMIT 1");
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    fwrite(STDERR, "YOOtheme system plugin is not installed.\n");
    exit(1);
}

$data = [];
if (is_string($row['custom_data']) && $row['custom_data'] !== '') {
    $decoded = json_decode($row['custom_data'], true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
}

$templates = is_array($data['templates'] ?? null) ? $data['templates'] : [];

foreach ($templates as $key => $template) {
    if (is_array($template) && str_starts_with((string) ($template['name'] ?? ''), 'MirasAI ETag Lab')) {
        unset($templates[$key]);
    }
}

$templates['mirasai-etag-lab'] = [
    'name' => 'MirasAI ETag Lab',
    'type' => 'com_content.article',
    'query' => [
        'option' => 'com_content',
        'view' => 'article',
        'id' => '1',
    ],
    'layout' => [
        'type' => 'layout',
        'children' => [[
            'type' => 'section',
            'props' => ['title' => 'Lab section'],
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
                            'props' => [
                                'content' => 'Original body copy',
                                'source' => [
                                    'query' => [
                                        'name' => 'Article',
                                        'field' => [
                                            'name' => 'article',
                                            'arguments' => ['id' => '1'],
                                        ],
                                    ],
                                    'props' => [
                                        'content' => ['name' => 'title'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]],
            ]],
        ]],
    ],
];

$data['templates'] = $templates;

$stmt = $pdo->prepare("UPDATE {$prefix}extensions SET custom_data = ? WHERE extension_id = ?");
$stmt->execute([
    json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    (int) $row['extension_id'],
]);

echo "seeded\n";
PHP
}

assert_jq() {
  local json="$1"
  shift
  local message="${*: -1}"
  local jq_args=("$@")
  unset 'jq_args[${#jq_args[@]}-1]'

  if ! printf '%s' "$json" | jq -e "${jq_args[@]}" >/dev/null; then
    echo "Assertion failed: ${message}" >&2
    echo "$json" >&2
    exit 1
  fi
}

main() {
  require_cmd curl
  require_cmd docker
  require_cmd jq
  load_env

  if [[ ! -f "$MCP_TOKEN_FILE" ]]; then
    echo "Missing MCP token file. Run docker/bootstrap-lab.sh first." >&2
    exit 1
  fi

  ensure_language "$TARGET_LANGUAGE" "Espanyol"
  seed_template_fixture >/dev/null

  local tools_response
  tools_response="$(mcp_request '{"jsonrpc":"2.0","method":"tools/list","params":{},"id":1}')"
  assert_jq "$tools_response" '.result.tools[]? | select(.name == "template/summary")' 'template/summary is registered'
  assert_jq "$tools_response" '.result.tools[]? | select(.name == "template/element-types")' 'template/element-types is registered'
  assert_jq "$tools_response" '.result.tools[]? | select(.name == "template/element-schema")' 'template/element-schema is registered'
  assert_jq "$tools_response" '.result.tools[]? | select(.name == "template/source-types")' 'template/source-types is registered'
  assert_jq "$tools_response" '.result.tools[]? | select(.name == "template/element-list")' 'template/element-list is registered'
  assert_jq "$tools_response" '.result.tools[]? | select(.name == "template/element-read")' 'template/element-read is registered'
  assert_jq "$tools_response" '.result.tools[]? | select(.name == "template/element-source-read")' 'template/element-source-read is registered'
  assert_jq "$tools_response" '.result.tools[]? | select(.name == "template/element-add")' 'template/element-add is registered'
  assert_jq "$tools_response" '.result.tools[]? | select(.name == "template/element-update-props")' 'template/element-update-props is registered'
  assert_jq "$tools_response" '.result.tools[]? | select(.name == "template/element-move")' 'template/element-move is registered'
  assert_jq "$tools_response" '.result.tools[]? | select(.name == "template/element-clone")' 'template/element-clone is registered'
  assert_jq "$tools_response" '.result.tools[]? | select(.name == "template/element-delete")' 'template/element-delete is registered'
  assert_jq "$tools_response" '.result.tools[]? | select(.name == "template/translate")' 'template/translate is registered'
  assert_jq "$tools_response" '.result.tools[]? | select(.metadata.surface == "essential" and .name == "template/element-list")' 'tools/list exposes essential surface metadata'

  local essentials_response
  essentials_response="$(mcp_request '{"jsonrpc":"2.0","method":"tools/list","params":{"surface":"essential"},"id":11}')"
  assert_jq "$essentials_response" '.result.tools[]? | select(.name == "template/element-list")' 'essential tools/list includes element-list'
  assert_jq "$essentials_response" 'all(.result.tools[]?; .metadata.surface == "essential")' 'essential tools/list filters advanced tools'

  local list_response list_result etag collection_etag
  list_response="$(mcp_call 'template/list' '{"fields":["key","name","etag","language"]}')"
  list_result="$(extract_result "$list_response")"
  assert_jq "$list_result" --arg key "$TEMPLATE_KEY" '.templates[]? | select(.key == $key and .etag != null)' 'template/list returns fixture with etag'
  collection_etag="$(printf '%s' "$list_result" | jq -r '.collection_etag')"
  etag="$(printf '%s' "$list_result" | jq -r --arg key "$TEMPLATE_KEY" '.templates[] | select(.key == $key) | .etag')"

  if [[ -z "$collection_etag" || "$collection_etag" == "null" || -z "$etag" || "$etag" == "null" ]]; then
    echo "Missing template or collection ETag." >&2
    echo "$list_result" >&2
    exit 1
  fi

  local summary_response summary_result
  summary_response="$(mcp_call 'template/summary' "$(jq -cn --arg key "$TEMPLATE_KEY" '{key:$key}')")"
  summary_result="$(extract_result "$summary_response")"
  assert_jq "$summary_result" --arg etag "$etag" '.etag == $etag' 'template/summary returns the same current etag'
  assert_jq "$summary_result" '.layout_summary.total_elements >= 5' 'template/summary returns layout summary'
  assert_jq "$summary_result" '.translatable_node_count >= 2' 'template/summary sees translatable nodes'

  local element_types_response element_types_result
  element_types_response="$(mcp_call 'template/element-types' "$(jq -cn --arg key "$TEMPLATE_KEY" '{key:$key,fields:["type","count","prop_keys","sample_paths"]}')")"
  element_types_result="$(extract_result "$element_types_response")"
  assert_jq "$element_types_result" '.types[]? | select(.type == "headline" and (.prop_keys | index("content")))' 'template/element-types returns observed headline props'
  assert_jq "$element_types_result" '.types[]? | select(.type == "text" and (.sample_paths | length >= 1))' 'template/element-types returns text sample paths'

  local element_schema_response element_schema_result
  element_schema_response="$(mcp_call 'template/element-schema' '{"type":"headline","fields":["name","type","source","ref","options"]}')"
  element_schema_result="$(extract_result "$element_schema_response")"
  assert_jq "$element_schema_result" '.type == "headline" and .field_count > 0 and (.source_fields[]? | select(.name == "content" and .source == true))' 'template/element-schema returns runtime source-capable fields'

  local source_types_response source_types_result
  source_types_response="$(mcp_call 'template/source-types' '{"type":"Article","include_fields":true}')"
  source_types_result="$(extract_result "$source_types_response")"
  assert_jq "$source_types_result" '((.mode == "live_introspection") and (.types[]? | select(.name == "Article" and .field_count > 0))) or ((.mode == "static_package_scan") and (.packages[]? | select(.name == "builder-joomla-source")))' 'template/source-types discovers YOOtheme source providers'

  local element_list_response element_list_result headline_path text_source_path element_read_response element_read_result element_source_response element_source_result
  element_list_response="$(mcp_call 'template/element-list' "$(jq -cn --arg key "$TEMPLATE_KEY" '{key:$key,fields:["path","type","label","has_source_binding"]}')")"
  element_list_result="$(extract_result "$element_list_response")"
  assert_jq "$element_list_result" '.elements[]? | select(.type == "headline" and .label == "Original headline")' 'template/element-list returns the headline element'
  assert_jq "$element_list_result" '.elements[]? | select(.type == "text" and .has_source_binding == true)' 'template/element-list detects Dynamic Source bindings'
  headline_path="$(printf '%s' "$element_list_result" | jq -r '.elements[] | select(.type == "headline" and .label == "Original headline") | .path' | head -n 1)"
  text_source_path="$(printf '%s' "$element_list_result" | jq -r '.elements[] | select(.type == "text" and .has_source_binding == true) | .path' | head -n 1)"

  if [[ -z "$headline_path" || "$headline_path" == "null" ]]; then
    echo "Missing headline path from template/element-list." >&2
    echo "$element_list_result" >&2
    exit 1
  fi

  element_read_response="$(mcp_call 'template/element-read' "$(jq -cn --arg key "$TEMPLATE_KEY" --arg path "$headline_path" '{key:$key,path:$path}')")"
  element_read_result="$(extract_result "$element_read_response")"
  assert_jq "$element_read_result" --arg path "$headline_path" '.metadata.path == $path and .element.type == "headline" and .element.props.content == "Original headline" and .include_children == false' 'template/element-read returns the requested element compactly'

  element_source_response="$(mcp_call 'template/element-source-read' "$(jq -cn --arg key "$TEMPLATE_KEY" --arg path "$text_source_path" '{key:$key,path:$path}')")"
  element_source_result="$(extract_result "$element_source_response")"
  assert_jq "$element_source_result" '.binding.has_binding == true and .binding.canonical_location == "props.source" and .binding.source_name == "Article" and (.binding.field_mappings[]? | select(.prop == "content" and .field == "title")) and (.binding.raw_source | not)' 'template/element-source-read summarizes props.source bindings'

  local unconfirmed_update_args unconfirmed_update_response unconfirmed_update_result dry_run_update_args dry_run_update_response dry_run_update_result update_args update_response update_result updated_etag updated_read_response updated_read_result stale_update_args stale_update_response stale_update_result
  unconfirmed_update_args="$(jq -cn \
    --arg key "$TEMPLATE_KEY" \
    --arg path "$headline_path" \
    --arg etag "$etag" \
    '{
      key:$key,
      path:$path,
      if_match:$etag,
      props:{content:"Should require confirmation"}
    }')"
  unconfirmed_update_response="$(mcp_call 'template/element-update-props' "$unconfirmed_update_args")"
  unconfirmed_update_result="$(extract_result "$unconfirmed_update_response")"
  assert_jq "$unconfirmed_update_result" '.code == "guarded_write_confirmation_required"' 'guarded writes require explicit confirmation'

  dry_run_update_args="$(jq -cn \
    --arg key "$TEMPLATE_KEY" \
    --arg path "$headline_path" \
    --arg etag "$etag" \
    '{
      key:$key,
      path:$path,
      if_match:$etag,
      props:{content:"Preview headline from element-update-props"},
      dry_run:true,
      include_element:true
    }')"
  dry_run_update_response="$(mcp_call 'template/element-update-props' "$dry_run_update_args")"
  dry_run_update_result="$(extract_result "$dry_run_update_response")"
  assert_jq "$dry_run_update_result" --arg etag "$etag" '.dry_run == true and .action == "preview" and .old_etag == $etag and .new_etag != $etag and .cache.reason == "dry_run" and .element.props.content == "Preview headline from element-update-props"' 'template/element-update-props dry_run previews without confirmation'

  update_args="$(jq -cn \
    --arg key "$TEMPLATE_KEY" \
    --arg path "$headline_path" \
    --arg etag "$etag" \
    '{
      key:$key,
      path:$path,
      if_match:$etag,
      props:{content:"Updated headline from element-update-props", title_element:"h2"},
      merge:true,
      include_element:true,
      confirm_guarded_write:true
    }')"
  update_response="$(mcp_call 'template/element-update-props' "$update_args")"
  update_result="$(extract_result "$update_response")"
  assert_jq "$update_result" --arg etag "$etag" '.old_etag == $etag and (.new_etag | type == "string") and .new_etag != $etag and (.cache.groups | index("com_templates")) and .element.props.content == "Updated headline from element-update-props" and .element.props.title_element == "h2"' 'template/element-update-props updates props with matching if_match and clears cache'
  updated_etag="$(printf '%s' "$update_result" | jq -r '.new_etag')"

  updated_read_response="$(mcp_call 'template/element-read' "$(jq -cn --arg key "$TEMPLATE_KEY" --arg path "$headline_path" '{key:$key,path:$path}')")"
  updated_read_result="$(extract_result "$updated_read_response")"
  assert_jq "$updated_read_result" '.element.props.content == "Updated headline from element-update-props" and .element.props.title_element == "h2"' 'template/element-read sees updated props'

  stale_update_args="$(jq -cn \
    --arg key "$TEMPLATE_KEY" \
    --arg path "$headline_path" \
    '{
      key:$key,
      path:$path,
      if_match:"definitely-stale",
      props:{content:"Should not write"},
      confirm_guarded_write:true
    }')"
  stale_update_response="$(mcp_call 'template/element-update-props' "$stale_update_args")"
  stale_update_result="$(extract_result "$stale_update_response")"
  assert_jq "$stale_update_result" '.code == "stale_etag"' 'template/element-update-props rejects stale if_match'

  local add_args add_response add_result added_path added_etag clone_args clone_response clone_result cloned_path cloned_etag move_args move_response move_result moved_path moved_etag delete_args delete_response delete_result deleted_etag
  add_args="$(jq -cn \
    --arg key "$TEMPLATE_KEY" \
    --arg path "root>section[0]>row[0]>column[0]" \
    --arg etag "$updated_etag" \
    '{
      key:$key,
      parent_path:$path,
      if_match:$etag,
      element:{type:"text", props:{content:"Added body copy"}},
      include_element:true,
      confirm_guarded_write:true
    }')"
  add_response="$(mcp_call 'template/element-add' "$add_args")"
  add_result="$(extract_result "$add_response")"
  assert_jq "$add_result" --arg etag "$updated_etag" '.old_etag == $etag and (.new_etag | type == "string") and .new_etag != $etag and (.cache.groups | index("com_templates")) and .element.type == "text" and .element.props.content == "Added body copy"' 'template/element-add adds a child with matching if_match and clears cache'
  added_path="$(printf '%s' "$add_result" | jq -r '.path')"
  added_etag="$(printf '%s' "$add_result" | jq -r '.new_etag')"

  clone_args="$(jq -cn \
    --arg key "$TEMPLATE_KEY" \
    --arg path "$headline_path" \
    --arg etag "$added_etag" \
    '{
      key:$key,
      path:$path,
      if_match:$etag,
      include_element:true,
      confirm_guarded_write:true
    }')"
  clone_response="$(mcp_call 'template/element-clone' "$clone_args")"
  clone_result="$(extract_result "$clone_response")"
  assert_jq "$clone_result" --arg etag "$added_etag" '.old_etag == $etag and (.new_etag | type == "string") and .new_etag != $etag and (.cache.groups | index("com_templates")) and .element.type == "headline"' 'template/element-clone clones a sibling with matching if_match and clears cache'
  cloned_path="$(printf '%s' "$clone_result" | jq -r '.new_path')"
  cloned_etag="$(printf '%s' "$clone_result" | jq -r '.new_etag')"

  move_args="$(jq -cn \
    --arg key "$TEMPLATE_KEY" \
    --arg path "$cloned_path" \
    --arg target "root>section[0]" \
    --arg etag "$cloned_etag" \
    '{
      key:$key,
      path:$path,
      target_parent_path:$target,
      if_match:$etag,
      include_element:true,
      confirm_guarded_write:true
    }')"
  move_response="$(mcp_call 'template/element-move' "$move_args")"
  move_result="$(extract_result "$move_response")"
  assert_jq "$move_result" --arg etag "$cloned_etag" --arg old "$cloned_path" '.old_etag == $etag and .old_path == $old and (.new_path | startswith("root>section[0]>headline[")) and (.cache.groups | index("com_templates")) and .element.type == "headline"' 'template/element-move moves a cloned element and clears cache'
  moved_path="$(printf '%s' "$move_result" | jq -r '.new_path')"
  moved_etag="$(printf '%s' "$move_result" | jq -r '.new_etag')"

  delete_args="$(jq -cn \
    --arg key "$TEMPLATE_KEY" \
    --arg path "$moved_path" \
    --arg etag "$moved_etag" \
    '{
      key:$key,
      path:$path,
      if_match:$etag,
      confirm_guarded_write:true
    }')"
  delete_response="$(mcp_call 'template/element-delete' "$delete_args")"
  delete_result="$(extract_result "$delete_response")"
  assert_jq "$delete_result" --arg etag "$moved_etag" --arg path "$moved_path" '.old_etag == $etag and .deleted_path == $path and .deleted_type == "headline" and (.new_etag | type == "string") and (.cache.groups | index("com_templates"))' 'template/element-delete deletes the moved element and clears cache'
  deleted_etag="$(printf '%s' "$delete_result" | jq -r '.new_etag')"

  local translate_args translate_response translate_result
  translate_args="$(jq -cn \
    --arg key "$TEMPLATE_KEY" \
    --arg lang "$TARGET_LANGUAGE" \
    --arg etag "$deleted_etag" \
    '{
      key:$key,
      target_language:$lang,
      translated_name:"MirasAI ETag Lab (es-ES)",
      if_match:$etag,
      overwrite:true,
      dry_run:true,
      yootheme_text_replacements:{
        "root>section[0].title":"Lab section ES",
        "root>section[0]>row[0]>column[0]>headline[0].content":"Translated headline"
      }
    }')"
  translate_response="$(mcp_call 'template/translate' "$translate_args")"
  translate_result="$(extract_result "$translate_response")"
  assert_jq "$translate_result" --arg key "$TEMPLATE_KEY" --arg lang "$TARGET_LANGUAGE" --arg etag "$deleted_etag" '.dry_run == true and .source_key == $key and .target_language == $lang and .source_etag == $etag and (.target_etag | type == "string") and .cache.reason == "dry_run"' 'template/translate dry_run accepts matching if_match'

  translate_args="$(jq -cn \
    --arg key "$TEMPLATE_KEY" \
    --arg lang "$TARGET_LANGUAGE" \
    --arg etag "$deleted_etag" \
    '{
      key:$key,
      target_language:$lang,
      translated_name:"MirasAI ETag Lab (es-ES)",
      if_match:$etag,
      overwrite:true,
      confirm_guarded_write:true,
      yootheme_text_replacements:{
        "root>section[0].title":"Lab section ES",
        "root>section[0]>row[0]>column[0]>headline[0].content":"Translated headline"
      }
    }')"
  translate_response="$(mcp_call 'template/translate' "$translate_args")"
  translate_result="$(extract_result "$translate_response")"
  assert_jq "$translate_result" --arg key "$TEMPLATE_KEY" --arg lang "$TARGET_LANGUAGE" --arg etag "$deleted_etag" '.dry_run == false and .source_key == $key and .target_language == $lang and .source_etag == $etag and (.target_etag | type == "string") and (.cache.groups | index("com_templates"))' 'template/translate accepts matching if_match with confirmation and clears cache'

  local stale_args stale_response stale_result
  stale_args="$(jq -cn \
    --arg key "$TEMPLATE_KEY" \
    --arg lang "$TARGET_LANGUAGE" \
    '{
      key:$key,
      target_language:$lang,
      if_match:"definitely-stale",
      overwrite:true,
      confirm_guarded_write:true,
      yootheme_text_replacements:{}
    }')"
  stale_response="$(mcp_call 'template/translate' "$stale_args")"
  stale_result="$(extract_result "$stale_response")"
  assert_jq "$stale_result" '.code == "stale_etag"' 'template/translate rejects stale if_match'

  echo "Template ETag integration checks passed."
}

main "$@"
