import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const VALID_PLATFORMS = new Set(['joomla', 'wordpress']);
const VALID_PROTOCOLS = new Set(['mirasai', 'mcp']);

export function defaultConfigPath() {
  const configHome = process.env.XDG_CONFIG_HOME || path.join(os.homedir(), '.config');
  return path.join(configHome, 'mirasai-mcp', 'sites.json');
}

export function loadRegistry(configPath = defaultConfigPath()) {
  const resolvedPath = path.resolve(configPath);
  const permissionWarnings = registryPermissionWarnings(resolvedPath);
  const raw = fs.readFileSync(resolvedPath, 'utf8');
  const registry = JSON.parse(raw);
  const errors = validateRegistry(registry);

  if (errors.length > 0) {
    throw new Error(`Invalid MirasAI site registry:\n${errors.map((error) => `- ${error}`).join('\n')}`);
  }

  return normalizeRegistry({
    ...registry,
    warnings: [
      ...(Array.isArray(registry.warnings) ? registry.warnings : []),
      ...permissionWarnings,
    ],
  }, resolvedPath);
}

export function loadRegistryOrEmpty(configPath = defaultConfigPath()) {
  const resolvedPath = path.resolve(configPath);

  if (!fs.existsSync(resolvedPath)) {
    return normalizeRegistry({
      schema_version: 1,
      default_site_id: null,
      sites: [],
    }, resolvedPath);
  }

  return loadRegistry(resolvedPath);
}

export function saveRegistry(registry, configPath = registry.config_path ?? defaultConfigPath()) {
  const resolvedPath = path.resolve(configPath);
  const serializable = serializeRegistry(registry);
  const errors = validateRegistry(serializable);

  if (errors.length > 0) {
    throw new Error(`Cannot save invalid MirasAI site registry:\n${errors.map((error) => `- ${error}`).join('\n')}`);
  }

  fs.mkdirSync(path.dirname(resolvedPath), { recursive: true, mode: 0o700 });
  fs.writeFileSync(resolvedPath, `${JSON.stringify(serializable, null, 2)}\n`, { mode: 0o600 });

  return normalizeRegistry(serializable, resolvedPath);
}

export function serializeRegistry(registry) {
  const defaultSiteId = registry.default_site_id ?? (registry.sites[0]?.site_id ?? null);

  return {
    schema_version: 1,
    default_site_id: defaultSiteId,
    sites: registry.sites.map((site) => serializeSite(site)),
  };
}

export function serializeSite(site) {
  const serialized = {
    site_id: site.site_id,
    label: site.label,
    platform: site.platform,
    url: site.url,
  };

  for (const key of ['protocol', 'token_ref', 'token_env', 'token_plain_dev', 'basic_ref', 'basic_env', 'basic_plain_dev', 'secret_ttl_seconds', 'style_worker_sha256']) {
    if (site[key] !== undefined) {
      serialized[key] = site[key];
    }
  }

  return serialized;
}

export function validateRegistry(registry) {
  const errors = [];

  if (registry === null || typeof registry !== 'object' || Array.isArray(registry)) {
    return ['registry must be an object'];
  }

  if (registry.schema_version !== 1) {
    errors.push('schema_version must be 1');
  }

  if (!Array.isArray(registry.sites)) {
    errors.push('sites must be an array');
    return errors;
  }

  const seen = new Set();
  registry.sites.forEach((site, index) => {
    const prefix = `sites[${index}]`;

    if (site === null || typeof site !== 'object' || Array.isArray(site)) {
      errors.push(`${prefix} must be an object`);
      return;
    }

    if (typeof site.site_id !== 'string' || site.site_id.trim() === '') {
      errors.push(`${prefix}.site_id must be a non-empty string`);
    } else if (seen.has(site.site_id)) {
      errors.push(`${prefix}.site_id duplicates "${site.site_id}"`);
    } else {
      seen.add(site.site_id);
    }

    if (typeof site.label !== 'string' || site.label.trim() === '') {
      errors.push(`${prefix}.label must be a non-empty string`);
    }

    if (!VALID_PLATFORMS.has(site.platform)) {
      errors.push(`${prefix}.platform must be "joomla" or "wordpress"`);
    }

    if (site.protocol !== undefined && !VALID_PROTOCOLS.has(site.protocol)) {
      errors.push(`${prefix}.protocol must be "mirasai" or "mcp" when present`);
    }

    if (typeof site.url !== 'string' || site.url.trim() === '') {
      errors.push(`${prefix}.url must be a non-empty string`);
    } else {
      try {
        const url = new URL(site.url);
        if (!['http:', 'https:'].includes(url.protocol)) {
          errors.push(`${prefix}.url must be http or https`);
        }
      } catch {
        errors.push(`${prefix}.url must be a valid URL`);
      }
    }

    const tokenSources = ['token_ref', 'token_env', 'token_plain_dev', 'basic_ref', 'basic_env', 'basic_plain_dev'].filter((key) => site[key] !== undefined);
    if (tokenSources.length !== 1) {
      errors.push(`${prefix} must define exactly one of token_ref, token_env, token_plain_dev, basic_ref, basic_env, basic_plain_dev`);
    }

    if (site.secret_ttl_seconds !== undefined) {
      if (typeof site.secret_ttl_seconds !== 'number' || !Number.isFinite(site.secret_ttl_seconds) || site.secret_ttl_seconds < 0) {
        errors.push(`${prefix}.secret_ttl_seconds must be a non-negative number when present`);
      }
    }

    if (site.style_worker_sha256 !== undefined) {
      if (typeof site.style_worker_sha256 !== 'string' || !/^[a-f0-9]{64}$/i.test(site.style_worker_sha256)) {
        errors.push(`${prefix}.style_worker_sha256 must be a 64-character hexadecimal SHA-256 when present`);
      }
    }
  });

  if (registry.default_site_id !== undefined && registry.default_site_id !== null) {
    if (typeof registry.default_site_id !== 'string' || registry.default_site_id.trim() === '') {
      errors.push('default_site_id must be a non-empty string when present');
    } else if (!seen.has(registry.default_site_id)) {
      errors.push(`default_site_id "${registry.default_site_id}" does not match any site`);
    }
  }

  return errors;
}

