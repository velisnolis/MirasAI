#!/usr/bin/env node

import { execFileSync, execSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, rmSync, writeFileSync, copyFileSync, readdirSync } from 'node:fs';
import { basename, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const rootDir = resolve(fileURLToPath(new URL('..', import.meta.url)));
const repoSlug = process.env.MIRASAI_REPO_SLUG || 'velisnolis/MirasAI';
const updateBaseUrl = process.env.MIRASAI_UPDATE_BASE_URL || 'https://raw.githubusercontent.com/velisnolis/MirasAI/main/updates';

function readJson(path) {
  return JSON.parse(readFileSync(join(rootDir, path), 'utf8'));
}

function readText(path) {
  return readFileSync(join(rootDir, path), 'utf8');
}

function sha256(path) {
  return createHash('sha256').update(readFileSync(path)).digest('hex');
}

function assertEqual(actual, expected, label) {
  if (actual !== expected) {
    throw new Error(`${label} is ${actual}, expected ${expected}`);
  }
}

function matchRequired(text, pattern, label) {
  const match = text.match(pattern);
  if (!match) {
    throw new Error(`Could not read ${label}`);
  }
  return match[1];
}

function run(command, args, options = {}) {
  execFileSync(command, args, {
    cwd: rootDir,
    stdio: 'inherit',
    ...options,
  });
}

const rootPackage = readJson('package.json');
const version = rootPackage.version;
const tag = `v${version}`;
const releaseBaseUrl = `https://github.com/${repoSlug}/releases/download/${tag}`;
const releasePageUrl = `https://github.com/${repoSlug}/releases/tag/${tag}`;

for (const packagePath of [
  'packages/mirasai-contract/package.json',
  'packages/mirasai-mcp/package.json',
  'packages/mirasai-joomla/package.json',
  'packages/mirasai-wp/package.json',
]) {
  assertEqual(readJson(packagePath).version, version, `${packagePath} version`);
}

const joomlaManifest = readText('packages/mirasai-joomla/pkg_mirasai.xml');
assertEqual(matchRequired(joomlaManifest, /<version>([^<]+)<\/version>/, 'Joomla manifest version'), version, 'Joomla manifest version');

const wpPlugin = readText('packages/mirasai-wp/mirasai-wp.php');
assertEqual(matchRequired(wpPlugin, /\* Version:\s*([^\n]+)/, 'WordPress plugin header version').trim(), version, 'WordPress plugin header version');
assertEqual(matchRequired(wpPlugin, /define\('MIRASAI_WP_VERSION', '([^']+)'\);/, 'WordPress version constant'), version, 'WordPress version constant');

run('npm', ['run', 'check:packages']);

const releaseDir = join(rootDir, '.release', tag);
rmSync(releaseDir, { recursive: true, force: true });
mkdirSync(releaseDir, { recursive: true });

const joomlaAssetName = `pkg_mirasai-${version}.zip`;
const wpAssetName = `mirasai-wp-${version}.zip`;
const joomlaBuildPath = join(rootDir, '.docker-build', joomlaAssetName);
const wpBuildPath = join(rootDir, 'packages', 'mirasai-wp', 'dist', wpAssetName);
const joomlaAssetPath = join(releaseDir, joomlaAssetName);
const wpAssetPath = join(releaseDir, wpAssetName);

copyFileSync(joomlaBuildPath, joomlaAssetPath);
copyFileSync(wpBuildPath, wpAssetPath);

execSync(`npm pack -w @miras/mirasai-mcp --pack-destination "${releaseDir}"`, {
  cwd: rootDir,
  stdio: 'inherit',
});

const mcpAssetName = readdirSync(releaseDir).find((file) => file.startsWith('miras-mirasai-mcp-') && file.endsWith('.tgz'));
if (!mcpAssetName) {
  throw new Error('Could not find packed @miras/mirasai-mcp asset');
}

const joomlaSha = sha256(joomlaAssetPath);
const wpSha = sha256(wpAssetPath);
const mcpSha = sha256(join(releaseDir, mcpAssetName));
const releaseDate = new Date().toISOString().slice(0, 10);

const joomlaDownloadUrl = `${releaseBaseUrl}/${joomlaAssetName}`;
const wpDownloadUrl = `${releaseBaseUrl}/${wpAssetName}`;
const mcpDownloadUrl = `${releaseBaseUrl}/${mcpAssetName}`;
const joomlaFeedUrl = `${updateBaseUrl}/pkg_mirasai.xml`;
const wpFeedUrl = `${updateBaseUrl}/mirasai-wp.json`;

writeFileSync(join(rootDir, 'updates', 'pkg_mirasai.xml'), `<?xml version="1.0" encoding="utf-8"?>
<updates>
  <update>
    <name>MirasAI</name>
    <description>MirasAI package for Joomla. MCP server, admin dashboard, and optional YOOtheme tools.</description>
    <element>pkg_mirasai</element>
    <type>package</type>
    <client>site</client>
    <version>${version}</version>
    <infourl title="MirasAI">${releasePageUrl}</infourl>
    <downloads>
      <downloadurl type="full" format="zip">${joomlaDownloadUrl}</downloadurl>
    </downloads>
    <tags>
      <tag>stable</tag>
    </tags>
    <maintainer>Alex Miras</maintainer>
    <maintainerurl>https://miras.pro</maintainerurl>
    <targetplatform name="joomla" version="[56]\\.[0-9]+"/>
    <sha256>${joomlaSha}</sha256>
  </update>
</updates>
`);

writeFileSync(join(rootDir, 'updates', 'mirasai-wp.json'), `${JSON.stringify({
  name: 'MirasAI',
  slug: 'mirasai',
  plugin: 'mirasai-wp/mirasai-wp.php',
  version,
  requires: '6.0',
  tested: '',
  requires_php: '8.0',
  last_updated: releaseDate,
  homepage: 'https://github.com/velisnolis/MirasAI',
  download_url: wpDownloadUrl,
  sha256: wpSha,
  sections: {
    description: 'MirasAI host endpoint for WordPress. Exposes an MCP-compatible HTTP surface for controlled AI access.',
    installation: 'Install the release ZIP from GitHub, activate MirasAI, then create a WordPress Application Password from the MirasAI dashboard.',
    changelog: `See ${releasePageUrl}`,
  },
}, null, 2)}
`);

const installUrls = `# MirasAI ${version} Install URLs

## Joomla

- Install ZIP: ${joomlaDownloadUrl}
- Update feed: ${joomlaFeedUrl}
- SHA-256: \`${joomlaSha}\`

## WordPress

- Install ZIP: ${wpDownloadUrl}
- Update feed: ${wpFeedUrl}
- SHA-256: \`${wpSha}\`

## Local MCP Router

- npm tarball: ${mcpDownloadUrl}
- SHA-256: \`${mcpSha}\`
`;

writeFileSync(join(releaseDir, 'install-urls.md'), installUrls);
writeFileSync(join(releaseDir, 'release-notes.md'), `${installUrls}

## Notes

- Joomla sites use the XML update feed above for automatic updates.
- WordPress sites use the JSON update feed above through the bundled MirasAI updater.
- The local MCP router is shipped as an npm tarball release asset.
`);

console.log(`Prepared ${tag}`);
console.log(`Release assets: ${releaseDir}`);
for (const file of readdirSync(releaseDir).sort()) {
  console.log(`- ${basename(file)}`);
}
