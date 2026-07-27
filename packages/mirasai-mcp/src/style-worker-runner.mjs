/**
 * Isolated process runner for the YOOtheme Style worker bundle.
 *
 * The parent process starts this file with Node's Permission Model enabled,
 * an empty environment, and access to this file only. The remotely supplied
 * bundle is then evaluated in a vm context that receives no host functions or
 * objects: all Web Worker shims are created inside the context itself.
 *
 * `node:vm` is not a security boundary by itself, and Node's Permission Model
 * is defense in depth rather than a complete sandbox. The parent therefore
 * also requires the remote bundle to match a locally pinned SHA-256 before
 * starting this runner.
 */
import readline from 'node:readline';
import vm from 'node:vm';

const DEFAULT_BOOT_TIMEOUT_MS = 10_000;
const DEFAULT_CALL_TIMEOUT_MS = 120_000;
const MAX_TIMER_ROUNDS = 10_000;

let workerContext = null;
let callTimeoutMs = DEFAULT_CALL_TIMEOUT_MS;

const SHIM_SOURCE = `
(() => {
  const listeners = [];
  const timerQueue = [];
  let timerId = 0;

  const noop = () => {};
  globalThis.console = Object.freeze({
    warn: noop,
    error: noop,
    info: noop,
    debug: noop,
    log: noop,
  });

  globalThis.location = Object.freeze({ href: String(globalThis.__baseUrl || '') });
  globalThis.fetch = async (url) => {
    throw new Error('Blocked network access from the style worker: ' + String(url));
  };
  globalThis.importScripts = noop;

  globalThis.setTimeout = (fn, _delay, ...args) => {
    const id = ++timerId;
    if (typeof fn === 'function') timerQueue.push({ id, fn, args });
    return id;
  };
  globalThis.clearTimeout = (id) => {
    const index = timerQueue.findIndex((entry) => entry.id === id);
    if (index >= 0) timerQueue.splice(index, 1);
  };
  globalThis.setInterval = () => {
    throw new Error('setInterval is not available in the isolated style worker.');
  };
  globalThis.clearInterval = noop;
  globalThis.queueMicrotask = (fn) => Promise.resolve().then(fn);

  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=';
  globalThis.btoa = (input) => {
    const value = String(input);
    let output = '';
    for (let block = 0, charCode, index = 0, map = alphabet;
      value.charAt(index | 0) || (map = '=', index % 1);
      output += map.charAt(63 & block >> 8 - index % 1 * 8)) {
      charCode = value.charCodeAt(index += 3 / 4);
      if (charCode > 255) throw new TypeError('btoa only accepts binary strings.');
      block = block << 8 | charCode;
    }
    return output;
  };
  globalThis.atob = (input) => {
    const value = String(input).replace(/=+$/, '');
    let output = '';
    if (value.length % 4 === 1) throw new TypeError('Invalid base64 input.');
    for (let block = 0, bc = 0, index = 0, buffer;
      (buffer = value.charAt(index++));
      ~buffer && (block = bc % 4 ? block * 64 + buffer : buffer, bc++ % 4)
        ? output += String.fromCharCode(255 & block >> (-2 * bc & 6))
        : 0) {
      buffer = alphabet.indexOf(buffer);
    }
    return output;
  };

  globalThis.TextEncoder = class TextEncoder {
    encode(input = '') {
      const binary = unescape(encodeURIComponent(String(input)));
      const bytes = new Uint8Array(binary.length);
      for (let index = 0; index < binary.length; index++) {
        bytes[index] = binary.charCodeAt(index);
      }
      return bytes;
    }
  };
  globalThis.TextDecoder = class TextDecoder {
    decode(input = new Uint8Array()) {
      let encoded = '';
      for (const byte of input) {
        encoded += '%' + Number(byte).toString(16).padStart(2, '0');
      }
      return decodeURIComponent(encoded);
    }
  };

  globalThis.addEventListener = (type, fn) => {
    if (type === 'message' && typeof fn === 'function') listeners.push(fn);
  };
  globalThis.removeEventListener = (type, fn) => {
    if (type !== 'message') return;
    const index = listeners.indexOf(fn);
    if (index >= 0) listeners.splice(index, 1);
  };
  globalThis.postMessage = (message) => {
    globalThis.__outbox.push(JSON.stringify(message));
  };

  globalThis.__listeners = listeners;
  globalThis.__timerQueue = timerQueue;
  globalThis.__outbox = [];
  globalThis.self = globalThis;
})();
`;

