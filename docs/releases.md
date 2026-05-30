# MirasAI Releases

MirasAI publishes installable artifacts through GitHub Releases.

## User-Facing URLs

Release index:

```text
https://github.com/velisnolis/MirasAI/releases
```

Joomla automatic update feed:

```text
https://raw.githubusercontent.com/velisnolis/MirasAI/main/updates/pkg_mirasai.xml
```

WordPress automatic update feed:

```text
https://raw.githubusercontent.com/velisnolis/MirasAI/main/updates/mirasai-wp.json
```

## Release Assets

Each release should contain:

- `pkg_mirasai-<version>.zip`: Joomla host package.
- `mirasai-wp-<version>.zip`: WordPress host plugin.
- `miras-mirasai-mcp-<version>.tgz`: local multi-site MCP router npm tarball.
- `install-urls.md`: human-readable install and feed URLs with SHA-256 hashes.
- `release-notes.md`: release notes used by GitHub.

## Prepare A Release Locally

1. Bump all package and CMS manifest versions.
2. Run:

```bash
npm run release:prepare
```

3. Review:

```bash
git diff -- updates/pkg_mirasai.xml updates/mirasai-wp.json
ls .release/v<version>/
```

4. Commit the version and feed changes.
5. Tag and create the GitHub release using the files under `.release/v<version>/`.

## Automated Release

Use the `Release` GitHub Actions workflow with the exact package version as input, for example `0.5.2`.

The workflow:

- validates the input matches `package.json`;
- runs `npm run release:prepare`;
- commits `updates/pkg_mirasai.xml` and `updates/mirasai-wp.json` if they changed;
- tags `v<version>`;
- creates a GitHub Release with all generated assets.

The feed files in `main` are the update source of truth. Do not edit release assets without regenerating and committing the matching feeds.
