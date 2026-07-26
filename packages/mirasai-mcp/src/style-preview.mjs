/**
 * Orchestrates a YOOtheme Style preview: pull the sources from the host,
 * compile them here, and report what a patch would change.
 *
 * Nothing is written. Not to the site's database, not to its filesystem. The
 * point is to answer "what would this do" before anyone commits to it.
 */
import { compileStyle, diffVariables, affectedComponents, sha256 } from './style-compiler.mjs';

const WORKER_PATH = 'wp-content/themes/yootheme/assets/admin/js/worker.js';

/**
 * Host tool results arrive as MCP content envelopes. Unwrap to the payload.
 */
function unwrap(result) {
  if (result && typeof result === 'object' && result.structuredContent !== undefined) {
    return result.structuredContent;
  }

  const text = result?.content?.[0]?.text;

  if (typeof text === 'string') {
    try {
      return JSON.parse(text);
    } catch {
      return { error: text };
    }
  }

  return result;
}

async function hostCall(client, name, args) {
  const payload = unwrap(await client.callTool(name, args));

  if (payload && typeof payload === 'object' && typeof payload.error === 'string') {
    const error = new Error(payload.error);
    error.code = payload.code ?? 'host_tool_error';
    error.tool = name;
    throw error;
  }

  return payload;
}

/**
 * @param {object} options
 * @param {object} options.client MirasaiHostClient for the target site
 * @param {string} options.siteUrl the site's base URL, used by the compiler's
 *   file resolver; taken from the registry entry rather than guessed
 * @param {?string} [options.styleId] defaults to the active style
 * @param {?string} [options.variation] defaults to the active variation
 * @param {object} [options.vars] Less variable patch to preview
 * @param {?string} [options.customLess] replaces custom_less when provided
 * @param {boolean} [options.includeCss] return the compiled CSS itself
 */
export async function previewStyle({
  client,
  siteUrl,
  styleId = null,
  variation = undefined,
  vars = {},
  customLess = undefined,
  includeCss = false,
}) {
  const sources = await hostCall(client, 'template/style-sources', {
    ...(styleId ? { style_id: styleId } : {}),
    include_imports: true,
  });

  const workerFile = await hostCall(client, 'file/read', { path: WORKER_PATH });

  if (typeof workerFile?.content !== 'string' || workerFile.content === '') {
    const error = new Error(`Could not read the style worker at ${WORKER_PATH}.`);
    error.code = 'worker_unavailable';
    throw error;
  }

  const baseUrl = `${String(siteUrl ?? 'https://localhost').replace(/\/+$/, '')}/wp-admin/customize.php`;

  // The baseline is what the site would compile right now: its stored
  // overrides, its custom Less, its variation. Comparing a patch against the
  // stored config rather than against the bare style is what makes the diff
  // mean "what changes on this site".
  const current = sources.overrides ?? {};
  const activeVariation = variation === undefined ? (current.internal_style ?? null) : variation;
  const currentVars = current.less && typeof current.less === 'object' && !Array.isArray(current.less)
    ? current.less
    : {};
  const currentCustom = typeof current.custom_less === 'string' ? current.custom_less : '';

  const baseline = await compileStyle({
    workerSource: workerFile.content,
    sources,
    vars: currentVars,
    variation: activeVariation,
    customLess: currentCustom,
    baseUrl,
  });

  if (!baseline.ok && !baseline.css) {
    return {
      ok: false,
      stage: 'baseline',
      error: 'The style fails to compile as currently configured, so a patch cannot be previewed against it.',
      errors: baseline.errors,
    };
  }

  const candidateVars = { ...currentVars, ...vars };
  const candidateCustom = customLess === undefined ? currentCustom : customLess;

  const candidate = await compileStyle({
    workerSource: workerFile.content,
    sources,
    vars: candidateVars,
    variation: activeVariation,
    customLess: candidateCustom,
    baseUrl,
  });

  if (!candidate.css) {
    return {
      ok: false,
      stage: 'candidate',
      error: 'The patch does not compile. Nothing has been changed.',
      errors: candidate.errors,
      requested_vars: Object.keys(vars),
    };
  }

  const diff = diffVariables(baseline.variables, candidate.variables);

  // Variables the caller asked to change but that did not move. Usually a typo
  // in the variable name, or a value the style forces regardless.
  const requested = Object.keys(vars);
  const movedNames = new Set(diff.changed.map((entry) => entry.name));
  const ineffective = requested.filter((name) => !movedNames.has(name));

  return {
    ok: candidate.ok,
    style: {
      id: sources.style_id,
      variation: activeVariation,
      is_active_style: sources.is_active_style === true,
    },
    compile: {
      errors: candidate.errors,
      duration_ms: candidate.duration_ms,
      import_count: sources.import_count,
    },
    css: {
      baseline_bytes: baseline.bytes ?? null,
      candidate_bytes: candidate.bytes,
      delta_bytes: baseline.bytes != null ? candidate.bytes - baseline.bytes : null,
      baseline_sha256: baseline.sha256 ?? null,
      candidate_sha256: candidate.sha256,
      identical: baseline.sha256 === candidate.sha256,
      rtl_bytes: candidate.rtl_bytes,
    },
    variables: {
      total: Object.keys(candidate.variables).length,
      changed: diff.changed,
      added: diff.added,
      removed: diff.removed,
      changed_count: diff.changed.length,
    },
    affected_components: affectedComponents(diff.changed),
    requested_but_ineffective: ineffective,
    // The site's stored state, so a later guarded write can check it has not
    // moved underneath us.
    etag: sources.etag ?? null,
    compiled_on_site: sources.compiled ?? null,
    ...(includeCss ? { compiled_css: candidate.css, compiled_rtl: candidate.rtl } : {}),
    note: 'Nothing was written. YOOtheme stores CSS only when the customizer POSTs it, or when a future style/update tool does the same thing under a guarded write.',
  };
}

