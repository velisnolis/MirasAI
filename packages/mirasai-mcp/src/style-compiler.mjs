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
import vm from 'node:vm';
import crypto from 'node:crypto';

const CALL_TIMEOUT_MS = 120_000;

/**
 * Boots a worker bundle and returns a call(cmd, data) function.
 *
 * @param {string} workerSource contents of the site's assets/admin/js/worker.js
 * @param {string} baseUrl an absolute URL on the target site; the worker's
 *   FileManager resolves import paths against it via extractUrlParts()
 */
export function bootStyleWorker(workerSource, baseUrl) {
  const listeners = [];
  const pending = new Map();
  let nextId = 0;

  // Only what the bundle actually needs.
  //
  // Do NOT add Node's intrinsics (Object, Array, Promise, Map…) here. The vm
  // context has its own, and mixing objects from two realms silently breaks
  // less.js's type checks: compilation reports no error, still returns the full
  // variable list, and emits a fraction of the CSS. That failure mode costs
  // hours to find because everything looks like it worked.
  const sandbox = {
    console: { warn() {}, error() {}, info() {}, debug() {}, log() {} },
    setTimeout,
    clearTimeout,
    setInterval,
    clearInterval,
    queueMicrotask,
    TextEncoder,
    TextDecoder,
    btoa: (s) => Buffer.from(s, 'binary').toString('base64'),
    atob: (s) => Buffer.from(s, 'base64').toString('binary'),
    location: { href: baseUrl },
    // every import arrives through api.staticFiles(); nothing may hit the network
    fetch: async (url) => {
      throw new Error(`Blocked network access from the style worker: ${url}`);
    },
    importScripts: () => {},
  };

  sandbox.self = sandbox;
  sandbox.globalThis = sandbox;
  sandbox.addEventListener = (type, fn) => {
    if (type === 'message') listeners.push(fn);
  };
  sandbox.removeEventListener = () => {};
  sandbox.postMessage = (message) => {
    const entry = pending.get(message?.id);
    if (!entry) return;
    pending.delete(message.id);
    clearTimeout(entry.timer);
    if (message.error) entry.reject(message.error);
    else entry.resolve(message.result);
  };

  vm.createContext(sandbox);
  vm.runInContext(workerSource, sandbox, { filename: 'yootheme-worker.js' });

  if (listeners.length === 0) {
    throw new Error('worker.js did not register a message listener; it may not be a YOOtheme style worker.');
  }

  return function call(cmd, data) {
    const id = ++nextId;

    return new Promise((resolve, reject) => {
      const timer = setTimeout(() => {
        pending.delete(id);
        reject(new Error(`Style worker timed out on "${cmd}" after ${CALL_TIMEOUT_MS} ms.`));
      }, CALL_TIMEOUT_MS);

      pending.set(id, { resolve, reject, timer });

      for (const fn of listeners) fn({ data: [id, { cmd, data }] });
    });
  };
}

export function sha256(value) {
  return crypto.createHash('sha256').update(value).digest('hex');
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
    bytes: minified.css.length,
    rtl_bytes: minified.rtl.length,
    sha256: sha256(minified.css),
    duration_ms: Date.now() - started,
  };
}

/**
 * Extracts only the variable catalogue, skipping CSS generation. The worker's
 * `vars` command aborts the render once variables are collected.
 */
export async function collectVariables({ workerSource, sources, vars = {}, variation = null, customLess = '', baseUrl }) {
  const call = bootStyleWorker(workerSource, baseUrl ?? deriveBaseUrl(sources));
  const callerVars = { ...vars };
  if (variation) callerVars['@internal-style'] = variation;

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
  // The FileManager resolves relative imports against location.href, so any
  // absolute URL on the same host works. Import keys are root-relative paths.
  const site = typeof sources?.site_url === 'string' ? sources.site_url : 'https://localhost';

  return `${site.replace(/\/+$/, '')}/wp-admin/customize.php`;
}
