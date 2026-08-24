import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import test from 'node:test';

const require = createRequire(import.meta.url);
const utils = require('../../assets/admin-utils.js');

const config = () => ({
	source: { type: 'upload', upload_path: '/protected/products.csv' },
	identity: 'sku',
	mappings: [
		{ target: 'name', source: 'Produktname' },
		{ target: 'sku', source: 'Artikelnummer' },
	],
	missing_policy: 'keep',
	batch_size: 50,
	retention_days: 30,
	email: '',
});

test('validates wizard steps and destructive confirmation', () => {
	const state = { config: config(), preview: { records: [{ sku: 'A-1' }] }, preflight: { valid: true } };
	state.preview.fields = ['sku'];
	assert.deepEqual(utils.validateStep(1, state), []);
	assert.deepEqual(utils.validateStep(2, state), []);
	assert.deepEqual(utils.validateStep(3, state), []);
	assert.deepEqual(utils.validateStep(4, state), []);
	state.config.missing_policy = 'trash';
	assert.equal(utils.validateStep(5, state).length, 1);
	assert.deepEqual(utils.validateStep(5, { ...state, destructiveConfirmed: true }), []);
});

test('requires a successful source preview and explicit suggestion review', () => {
	const state = { config: config(), preview: null, suggestions: [], suggestionsReviewed: true };
	assert.equal(utils.validateStep(1, state).length, 1);
	state.preview = { fields: ['Produktname', 'Artikelnummer'], records: [{ Artikelnummer: 'A-1' }] };
	assert.deepEqual(utils.validateStep(1, state), []);
	state.suggestions = [{ source: 'Produktname', target: 'name' }];
	state.suggestionsReviewed = false;
	assert.match(utils.validateStep(3, state).join(' '), /Mapping-Vorschläge/);
	state.suggestionsReviewed = true;
	assert.deepEqual(utils.validateStep(3, state), []);
});

test('source changes reset dependent state', () => {
	const state = {
		config: config(), preview: { records: [{}] }, suggestions: [{}],
		suggestionsReviewed: true, preflight: { valid: true }, destructiveConfirmed: true, wizard_step: 5,
	};
	const reset = utils.resetAfterChange(state, 'source');
	assert.equal(reset.wizard_step, 1);
	assert.equal(reset.preview, null);
	assert.deepEqual(reset.config.mappings, []);
	assert.equal(reset.preflight, null);
	assert.equal(reset.suggestionsReviewed, false);
});

test('mapping and rule changes invalidate preflight without resetting unrelated approval', () => {
	const state = {
		config: config(), preview: { fields: ['sku'], records: [{}] }, suggestions: [],
		suggestionsReviewed: true, preflight: { valid: true }, destructiveConfirmed: true, wizard_step: 5,
	};
	const mappingReset = utils.resetAfterChange(state, 'mapping');
	assert.equal(mappingReset.preflight, null);
	assert.equal(mappingReset.destructiveConfirmed, true);
	assert.equal(mappingReset.preview, state.preview);
	const missingPolicyReset = utils.resetAfterChange(state, 'missing-policy');
	assert.equal(missingPolicyReset.preflight, null);
	assert.equal(missingPolicyReset.destructiveConfirmed, false);
});

test('localizes validation and validates rollback retention', () => {
	const state = { config: config(), preview: null };
	assert.match(utils.validateStep(1, state, 'en').join(' '), /Test the source successfully/);
	state.config.retention_days = 0;
	assert.match(utils.validateStep(4, state, 'en').join(' '), /between 7 and 365 days/);
	state.config.retention_days = 365;
	assert.deepEqual(utils.validateStep(4, state, 'en'), []);
});

test('clamps deep links to the highest saved wizard step', () => {
	assert.equal(utils.initialWizardStep(null, 1, 5), 1);
	assert.equal(utils.initialWizardStep(null, 4, 3), 3);
	assert.equal(utils.initialWizardStep(null, 4, 5), 4);
	assert.equal(utils.initialWizardStep(27, 1, 1), 6);
});

test('classifies mappings and rollback states without stale confidence assumptions', () => {
	assert.equal(utils.mappingGroupKey('regular_price'), 'prices');
	assert.equal(utils.mappingGroupKey('gallery_images'), 'media');
	assert.equal(utils.mappingGroupKey('acf.color'), 'meta');
	assert.equal(utils.canRollbackJob('cancelled'), true);
	assert.equal(utils.canRollbackJob('running'), false);
});

test('classifies conflict and permanent polling errors', () => {
	assert.equal(utils.isConflictError({ data: { status: 409 } }), true);
	assert.equal(utils.isPermanentHttpError({ data: { status: 404 } }), true);
	assert.equal(utils.isPermanentHttpError({ data: { status: 429 } }), false);
	assert.equal(utils.isPermanentHttpError(new Error('offline')), false);
});

test('merges normalized draft fields while preserving transient mapping UI state', () => {
	const mappings = [{ target: 'name', source: 'name', empty: 'invalid', _uiId: 'stable-row', confidence: .98 }];
	const merged = utils.mergeSavedDraft(
		{ id: 1, name: ' Draft ', config: { retention_days: 0, mappings } },
		{ id: 1, revision: 2, name: 'Draft', config: { retention_days: 7, mappings: [{ target: 'name', source: 'name', empty: 'keep' }] } },
	);
	assert.equal(merged.name, 'Draft');
	assert.equal(merged.config.retention_days, 7);
	assert.equal(merged.config.mappings[0].empty, 'keep');
	assert.equal(merged.config.mappings[0]._uiId, 'stable-row');
	assert.equal(merged.config.mappings[0].confidence, .98);
});

test('progress is bounded and completed jobs are final', () => {
	assert.equal(utils.progress({ total: 200, processed: 50 }), 25);
	assert.equal(utils.progress({ total: 10, processed: 12 }), 100);
	assert.equal(utils.progress({ status: 'completed' }), 100);
	assert.equal(utils.progress({ total: 200, processed: 50, metrics: { progress_percent: 42.4 } }), 42);
});

test('normalizes live metrics, ETA formatting, and terminal job states', () => {
	assert.deepEqual(utils.jobMetrics({
		phase: 'parse', total: 100, processed: 25,
		metrics: { elapsed_seconds: 90, records_per_minute: 12.5, eta_seconds: 360, current_phase: 'import' },
	}), {
		elapsedSeconds: 90,
		recordsPerMinute: 12.5,
		etaSeconds: 360,
		progressPercent: 25,
		currentPhase: 'import',
	});
	assert.equal(utils.formatDuration(360), '6 min');
	assert.equal(utils.formatDuration(null), '–');
	assert.equal(utils.isTerminalStatus('partial'), true);
	assert.equal(utils.isTerminalStatus('running'), false);
});
