import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, statSync } from 'node:fs';
import { basename, join } from 'node:path';
import { spawnSync } from 'node:child_process';

// Run after importing legacy images or adding homepage content:
// node scripts/build-responsive-images.mjs
const root = process.cwd();
const response = await fetch('http://127.0.0.1:8088/');
if (!response.ok) throw new Error(`Homepage returned ${response.status}`);
const html = await response.text();
const paths = [...new Set([...html.matchAll(/(?:src|data-src)="(\/uploads\/[^"?]+)"/g)].map((match) => match[1]))];
const outputDir = join(root, 'uploads', 'responsive');
mkdirSync(outputDir, { recursive: true });
let created = 0;

for (const path of paths) {
  const source = join(root, path.slice(1));
  if (!existsSync(source) || statSync(source).size < 40_000) continue;
  const key = createHash('sha1').update(basename(path)).digest('hex').slice(0, 12);
  for (const width of [480, 960]) {
    const target = join(outputDir, `${key}-${width}.webp`);
    if (existsSync(target)) continue;
    const result = spawnSync('cwebp', ['-quiet', '-q', '78', '-m', '5', '-resize', String(width), '0', source, '-o', target], { stdio: 'pipe' });
    if (result.status !== 0) {
      throw new Error(`Could not resize ${path}: ${result.stderr.toString()}`);
    }
    created += 1;
  }
}

console.log(`Created ${created} responsive WebP images from ${paths.length} homepage sources.`);
