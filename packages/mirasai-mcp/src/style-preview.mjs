/**
 * Orchestrates a YOOtheme Style preview: pull the sources from the host,
 * compile them here, and report what a patch would change.
 *
 * Nothing is written. Not to the site's database, not to its filesystem. The
 * point is to answer "what would this do" before anyone commits to it.
 */
import { compileStyle, diffVariables, affectedComponents, sha256 } from './style-compiler.mjs';

const LEGACY_WORKER_PATH = 'wp-content/themes/yootheme/assets/admin/js/worker.js';

function canonicalize(value) {
  if (Array.isArray(value)) return value.map(canonicalize);
  if (value === null || typeof value !== 'object') return value;

  return Object.fromEntries(
    Object.keys(value).sort().map((key) => [key, canonicalize(value[key])]),
  );
}

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
 * @param {string[]} [options.unsetVars] stored variable overrides to remove
 * @param {?string} [options.customLess] replaces custom_less when provided
 * @param {boolean} [options.includeCss] return the compiled CSS itself
 * @param {string} options.expectedWorkerSha256 locally pinned worker hash
 */
export async function previewStyle({
  client,
  siteUrl,
  styleId = null,
  variation = undefined,
  vars = {},
  unsetVars = [],
  customLess = undefined,
  includeCss = false,
  expectedWorkerSha256,
}) {
  const sources = await hostCall(client, 'template/style-sources', {
    ...(styleId ? { style_id: styleId } : {}),
    include_imports: true,
  });

  const workerPath = typeof sources?.compile_contract?.worker === 'string'
    ? sources.compile_contract.worker
    : LEGACY_WORKER_PATH;
  const workerFile = await hostCall(client, 'file/read', { path: workerPath });

  if (typeof workerFile?.content !== 'string' || workerFile.content === '') {
    const error = new Error(`Could not read the style worker at ${workerPath}.`);
    error.code = 'worker_unavailable';
    throw error;
  }

  const observedWorkerSha256 = sha256(workerFile.content);

  if (typeof expectedWorkerSha256 !== 'string' || !/^[a-f0-9]{64}$/i.test(expectedWorkerSha256)) {
    const error = new Error(
      `The style worker is not pinned for this site. Review SHA-256 ${observedWorkerSha256} and store it as style_worker_sha256 before executing the remote bundle.`,
    );
    error.code = 'style_worker_hash_required';
    error.observed_sha256 = observedWorkerSha256;
    throw error;
  }

  if (observedWorkerSha256 !== expectedWorkerSha256.toLowerCase()) {
    const error = new Error(
      `The remote style worker SHA-256 changed: expected ${expectedWorkerSha256.toLowerCase()}, observed ${observedWorkerSha256}. Refusing to execute it.`,
    );
    error.code = 'style_worker_hash_mismatch';
    error.expected_sha256 = expectedWorkerSha256.toLowerCase();
    error.observed_sha256 = observedWorkerSha256;
    throw error;
  }

  const contractBaseUrl = sources?.compile_contract?.base_url;
  const platform = sources?.compile_contract?.platform;
  const fallbackPath = platform === 'joomla'
    ? '/administrator/index.php'
    : '/wp-admin/customize.php';
  const baseUrl = isHttpUrl(contractBaseUrl)
    ? contractBaseUrl
    : `${String(siteUrl ?? 'https://localhost').replace(/\/+$/, '')}${fallbackPath}`;
  const sourcesSha256 = sha256(JSON.stringify(canonicalize({
    filename: sources.filename,
    filepath: sources.filepath,
    desturl: sources.desturl,
    imports: sources.imports,
    vars: sources.vars,
    base_url: baseUrl,
  })));

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
  for (const name of unsetVars) delete candidateVars[name];
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
  const requested = [...Object.keys(vars), ...unsetVars];
  const movedNames = new Set(
    [...diff.changed, ...diff.added, ...diff.removed].map((entry) => entry.name)
  );
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
      sources_sha256: sourcesSha256,
      provenance: sources?.compile_contract?.provenance ?? null,
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
    worker: {
      path: workerPath,
      sha256: observedWorkerSha256,
      pinned: true,
    },
    ...(includeCss ? { compiled_css: candidate.css, compiled_rtl: candidate.rtl } : {}),
    note: 'Nothing was written. YOOtheme stores CSS only when the customizer POSTs it, or when a future style/update tool does the same thing under a guarded write.',
  };
}

