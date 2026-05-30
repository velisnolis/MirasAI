import { execFileSync } from 'node:child_process';

const DEFAULT_SECRET_TTL_SECONDS = 3600;
const secretCache = new Map();

export function resolveToken(site, env = process.env, options = {}) {
  const reader = options.readOnePassword ?? readOnePassword;
  const now = options.now ?? Date.now;
  const basic = resolveBasicCredentials(site, env, reader, now);
  if (basic !== null) {
    return basic;
  }

  if (site.token_ref !== undefined) {
    return {
      type: 'header',
      value: readCachedOnePassword(site.token_ref, site, env, reader, now),
    };
  }

  if (site.token_env !== undefined) {
    const value = env[site.token_env];
    if (typeof value !== 'string' || value === '') {
      throw new Error(`Environment variable ${site.token_env} is not set for site ${site.site_id}.`);
    }
    return {
      type: 'header',
      value,
    };
  }

  if (site.token_plain_dev !== undefined) {
    return {
      type: 'header',
      value: site.token_plain_dev,
    };
  }

  throw new Error(`Site ${site.site_id} has no token source.`);
}

export function clearSecretCache() {
  secretCache.clear();
}

function resolveBasicCredentials(site, env, reader, now) {
  if (site.basic_ref !== undefined) {
    return {
      type: 'basic',
      value: readCachedOnePassword(site.basic_ref, site, env, reader, now),
    };
  }

  if (site.basic_env !== undefined) {
    const value = env[site.basic_env];
    if (typeof value !== 'string' || value === '') {
      throw new Error(`Environment variable ${site.basic_env} is not set for site ${site.site_id}.`);
    }

    return {
      type: 'basic',
      value,
    };
  }

  if (site.basic_plain_dev !== undefined) {
    return {
      type: 'basic',
      value: site.basic_plain_dev,
    };
  }

  return null;
}

function readCachedOnePassword(reference, site, env, reader, now) {
  const ttlMs = secretTtlMs(site, env);

  if (ttlMs > 0) {
    const cached = secretCache.get(reference);
    const currentTime = now();

    if (cached !== undefined && cached.expires_at > currentTime) {
      return cached.value;
    }

    const value = reader(reference);
    secretCache.set(reference, {
      value,
      expires_at: currentTime + ttlMs,
    });
    return value;
  }

  return reader(reference);
}

function secretTtlMs(site, env) {
  const raw = site.secret_ttl_seconds ?? env.MIRASAI_MCP_SECRET_TTL_SECONDS ?? DEFAULT_SECRET_TTL_SECONDS;
  const seconds = Number(raw);

  if (!Number.isFinite(seconds) || seconds < 0) {
    throw new Error(`Invalid secret_ttl_seconds for site ${site.site_id}: must be a non-negative number.`);
  }

  return seconds * 1000;
}

function readOnePassword(reference) {
  try {
    return execFileSync('op', ['read', reference], {
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
    }).trim();
  } catch (error) {
    const message = error.stderr?.toString?.().trim() || error.message;
    throw new Error(`Could not resolve 1Password reference ${reference}: ${message}`);
  }
}
