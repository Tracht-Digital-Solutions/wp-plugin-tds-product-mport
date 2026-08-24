import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const source = await readFile(new URL('../../assets/admin.js', import.meta.url), 'utf8');

test('admin app exposes the required workflows', () => {
	assert.ok(source.includes('/preflight/'));
	assert.ok(source.includes('/rollback'));
	assert.ok(source.includes('/map-preview'));
	assert.ok(source.includes('/wizard/drafts'));
	assert.ok(source.includes('/mapping-suggestions'));
	assert.ok(source.includes('/source-preview'));
	assert.match(source, /SFTP/);
});

test('admin app contains no dynamic code execution', () => {
	assert.doesNotMatch(source, /\beval\s*\(/);
	assert.doesNotMatch(source, /new\s+Function\s*\(/);
});

test('wizard uses explicit suggestion review and accessible live progress', () => {
	assert.match(source, /reviewSuggestions/);
	assert.match(source, /Vorschläge übernehmen/);
	assert.match(source, /Ohne Vorschläge fortfahren/);
	assert.match(source, /aria-valuetext/);
	assert.match(source, /recent_warnings/);
	assert.match(source, /jobMetrics/);
	assert.match(source, /_uiId/);
	assert.match(source, /'missing-policy'/);
	assert.match(source, /tds-mobile-steps/);
	assert.match(source, /errorSummary/);
	assert.match(source, /scope: 'col'/);
	assert.match(source, /confirm_missing_policy/);
	assert.match(source, /utils\.canRollbackJob/);
});

test('autosave and polling avoid unsafe navigation races', () => {
	assert.match(source, /beforeunload/);
	assert.match(source, /visibilitychange/);
	assert.match(source, /wizardFlush/);
	assert.match(source, /await saveNow/);
	assert.match(source, /polling\.current/);
	assert.match(source, /utils\.isPermanentHttpError/);
	assert.match(source, /Reload server version/);
	assert.match(source, /utils\.initialWizardStep/);
	assert.doesNotMatch(source, /setInterval\s*\(/);
});

test('manual mapping changes clear confidence and regroup after focus leaves the field', () => {
	assert.match(source, /confidence: manualFieldChange \? null : row\.confidence/);
	assert.match(source, /onBlur: \(event\) => change\(row, \{ _uiGroup: mappingGroupKey\(event\.target\.value\) \}\)/);
});