/**
 * Compile a candidate and hand the exact LTR/RTL artefacts to the host's
 * guarded writer. The host owns ETag revalidation, private snapshotting,
 * atomic replacement and rollback; the router owns the only faithful compiler.
 */
export async function updateStyle({
  client,
  siteUrl,
  styleId = null,
  variation = undefined,
  vars = {},
  unsetVars = [],
  customLess = undefined,
  ifMatch,
  dryRun = true,
  confirmGuardedWrite = false,
  expectedWorkerSha256,
}) {
  if (typeof ifMatch !== 'string' || ifMatch.trim() === '') {
    return {
      ok: false,
      error: 'if_match is required. Read or preview the Style first.',
      code: 'missing_if_match',
    };
  }

  if (!dryRun && confirmGuardedWrite !== true) {
    return {
      ok: false,
      error: 'This is a guarded write. Run dry_run=true first, then retry with confirm_guarded_write=true and a fresh if_match.',
      code: 'guarded_write_confirmation_required',
    };
  }

  const preview = await previewStyle({
    client,
    siteUrl,
    styleId,
    variation,
    vars,
    unsetVars,
    customLess,
    includeCss: true,
    expectedWorkerSha256,
  });

  if (preview.ok !== true) {
    return {
      ...preview,
      ok: false,
      stage: preview.stage ?? 'compile',
    };
  }

  if (typeof preview.etag !== 'string' || !hashEquals(preview.etag, ifMatch.trim())) {
    return {
      ok: false,
      error: 'Style etag mismatch. Re-read or preview it and retry with the fresh etag.',
      code: 'stale_etag',
      expected_etag: preview.etag ?? null,
      provided_etag: ifMatch.trim(),
    };
  }

  const host = await hostCall(client, 'template/style-update', {
    if_match: ifMatch.trim(),
    style_id: preview.style.id,
    ...(preview.style.variation !== undefined && preview.style.variation !== null
      ? { variation: preview.style.variation }
      : {}),
    vars,
    unset_vars: unsetVars,
    ...(customLess !== undefined ? { custom_less: customLess } : {}),
    compiled_css: preview.compiled_css,
    compiled_rtl: preview.compiled_rtl,
    compiled_css_sha256: preview.css.candidate_sha256,
    compiled_rtl_sha256: sha256(preview.compiled_rtl),
    ...(preview.compile.provenance === 'router_provenance_v1'
      ? {
        compile_provenance: {
          worker_sha256: preview.worker.sha256,
          sources_sha256: preview.compile.sources_sha256,
        },
      }
      : {}),
    dry_run: dryRun,
    ...(confirmGuardedWrite ? { confirm_guarded_write: true } : {}),
  });

  const safePreview = {
    style: preview.style,
    compile: preview.compile,
    css: preview.css,
    variables: preview.variables,
    affected_components: preview.affected_components,
    requested_but_ineffective: preview.requested_but_ineffective,
    worker: preview.worker,
  };

  if (dryRun) {
    return {
      ok: true,
      dry_run: true,
      preview: safePreview,
      host,
      etag: preview.etag,
      note: 'Nothing was written. Retry with dry_run=false, confirm_guarded_write=true, and the same fresh if_match.',
    };
  }

  let served = null;
  let readbackError = null;

  try {
    served = await hostCall(client, 'template/style-read', {});
  } catch (error) {
    readbackError = error instanceof Error ? error.message : String(error);
  }

  const verification = {
    host_action_is_updated: host?.action === 'updated',
    host_confirms_real_write: host?.dry_run === false,
    host_returned_new_etag: typeof host?.new_etag === 'string' && host.new_etag.trim() !== '',
    readback_succeeded: readbackError === null,
    readback_error: readbackError,
    etag_matches_host_result: typeof host?.new_etag === 'string'
      && typeof served?.etag === 'string'
      && hashEquals(host.new_etag, served.etag),
    compiled: served?.compiled ?? null,
    warnings: served?.warnings ?? [],
  };

  if (!verification.host_action_is_updated
    || !verification.host_confirms_real_write
    || !verification.host_returned_new_etag
    || !verification.readback_succeeded
    || !verification.etag_matches_host_result
  ) {
    return {
      ok: false,
      dry_run: false,
      error: 'The host may have written the Style, but post-write verification failed. Inspect the host result and private snapshot before retrying; do not blindly repeat the write.',
      code: 'style_write_verification_failed',
      stage: 'post_write_verification',
      preview: safePreview,
      host,
      verification,
      etag: served?.etag ?? host?.new_etag ?? null,
    };
  }

  return {
    ok: true,
    dry_run: false,
    preview: safePreview,
    host,
    verification,
    etag: served.etag,
  };
}

