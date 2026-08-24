(function (root, factory) {
	'use strict';
	const api = factory();
	if (typeof module === 'object' && module.exports) module.exports = api;
	root.tdsWizardUtils = api;
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
	'use strict';

	const destructivePolicies = ['draft', 'outofstock', 'trash'];
	const terminalStatuses = ['cancelled', 'completed', 'failed', 'partial', 'rolled_back'];
	const rollbackStatuses = ['cancelled', 'completed', 'failed', 'partial'];
	const validationMessages = {
		de: {
			source: 'Bitte konfigurieren Sie eine erreichbare Quelle.',
			sourceTest: 'Testen Sie die Quelle erfolgreich, bevor Sie fortfahren.',
			structure: 'Die Struktur muss erfolgreich erkannt werden.',
			mapping: 'Produktname und gewählter Identifikator müssen eindeutig zugeordnet sein.',
			suggestions: 'Prüfen und bestätigen oder verwerfen Sie die Mapping-Vorschläge.',
			batchSize: 'Die Batchgröße muss zwischen 10 und 250 liegen.',
			retention: 'Die Rollback-Aufbewahrung muss zwischen 7 und 365 Tagen liegen.',
			email: 'Die E-Mail-Adresse ist ungültig.',
			preflight: 'Der Preflight muss fehlerfrei abgeschlossen sein.',
			destructive: 'Bestätigen Sie die Auswirkung auf fehlende Produkte.',
		},
		en: {
			source: 'Configure a reachable source.',
			sourceTest: 'Test the source successfully before continuing.',
			structure: 'The structure must be detected successfully.',
			mapping: 'Product name and the selected identifier must each have a unique mapping.',
			suggestions: 'Review and apply or dismiss the mapping suggestions.',
			batchSize: 'The batch size must be between 10 and 250.',
			retention: 'Rollback retention must be between 7 and 365 days.',
			email: 'The email address is invalid.',
			preflight: 'Preflight must complete without errors.',
			destructive: 'Confirm how missing products will be changed.',
		},
	};

	function validationMessage(key, locale = 'de') {
		return (validationMessages[locale] || validationMessages.en)[key] || key;
	}

	function previewIsValid(preview) {
		return Boolean(
			preview
			&& Array.isArray(preview.fields)
			&& preview.fields.length
			&& Array.isArray(preview.records)
			&& preview.records.length
		);
	}

	function sourceIsValid(config) {
		const source = config?.source || {};
		if (source.type === 'upload') return Boolean(source.upload_path);
		if (source.type === 'https') return /^https:\/\//i.test(source.url || '');
		if (source.type === 'sftp') {
			return Boolean(source.host && source.username && source.remote_path && source.fingerprint);
		}
		return false;
	}

	function requiredTargets(config) {
		return config?.identity === 'external_id' ? ['name', 'external_id'] : ['name', 'sku'];
	}

	function mappingIsValid(config) {
		const mappings = Array.isArray(config?.mappings) ? config.mappings : [];
		const targets = mappings.filter((row) => row?.target && (row?.source || row?.expression)).map((row) => row.target);
		return requiredTargets(config).every((target) => targets.includes(target))
			&& new Set(targets).size === targets.length;
	}

	function validateStep(step, state, locale = 'de') {
		const config = state?.config || {};
		if (step === 1) {
			const errors = sourceIsValid(config) ? [] : [validationMessage('source', locale)];
			if (!previewIsValid(state?.preview)) errors.push(validationMessage('sourceTest', locale));
			return errors;
		}
		if (step === 2) return previewIsValid(state?.preview) ? [] : [validationMessage('structure', locale)];
		if (step === 3) {
			const errors = mappingIsValid(config) ? [] : [validationMessage('mapping', locale)];
			if (state?.suggestions?.length && !state?.suggestionsReviewed) {
				errors.push(validationMessage('suggestions', locale));
			}
			return errors;
		}
		if (step === 4) {
			const errors = [];
			const batchSize = Number(config.batch_size);
			const retention = Number(config.retention_days);
			if (!Number.isFinite(batchSize) || batchSize < 10 || batchSize > 250) errors.push(validationMessage('batchSize', locale));
			if (!Number.isFinite(retention) || retention < 7 || retention > 365) errors.push(validationMessage('retention', locale));
			if (config.email && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(config.email)) errors.push(validationMessage('email', locale));
			return errors;
		}
		if (step === 5) {
			const errors = state?.preflight?.valid ? [] : [validationMessage('preflight', locale)];
			if (destructivePolicies.includes(config.missing_policy) && !state?.destructiveConfirmed) {
				errors.push(validationMessage('destructive', locale));
			}
			return errors;
		}
		return [];
	}

	function initialWizardStep(jobId, storedStep, requestedStep) {
		if (jobId) return 6;
		const stored = Math.min(5, Math.max(1, Number(storedStep) || 1));
		const requested = Math.min(5, Math.max(1, Number(requestedStep) || stored));
		return Math.min(stored, requested);
	}

	function mappingGroupKey(target = '') {
		const value = String(target).toLowerCase();
		if (/^(acf|meta)[.\-_:]/.test(value)) return 'meta';
		if (/(upsell|cross.?sell|grouped|children|related)/.test(value)) return 'relationships';
		if (/(variation|attribute|parent)/.test(value)) return 'variants';
		if (/(image|gallery|download)/.test(value)) return 'media';
		if (/(categor|tag|shipping_class|tax_class)/.test(value)) return 'taxonomy';
		if (/(stock|inventory|backorder|sold_individually)/.test(value)) return 'stock';
		if (/(price|cost|tax_status)/.test(value)) return 'prices';
		return 'basic';
	}

	function responseStatus(error) {
		const status = Number(error?.data?.status ?? error?.status ?? 0);
		return Number.isFinite(status) ? status : 0;
	}

	function isConflictError(error) {
		return responseStatus(error) === 409;
	}

	function isPermanentHttpError(error) {
		const status = responseStatus(error);
		return status >= 400 && status < 500 && ![408, 409, 425, 429].includes(status);
	}

	function canRollbackJob(status) {
		return rollbackStatuses.includes(String(status || ''));
	}

	function mergeSavedDraft(local, saved) {
		if (!local || !saved) return saved || local;
		const localMappings = Array.isArray(local.config?.mappings) ? local.config.mappings : [];
		const savedMappings = Array.isArray(saved.config?.mappings) ? saved.config.mappings : localMappings;
		const available = localMappings.map((row, index) => ({ row, index }));
		const mappings = savedMappings.map((row, index) => {
			let match = available.findIndex((entry) => entry.row.target === row.target && entry.row.source === row.source);
			if (match < 0) match = available.findIndex((entry) => entry.index === index);
			const localRow = match >= 0 ? available.splice(match, 1)[0].row : null;
			return {
				...row,
				...(localRow?._uiId ? { _uiId: localRow._uiId } : {}),
				_uiGroup: mappingGroupKey(row.target),
				...(localRow?.confidence != null && localRow.target === row.target && localRow.source === row.source
					? { confidence: localRow.confidence }
					: {}),
			};
		});
		return {
			...local,
			...saved,
			config: {
				...(saved.config || local.config || {}),
				mappings,
			},
		};
	}

	function resetAfterChange(state, level) {
		const resetsSource = level === 'source' || level === 'structure';
		const resetsConfirmation = resetsSource || level === 'missing-policy';
		const next = {
			...state,
			preview: resetsSource ? null : state.preview,
			suggestions: resetsSource ? [] : state.suggestions,
			suggestionsReviewed: resetsSource ? false : state.suggestionsReviewed,
			preflight: null,
			destructiveConfirmed: resetsConfirmation ? false : state.destructiveConfirmed,
		};
		if (resetsSource) {
			next.config = { ...state.config, mappings: [] };
			next.wizard_step = level === 'source' ? 1 : 2;
		}
		return next;
	}

	function progress(job) {
		const metric = finiteMetric(job?.metrics?.progress_percent);
		if (metric !== null) return Math.max(0, Math.min(100, Math.round(metric)));
		const total = Math.max(0, Number(job?.total || 0));
		const processed = Math.max(0, Number(job?.processed || 0));
		if (job?.status === 'completed') return 100;
		return total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;
	}

	function finiteMetric(value) {
		const number = Number(value);
		return value === null || value === undefined || value === '' || !Number.isFinite(number) ? null : number;
	}

	function jobMetrics(job) {
		const metrics = job?.metrics || {};
		return {
			elapsedSeconds: finiteMetric(metrics.elapsed_seconds),
			recordsPerMinute: finiteMetric(metrics.records_per_minute),
			etaSeconds: finiteMetric(metrics.eta_seconds),
			progressPercent: progress(job),
			currentPhase: String(metrics.current_phase || job?.phase || ''),
		};
	}

	function formatDuration(seconds) {
		const value = finiteMetric(seconds);
		if (value === null) return '–';
		const rounded = Math.max(0, Math.round(value));
		if (rounded < 60) return `${rounded} s`;
		const minutes = Math.floor(rounded / 60);
		const remainder = rounded % 60;
		if (minutes < 60) return remainder ? `${minutes} min ${remainder} s` : `${minutes} min`;
		const hours = Math.floor(minutes / 60);
		const minuteRemainder = minutes % 60;
		return minuteRemainder ? `${hours} h ${minuteRemainder} min` : `${hours} h`;
	}

	function isTerminalStatus(status) {
		return terminalStatuses.includes(String(status || ''));
	}

	return {
		canRollbackJob, destructivePolicies, formatDuration, initialWizardStep, isConflictError,
		isPermanentHttpError, isTerminalStatus, jobMetrics, mappingGroupKey, mappingIsValid,
		mergeSavedDraft, previewIsValid, progress, requiredTargets, resetAfterChange,
		responseStatus, rollbackStatuses, sourceIsValid, terminalStatuses, validateStep,
		validationMessage,
	};
}));
