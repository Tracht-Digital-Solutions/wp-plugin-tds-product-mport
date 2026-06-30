import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const source = await readFile(new URL('../../assets/admin.js', import.meta.url), 'utf8');

test('admin app exposes the required workflows', () => {
	assert.ok(source.includes('/preflight/'));
	assert.ok(source.includes('/rollback'));
	assert.ok(source.includes('/map-preview'));
	assert.match(source, /SFTP/);
});

test('admin app contains no dynamic code execution', () => {
	assert.doesNotMatch(source, /\beval\s*\(/);
	assert.doesNotMatch(source, /new\s+Function\s*\(/);
});
