#!/usr/bin/env node
/**
 * capture.mjs — regenerates the screenshots embedded in README.md.
 *
 * It drives the Chrome/Chromium already installed on the machine over the
 * DevTools Protocol (`--headless=new`), so the project keeps no browser
 * automation dependency. Every image is a real capture of Fiscal Hub: the app
 * screens come from an instance running `AUTH_METHOD=none`, the login screens
 * from instances running `account` and `keycloak` mode with demo values.
 *
 * Usage:
 *   docs/screenshots/generate.sh             # build image, boot containers, capture
 *   node docs/screenshots/capture.mjs        # capture from already running servers
 *
 * Environment:
 *   SCREENSHOT_BASE_URL      none-mode instance      (default http://127.0.0.1:8080)
 *   SCREENSHOT_ACCOUNT_URL   account-mode instance   (default http://127.0.0.1:8081)
 *   SCREENSHOT_KEYCLOAK_URL  keycloak-mode instance  (default http://127.0.0.1:8082)
 *   SCREENSHOT_DIR           output directory        (default this directory)
 *   SCREENSHOT_DPR           device pixel ratio      (default 2)
 *   SCREENSHOT_MAX_HEIGHT    tallest full-page clip  (default 2400)
 *   CHROME_BIN               browser to drive (default: google-chrome, then chromium)
 */
