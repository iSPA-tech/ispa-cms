#!/usr/bin/env node
/**
 * Dump the admin route and sidebar parity snapshot (platform).
 *
 * Usage:
 *   node scripts/dump-route-parity.mjs            print the snapshot JSON to stdout
 *   node scripts/dump-route-parity.mjs --update   rewrite src/__tests__/config/route-parity.fixture.json
 *
 * The router imports `.vue` files and `import.meta.glob`, so it can only be loaded through Vite. This script
 * therefore runs the parity spec under vitest with `ROUTE_PARITY_OUT` pointing at the output file; only in that mode
 * does the spec write the snapshot instead of asserting.
 */
import { spawnSync } from 'node:child_process'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const spec = 'src/__tests__/config/route-parity.spec.ts'
const fixture = path.join(root, 'src/__tests__/config/route-parity.fixture.json')
const update = process.argv.includes('--update')

const tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'route-parity-'))
const target = update ? path.join(tmpDir, 'fixture.json') : path.join(tmpDir, 'dump.json')

const result = spawnSync('npx', ['vitest', 'run', spec], {
    cwd: root,
    env: { ...process.env, ROUTE_PARITY_OUT: target },
    stdio: ['ignore', 'ignore', 'inherit'],
})

if (result.status !== 0 || !fs.existsSync(target)) {
    fs.rmSync(tmpDir, { recursive: true, force: true })
    console.error('Route parity dump failed.')
    process.exit(result.status || 1)
}

const json = fs.readFileSync(target, 'utf8')
fs.rmSync(tmpDir, { recursive: true, force: true })

if (update) {
    fs.writeFileSync(fixture, json)
    console.error(`Wrote ${path.relative(root, fixture)}`)
} else {
    process.stdout.write(json)
}