/**
 * Recompiles the active style exactly as configured and compares the result
 * with the CSS the site is actually serving.
 *
 * This is the tool for the case where compiled CSS has fallen behind its own
 * sources: a plugin contributing Less updates, nobody reopens the customizer,
 * and the site keeps serving stale CSS with nothing in the UI saying so.
 */
export async function verifyCompiledStyle({ client, siteUrl, includeCss = false }) {
  const preview = await previewStyle({ client, siteUrl, includeCss: true });

  if (preview.ok === false && preview.error) {
    return preview;
  }

  const served = await hostCall(client, 'template/style-read', {});
  const compiled = served?.compiled ?? {};

  // Byte counts are NOT directly comparable, and saying so matters: the served
  // file has a version header prepended and its Google Fonts @import replaced
  // by locally stored @font-face rules, both added by the host after the
  // browser uploaded the CSS. A served file that is larger than a fresh compile
  // is normal. The authoritative staleness signals are stale_sources and
  // stale_version, not size.
  const freshBytes = preview.css.candidate_bytes;
  const servedBytes = typeof compiled.bytes === 'number' ? compiled.bytes : null;

  return {
    ok: true,
    style: preview.style,
    fresh_compile: {
      bytes: freshBytes,
      sha256: preview.css.candidate_sha256,
      errors: preview.compile.errors,
    },
    size_comparison: {
      comparable: false,
      delta_bytes: servedBytes === null ? null : servedBytes - freshBytes,
      why: 'The served file carries a version header and locally stored @font-face rules that replace the compiler\'s Google Fonts @import. Both are added on the host after compilation, so it is normally larger. Judge freshness by stale_sources and stale_version.',
    },
    served: {
      file: compiled.file ?? null,
      bytes: compiled.bytes ?? null,
      compiled_at: compiled.compiled_at ?? null,
      compiled_version: compiled.compiled_version ?? null,
      stale_sources: compiled.stale_sources ?? null,
      stale_version: compiled.stale_version ?? null,
      newest_source: compiled.newest_source ?? null,
    },
    warnings: served?.warnings ?? [],
    etag: served?.etag ?? null,
    ...(includeCss ? { compiled_css: preview.compiled_css, compiled_rtl: preview.compiled_rtl } : {}),
    note: 'The compiled CSS was produced here and not stored. Writing it to the site is a guarded write and is not implemented yet.',
  };
}

export { sha256 };