import { spawn } from 'node:child_process';
import { existsSync } from 'node:fs';
import { mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import { join, resolve } from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';

const BASE_URL = (process.env.SCREENSHOT_BASE_URL ?? 'http://127.0.0.1:8080').replace(/\/+$/, '');
const ACCOUNT_URL = (process.env.SCREENSHOT_ACCOUNT_URL ?? 'http://127.0.0.1:8081').replace(/\/+$/, '');
const KEYCLOAK_URL = (process.env.SCREENSHOT_KEYCLOAK_URL ?? 'http://127.0.0.1:8082').replace(/\/+$/, '');
const OUT_DIR = resolve(process.env.SCREENSHOT_DIR ?? import.meta.dirname);
const DPR = Number(process.env.SCREENSHOT_DPR ?? 2);
const MAX_HEIGHT = Number(process.env.SCREENSHOT_MAX_HEIGHT ?? 2400);
const VIEWPORT = { width: 1440, height: 900 };
const MOBILE = { width: 390, height: 844 };

const ORIGINS = { app: BASE_URL, account: ACCOUNT_URL, keycloak: KEYCLOAK_URL };

// 44 positions in the official NFe layout (UF, AAMM, CNPJ, model, series,
// number, emission type, code, check digit). Illustrative value only.
const SAMPLE_KEY = '35240512345678000190550010000012341000012345';
// A public company record (Petrobras) so the CNPJ tab shows a real BrasilAPI
// answer instead of an empty form. No private data is involved.
const SAMPLE_CNPJ = '33000167000101';

/**
 * STEPS is the list of screens, in the order the README presents them.
 * `base` picks the origin, `tab` the visible tab, `login` the auth gate to
 * wait for; `viewport` limits a capture to the visible viewport and `mobile`
 * switches to a phone-sized, 2x screen.
 */
const STEPS = [
  { file: '01-upload-xml-light', base: 'app', path: '/', title: 'Upload de XML (light)', tab: 'upload' },
  { file: '02-consulta-chave', base: 'app', path: '/', title: 'Consulta por chave', tab: 'consulta', key: SAMPLE_KEY },
  { file: '03-consulta-nfe', base: 'app', path: '/', title: 'Consulta NF-e', tab: 'nfe' },
  { file: '04-consulta-cnpj', base: 'app', path: '/', title: 'Consulta CNPJ', tab: 'cnpja', cnpj: SAMPLE_CNPJ, viewport: true },
  { file: '05-upload-xml-dark', base: 'app', path: '/', title: 'Upload de XML (dark)', tab: 'upload', dark: true },
  { file: '06-login-account', base: 'account', path: '/login.html', title: 'Login (account)', login: 'account' },
  { file: '07-login-keycloak', base: 'keycloak', path: '/login.html?sso=1', title: 'Login (Keycloak)', login: 'keycloak' },
  { file: '08-mobile', base: 'app', path: '/', title: 'Mobile layout', tab: 'upload', mobile: true },
];

/** chromeBinary returns the first browser the host provides. */
function chromeBinary() {
  if (process.env.CHROME_BIN) {
    return process.env.CHROME_BIN;
  }
  for (const name of ['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser']) {
    for (const dir of ['/usr/bin/', '/usr/local/bin/', '/opt/google/chrome/']) {
      if (existsSync(dir + name)) {
        return dir + name;
      }
    }
  }
  throw new Error('no Chrome/Chromium found: set CHROME_BIN to the browser to drive');
}

// ---------------------------------------------------------------------------
// DevTools Protocol plumbing
// ---------------------------------------------------------------------------

/**
 * connect opens the browser level socket and returns a small CDP client. A new
 * tab is driven through a flat session, which keeps every request and event of
 * the capture routable to that one tab.
 */
async function connect(browserURL) {
  const socket = new WebSocket(browserURL);
  await new Promise((resolve_, reject) => {
    socket.addEventListener('open', resolve_, { once: true });
    socket.addEventListener('error', () => reject(new Error('the DevTools socket failed')), {
      once: true,
    });
  });

  const client = { socket, nextId: 1, pending: new Map(), listeners: [] };
  client.send = (method, params = {}, sessionId) => {
    const id = client.nextId++;
    const payload = { id, method, params };
    if (sessionId) {
      payload.sessionId = sessionId;
    }
    return new Promise((resolve_, reject) => {
      client.pending.set(id, { resolve: resolve_, reject });
      socket.send(JSON.stringify(payload));
    });
  };
  socket.addEventListener('message', (event) => {
    const message = JSON.parse(event.data);
    if (message.id && client.pending.has(message.id)) {
      const { resolve: resolve_, reject } = client.pending.get(message.id);
      client.pending.delete(message.id);
      if (message.error) {
        reject(new Error(`${message.error.message} (${message.error.code})`));
      } else {
        resolve_(message.result ?? {});
      }
      return;
    }
    for (const listener of client.listeners) {
      listener(message);
    }
  });
  return client;
}

/** waitForPort polls the DevTools port file Chrome writes on startup. */
async function waitForPort(profileDir, timeoutMs = 20000) {
  const file = join(profileDir, 'DevToolsActivePort');
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    try {
      const [port] = (await readFile(file, 'utf8')).split('\n');
      if (port) {
        return `http://127.0.0.1:${Number(port.trim())}`;
      }
    } catch {
      /* Chrome has not written the file yet. */
    }
    await sleep(150);
  }
  throw new Error('Chrome did not open its DevTools port');
}

/** browserWebSocket reads the browser level WebSocket URL from the HTTP endpoint. */
async function browserWebSocket(host, timeoutMs = 20000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    try {
      const response = await fetch(`${host}/json/version`);
      const version = await response.json();
      if (version.webSocketDebuggerUrl) {
        return version.webSocketDebuggerUrl;
      }
    } catch {
      /* the HTTP endpoint is not up yet. */
    }
    await sleep(150);
  }
  throw new Error('the DevTools HTTP endpoint never answered');
}

/** evaluate runs an expression in the page and returns its value. */
async function evaluate(session, expression, awaitPromise = false) {
  const answer = await session.send('Runtime.evaluate', {
    expression,
    returnByValue: true,
    awaitPromise,
  });
  if (answer.exceptionDetails) {
    throw new Error(answer.exceptionDetails.text ?? 'the page threw while evaluating');
  }
  return answer.result?.value;
}

/** waitUntil polls an expression until it returns true. */
async function waitUntil(session, expression, timeoutMs = 15000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    if (await evaluate(session, expression)) {
      return true;
    }
    await sleep(120);
  }
  return false;
}

