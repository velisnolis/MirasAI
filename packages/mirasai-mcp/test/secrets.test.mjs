import assert from 'node:assert/strict';
import { chmodSync, mkdtempSync, writeFileSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { clearSecretCache, resolveToken } from '../src/secrets.mjs';

test('1Password token refs are cached for the site ttl', () => {
  clearSecretCache();
  let reads = 0;
  const site = {
    site_id: 'joomla-demo',
    token_ref: 'op://feina/site/token',
    secret_ttl_seconds: 60,
  };
  const readOnePassword = () => {
    reads += 1;
    return `secret-${reads}`;
  };

  assert.deepEqual(resolveToken(site, {}, { readOnePassword, now: () => 1000 }), {
    type: 'header',
    value: 'secret-1',
  });
  assert.deepEqual(resolveToken(site, {}, { readOnePassword, now: () => 30_000 }), {
    type: 'header',
    value: 'secret-1',
  });
  assert.equal(reads, 1);
});

test('1Password refs are re-read after ttl expiry', () => {
  clearSecretCache();
  let reads = 0;
  const site = {
    site_id: 'wp-demo',
    basic_ref: 'op://feina/site/basic',
    secret_ttl_seconds: 1,
  };
  const readOnePassword = () => {
    reads += 1;
    return `user:secret-${reads}`;
  };

  assert.deepEqual(resolveToken(site, {}, { readOnePassword, now: () => 1000 }), {
    type: 'basic',
    value: 'user:secret-1',
  });
  assert.deepEqual(resolveToken(site, {}, { readOnePassword, now: () => 2500 }), {
    type: 'basic',
    value: 'user:secret-2',
  });
  assert.equal(reads, 2);
});

test('secret ttl can be disabled with 0 seconds', () => {
  clearSecretCache();
  let reads = 0;
  const site = {
    site_id: 'wp-demo',
    basic_ref: 'op://feina/site/basic',
    secret_ttl_seconds: 0,
  };
  const readOnePassword = () => {
    reads += 1;
    return `user:secret-${reads}`;
  };

  assert.equal(resolveToken(site, {}, { readOnePassword, now: () => 1000 }).value, 'user:secret-1');
  assert.equal(resolveToken(site, {}, { readOnePassword, now: () => 1000 }).value, 'user:secret-2');
  assert.equal(reads, 2);
});

test('secret ttl can be configured globally with an environment variable', () => {
  clearSecretCache();
  let reads = 0;
  const site = {
    site_id: 'wp-demo',
    basic_ref: 'op://feina/site/basic',
  };
  const readOnePassword = () => {
    reads += 1;
    return `user:secret-${reads}`;
  };

  assert.equal(resolveToken(site, { MIRASAI_MCP_SECRET_TTL_SECONDS: '30' }, { readOnePassword, now: () => 1000 }).value, 'user:secret-1');
  assert.equal(resolveToken(site, { MIRASAI_MCP_SECRET_TTL_SECONDS: '30' }, { readOnePassword, now: () => 2000 }).value, 'user:secret-1');
  assert.equal(reads, 1);
});

test('MIRASAI_MCP_SECRET_READER replaces op read and receives the site ttl', () => {
  clearSecretCache();
  const dir = mkdtempSync(path.join(os.tmpdir(), 'mirasai-reader-'));
  const reader = path.join(dir, 'reader');
  writeFileSync(reader, '#!/bin/sh\nprintf "%s|%s|%s" "$1" "$MIRASAI_MCP_SECRET_SITE_ID" "$MIRASAI_MCP_SECRET_TTL_SECONDS"\n');
  chmodSync(reader, 0o755);
  const site = {
    site_id: 'wp-demo',
    basic_ref: 'op://feina/site/basic',
    secret_ttl_seconds: 900,
  };

  assert.deepEqual(resolveToken(site, { MIRASAI_MCP_SECRET_READER: reader }), {
    type: 'basic',
    value: 'op://feina/site/basic|wp-demo|900',
  });
});

test('MIRASAI_MCP_SECRET_READER failures name the reference', () => {
  clearSecretCache();
  const site = { site_id: 'wp-demo', basic_ref: 'op://feina/site/missing', secret_ttl_seconds: 0 };

  assert.throws(
    () => resolveToken(site, { MIRASAI_MCP_SECRET_READER: '/usr/bin/false' }),
    /op:\/\/feina\/site\/missing via MIRASAI_MCP_SECRET_READER/,
  );
});
