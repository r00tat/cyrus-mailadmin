#!/usr/bin/env node
/**
 * Removes what a previous frontend build put into `public/`, and only that.
 *
 * `public/` is not a build directory — it holds the front controller and the
 * .htaccess that grants access to it (U-25, layer 3). Vite's `emptyOutDir` is
 * therefore off, which would otherwise leave every earlier content-hashed
 * bundle behind until the directory is full of files nothing references.
 */
import { readdir, rm, stat } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';

const publicDir = fileURLToPath(new URL('../../public/', import.meta.url));

/** Everything the build writes at the top level of `public/`. */
const GENERATED_FILES = ['index.html'];

/** Directories the build owns completely. */
const GENERATED_DIRECTORIES = ['assets'];

async function exists(path) {
  try {
    await stat(path);

    return true;
  } catch {
    return false;
  }
}

let removed = 0;

for (const name of GENERATED_DIRECTORIES) {
  const path = `${publicDir}${name}`;

  if (await exists(path)) {
    removed += (await readdir(path)).length;
    await rm(path, { recursive: true, force: true });
  }
}

for (const name of GENERATED_FILES) {
  const path = `${publicDir}${name}`;

  if (await exists(path)) {
    await rm(path, { force: true });
    removed += 1;
  }
}

console.log(`clean-output: removed ${removed} generated file(s) from public/.`);