/** setViewport applies a CSS viewport and its device pixel ratio. */
async function setViewport(session, size, mobile) {
  await session.send('Emulation.setDeviceMetricsOverride', {
    width: size.width,
    height: size.height,
    deviceScaleFactor: DPR,
    mobile,
  });
}

/**
 * navigate loads a page and waits for the document plus the web fonts. The
 * remaining CDN assets (Font Awesome, flag icons) are given a short grace
 * period; a missing icon must never fail the capture.
 */
async function navigate(session, url) {
  await session.send('Page.navigate', { url });
  await waitUntil(session, `document.readyState === 'complete'`, 20000);
  await evaluate(session, 'document.fonts.ready.then(() => true)', true);
  await sleep(900);
}

/** settle waits until the visible images of the page finished loading. */
async function settle(session, timeoutMs = 6000) {
  await waitUntil(
    session,
    `[...document.images]
      .filter((image) => image.offsetParent !== null)
      .every((image) => image.complete)`,
    timeoutMs,
  );
  await sleep(400);
}

/**
 * APP_READY tells whether the SPA finished rendering: the shell is mounted, the
 * tab bar exists and no blocking overlay is on screen.
 */
const APP_READY = `(() => {
  if (document.readyState !== 'complete') return false;
  if (!document.querySelector('#tab-btn-upload')) return false;
  if (document.querySelector('.loading-overlay--visible')) return false;
  return true;
})()`;

/**
 * LOGIN_READY tells whether login.html resolved its auth method: the page fetches
 * auth-config.php, then reveals either the credential form or the SSO gate.
 * `sso` selects the gate that must be visible.
 */
function loginReady(sso) {
  return `(() => {
    if (document.readyState !== 'complete') return false;
    const form = document.querySelector('#login-form');
    const gate = document.querySelector('#login-sso');
    if (!form || !gate) return false;
    if (![...document.images].every((image) => image.complete)) return false;
    return ${sso ? '!gate.hidden && form.hidden' : '!form.hidden && gate.hidden'};
  })()`;
}

// ---------------------------------------------------------------------------
// Entry point
// ---------------------------------------------------------------------------

/** fill sets an input value the way a user would, mask handlers included. */
async function fill(session, selector, value) {
  const filled = await evaluate(
    session,
    `(() => {
      const input = document.querySelector(${JSON.stringify(selector)});
      if (!input) return false;
      const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set;
      setter.call(input, ${JSON.stringify(value)});
      input.dispatchEvent(new Event('input', { bubbles: true }));
      return true;
    })()`,
  );
  if (!filled) {
    throw new Error(`no field ${selector}`);
  }
}

