# MirasAI Host MCP Contract v1

This document defines the HTTP contract implemented by a MirasAI host plugin and consumed by the local `mirasai-mcp` router.

The goal is one client-facing MCP server with multiple CMS hosts behind it:

```text
AI client -> mirasai-mcp router -> Joomla or WordPress MirasAI host
```

Each host remains usable directly as an MCP HTTP endpoint, but the preferred operator experience is the local router with a `site_id`.

## Endpoint

Each host exposes one JSON-RPC MCP endpoint.

Joomla:

```text
/api/v1/mirasai/mcp
```

WordPress:

```text
/wp-json/mirasai/v1/mcp
```

The endpoint must accept JSON-RPC `POST` requests. `GET` streaming support is optional for hosts and not required by the router contract.

## Authentication

Hosts authenticate with an HTTP header. Existing Joomla installations may continue to accept `X-Joomla-Token`.

Preferred v1 headers:

```text
X-MirasAI-Token: <token>
```

WordPress hosts should prefer WordPress Application Passwords:

```text
Authorization: Basic base64(username:application-password)
```

The authenticated WordPress user must have the host-required capability, currently `manage_options`.

Legacy Joomla compatibility:

```text
X-Joomla-Token: <joomla-api-token>
```

The router registry must not store production tokens in clear text by default. Preferred secret sources are:

- `token_ref`: 1Password reference such as `op://vault/item/field`
- `token_env`: environment variable name
- `token_plain_dev`: development-only clear token with warnings

## Required JSON-RPC Methods

Every host must support:

- `initialize`
- `tools/list`
- `tools/call`
- `ping`

Unsupported MCP surfaces such as resources, prompts, sampling, and roots may be omitted.

## Initialize Result

`initialize` must return:

```json
{
  "protocolVersion": "2024-11-05",
  "capabilities": {
    "tools": {
      "listChanged": false
    }
  },
  "serverInfo": {
    "name": "MirasAI",
    "version": "0.5.0"
  },
  "instructions": "..."
}
```

Hosts should include their platform and contract version in `serverInfo` when available:

```json
{
  "serverInfo": {
    "name": "MirasAI",
    "version": "0.5.0",
    "host_platform": "joomla",
    "host_contract_version": "1"
  }
}
```

For existing hosts that do not yet expose these keys, the router may infer the platform from the registry.

## Tools List

`tools/list` must return:

```json
{
  "tools": [
    {
      "name": "system/diagnose",
      "description": "...",
      "inputSchema": {
        "type": "object",
        "properties": {}
      },
      "annotations": {
        "readOnlyHint": true,
        "destructiveHint": false,
        "idempotentHint": true,
        "openWorldHint": false
      },
      "metadata": {
        "risk_level": "read",
        "workflow_hint": "direct",
        "surface": "essential",
        "platforms": ["joomla", "wordpress"]
      }
    }
  ]
}
```

Required tool fields:

- `name`
- `description`
- `inputSchema.type = "object"`
- `inputSchema.properties`
- `metadata.risk_level`
- `metadata.workflow_hint`
- `metadata.surface`

Recommended tool fields:

- `annotations.readOnlyHint`
- `annotations.destructiveHint`
- `annotations.idempotentHint`
- `annotations.openWorldHint`
- `metadata.platforms`
- `metadata.requires`

## Risk Model

`metadata.risk_level` must be one of:

- `read`: inspection only.
- `safe_write`: constrained CMS write through a domain-specific tool.
- `guarded_write`: persistent write requiring preview, ETag, or explicit confirmation.
- `dangerous_exec`: file mutation, deletion, PHP execution, or runtime/code persistence.

`metadata.workflow_hint` must be one of:

- `direct`
- `validate_then_apply`
- `dry_run_confirm_if_match`
- `elevation_required`

## Surfaces

`metadata.surface` must be one of:

- `essential`
- `advanced`

Hosts may support filtered discovery:

```json
{
  "surface": "essential"
}
```

If unsupported, returning all tools is acceptable, but every tool should still carry `metadata.surface`.

## Tool Calls

`tools/call` must accept:

```json
{
  "name": "system/diagnose",
  "arguments": {}
}
```

Tool results must use MCP CallToolResult shape:

```json
{
  "content": [
    {
      "type": "text",
      "text": "{\"ok\":true}"
    }
  ],
  "structuredContent": {
    "ok": true
  }
}
```

Tool-originated errors should be returned as a CallToolResult with `isError: true` and structured details:

```json
{
  "isError": true,
  "content": [
    {
      "type": "text",
      "text": "{\"error\":\"Template key is required.\"}"
    }
  ],
  "structuredContent": {
    "error": "Template key is required.",
    "code": "invalid_arguments"
  }
}
```

Transport-level JSON-RPC errors should be reserved for invalid JSON-RPC requests, unknown methods, authentication failures, and malformed method params.

## Router Rules

The local router owns `site_id`.

Remote tools are called with the original host tool name and arguments after removing router-only keys such as `site_id`.

The router should:

- default to `default_site_id` when `site_id` is omitted
- add `site_id` to proxied tool input schemas
- preserve remote `annotations` and `metadata`
- return `unknown_site` before contacting a host if the `site_id` is invalid
- return `tool_not_supported_on_platform` when a host does not expose the requested tool

## Platform Capabilities

Tools may declare compatibility:

```json
{
  "metadata": {
    "platforms": ["joomla", "wordpress"],
    "requires": ["yootheme"]
  }
}
```

The router uses this for diagnostics and clearer agent errors, but host `tools/list` remains the authoritative source for available tools.