export function normalizeRegistry(registry, configPath = null) {
  return {
    schema_version: 1,
    default_site_id: registry.default_site_id ?? (registry.sites[0]?.site_id ?? null),
    config_path: configPath,
    warnings: Array.isArray(registry.warnings) ? registry.warnings : [],
    sites: registry.sites.map((site) => ({
      site_id: site.site_id,
      label: site.label,
      platform: site.platform,
      protocol: site.protocol ?? 'mirasai',
      url: site.url,
      token_ref: site.token_ref,
      token_env: site.token_env,
      token_plain_dev: site.token_plain_dev,
      basic_ref: site.basic_ref,
      basic_env: site.basic_env,
      basic_plain_dev: site.basic_plain_dev,
      secret_ttl_seconds: site.secret_ttl_seconds,
      style_worker_sha256: typeof site.style_worker_sha256 === 'string'
        ? site.style_worker_sha256.toLowerCase()
        : undefined,
      default: site.site_id === (registry.default_site_id ?? registry.sites[0]?.site_id),
    })),
  };
}

function registryPermissionWarnings(configPath) {
  const stats = fs.statSync(configPath);
  const extraPermissions = stats.mode & 0o077;

  if (extraPermissions === 0) {
    return [];
  }

  return [
    {
      code: 'registry_permissions_too_open',
      path: configPath,
      mode: `0${(stats.mode & 0o777).toString(8)}`,
      message: 'MirasAI registry file is readable or writable by group/other users. Recommended mode: 0600.',
    },
  ];
}

export function findSite(registry, siteId = null) {
  const resolvedSiteId = siteId || registry.default_site_id;

  if (resolvedSiteId === null || resolvedSiteId === undefined || resolvedSiteId === '') {
    throw new Error('No site_id provided and registry has no default_site_id.');
  }

  const site = registry.sites.find((candidate) => candidate.site_id === resolvedSiteId);

  if (site === undefined) {
    const available = registry.sites.map((candidate) => candidate.site_id).join(', ');
    throw new Error(`Unknown site_id "${resolvedSiteId}". Available sites: ${available}`);
  }

  return site;
}

export function upsertSite(registry, site, { makeDefault = false } = {}) {
  const serializedSite = serializeSite(site);
  const current = serializeRegistry(registry);
  const index = current.sites.findIndex((candidate) => candidate.site_id === serializedSite.site_id);

  if (index === -1) {
    current.sites.push(serializedSite);
  } else {
    current.sites[index] = serializedSite;
  }

  if (makeDefault || current.default_site_id === null || current.default_site_id === undefined) {
    current.default_site_id = serializedSite.site_id;
  }

  const errors = validateRegistry(current);
  if (errors.length > 0) {
    throw new Error(`Invalid site definition:\n${errors.map((error) => `- ${error}`).join('\n')}`);
  }

  return normalizeRegistry(current, registry.config_path);
}

export function setDefaultSite(registry, siteId) {
  findSite(registry, siteId);
  const current = serializeRegistry(registry);
  current.default_site_id = siteId;

  return normalizeRegistry(current, registry.config_path);
}
