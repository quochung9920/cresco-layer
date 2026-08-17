import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const roots = [
  path.join(root, 'cresco-layer.php'),
  path.join(root, 'includes'),
  path.join(root, 'tests', 'php'),
];

function collect(target, out) {
  if (!fs.existsSync(target)) return;
  const stat = fs.statSync(target);
  if (stat.isFile()) {
    if (target.endsWith('.php')) out.push(target);
    return;
  }
  for (const entry of fs.readdirSync(target, { withFileTypes: true })) {
    const child = path.join(target, entry.name);
    if (entry.isDirectory()) collect(child, out);
    else if (entry.isFile() && child.endsWith('.php')) out.push(child);
  }
}

const files = [];
for (const target of roots) collect(target, files);
files.sort();

if (!files.length) {
  console.error('No PHP files found to lint.');
  process.exit(1);
}

for (const file of files) {
  const result = spawnSync('php', ['-l', file], { encoding: 'utf8' });
  if (result.error) {
    console.error(`PHP CLI could not run for ${path.relative(root, file)}: ${result.error.message}`);
    process.exit(1);
  }
  if (result.status !== 0) {
    process.stderr.write(result.stderr || result.stdout || `PHP syntax check failed: ${file}\n`);
    process.exit(result.status || 1);
  }
}

console.log(`PHP syntax lint passed for ${files.length} files.`);