/**
 * Recompiles the active style exactly as configured and reports the host's
 * freshness signals for the CSS the site is actually serving.
 *
 * This is the tool for the case where compiled CSS has fallen behind its own
 * sources: a plugin contributing Less updates, nobody reopens the customizer,
 * and the site keeps serving stale CSS with nothing in the UI saying so.
 */
export async function verifyCompiledStyle({ client, siteUrl, includeCss = false, expectedWorkerSha256 }) {
  const preview = await previewStyle({
    client,
    siteUrl,
    includeCss: true,
    expectedWorkerSha256,
  });

  if (preview.ok !== true) {
    return {
      ...preview,
      ok: false,
      stage: preview.stage ?? 'fresh_compile',
      error: preview.error ?? 'The active style compiled with errors, so its served freshness cannot be verified.',
    };
  }

  const served = await hostCall(client, 'template/style-read', {});
  const compiled = served?.compiled ?? {};

  // Byte counts are NOT directly comparable, and saying so matters: the served
  // file has a version header prepended and its Google Fonts @import replaced
  // by locally stored @font-face rules, both added by the host after the
  // browser uploaded the CSS. A served file that is larger than a fresh compile
  // is normal. The host's staleness signals are useful but do not constitute a
  // content comparison; stale_sources is currently an mtime heuristic.
  const freshBytes = preview.css.candidate_bytes;
  const servedBytes = typeof compiled.bytes === 'number' ? compiled.bytes : null;
  const staleSources = typeof compiled.stale_sources === 'boolean' ? compiled.stale_sources : null;
  const staleVersion = typeof compiled.stale_version === 'boolean' ? compiled.stale_version : null;
  const freshnessKnown = staleSources !== null && staleVersion !== null;
  const fresh = freshnessKnown ? staleSources === false && staleVersion === false : null;

  return {
    ok: true,
    fresh,
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
      stale_sources: staleSources,
      stale_version: staleVersion,
      newest_source: compiled.newest_source ?? null,
      freshness_method: compiled.freshness_method ?? null,
    },
    verification: {
      content_compared: false,
      signals: ['fresh_compile_ok', 'compiled_version', 'source_mtime'],
      limitation: 'YOOtheme adds a version header and rewrites remote font imports after compilation, so the fresh CSS hash is not compared directly with the served file.',
    },
    warnings: served?.warnings ?? [],
    etag: served?.etag ?? null,
    ...(includeCss ? { compiled_css: preview.compiled_css, compiled_rtl: preview.compiled_rtl } : {}),
    note: 'The fresh CSS was produced here and not stored. `fresh` summarizes the host version and source-mtime signals; it is not a byte-for-byte content comparison.',
  };
}

export { sha256 };

function isHttpUrl(value) {
  if (typeof value !== 'string' || value === '') return false;

  try {
    return ['http:', 'https:'].includes(new URL(value).protocol);
  } catch {
    return false;
  }
}

function hashEquals(left, right) {
  if (typeof left !== 'string' || typeof right !== 'string' || left.length !== right.length) {
    return false;
  }

  return cryptoSafeEqual(left, right);
}

function cryptoSafeEqual(left, right) {
  let mismatch = 0;
  for (let index = 0; index < left.length; index += 1) {
    mismatch |= left.charCodeAt(index) ^ right.charCodeAt(index);
  }
  return mismatch === 0;
}
