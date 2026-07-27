import assert from 'node:assert/strict';
import test from 'node:test';

import { previewStyle, updateStyle, verifyCompiledStyle, sha256 } from '../src/style-preview.mjs';

function clientWithWorker(workerSource) {
  return {
    async callTool(name) {
      if (name === 'template/style-sources') {
        return {
          structuredContent: {
            filename: 'entry.less',
            filepath: '/less/',
            desturl: '/css',
            imports: { 'entry.less': '@a: 1;' },
            vars: {},
            overrides: { less: {}, custom_less: '', internal_style: null },
            style_id: 'flow',
            is_active_style: true,
            import_count: 1,
          },
        };
      }

      if (name === 'file/read') {
        return { structuredContent: { content: workerSource } };
      }

      if (name === 'template/style-read') {
        return {
          structuredContent: {
            compiled: {
              bytes: 10,
              stale_sources: false,
              stale_version: false,
            },
            warnings: [],
          },
        };
      }

      throw new Error(`Unexpected tool: ${name}`);
    },
  };
}

test('style-preview refuses an unpinned or changed remote worker', async () => {
  const worker = `
    self.addEventListener('message', function (event) {
      self.postMessage({ id: event.data[0], result: {} });
    });
  `;
  const client = clientWithWorker(worker);

  await assert.rejects(
    () => previewStyle({ client, siteUrl: 'https://example.test' }),
    (error) => error.code === 'style_worker_hash_required'
      && error.observed_sha256 === sha256(worker),
  );

  await assert.rejects(
    () => previewStyle({
      client,
      siteUrl: 'https://example.test',
      expectedWorkerSha256: '0'.repeat(64),
    }),
    (error) => error.code === 'style_worker_hash_mismatch'
      && error.expected_sha256 === '0'.repeat(64)
      && error.observed_sha256 === sha256(worker),
  );
});

test('style-verify stays failed when compilation returns CSS plus errors', async () => {
  const worker = `
    self.addEventListener('message', function (event) {
      var id = event.data[0];
      var payload = event.data[1];
      if (payload.cmd === 'css') {
        self.postMessage({
          id: id,
          result: {
            css: '.x{color:red}',
            variables: { '@a': { value: '1' } },
            errors: ['synthetic compile error']
          }
        });
        return;
      }
      if (payload.cmd === 'minify') {
        self.postMessage({
          id: id,
          result: { css: payload.data.css, rtl: payload.data.css }
        });
      }
    });
  `;

  const result = await verifyCompiledStyle({
    client: clientWithWorker(worker),
    siteUrl: 'https://example.test',
    expectedWorkerSha256: sha256(worker),
  });

  assert.equal(result.ok, false);
  assert.equal(result.stage, 'fresh_compile');
  assert.match(result.error, /compiled with errors/);
  assert.deepEqual(result.compile.errors, ['synthetic compile error']);
});

test('style-preview honors the host worker path and base URL contract', async () => {
  const calls = [];
  let contractBaseUrl = 'https://joomla.test/administrator/index.php';
  const worker = `
    self.addEventListener('message', function (event) {
      var id = event.data[0];
      var payload = event.data[1];
      if (payload.cmd === 'css') {
        self.postMessage({
          id: id,
          result: {
            css: '/*' + location.href + '*/.x{color:red}',
            variables: {},
            errors: []
          }
        });
        return;
      }
      if (payload.cmd === 'minify') {
        self.postMessage({
          id: id,
          result: { css: payload.data.css, rtl: payload.data.css }
        });
      }
    });
  `;
  const client = {
    async callTool(name, args) {
      calls.push({ name, args });

      if (name === 'template/style-sources') {
        return {
          structuredContent: {
            filename: 'entry.less',
            filepath: '/less/',
            desturl: '/css',
            imports: { 'entry.less': '' },
            vars: {},
            overrides: { less: {}, custom_less: '', internal_style: null },
            style_id: 'custom',
            is_active_style: true,
            import_count: 1,
            compile_contract: {
              platform: 'joomla',
              worker: 'templates/yootheme/assets/admin/js/worker.js',
              base_url: contractBaseUrl,
            },
          },
        };
      }

      if (name === 'file/read') {
        return { structuredContent: { content: worker } };
      }

      throw new Error(`Unexpected tool: ${name}`);
    },
  };

  const result = await previewStyle({
    client,
    siteUrl: 'https://wrong-fallback.test',
    includeCss: true,
    expectedWorkerSha256: sha256(worker),
  });

  assert.equal(result.ok, true);
  assert.match(result.compiled_css, /https:\/\/joomla\.test\/administrator\/index\.php/);
  assert.deepEqual(calls[1], {
    name: 'file/read',
    args: { path: 'templates/yootheme/assets/admin/js/worker.js' },
  });

  contractBaseUrl = 'http://Standard input code/administrator/index.php';
  const fallback = await previewStyle({
    client,
    siteUrl: 'https://fallback-joomla.test',
    includeCss: true,
    expectedWorkerSha256: sha256(worker),
  });

  assert.match(
    fallback.compiled_css,
    /https:\/\/fallback-joomla\.test\/administrator\/index\.php/
  );
});