const DISPATCH_SOURCE = `
(() => {
  globalThis.__outbox.length = 0;
  globalThis.__dispatchError = null;
  const request = JSON.parse(globalThis.__requestJson);
  const event = { data: [request.id, { cmd: request.cmd, data: request.data }] };
  const returns = globalThis.__listeners.map((listener) => listener(event));
  Promise.all(returns).catch((error) => {
    globalThis.__dispatchError = String(error && (error.message || error));
  });
})()
`;

const RUN_TIMERS_SOURCE = `
(() => {
  const queue = globalThis.__timerQueue.splice(0);
  Promise.all(queue.map((entry) => entry.fn(...entry.args))).catch((error) => {
    globalThis.__dispatchError = String(error && (error.message || error));
  });
})()
`;

function positiveTimeout(value, fallback) {
  return Number.isInteger(value) && value > 0 ? value : fallback;
}

function initialize(message) {
  const bootTimeoutMs = positiveTimeout(message.boot_timeout_ms, DEFAULT_BOOT_TIMEOUT_MS);
  callTimeoutMs = positiveTimeout(message.call_timeout_ms, DEFAULT_CALL_TIMEOUT_MS);
  const sandbox = Object.create(null);
  sandbox.__baseUrl = String(message.base_url ?? '');

  workerContext = vm.createContext(
    sandbox,
    {
      name: 'yootheme-style-worker',
      origin: String(message.base_url ?? 'https://localhost'),
      codeGeneration: { strings: true, wasm: false },
      microtaskMode: 'afterEvaluate',
    },
  );

  vm.runInContext(SHIM_SOURCE, workerContext, {
    filename: 'mirasai-style-worker-shim.js',
    timeout: bootTimeoutMs,
  });
  vm.runInContext(String(message.worker_source ?? ''), workerContext, {
    filename: 'yootheme-worker.js',
    timeout: bootTimeoutMs,
  });

  const listenerCount = vm.runInContext('globalThis.__listeners.length', workerContext, {
    timeout: bootTimeoutMs,
  });

  if (!Number.isInteger(listenerCount) || listenerCount === 0) {
    throw new Error('worker.js did not register a message listener; it may not be a YOOtheme style worker.');
  }
}

function dispatch(message) {
  if (workerContext === null) {
    throw new Error('Style worker runner is not initialized.');
  }

  workerContext.__requestJson = JSON.stringify({
    id: message.id,
    cmd: message.cmd,
    data: message.data,
  });

  vm.runInContext(DISPATCH_SOURCE, workerContext, {
    filename: 'mirasai-style-worker-dispatch.js',
    timeout: callTimeoutMs,
  });

  for (let round = 0; round <= MAX_TIMER_ROUNDS; round++) {
    const state = vm.runInContext(
      `JSON.stringify({
        outbox: globalThis.__outbox,
        timer_count: globalThis.__timerQueue.length,
        error: globalThis.__dispatchError
      })`,
      workerContext,
      { timeout: callTimeoutMs },
    );
    const parsed = JSON.parse(state);

    if (parsed.error) {
      throw new Error(parsed.error);
    }

    if (Array.isArray(parsed.outbox) && parsed.outbox.length > 0) {
      const response = parsed.outbox
        .map((entry) => JSON.parse(entry))
        .find((entry) => entry?.id === message.id);

      if (!response) {
        throw new Error(`Style worker returned no matching response for "${message.cmd}".`);
      }

      if (response.error) {
        throw new Error(String(response.error));
      }

      return response.result;
    }

    if (parsed.timer_count > 0) {
      vm.runInContext(RUN_TIMERS_SOURCE, workerContext, {
        filename: 'mirasai-style-worker-timers.js',
        timeout: callTimeoutMs,
      });
      continue;
    }

    // A blank evaluation drains promise jobs because the context uses
    // microtaskMode: "afterEvaluate".
    vm.runInContext('', workerContext, { timeout: callTimeoutMs });
  }

  throw new Error(`Style worker returned no response for "${message.cmd}".`);
}

function send(payload) {
  process.stdout.write(`${JSON.stringify(payload)}\n`);
}

const input = readline.createInterface({
  input: process.stdin,
  crlfDelay: Infinity,
});

input.on('line', async (line) => {
  let message;

  try {
    message = JSON.parse(line);
  } catch (caught) {
    send({ error: `Invalid runner request: ${caught instanceof Error ? caught.message : String(caught)}` });
    return;
  }

  try {
    if (message.type === 'init') {
      initialize(message);
      send({ type: 'ready' });
      return;
    }

    if (message.type === 'call') {
      send({ id: message.id, result: await dispatch(message) });
      return;
    }

    throw new Error(`Unknown runner request type: ${String(message.type)}`);
  } catch (caught) {
    send({
      id: message?.id ?? null,
      error: caught instanceof Error ? caught.message : String(caught),
    });
  }
});
