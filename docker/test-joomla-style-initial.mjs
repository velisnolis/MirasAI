/**
 * Opt-in integration test on an isolated Joomla lab with YOOtheme active and
 * no Style family or overrides. This WRITES the first family and its CSS.
 * Requires MIRASAI_STYLE_TEST_URL, MIRASAI_STYLE_TEST_TOKEN,
 * MIRASAI_STYLE_TEST_WORKER_SHA256, MIRASAI_STYLE_TEST_ALLOW_WRITE=1.
 * Take a database/files backup first. Never target production.
 */
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { updateStyle } from '../packages/mirasai-mcp/src/style-preview.mjs';

const env = process.env;
assert.equal(env.MIRASAI_STYLE_TEST_ALLOW_WRITE, '1', 'Explicit lab write opt-in required');
const base = env.MIRASAI_STYLE_TEST_URL?.replace(/\/$/, '');
assert.ok(base && env.MIRASAI_STYLE_TEST_TOKEN && env.MIRASAI_STYLE_TEST_WORKER_SHA256);
const unwrap = (result) => result.structuredContent ?? JSON.parse(result.content[0].text);
let lastWriteArgs;
const client = {
  async callTool(name, args) {
    if (name === 'template/style-update') lastWriteArgs = args;
    const response = await fetch(`${base}/api/v1/mirasai/mcp`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Joomla-Token': env.MIRASAI_STYLE_TEST_TOKEN },
      body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'tools/call', params: { name, arguments: args } }),
      signal: AbortSignal.timeout(120000),
    });
    assert.ok(response.ok, `HTTP ${response.status}`);
    const rpc = await response.json();
    assert.ok(!rpc.error, JSON.stringify(rpc.error));
    return rpc.result;
  },
};
const read = async () => unwrap(await client.callTool('template/style-read', {}));
const before = await read();
assert.equal(before.active?.style_id, '', 'Lab must start without a Style family');
assert.equal(before.overrides.customised, false);
const options = {
  client, siteUrl: base, styleId: 'fuse', ifMatch: before.etag,
  expectedWorkerSha256: env.MIRASAI_STYLE_TEST_WORKER_SHA256,
};
const preview = await updateStyle({ ...options, dryRun: true });
assert.equal(preview.ok, true);
assert.equal(preview.host.style_assignment, 'initial');
assert.equal((await read()).etag, before.etag, 'Dry-run must not change config or CSS state');
const unconfirmed = await updateStyle({ ...options, dryRun: false });
assert.equal(unconfirmed.code, 'guarded_write_confirmation_required');
const applied = await updateStyle({ ...options, dryRun: false, confirmGuardedWrite: true });
assert.equal(applied.ok, true);
assert.equal(applied.host.style_assignment, 'initial');
assert.equal(applied.host.snapshot_created, true);
assert.equal(applied.verification.etag_matches_host_result, true);
const after = await read();
const rejectionArgs = { ...lastWriteArgs, dry_run: true, confirm_guarded_write: false };
assert.equal(after.active.style_id, 'fuse');
assert.notEqual(after.etag, before.etag);
const served = [];
for (const path of applied.host.written_files) {
  const response = await fetch(`${base}/${path}`, { signal: AbortSignal.timeout(30000) });
  assert.equal(response.status, 200);
  const css = Buffer.from(await response.arrayBuffer());
  const hash = createHash('sha256').update(css).digest('hex');
  assert.equal(hash, applied.host.written_sha256[path], 'HTTP CSS must match the written file');
  assert.match(css.toString('utf8', 0, 200), /YOOtheme Pro v.+compiled on/);
  served.push({ path, bytes: css.length, sha256: hash });
}
const stale = unwrap(await client.callTool('template/style-update', {
  ...rejectionArgs, if_match: before.etag, style_id: 'fuse',
}));
assert.equal(stale.code, 'stale_etag');
const other = after.available.find((style) => style.id !== 'fuse').id;
const switched = unwrap(await client.callTool('template/style-update', {
  ...rejectionArgs, if_match: after.etag, style_id: other,
}));
assert.equal(switched.code, 'style_switch_not_supported');
assert.equal((await read()).etag, after.etag, 'Rejected writes must leave the state intact');
console.log(JSON.stringify({ ok: true, checks: ['initial_preview', 'preview_unchanged', 'confirmation_required', 'initial_write', 'snapshot_created', 'readback_etag', 'http_css_hashes', 'stale_etag_rejected', 'family_switch_rejected', 'rejections_unchanged'], before_etag: before.etag, after_etag: after.etag, snapshot_id: applied.host.snapshot_id, served }, null, 2));