test('style-verify separates tool success from served freshness', async () => {
  const worker = `
    self.addEventListener('message', function (event) {
      var id = event.data[0];
      var payload = event.data[1];
      if (payload.cmd === 'css') {
        self.postMessage({
          id: id,
          result: { css: '.x{color:red}', variables: {}, errors: [] }
        });
        return;
      }
      if (payload.cmd === 'minify') {
        self.postMessage({
          id: id,
          result: { css: payload.data.css, rtl: payload.data.css }
        });
      }
    });
  `;
  const client = clientWithWorker(worker);
  const originalCall = client.callTool;
  client.callTool = async (name, args) => {
    if (name === 'template/style-read') {
      return {
        structuredContent: {
          compiled: {
            bytes: 10,
            stale_sources: true,
            stale_version: false,
            freshness_method: 'broad_less_mtime_heuristic',
          },
          warnings: ['stale'],
        },
      };
    }
    return originalCall(name, args);
  };

  const result = await verifyCompiledStyle({
    client,
    siteUrl: 'https://example.test',
    expectedWorkerSha256: sha256(worker),
  });

  assert.equal(result.ok, true);
  assert.equal(result.fresh, false);
  assert.equal(result.verification.content_compared, false);
  assert.equal(result.served.freshness_method, 'broad_less_mtime_heuristic');
});

test('style-update compiles first and sends a hash-bound dry-run to the host', async () => {
  const calls = [];
  const worker = `
    self.addEventListener('message', function (event) {
      var id = event.data[0];
      var payload = event.data[1];
      if (payload.cmd === 'css') {
        self.postMessage({
          id: id,
          result: {
            css: '.x{color:red}',
            variables: { '@a': { value: payload.data.vars['@a'] || '1' } },
            errors: []
          }
        });
        return;
      }
      if (payload.cmd === 'minify') {
        self.postMessage({
          id: id,
          result: { css: payload.data.css, rtl: payload.data.css + '/*rtl*/' }
        });
      }
    });
  `;
  const client = {
    async callTool(name, args) {
      calls.push({ name, args });

      if (name === 'template/style-sources') {
        return {
          structuredContent: {
            filename: 'entry.less',
            filepath: '/less/',
            desturl: '/css',
            imports: { 'entry.less': '@a: 1;' },
            vars: {},
            overrides: { less: {}, custom_less: '', internal_style: null },
            style_id: 'flow',
            is_active_style: true,
            import_count: 1,
            etag: 'etag-123',
          },
        };
      }

      if (name === 'file/read') {
        return { structuredContent: { content: worker } };
      }

      if (name === 'template/style-update') {
        return {
          structuredContent: {
            action: 'preview',
            dry_run: true,
            old_etag: args.if_match,
          },
        };
      }

      throw new Error(`Unexpected tool: ${name}`);
    },
  };

  const result = await updateStyle({
    client,
    siteUrl: 'https://example.test',
    vars: { '@a': '2' },
    ifMatch: 'etag-123',
    dryRun: true,
    expectedWorkerSha256: sha256(worker),
  });

  assert.equal(result.ok, true);
  assert.equal(result.dry_run, true);
  const hostCall = calls.find((call) => call.name === 'template/style-update');
  assert.equal(hostCall.args.dry_run, true);
  assert.equal(hostCall.args.if_match, 'etag-123');
  assert.equal(hostCall.args.compiled_css_sha256, sha256(hostCall.args.compiled_css));
  assert.equal(hostCall.args.compiled_rtl_sha256, sha256(hostCall.args.compiled_rtl));
  assert.equal(result.preview.compiled_css, undefined);
});

test('style-update refuses a real write without explicit guarded confirmation', async () => {
  let called = false;
  const result = await updateStyle({
    client: { callTool: async () => { called = true; } },
    siteUrl: 'https://example.test',
    ifMatch: 'etag-123',
    dryRun: false,
    confirmGuardedWrite: false,
    expectedWorkerSha256: '0'.repeat(64),
  });

  assert.equal(result.ok, false);
  assert.equal(result.code, 'guarded_write_confirmation_required');
  assert.equal(called, false);
});
