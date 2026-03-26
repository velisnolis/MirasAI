# MirasAI ReReplacer Phase 1 Spec

This document defines the first implementation phase for a future `plg_mirasai_rereplacer` addon.

Phase 1 focuses on safe read and simple write capabilities for agents. It does not attempt to expose the full ReReplacer or Conditions admin UI.

## Goals

Phase 1 should let agents:

- inspect existing ReReplacer items
- inspect existing Conditions sets
- create safe simple replacements
- update safe simple replacements
- attach existing condition sets to replacements
- publish, unpublish, or trash items
- preview likely scope and risk before writing

Phase 1 should not let agents:

- create unrestricted regex replacements
- create PHP-based replacements
- create XML-based replacement batches
- write replacements into unsafe areas like admin or edit forms
- author complex Conditions group/rule structures

## Provider

- Provider id: `mirasai.rereplacer`
- Provider name: `MirasAI ReReplacer`

Availability expectations:

- ReReplacer item tools require `com_rereplacer` and `plg_system_rereplacer`
- Conditions tools require `com_conditions`

## Tool Surface

Recommended Phase 1 tools:

- `rereplacer/capabilities`
- `rereplacer/list-items`
- `rereplacer/read-item`
- `rereplacer/create-item-simple`
- `rereplacer/update-item-simple`
- `rereplacer/publish-item`
- `conditions/list`
- `conditions/read`
- `rereplacer/attach-condition`
- `rereplacer/preview-match-scope`

## Tool Contracts

### `rereplacer/capabilities`

Purpose:

- report whether this site has ReReplacer Free or PRO, whether Conditions is installed, and which ReReplacer features are unavailable without PRO

Inputs:

- none

Output:

- `installed`
- `version`
- `is_pro`
- `conditions_installed`
- `features`
- `pro_required_for[]`
- `summary`

### `rereplacer/list-items`

Purpose:

- list existing ReReplacer items in a compact agent-friendly format

Inputs:

- `published` optional: `published | unpublished | trashed | all`
- `area` optional: `articles | body | head | everywhere`
- `search_text` optional
- `limit` optional

Output:

- `items[]` with:
  - `id`
  - `name`
  - `published`
  - `area`
  - `has_conditions`
  - `condition_id`
  - `condition_name`

### `rereplacer/read-item`

Purpose:

- read a single item with enough detail to reason about updates and risk

Inputs:

- `id` required

Output:

- `id`
- `name`
- `description`
- `search`
- `replace`
- `area`
- `published`
- `flags`
  - `casesensitive`
  - `word_search`
  - `strip_p_tags`
  - `has_conditions`
- `condition`
- `risk_summary`

### `rereplacer/create-item-simple`

Purpose:

- create a simple, non-elevated ReReplacer item

Inputs:

- `name` required
- `search` required
- `replace` required
- `area` optional
- `description` optional
- `published` optional
- `casesensitive` optional
- `word_search` optional
- `strip_p_tags` optional
- `condition_id` optional

Allowed scope:

- `area = articles` or `area = body`

Blocked features:

- `regex`
- `treat_as_php`
- `use_xml`
- `enable_in_admin`
- `enable_in_edit_forms`
- `area = head`
- `area = everywhere`

Validation rules:

- `search` must not be empty
- reject overly generic searches unless constrained by `word_search` or condition
- reject advanced flags not supported in Phase 1
- verify `condition_id` exists when provided

Output:

- `created`
- `item_id`
- `draft_state`
- `applied_defaults`
- `warnings[]`

### `rereplacer/update-item-simple`

Purpose:

- update an existing item if it stays inside the Phase 1 safe subset

Inputs:

- `id` required
- any supported simple-write field optional

Rules:

- must refuse to edit advanced items that already rely on regex, PHP, XML, admin scope, or other elevated behavior
- should direct the agent to an elevated tool once such tools exist

Output:

- `updated`
- `item_id`
- `changes[]`
- `warnings[]`

### `rereplacer/publish-item`

Purpose:

- publish, unpublish, or trash an item

Inputs:

- `id` required
- `state` required: `published | unpublished | trashed`

Output:

- `id`
- `old_state`
- `new_state`

### `conditions/list`

Purpose:

- list reusable Conditions sets

Inputs:

- `search_text` optional
- `limit` optional

Output:

- `conditions[]` with:
  - `id`
  - `name`
  - `alias`
  - `published`
  - `rule_count`
  - `summary`

### `conditions/read`

Purpose:

- inspect a Conditions set before reuse

Inputs:

- `id` required

Output:

- `id`
- `name`
- `alias`
- `published`
- `match_all`
- `groups[]`
- `rules[]`
- `usage[]` when practical

### `rereplacer/attach-condition`

Purpose:

- attach an existing Conditions set to an existing ReReplacer item

Inputs:

- `item_id` required
- `condition_id` required

Validation rules:

- item must exist
- condition must exist
- tool should reject unsupported advanced item states if needed

Output:

- `attached`
- `item_id`
- `condition_id`
- `condition_name`

### `rereplacer/preview-match-scope`

Purpose:

- estimate scope and risk before writing

Inputs:

- either a proposed simple item payload
- or an `item_id`

Output:

- `risk_level`: `low | medium | high`
- `scope_summary`
- `reasons[]`
- `recommended_condition_types[]`
- `requires_elevation`

## Safe Defaults

Recommended defaults:

- `published = false`
- prefer `area = articles` when the request is clearly article-content specific
- otherwise default to `area = body`
- warn if no condition narrows scope and the search phrase looks broad

## Elevation Boundary

The following belong outside Phase 1:

- unrestricted regex replacements
- PHP replacements
- XML file replacements
- `head` replacements
- `everywhere` replacements
- admin and edit-form replacements
- advanced tag or attribute rewriting
- advanced Conditions authoring with multiple groups and complex include/exclude logic

These should eventually move into explicit elevated tools with human confirmation.

## Agent Policy

Agents using Phase 1 tools should follow this order:

1. `rereplacer/list-items`
2. `conditions/list`
3. `rereplacer/preview-match-scope`
4. `rereplacer/create-item-simple` or `rereplacer/update-item-simple`
5. `rereplacer/attach-condition`
6. `rereplacer/publish-item`

If the plan requires features outside the safe subset, the agent should stop and explain why elevation is needed.

## Notes For Future Work

Potential later-phase elevated tools:

- `rereplacer/create-item-advanced`
- `rereplacer/update-item-advanced`
- `conditions/create-set-simple`
- `conditions/create-set-advanced`

Future docs should add:

- concrete JSON input examples
- exact response payload examples
- rollback procedures
- wording for human risk confirmation
