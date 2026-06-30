import { copyFile, mkdir } from 'node:fs/promises';

await mkdir('build/assets', { recursive: true });
await Promise.all([
  copyFile('assets/admin.js', 'build/assets/admin.js'),
  copyFile('assets/admin.css', 'build/assets/admin.css'),
]);
console.log('Admin assets built.');

