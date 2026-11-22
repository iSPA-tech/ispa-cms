#!/usr/bin/env node
const { spawnSync } = require('node:child_process');

const phpBinary = process.env.PHP_BIN || 'php';
const result = spawnSync(phpBinary, ['artisan', ...process.argv.slice(2)], {
  stdio: 'inherit',
  env: process.env,
});

if (result.error) {
  console.error(result.error.message);
  process.exit(1);
}

process.exit(result.status ?? 1);
