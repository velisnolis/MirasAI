import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { normalizeRegistry, validateRegistry, findSite, serializeRegistry, upsertSite, setDefaultSite, loadRegistry } from '../src/config.mjs';

test('valid registry normalizes default site', () => {
  const registry = {
    schema_version: 1,
    sites: [
      {
        site_id: 'joomla-demo',
        label: 'Joomla demo',
        platform: 'joomla',
        url: 'https://example.test/api/v1/mirasai/mcp',
        token_env: 'JOOMLA_TOKEN',
      },
    ],
  };

  assert.deepEqual(validateRegistry(registry), []);

  const normalized = normalizeRegistry(registry, '/tmp/sites.json');
  assert.equal(normalized.default_site_id, 'joomla-demo');
  assert.equal(normalized.sites[0].default, true);
});

test('registry requires one token source per site', () => {
  const errors = validateRegistry({
    schema_version: 1,
    sites: [
      {
        site_id: 'wp-demo',
        label: 'WP demo',
        platform: 'wordpress',
        url: 'https://wp.example.test/wp-json/mirasai/v1/mcp',
        token_env: 'WP_TOKEN',
        token_plain_dev: 'dev',
      },
    ],
  });

  assert.match(errors.join('\n'), /exactly one/);
});

test('registry accepts basic auth sources for WordPress application passwords', () => {
  const errors = validateRegistry({
    schema_version: 1,
    sites: [
      {
        site_id: 'wp-demo',
        label: 'WP demo',
        platform: 'wordpress',
        url: 'https://wp.example.test/wp-json/mirasai/v1/mcp',
        basic_env: 'WP_APP_PASSWORD',
      },
    ],
  });

  assert.deepEqual(errors, []);
});

test('registry accepts non-negative secret ttl per site', () => {
  const errors = validateRegistry({
    schema_version: 1,
    sites: [
      {
        site_id: 'wp-demo',
        label: 'WP demo',
        platform: 'wordpress',
        url: 'https://wp.example.test/wp-json/mirasai/v1/mcp',
        basic_ref: 'op://vault/item/field',
        secret_ttl_seconds: 3600,
      },
    ],
  });

  assert.deepEqual(errors, []);
});

test('registry accepts and preserves a pinned style worker hash', () => {
  const hash = 'A'.repeat(64);
  const registry = normalizeRegistry({
    schema_version: 1,
    sites: [
      {
        site_id: 'wp-demo',
        label: 'WP demo',
        platform: 'wordpress',
        url: 'https://wp.example.test/wp-json/mirasai/v1/mcp',
        basic_ref: 'op://vault/item/field',
        style_worker_sha256: hash,
      },
    ],
  });

  assert.deepEqual(validateRegistry(serializeRegistry(registry)), []);
  assert.equal(registry.sites[0].style_worker_sha256, hash.toLowerCase());
  assert.equal(serializeRegistry(registry).sites[0].style_worker_sha256, hash.toLowerCase());
});

test('registry rejects malformed style worker hashes', () => {
  const errors = validateRegistry({
    schema_version: 1,
    sites: [
      {
        site_id: 'wp-demo',
        label: 'WP demo',
        platform: 'wordpress',
        url: 'https://wp.example.test/wp-json/mirasai/v1/mcp',
        basic_ref: 'op://vault/item/field',
        style_worker_sha256: 'not-a-sha256',
      },
    ],
  });

  assert.match(errors.join('\n'), /style_worker_sha256/);
});

test('registry rejects negative secret ttl', () => {
  const errors = validateRegistry({
    schema_version: 1,
    sites: [
      {
        site_id: 'wp-demo',
        label: 'WP demo',
        platform: 'wordpress',
        url: 'https://wp.example.test/wp-json/mirasai/v1/mcp',
        basic_ref: 'op://vault/item/field',
        secret_ttl_seconds: -1,
      },
    ],
  });

  assert.match(errors.join('\n'), /secret_ttl_seconds/);
});

test('serializeRegistry preserves secret ttl without leaking defaults', () => {
  const registry = normalizeRegistry({
    schema_version: 1,
    sites: [
      {
        site_id: 'wp-demo',
        label: 'WP demo',
        platform: 'wordpress',
        url: 'https://wp.example.test/wp-json/mirasai/v1/mcp',
        basic_ref: 'op://vault/item/field',
        secret_ttl_seconds: 900,
      },
    ],
  });

  assert.equal(serializeRegistry(registry).sites[0].secret_ttl_seconds, 900);
});

test('findSite resolves default and rejects unknown sites', () => {
  const registry = normalizeRegistry({
    schema_version: 1,
    default_site_id: 'wp-demo',
    sites: [
      {
        site_id: 'wp-demo',
        label: 'WP demo',
        platform: 'wordpress',
        url: 'https://wp.example.test/wp-json/mirasai/v1/mcp',
        token_env: 'WP_TOKEN',
      },
    ],
  });

  assert.equal(findSite(registry).site_id, 'wp-demo');
  assert.throws(() => findSite(registry, 'missing'), /Unknown site_id/);
});

