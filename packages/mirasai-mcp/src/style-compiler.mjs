/**
 * Headless YOOtheme Pro 5 Less compilation.
 *
 * YOOtheme Pro 5 has no server-side Less compiler. The Style customizer hands
 * the browser the whole import tree, a web worker compiles it with a bundled
 * less.js, and the browser POSTs the resulting CSS back for storage. A tool
 * that changed style variables without compiling would leave the site serving
 * the old CSS, with stored state and served state silently diverging.
 *
 * So we run the site's own worker.js, in a vm context with a minimal Web Worker
 * shim. Using the installed bundle rather than a less.js from npm is deliberate:
 * it is the same compiler, the same version and the same plugins the customizer
 * would have used. Verified byte-identical against CSS produced by the browser.
 *
 * Message contract, read off the bundle itself:
 *
 *   self.addEventListener('message', ({data: [id, {cmd, data}]}) => …)
 *   self.postMessage({id, result})   // or {id, error}
 *
 * Commands: css | vars | minify | rtl
 */
import { spawn } from 'node:child_process';
import crypto from 'node:crypto';
import os from 'node:os';
import { fileURLToPath } from 'node:url';

const CALL_TIMEOUT_MS = 120_000;
const BOOT_TIMEOUT_MS = 10_000;
const RUNNER_FILE = fileURLToPath(new URL('./style-worker-runner.mjs', import.meta.url));
const PERMISSION_FLAG = process.allowedNodeEnvironmentFlags.has('--permission')
  ? '--permission'
  : '--experimental-permission';

/**
 * Boots a worker bundle and returns a call(cmd, data) function.
 *
 * The remotely supplied bundle never runs in this process. A separate Node
 * process starts with an empty environment and the Permission Model enabled,
 * then evaluates the bundle in a context without host functions or objects.
 * The process boundary also gives us a timeout that can stop a synchronous
 * infinite loop instead of waiting for the blocked event loop.
 *
 * @param {string} workerSource contents of the site's assets/admin/js/worker.js
 * @param {string} baseUrl an absolute URL on the target site; the worker's
 *   FileManager resolves import paths against it via extractUrlParts()
 * @param {object} [options]
 * @param {number} [options.callTimeoutMs]
 * @param {number} [options.bootTimeoutMs]
 */
