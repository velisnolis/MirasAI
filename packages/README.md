# MirasAI Packages

This directory contains the multi-platform MirasAI packages.

## Packages

| Package | Role | Runtime |
| --- | --- | --- |
| `@miras/mirasai-joomla` | Joomla host package source. Builds the installable `pkg_mirasai-<version>.zip`. | Joomla/PHP |
| `@miras/mirasai-contract` | Shared host MCP contract, schemas, and fixtures. | Node.js tooling |
| `@miras/mirasai-mcp` | Local multi-site MCP router for Joomla and WordPress hosts. | Node.js stdio MCP |
| `@miras/mirasai-wp` | WordPress host plugin implementing the MirasAI host contract. | WordPress/PHP |

## Common Commands

From the repository root:

```bash
npm test
npm run build:joomla
npm run build:wp
npm run check:packages
```

Package-specific commands still work:

```bash
npm run test -w @miras/mirasai-contract
npm run test -w @miras/mirasai-mcp
npm run lint:php -w @miras/mirasai-joomla
npm run build:zip -w @miras/mirasai-joomla
npm run lint:php -w @miras/mirasai-wp
npm run build:zip -w @miras/mirasai-wp
```

Generated Joomla ZIPs are written under `.docker-build/`, which is ignored by git.
Generated WordPress ZIPs are written under `packages/mirasai-wp/dist/`, which is ignored by git.
