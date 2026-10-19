#!/usr/bin/env node
/**
 * Checks the built shell against the parts of the Content-Security-Policy that
 * the *build* can break (architecture 5.7, U-17, U-35).
 *
 * `script-src 'self'` is the half of the policy that carries the residual risk
 * accepted in E-15 — the access token in `localStorage` is reachable only
 * through script execution. A bundler that emits one inline `<script>` makes
 * that half unenforceable, and the failure is silent: the page still works,
 * because a developer preview has no CSP. So the build fails here instead.
 *
 * Exit code 1 on any finding. Run automatically as `npm run postbuild`.
 */
import { readFile, readdir } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';

const publicDir = fileURLToPath(new URL('../../public/', import.meta.url));
const shell = `${publicDir}index.html`;

const findings = [];

const rawHtml = await readFile(shell, 'utf8');

// Comments are stripped first. The shell documents *why* it carries no inline
// script, and a checker that reads its own explanation as a violation is the
// kind of thing nobody debugs twice.
const html = rawHtml.replace(/<!--[\s\S]*?-->/g, '');

// --- inline scripts ---------------------------------------------------------
// `<script src=…>` is fine; `<script>…</script>` with a body is not. The
// pattern deliberately ignores whitespace-only bodies, which no bundler emits
// but a formatter might introduce.
const scriptTag = /<script\b([^>]*)>([\s\S]*?)<\/script>/gi;

for (const match of html.matchAll(scriptTag)) {
  const [, attributes, body] = match;

  if (body.trim() !== '') {
    findings.push(
      `inline <script> with a body (${body.trim().length} characters) — `
        + `script-src 'self' would block it. Attributes: ${attributes.trim() || '(none)'}`,
    );
  }
}

// --- inline event handlers --------------------------------------------------
for (const match of html.matchAll(/\son[a-z]+\s*=\s*["']/gi)) {
  findings.push(`inline event handler attribute near "${match[0].trim()}" — also blocked by script-src.`);
}

// --- javascript: URLs -------------------------------------------------------
if (/\b(?:href|src)\s*=\s*["']\s*javascript:/i.test(html)) {
  findings.push("a javascript: URL — blocked by script-src 'self'.");
}

// --- external origins -------------------------------------------------------
// `default-src 'self'` means the shell may not reference any other host. The
// most likely offender is the Google Fonts CDN, which is MUI's documented way
// of loading Roboto and is ruled out by U-35 point 3.
for (const match of html.matchAll(/\b(?:href|src)\s*=\s*["'](https?:)?\/\/([^"'/]+)/gi)) {
  findings.push(`reference to the external origin "${match[2]}" — no foreign content in the frontend (U-35).`);
}

// --- the bundle must not reach out either -----------------------------------
const assetsDir = `${publicDir}assets`;
const fontProviders = /fonts\.(?:googleapis|gstatic)\.com/i;

let assets = [];

try {
  assets = await readdir(assetsDir);
} catch {
  findings.push('public/assets/ does not exist — the build produced no bundle.');
}

for (const name of assets) {
  if (!name.endsWith('.js') && !name.endsWith('.css')) {
    continue;
  }

  const contents = await readFile(`${assetsDir}/${name}`, 'utf8');

  if (fontProviders.test(contents)) {
    findings.push(`assets/${name} references a Google Fonts host — fonts are bundled locally (U-35).`);
  }

  // `default-src 'self'` carries no `data:`, so `font-src` falls back to
  // `'self'` and an inlined font is simply refused. The page still renders
  // through the fallback stack, which is precisely why this needs a check
  // rather than an eye.
  const inlinedFonts = contents.match(/url\(\s*["']?data:(?:font|application\/font)/gi);

  if (inlinedFonts !== null) {
    findings.push(
      `assets/${name} inlines ${String(inlinedFonts.length)} font(s) as a data: URI — `
        + "blocked by default-src 'self'. Set build.assetsInlineLimit to 0.",
    );
  }
}

// --- content hashes ---------------------------------------------------------
// `Cache-Control: immutable` in public/.htaccess is only safe if every asset
// name changes when its content does (architecture 11.3).
// Vite's default: a dash, exactly eight characters of base64url hash, then the
// extension. `.map` counts as a suffix on top. Exactly eight rather than "at
// least eight" so that a hashless `roboto-latin-400-normal.woff2` cannot pass
// by having a long enough word in front of the extension.
const hashed = /-[0-9A-Za-z_-]{8}\.[0-9a-z]+(?:\.map)?$/;

for (const name of assets) {
  if (!hashed.test(name)) {
    findings.push(`assets/${name} carries no content hash, but is served as immutable (architecture 11.3).`);
  }
}

if (findings.length > 0) {
  console.error('\ncheck-build: the built shell violates the Content-Security-Policy contract.\n');

  for (const finding of findings) {
    console.error(`  - ${finding}`);
  }

  console.error('\nFix the bundler, not the policy (U-35 point 4).\n');

  process.exit(1);
}

console.log(`check-build: shell and ${assets.length} asset(s) clean — no inline script, no external origin.`);