export function bootStyleWorker(workerSource, baseUrl, options = {}) {
  const callTimeoutMs = positiveTimeout(options.callTimeoutMs, CALL_TIMEOUT_MS);
  const bootTimeoutMs = positiveTimeout(options.bootTimeoutMs, BOOT_TIMEOUT_MS);
  const pending = new Map();
  let nextId = 0;
  let stdoutBuffer = '';
  let stderrBuffer = '';
  let closed = false;
  let readyResolve;
  let readyReject;

  const ready = new Promise((resolve, reject) => {
    readyResolve = resolve;
    readyReject = reject;
  });

  // Boot failures arrive from child events, so the rejection can land before
  // any call() attaches a handler. Without this the router would die of an
  // unhandled rejection instead of returning the error to the caller.
  ready.catch(() => {});

  const child = spawn(
    process.execPath,
    [
      PERMISSION_FLAG,
      `--allow-fs-read=${RUNNER_FILE}`,
      '--no-warnings',
      RUNNER_FILE,
    ],
    {
      cwd: os.tmpdir(),
      env: {},
      stdio: ['pipe', 'pipe', 'pipe'],
      windowsHide: true,
    },
  );

  const bootTimer = setTimeout(() => {
    const error = new Error(`Style worker failed to start after ${bootTimeoutMs} ms.`);
    readyReject(error);
    rejectAll(error);
    stopChild();
  }, bootTimeoutMs);

  child.stdout.setEncoding('utf8');
  child.stdout.on('data', (chunk) => {
    stdoutBuffer += chunk;

    for (;;) {
      const newline = stdoutBuffer.indexOf('\n');
      if (newline < 0) break;

      const line = stdoutBuffer.slice(0, newline);
      stdoutBuffer = stdoutBuffer.slice(newline + 1);
      if (line === '') continue;

      let message;
      try {
        message = JSON.parse(line);
      } catch {
        const error = new Error(`Style worker runner returned invalid JSON: ${line.slice(0, 200)}`);
        readyReject(error);
        rejectAll(error);
        stopChild();
        continue;
      }

      if (message.type === 'ready') {
        clearTimeout(bootTimer);
        readyResolve();
        continue;
      }

      if (message.id === null && message.error) {
        const error = new Error(String(message.error));
        clearTimeout(bootTimer);
        readyReject(error);
        rejectAll(error);
        stopChild();
        continue;
      }

      const entry = pending.get(message.id);
      if (!entry) continue;

      pending.delete(message.id);
      clearTimeout(entry.timer);
      if (message.error) entry.reject(new Error(String(message.error)));
      else entry.resolve(message.result);
    }
  });

  child.stderr.setEncoding('utf8');
  child.stderr.on('data', (chunk) => {
    if (stderrBuffer.length < 4_096) stderrBuffer += chunk;
  });

  child.stdin.on('error', (caught) => {
    clearTimeout(bootTimer);
    readyReject(caught);
    rejectAll(caught);
    stopChild();
  });

  child.on('error', (caught) => {
    clearTimeout(bootTimer);
    readyReject(caught);
    rejectAll(caught);
  });

  child.on('exit', (code, signal) => {
    if (closed) return;

    const detail = stderrBuffer.trim();
    const error = new Error(
      `Style worker runner exited before completion (${signal ?? `code ${code}`})${detail ? `: ${detail}` : '.'}`,
    );
    clearTimeout(bootTimer);
    readyReject(error);
    rejectAll(error);
  });

  child.stdin.write(`${JSON.stringify({
    type: 'init',
    worker_source: workerSource,
    base_url: baseUrl,
    call_timeout_ms: callTimeoutMs,
    boot_timeout_ms: bootTimeoutMs,
  })}\n`);

  function rejectAll(error) {
    for (const entry of pending.values()) {
      clearTimeout(entry.timer);
      entry.reject(error);
    }
    pending.clear();
  }

  function stopChild() {
    if (closed) return;
    closed = true;
    child.kill('SIGKILL');
  }

  async function call(cmd, data) {
    await ready;

    if (closed || !child.stdin.writable) {
      throw new Error('Style worker runner is closed.');
    }

    const id = ++nextId;

    return new Promise((resolve, reject) => {
      const timer = setTimeout(() => {
        pending.delete(id);
        const error = new Error(`Style worker timed out on "${cmd}" after ${callTimeoutMs} ms.`);
        reject(error);
        rejectAll(error);
        stopChild();
      }, callTimeoutMs);

      pending.set(id, { resolve, reject, timer });
      child.stdin.write(`${JSON.stringify({ type: 'call', id, cmd, data })}\n`);
    });
  }

  call.close = async () => {
    if (closed) return;
    closed = true;
    child.stdin.end();
    rejectAll(new Error('Style worker runner was closed.'));

    if (child.exitCode === null && child.signalCode === null) {
      await new Promise((resolve) => {
        const timer = setTimeout(() => {
          child.kill('SIGKILL');
          resolve();
        }, 1_000);
        child.once('exit', () => {
          clearTimeout(timer);
          resolve();
        });
      });
    }
  };

  return call;
}

export function sha256(value) {
  return crypto.createHash('sha256').update(value).digest('hex');
}

function positiveTimeout(value, fallback) {
  return Number.isInteger(value) && value > 0 ? value : fallback;
}

/**
 * Values coming back from the worker belong to the vm realm, so `instanceof`
 * and deep-equality against host objects fail even when the data is identical.
 * Everything the worker returns is plain data, so a structured clone is enough
 * to hand callers ordinary host-realm objects. Strings and numbers are
 * primitives and cross realms unchanged.
 */
function toHostRealm(value) {
  if (value === null || typeof value !== 'object') return value;

  try {
    return structuredClone(value);
  } catch {
    return JSON.parse(JSON.stringify(value));
  }
}

/**
 * Compiles one style.
 *
 * @param {object} options
 * @param {string} options.workerSource
 * @param {object} options.sources payload from template/style-sources
 * @param {object} [options.vars] Less variable overrides
 * @param {?string} [options.variation] applied as @internal-style
 * @param {string} [options.customLess]
 * @param {string} [options.baseUrl]
 */
