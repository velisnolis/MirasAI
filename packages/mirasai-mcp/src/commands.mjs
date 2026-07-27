import { loadRegistry, loadRegistryOrEmpty, saveRegistry, findSite, upsertSite, setDefaultSite } from './config.mjs';
import { MirasaiHostClient } from './site-client.mjs';
import { createRouterHandler, serveStdio } from './mcp-stdio.mjs';

export async function runCli(argv = process.argv.slice(2), io = defaultIo()) {
  const { command, args, configPath } = parseArgs(argv);

  if (command === 'help' || command === undefined) {
    io.stdout.write(helpText());
    return 0;
  }

  if (command === 'list-sites') {
    const registry = loadRegistry(configPath);
    io.stdout.write(`${JSON.stringify({
      default_site_id: registry.default_site_id,
      sites: registry.sites.map((site) => ({
        site_id: site.site_id,
        label: site.label,
        platform: site.platform,
        url: site.url,
        default: site.default,
        token_source: tokenSourceForSite(site),
        style_worker_pinned: typeof site.style_worker_sha256 === 'string',
      })),
    }, null, 2)}\n`);
    return 0;
  }

  if (command === 'add-site') {
    const registry = loadRegistryOrEmpty(configPath);
    const { flags } = parseFlags(args);
    const site = siteFromFlags(flags);
    const next = upsertSite(registry, site, { makeDefault: flags.default === true || registry.sites.length === 0 });
    const saved = saveRegistry(next, configPath ?? registry.config_path);

    io.stdout.write(`${JSON.stringify({
      saved: true,
      config_path: saved.config_path,
      site_id: site.site_id,
      default_site_id: saved.default_site_id,
      token_source: tokenSourceForSite(site),
    }, null, 2)}\n`);
    return 0;
  }

  if (command === 'set-default') {
    const registry = loadRegistry(configPath);
    const siteId = args[0];

    if (typeof siteId !== 'string' || siteId === '') {
      io.stderr.write('set-default requires a site_id.\n');
      return 2;
    }

    const next = setDefaultSite(registry, siteId);
    const saved = saveRegistry(next, configPath ?? registry.config_path);
    io.stdout.write(`${JSON.stringify({
      saved: true,
      config_path: saved.config_path,
      default_site_id: saved.default_site_id,
    }, null, 2)}\n`);
    return 0;
  }

  if (command === 'test-site') {
    const registry = loadRegistry(configPath);
    const site = findSite(registry, args[0] ?? null);
    const result = await new MirasaiHostClient(site).test();
    io.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
    return result.ok === true ? 0 : 1;
  }

  if (command === 'serve') {
    const registry = loadRegistry(configPath);
    await serveStdio(createRouterHandler(registry), io.stdin, io.stdout);
    return 0;
  }

  io.stderr.write(`Unknown command: ${command}\n\n${helpText()}`);
  return 2;
}

function tokenSourceForSite(site) {
  if (site.token_ref !== undefined) {
    return '1password';
  }
  if (site.token_env !== undefined) {
    return 'env';
  }
  if (site.token_plain_dev !== undefined) {
    return 'plain-dev';
  }
  if (site.basic_ref !== undefined) {
    return 'basic-1password';
  }
  if (site.basic_env !== undefined) {
    return 'basic-env';
  }
  if (site.basic_plain_dev !== undefined) {
    return 'basic-plain-dev';
  }
  return 'none';
}

export function parseArgs(argv) {
  const args = [];
  let configPath = undefined;

  for (let index = 0; index < argv.length; index += 1) {
    const arg = argv[index];

    if (arg === '--config') {
      configPath = argv[index + 1];
      index += 1;
      continue;
    }

    args.push(arg);
  }

  return {
    command: args.shift(),
    args,
    configPath,
  };
}

function parseFlags(argv) {
  const flags = {};
  const positional = [];

  for (let index = 0; index < argv.length; index += 1) {
    const arg = argv[index];

    if (!arg.startsWith('--')) {
      positional.push(arg);
      continue;
    }

    const name = arg.slice(2).replace(/-([a-z])/g, (_, char) => char.toUpperCase());

    if (name === 'default') {
      flags.default = true;
      continue;
    }

    flags[name] = argv[index + 1];
    index += 1;
  }

  return { flags, positional };
}

function siteFromFlags(flags) {
  const site = {
    site_id: requiredFlag(flags, 'siteId'),
    label: requiredFlag(flags, 'label'),
    platform: requiredFlag(flags, 'platform'),
    url: requiredFlag(flags, 'url'),
  };

  if (typeof flags.protocol === 'string' && flags.protocol !== '') {
    site.protocol = flags.protocol;
  }

  const secretFlags = {
    tokenRef: 'token_ref',
    tokenEnv: 'token_env',
    tokenPlainDev: 'token_plain_dev',
    basicRef: 'basic_ref',
    basicEnv: 'basic_env',
    basicPlainDev: 'basic_plain_dev',
  };

  for (const [flagName, siteKey] of Object.entries(secretFlags)) {
    if (typeof flags[flagName] === 'string' && flags[flagName] !== '') {
      site[siteKey] = flags[flagName];
    }
  }

  if (typeof flags.secretTtlSeconds === 'string' && flags.secretTtlSeconds !== '') {
    site.secret_ttl_seconds = Number(flags.secretTtlSeconds);
  }

  if (typeof flags.styleWorkerSha256 === 'string' && flags.styleWorkerSha256 !== '') {
    site.style_worker_sha256 = flags.styleWorkerSha256.toLowerCase();
  }

  return site;
}

function requiredFlag(flags, name) {
  if (typeof flags[name] !== 'string' || flags[name] === '') {
    throw new Error(`Missing required --${name.replace(/[A-Z]/g, (char) => `-${char.toLowerCase()}`)} flag.`);
  }

  return flags[name];
}

function defaultIo() {
  return {
    stdin: process.stdin,
    stdout: process.stdout,
    stderr: process.stderr,
  };
}

function helpText() {
  return `MirasAI MCP router

Usage:
  mirasai-mcp list-sites [--config sites.json]
  mirasai-mcp add-site --site-id ID --label LABEL --platform joomla|wordpress --url URL (--token-ref REF|--token-env ENV|--token-plain-dev TOKEN|--basic-ref REF|--basic-env ENV|--basic-plain-dev USER:PASS) [--protocol mirasai|mcp] [--secret-ttl-seconds 3600] [--style-worker-sha256 HASH] [--default] [--config sites.json]
  mirasai-mcp set-default site_id [--config sites.json]
  mirasai-mcp test-site [site_id] [--config sites.json]
  mirasai-mcp serve [--config sites.json]

`;
}
