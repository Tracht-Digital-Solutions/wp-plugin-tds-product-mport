import { createHash } from 'node:crypto';
import { cp, mkdir, readFile, readdir, rm, writeFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';

const slug = 'tds-product-importer';
const stage = `dist/${slug}`;
const zip = `dist/${slug}.zip`;
const vendorSource = process.env.TDS_PACKAGE_VENDOR || 'vendor';
const include = [
  'tds-product-importer.php', 'src', 'languages',
  'composer.json', 'readme.txt', 'LICENSE',
];

await assertProductionVendor(vendorSource);
await rm(stage, { recursive: true, force: true });
await rm(zip, { force: true });
await rm(`${zip}.sha256`, { force: true });
await mkdir(stage, { recursive: true });
for (const source of include) {
  await cp(source, `${stage}/${source}`, { recursive: true, force: true });
}
await cp(vendorSource, `${stage}/vendor`, { recursive: true, force: true });
await cp('build/assets', `${stage}/assets`, { recursive: true, force: true });
await pruneVendor(`${stage}/vendor`);

const archive = process.platform === 'win32'
  ? spawnSync(
    'powershell',
    [
      '-NoProfile',
      '-Command',
      `Add-Type -AssemblyName System.IO.Compression.FileSystem; [System.IO.Compression.ZipFile]::CreateFromDirectory((Resolve-Path '${slug}').Path, (Join-Path (Get-Location) '${slug}.zip'), [System.IO.Compression.CompressionLevel]::Optimal, $true)`,
    ],
    { cwd: 'dist', stdio: 'inherit' },
  )
  : spawnSync('zip', ['-qr', `${slug}.zip`, slug], { cwd: 'dist', stdio: 'inherit' });
if (archive.error) throw archive.error;
if (archive.status !== 0) process.exit(archive.status ?? 1);
await normalizeZipPaths(zip);
const digest = createHash('sha256').update(await readFile(zip)).digest('hex');
await writeFile(`${zip}.sha256`, `${digest}  ${slug}.zip\n`);
console.log(`${zip}\n${digest}`);

async function assertProductionVendor(directory) {
  const metadata = JSON.parse(await readFile(`${directory}/composer/installed.json`, 'utf8'));
  if (metadata.dev === true || (metadata['dev-package-names'] || []).length > 0) {
    throw new Error('Production package requires `composer install --no-dev --classmap-authoritative`.');
  }
}

async function normalizeZipPaths(path) {
  const data = Buffer.from(await readFile(path));
  const eocd = data.lastIndexOf(Buffer.from([0x50, 0x4b, 0x05, 0x06]));
  if (eocd < 0) throw new Error('ZIP end-of-central-directory record not found.');
  const entries = data.readUInt16LE(eocd + 10);
  let cursor = data.readUInt32LE(eocd + 16);
  for (let index = 0; index < entries; index += 1) {
    if (data.readUInt32LE(cursor) !== 0x02014b50) throw new Error('Invalid ZIP central directory.');
    const nameLength = data.readUInt16LE(cursor + 28);
    const extraLength = data.readUInt16LE(cursor + 30);
    const commentLength = data.readUInt16LE(cursor + 32);
    const localOffset = data.readUInt32LE(cursor + 42);
    normalizeName(data, cursor + 46, nameLength);
    if (data.readUInt32LE(localOffset) !== 0x04034b50) throw new Error('Invalid ZIP local header.');
    normalizeName(data, localOffset + 30, data.readUInt16LE(localOffset + 26));
    cursor += 46 + nameLength + extraLength + commentLength;
  }
  await writeFile(path, data);
}

function normalizeName(data, start, length) {
  for (let index = start; index < start + length; index += 1) {
    if (data[index] === 0x5c) data[index] = 0x2f;
  }
}

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