export async function compileStyle({
  workerSource,
  sources,
  vars = {},
  variation = null,
  customLess = '',
  baseUrl,
}) {
  if (!sources || typeof sources !== 'object') {
    throw new Error('sources is required; call template/style-sources first.');
  }

  const imports = sources.imports;

  if (!imports || typeof imports !== 'object' || Object.keys(imports).length === 0) {
    throw new Error('sources.imports is empty; call template/style-sources with include_imports enabled.');
  }

  if (typeof imports[sources.filename] !== 'string') {
    throw new Error(`The entry file ${sources.filename} is missing from the import tree.`);
  }

  const call = bootStyleWorker(workerSource, baseUrl ?? deriveBaseUrl(sources));

  // The variation is a Less variable, not an import path: the entry file ends
  // with @import (optional) ".../styles/@{internal-style}.less". It has to go
  // through the caller vars, because render() merges {...callerVars, ...styleVars}
  // and style vars win.
  const callerVars = { ...vars };
  if (variation) callerVars['@internal-style'] = variation;

  const started = Date.now();

  try {
    const rendered = await call('css', {
      style: {
        filename: sources.filename,
        filepath: sources.filepath,
        imports,
        vars: sources.vars && Object.keys(sources.vars).length > 0 ? sources.vars : {},
      },
      input: customLess ?? '',
      vars: callerVars,
    });

    const errors = toHostRealm(Array.isArray(rendered?.errors) ? [...rendered.errors].map(String) : []);

    if (typeof rendered?.css !== 'string' || rendered.css === '') {
      return {
        ok: false,
        errors: errors.length > 0 ? errors : ['The compiler returned no CSS.'],
        variables: toHostRealm(rendered?.variables ?? {}),
        duration_ms: Date.now() - started,
      };
    }

    const minified = await call('minify', {
      style: { desturl: sources.desturl },
      css: rendered.css,
    });

    return {
      ok: errors.length === 0,
      errors,
      css: minified.css,
      rtl: minified.rtl,
      raw_css: rendered.css,
      variables: toHostRealm(rendered.variables ?? {}),
      bytes: Buffer.byteLength(minified.css, 'utf8'),
      rtl_bytes: Buffer.byteLength(minified.rtl, 'utf8'),
      sha256: sha256(minified.css),
      duration_ms: Date.now() - started,
    };
  } finally {
    await call.close();
  }
}

/**
 * Extracts only the variable catalogue, skipping CSS generation. The worker's
 * `vars` command aborts the render once variables are collected.
 */
export async function collectVariables({ workerSource, sources, vars = {}, variation = null, customLess = '', baseUrl }) {
  const call = bootStyleWorker(workerSource, baseUrl ?? deriveBaseUrl(sources));
  const callerVars = { ...vars };
  if (variation) callerVars['@internal-style'] = variation;

  try {
    const result = await call('vars', {
      style: {
        filename: sources.filename,
        filepath: sources.filepath,
        imports: sources.imports,
        vars: sources.vars && Object.keys(sources.vars).length > 0 ? sources.vars : {},
      },
      input: customLess ?? '',
      vars: callerVars,
    });

    return toHostRealm(result?.variables ?? {});
  } finally {
    await call.close();
  }
}

/**
 * Compares two variable catalogues from compileStyle().
 *
 * Only the resolved value matters: a variable can be declared in several files
 * and still end up identical, and a variable can be untouched by the patch yet
 * change because it derives from one that was.
 */
export function diffVariables(before, after) {
  const changed = [];
  const added = [];
  const removed = [];
  const keys = new Set([...Object.keys(before ?? {}), ...Object.keys(after ?? {})]);

  for (const key of [...keys].sort()) {
    const a = before?.[key];
    const b = after?.[key];

    if (a === undefined) {
      added.push({ name: key, value: b?.value ?? null, file: b?.file ?? null });
      continue;
    }

    if (b === undefined) {
      removed.push({ name: key, value: a?.value ?? null, file: a?.file ?? null });
      continue;
    }

    if ((a?.value ?? null) !== (b?.value ?? null)) {
      changed.push({
        name: key,
        from: a?.value ?? null,
        to: b?.value ?? null,
        file: b?.file ?? a?.file ?? null,
        // `style: true` means the variation supplies this value, so overriding
        // it departs from the chosen variation rather than from the base style.
        from_variation: b?.style === true,
      });
    }
  }

  return { changed, added, removed };
}

/**
 * Maps changed variables to the UIkit components they belong to, so a reviewer
 * can tell what a patch will visibly affect. The prefix before the first dash
 * is YOOtheme's own grouping convention.
 */
export function affectedComponents(changed) {
  const components = new Set();

  for (const entry of changed) {
    const match = /^@(inverse-)?([a-z0-9]+)/.exec(entry.name ?? '');
    if (match) components.add(match[2]);
  }

  return [...components].sort();
}

function deriveBaseUrl(sources) {
  const contractBase = sources?.compile_contract?.base_url;
  if (typeof contractBase === 'string' && contractBase !== '') {
    try {
      const parsed = new URL(contractBase);
      if (['http:', 'https:'].includes(parsed.protocol)) return contractBase;
    } catch {
      // Fall through to the site-derived URL.
    }
  }

  // The FileManager resolves relative imports against location.href, so any
  // absolute URL on the same host works. Import keys are root-relative paths.
  const site = typeof sources?.site_url === 'string' ? sources.site_url : 'https://localhost';
  const fallbackPath = sources?.compile_contract?.platform === 'joomla'
    ? '/administrator/index.php'
    : '/wp-admin/customize.php';

  return `${site.replace(/\/+$/, '')}${fallbackPath}`;
}