async function main() {
  await mkdir(OUT_DIR, { recursive: true });
  const profile = join(process.env.TMPDIR ?? '/tmp', `fiscalhub-screenshots-${process.pid}`);
  await rm(profile, { recursive: true, force: true });

  console.log(`capturing ${STEPS.length} screens into ${OUT_DIR}`);
  const chrome = spawn(
    chromeBinary(),
    [
      '--headless=new',
      '--disable-gpu',
      '--hide-scrollbars',
      '--no-first-run',
      '--no-default-browser-check',
      '--disable-extensions',
      `--user-data-dir=${profile}`,
      '--remote-debugging-port=0',
      'about:blank',
    ],
    // The browser runs in UTC so the dates it renders match the sample payloads.
    { stdio: ['ignore', 'ignore', 'pipe'], env: { ...process.env, TZ: 'UTC' } },
  );
  // Chrome is chatty on stderr (DBus, GPU probes); only the exit code matters.
  chrome.stderr.on('data', () => {});

  const host = await waitForPort(profile);
  const client = await connect(await browserWebSocket(host));
  const written = [];

  try {
    const { targetId } = await client.send('Target.createTarget', { url: 'about:blank' });
    const { sessionId } = await client.send('Target.attachToTarget', { targetId, flatten: true });
    const session = { send: (method, params = {}) => client.send(method, params, sessionId) };

    await session.send('Page.enable');
    await session.send('Runtime.enable');
    await session.send('Network.enable');
    // Every capture is light except the dedicated dark-theme one.
    await session.send('Emulation.setEmulatedMedia', {
      features: [{ name: 'prefers-color-scheme', value: 'light' }],
    });

    for (const [index, step] of STEPS.entries()) {
      const size = step.mobile ? MOBILE : VIEWPORT;
      await setViewport(session, size, Boolean(step.mobile));
      await navigate(session, ORIGINS[step.base] + step.path);
      const label = `${String(index + 1).padStart(2, '0')}/${STEPS.length} ${step.file}`;

      if (step.login) {
        const resolved = await waitUntil(session, loginReady(step.login === 'keycloak'), 20000);
        if (!resolved) {
          throw new Error(`${step.file}: login.html never revealed its ${step.login} gate`);
        }
      } else {
        const rendered = await waitUntil(session, APP_READY, 20000);
        if (!rendered) {
          throw new Error(`${step.file}: the application never rendered`);
        }
        if (step.tab && step.tab !== 'upload') {
          await evaluate(session, `document.querySelector('#tab-btn-${step.tab}').click()`);
          await sleep(350);
        }
        // A previous capture may have left the dark theme in localStorage.
        const dark = await evaluate(session, `document.body.classList.contains('theme-dark')`);
        if (dark !== Boolean(step.dark)) {
          await evaluate(session, `document.querySelector('#btn-theme-toggle').click()`);
          await sleep(300);
        }
        if (step.key) {
          await fill(session, '#input-chave', step.key);
          await sleep(250);
        }
        if (step.cnpj) {
          await fill(session, '#input-cnpj', step.cnpj);
          await sleep(250);
          const clicked = await evaluate(
            session,
            `(() => {
              const button = document.querySelector('#btn-consultar-cnpj');
              if (!button || button.disabled) return false;
              button.click();
              return true;
            })()`,
          );
          if (!clicked) {
            throw new Error(`${step.file}: the CNPJ button did not enable after filling the field`);
          }
          const answered = await waitUntil(
            session,
            `(() => {
              if (document.querySelector('.loading-overlay--visible')) return false;
              const result = document.querySelector('#cnpj-result');
              const error = document.querySelector('#cnpj-error');
              if (result && result.children.length > 0) return true;
              return Boolean(error && error.classList.contains('cnpj-error--visible'));
            })()`,
            30000,
          );
          if (!answered) {
            throw new Error(`${step.file}: BrasilAPI never answered`);
          }
          await sleep(400);
        }
      }

      await settle(session);
      const metrics = await session.send('Page.getLayoutMetrics');
      const content = metrics.cssContentSize ?? metrics.contentSize;
      const height = step.viewport
        ? size.height
        : Math.min(Math.ceil(content.height), MAX_HEIGHT);
      const shot = await session.send('Page.captureScreenshot', {
        format: 'png',
        captureBeyondViewport: true,
        clip: { x: 0, y: 0, width: size.width, height, scale: 1 },
      });
      const bytes = Buffer.from(shot.data, 'base64');
      await writeFile(join(OUT_DIR, `${step.file}.png`), bytes);
      written.push(bytes.length);
      console.log(
        `  ${label.padEnd(26)} ${step.title.padEnd(24)} ` +
          `${String(Math.round(bytes.length / 1024)).padStart(5)} KiB  ` +
          `${size.width}x${height}@${DPR}x`,
      );
    }

    const total = written.reduce((sum, size) => sum + size, 0);
    console.log(
      `wrote ${written.length} screenshots, ${(total / 1024 / 1024).toFixed(2)} MiB in total`,
    );
  } finally {
    client.socket.close();
    chrome.kill('SIGTERM');
    // Chrome takes a moment to flush its profile: give it one, then drop it.
    await sleep(500);
    await rm(profile, { recursive: true, force: true, maxRetries: 5 }).catch(() => {});
  }
}

main().catch((error) => {
  console.error(`screenshot capture failed: ${error.message}`);
  process.exitCode = 1;
});

