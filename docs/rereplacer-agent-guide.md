# MirasAI ReReplacer Agent Guide

This guide defines how agents should think about ReReplacer and Conditions when MirasAI exposes a `plg_mirasai_rereplacer` addon.

The goal is not to mirror every Regular Labs admin option. The goal is to give agents a safe mental model for choosing the right replacement strategy, understanding when Conditions make a change more surgical, and recognizing when a request needs elevation.

## Purpose

ReReplacer is a runtime content transformation layer for Joomla output.

Agents should use it when they need to:

- patch rendered output without manually editing many Joomla articles
- scope a change to specific pages, languages, URLs, menus, templates, or user contexts
- apply a repeatable transformation that content editors would otherwise maintain by hand

Agents should not use it when the problem is better solved by:

- editing the source article or module directly
- fixing a template override or component bug
- creating a proper Joomla module, plugin, or YOOtheme change
- running arbitrary PHP inside replacements when a simpler solution exists

## Primary References

- [Regular Labs ReReplacer docs](https://docs.regularlabs.com/rereplacer)
- [ReReplacer Conditions docs](https://docs.regularlabs.com/rereplacer/the-basics/conditions)
- [MirasAI Plugin Developer Guide](/Users/alexmiras/Desktop/Claude Code Default/MovaMiraAI/docs/plugin-developer-guide.md)

## Mental Model

Think in three layers:

1. `Replacement`
   A `search -> replace` rule that changes rendered output.
2. `Area`
   Where the replacement runs, such as `articles`, `body`, `head`, or `everywhere`.
3. `Condition Set`
   A reusable scope definition that limits where or when the replacement applies.

In practical terms:

- ReReplacer decides what text or markup is transformed.
- Conditions decides when the transformation is allowed to happen.
- MirasAI should expose both, but default to the safest subset first.

## What We Observed On A Real Site

On the reference site `acvic`, ReReplacer is installed as `14.5.11PRO` and Conditions as `26.1.17914`.

Observed usage pattern:

- most replacements run in `body`
- current items are mostly simple text replacements
- several items use reusable language-based condition sets
- advanced features like PHP, XML, and regex are not the dominant pattern

This matters because the first MirasAI tools should match the common safe workflow:

- inspect existing items
- create or update simple replacements
- attach an existing condition set
- publish or unpublish

## Safe-By-Default Strategy

Agents should prefer this order:

1. Reuse an existing condition set if one already matches the request.
2. Use the narrowest viable area, usually `articles` or `body`.
3. Use simple text replacement before regex.
4. Use a targeted condition before broadening the search area.
5. Ask for elevation before using risky features.

### Good default behavior

- page-specific call-to-action text or markup
- language-specific text swaps
- replacing known placeholder strings
- applying a small output patch to a specific menu item or URL

### Bad default behavior

- broad replacements on common words across the whole site
- changing markup in `head` without a precise reason
- replacing inside admin or edit forms
- using `everywhere` because it is convenient
- using PHP because the requested output is dynamic

## Phase 1 Tooling Philosophy

Phase 1 should expose general-purpose primitives for agents, not a giant low-level mirror of the full ReReplacer admin UI.

Recommended non-elevated tool surface:

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

These tools should be expressive enough that agents can solve open-ended requests, but constrained enough to avoid easy footguns.

## What Counts As “Simple”

Non-elevated simple operations should allow:

- plain `search`
- plain `replace`
- limited HTML output in `replace`
- area restricted to `articles` or `body`
- existing `condition_id` attachment
- basic flags such as case sensitivity or word search, if clearly safe

Non-elevated simple operations should block or ignore:

- `treat_as_php`
- `use_xml`
- `enable_in_admin`
- `enable_in_edit_forms`
- `area = everywhere`
- unrestricted regex
- broad tag/attribute rewrites
- complex `between_start` and `between_end` patterns

## Elevation Model

Some ReReplacer operations are powerful enough that they should only be available after explicit human approval.

Elevation should be required for:

- unrestricted regex replacements
- `treat_as_php`
- XML-driven bulk replacements
- replacements in `head`
- replacements in `everywhere`
- replacements in admin or edit forms
- complex HTML attribute rewriting
- advanced multi-group condition authoring
- any replacement whose scope cannot be previewed confidently

Before using an elevated tool, the agent should explain:

- what it wants to change
- why simple tools are not enough
- what could go wrong
- how the human can roll it back

## Conditions: How Agents Should Use Them

Conditions make replacements surgical. Agents should reach for Conditions before broadening area or search patterns.

Preferred early condition families:

- language
- menu item
- URL
- component
- template
- date or time

Lower-priority condition families for early support:

- geolocation
- custom PHP conditions
- third-party extension-specific conditions

### Example reasoning

If a user asks:

- “Only show this replacement on the English site”
  Prefer a language condition set over duplicating many manual page-level replacements.
- “Only on this landing page”
  Prefer a URL or menu condition instead of using a fragile search pattern.
- “Only in article content”
  Prefer `articles` area over `body`.

## Agent Workflow

When solving a request with ReReplacer, agents should follow this sequence:

1. Inspect current state.
   List existing items and relevant conditions.
2. Choose the narrowest approach.
   Prefer existing condition sets and the smallest viable area.
3. Preview the expected scope.
   If scope is ambiguous, stop and tighten the plan.
4. Create or update the item.
5. Publish only after the item definition looks safe.
6. Report back with:
   the item name, area, condition, risk level, and rollback path.

## Recommended Tool Documentation Pattern

Every ReReplacer-related tool should include:

- what it does
- when to use it
- when not to use it
- required inputs
- safe defaults
- blocked risky inputs
- example calls
- whether elevation is required

Example documentation shape for `rereplacer/create-item-simple`:

- Purpose: Create a plain text or limited-HTML replacement in a safe output area.
- Use when: The change can be expressed without regex, PHP, XML, or admin/edit-form scope.
- Do not use when: The request requires `head`, `everywhere`, regex-heavy parsing, or dynamic PHP output.
- Defaults: `published = 0`, `area = body` unless a narrower area is available.
- Safety: Reject common dangerous combinations, such as common-word replacements with no condition and no word boundaries.

## Example Requests And The Right Direction

These are examples for humans and agents. They are not the only supported outcomes.

### “Create a WhatsApp button on this page”

Likely strategy:

- create a simple replacement or placeholder-driven insertion
- restrict to one menu item or URL through Conditions
- keep the area in `body` or `articles`

### “Every YouTube embed should become responsive”

Likely strategy:

- inspect whether simple replacement is enough
- if not, this probably moves toward elevated regex
- prefer scoping to content output before touching the whole page body

### “Replace the company name with an SVG logo”

Likely strategy:

- first ask whether this should only affect visible content
- if yes, create a simple replacement in `articles` or `body`
- if the request includes meta tags, attributes, or `head`, treat it as elevated

## When ReReplacer Is The Wrong Tool

Agents should say so explicitly when:

- the user actually needs a reusable Joomla module
- the request is a template/layout problem
- the replacement would be too fragile to maintain
- the change belongs in structured content, not runtime output rewriting

ReReplacer is a powerful patch layer. It is not the right answer to every display problem.

## Suggested Skill Shape

A future `rereplacer` skill should teach agents to:

- start with `list-items` and `list-conditions`
- prefer existing conditions before creating new ones
- prefer `articles` or `body` over `head` or `everywhere`
- avoid elevation unless the simple path is clearly insufficient
- explain impact and rollback in plain language

The skill should link both to the official Regular Labs docs and to this local guide, so agents get:

- official feature semantics
- local safety policy
- examples from actual Joomla usage

## Next Step For Implementation

This guide assumes a Phase 1 addon that exposes safe read and simple-write tools first.

Once those tools exist, update this guide with:

- exact tool names
- JSON input examples
- returned payload shapes
- elevation wording
- rollback procedures
