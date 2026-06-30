import { createHash } from 'node:crypto';
import { cp, mkdir, readFile, readdir, rm, writeFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';

const slug = 'tds-product-importer';
const stage = `dist/${slug}`;
const zip = `dist/${slug}.zip`;
const include = [
  'tds-product-importer.php', 'src', 'assets', 'languages', 'vendor',
  'readme.txt', 'LICENSE',
];

await rm(stage, { recursive: true, force: true });
await rm(zip, { force: true });
await mkdir(stage, { recursive: true });
for (const source of include) {
  await cp(source, `${stage}/${source}`, { recursive: true, force: true });
}
await pruneVendor(`${stage}/vendor`);

const ps = spawnSync(
  'powershell',
  ['-NoProfile', '-Command', `Compress-Archive -Path '${stage}' -DestinationPath '${zip}' -Force`],
  { stdio: 'inherit' },
);
if (ps.status !== 0) process.exit(ps.status ?? 1);
const digest = createHash('sha256').update(await readFile(zip)).digest('hex');
await writeFile(`${zip}.sha256`, `${digest}  ${slug}.zip\n`);
console.log(`${zip}\n${digest}`);

async function pruneVendor(directory) {
  for (const entry of await readdir(directory, { withFileTypes: true })) {
    const path = `${directory}/${entry.name}`;
    if (entry.isDirectory()) {
      if (entry.name === 'bin') {
        await rm(path, { recursive: true, force: true });
      } else {
        await pruneVendor(path);
        if ((await readdir(path)).length === 0) await rm(path, { recursive: true });
      }
      continue;
    }
    const keep = entry.name.endsWith('.php') || entry.name.endsWith('.cnf') || entry.name.startsWith('LICENSE');
    if (!keep) await rm(path);
  }
}