test('upsertSite adds and replaces sites without leaking normalized defaults', () => {
  const registry = normalizeRegistry({
    schema_version: 1,
    sites: [],
  });

  const added = upsertSite(registry, {
    site_id: 'wp-demo',
    label: 'WP demo',
    platform: 'wordpress',
    url: 'https://wp.example.test/wp-json/mirasai/v1/mcp',
    basic_ref: 'op://vault/item/field',
  }, { makeDefault: true });

  assert.equal(added.default_site_id, 'wp-demo');
  assert.equal(added.sites.length, 1);

  const replaced = upsertSite(added, {
    site_id: 'wp-demo',
    label: 'WP demo changed',
    platform: 'wordpress',
    url: 'https://wp.example.test/wp-json/mirasai/v1/mcp',
    basic_env: 'WP_BASIC',
  });

  const serialized = serializeRegistry(replaced);
  assert.equal(serialized.sites.length, 1);
  assert.equal(serialized.sites[0].label, 'WP demo changed');
  assert.equal(serialized.sites[0].basic_env, 'WP_BASIC');
  assert.equal(serialized.sites[0].default, undefined);
});

test('setDefaultSite changes default and validates the site exists', () => {
  const registry = normalizeRegistry({
    schema_version: 1,
    default_site_id: 'one',
    sites: [
      {
        site_id: 'one',
        label: 'One',
        platform: 'joomla',
        url: 'https://one.example.test/api/v1/mirasai/mcp',
        token_env: 'ONE_TOKEN',
      },
      {
        site_id: 'two',
        label: 'Two',
        platform: 'wordpress',
        url: 'https://two.example.test/wp-json/mirasai/v1/mcp',
        basic_env: 'TWO_BASIC',
      },
    ],
  });

  assert.equal(setDefaultSite(registry, 'two').default_site_id, 'two');
  assert.throws(() => setDefaultSite(registry, 'missing'), /Unknown site_id/);
});

test('loadRegistry warns when registry file permissions are too open', () => {
  const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'mirasai-registry-'));
  const configPath = path.join(tempDir, 'sites.json');

  try {
    fs.writeFileSync(configPath, `${JSON.stringify({
      schema_version: 1,
      sites: [
        {
          site_id: 'wp-demo',
          label: 'WP demo',
          platform: 'wordpress',
          url: 'https://wp.example.test/wp-json/mirasai/v1/mcp',
          basic_env: 'WP_BASIC',
        },
      ],
    }, null, 2)}\n`, { mode: 0o644 });
    fs.chmodSync(configPath, 0o644);

    const registry = loadRegistry(configPath);

    assert.equal(registry.warnings.length, 1);
    assert.equal(registry.warnings[0].code, 'registry_permissions_too_open');
    assert.equal(registry.warnings[0].mode, '0644');
  } finally {
    fs.rmSync(tempDir, { recursive: true, force: true });
  }
});

test('protocol accepts mcp, rejects unknown values, and defaults to mirasai', () => {
  const base = {
    site_id: 'wp-adapter',
    label: 'WP adapter',
    platform: 'wordpress',
    url: 'https://wp.example.test/wp-json/mcp/mcp-adapter-default-server',
    basic_env: 'WP_BASIC',
  };

  assert.deepEqual(validateRegistry({
    schema_version: 1,
    sites: [{ ...base, protocol: 'mcp' }],
  }), []);

  const errors = validateRegistry({
    schema_version: 1,
    sites: [{ ...base, protocol: 'rest' }],
  });
  assert.equal(errors.length, 1);
  assert.match(errors[0], /protocol must be "mirasai" or "mcp"/);

  const registry = normalizeRegistry({
    schema_version: 1,
    sites: [base, { ...base, site_id: 'wp-adapter-2', protocol: 'mcp' }],
  });
  assert.equal(registry.sites[0].protocol, 'mirasai');
  assert.equal(registry.sites[1].protocol, 'mcp');
});

test('serializeRegistry round-trips the protocol field', () => {
  const registry = normalizeRegistry({
    schema_version: 1,
    sites: [{
      site_id: 'wp-adapter',
      label: 'WP adapter',
      platform: 'wordpress',
      protocol: 'mcp',
      url: 'https://wp.example.test/wp-json/mcp/mcp-adapter-default-server',
      basic_env: 'WP_BASIC',
    }],
  });

  const serialized = serializeRegistry(registry);
  assert.equal(serialized.sites[0].protocol, 'mcp');
  assert.deepEqual(validateRegistry(serialized), []);
});
