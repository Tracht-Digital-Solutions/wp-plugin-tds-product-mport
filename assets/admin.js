(function (wp) {
	'use strict';

	const h = wp.element.createElement;
	const { useEffect, useMemo, useRef, useState } = wp.element;
	const {
		Button, Card, CardBody, CheckboxControl, Notice, SelectControl, Spinner,
		TextControl, TextareaControl, ToggleControl,
	} = wp.components;
	const api = wp.apiFetch;
	const utils = window.tdsWizardUtils;
	const isGerman = (document.documentElement.lang || '').toLowerCase().startsWith('de');
	const t = (de, en) => isGerman ? de : en;
	const locale = isGerman ? 'de' : 'en';
	api.use(api.createNonceMiddleware(window.tdsImporter.nonce));

	const request = (path, options = {}) => api({ path: '/tds-import/v1' + path, ...options });
	const clone = (value) => JSON.parse(JSON.stringify(value));
	const mappingGroupKey = utils.mappingGroupKey;
	let mappingRowSequence = 0;
	const mappingRowId = () => `tds-map-${++mappingRowSequence}`;
	const input = (label, value, onChange, type = 'text', props = {}) =>
		h(TextControl, { label, value: value ?? '', type, onChange, ...props });
	const mappingRow = (source = '', target = '', confidence = null) => ({
		target, source, expression: '', ast: null, empty: 'keep', default: '', confidence,
		_uiId: mappingRowId(), _uiGroup: mappingGroupKey(target),
	});
	const hydrateMappings = (mappings = []) => mappings.map((row) => ({
		...row, _uiId: row._uiId || mappingRowId(), _uiGroup: row._uiGroup || mappingGroupKey(row.target),
	}));
	const hydrateModel = (value) => {
		const model = clone(value);
		if (model?.config) model.config.mappings = hydrateMappings(model.config.mappings || []);
		return model;
	};
	const mappingGroupLabels = () => ({
		basic: t('Basisdaten', 'Basic data'),
		prices: t('Preise', 'Prices'),
		stock: t('Bestand', 'Inventory'),
		taxonomy: t('Taxonomien', 'Taxonomies'),
		media: t('Medien', 'Media'),
		variants: t('Varianten', 'Variations'),
		relationships: t('Beziehungen', 'Relationships'),
		meta: t('Meta / ACF', 'Meta / ACF'),
	});
	const sourceName = (path) => String(path || '').split(/[\\/]/).pop();
	const formatBytes = (bytes) => {
		const value = Number(bytes || 0);
		if (value < 1024) return `${value} B`;
		if (value < 1048576) return `${(value / 1024).toFixed(1)} KB`;
		return `${(value / 1048576).toFixed(1)} MB`;
	};
	const emptyConfig = () => ({
		source: {
			type: 'upload', upload_path: '', url: '', host: '', port: 22,
			username: '', password: '', private_key: '', remote_path: '', fingerprint: '',
			basic_username: '', basic_password: '',
		},
		format: 'auto',
		csv: { delimiter: '', enclosure: '"', encoding: 'auto' },
		xml: { record_path: '' },
		identity: 'sku',
		identity_field: 'sku',
		parent_field: 'parent_sku',
		type_field: 'type',
		mappings: [],
		missing_policy: 'keep',
		schedule: { enabled: false, period: 'daily', time: '02:00', weekday: 1 },
		email: '',
		retention_days: 30,
		batch_size: 50,
	});

	function updateUrl(values) {
		const url = new URL(window.location.href);
		Object.entries(values).forEach(([key, value]) => {
			if (value === null || value === undefined || value === '') url.searchParams.delete(key);
			else url.searchParams.set(key, String(value));
		});
		window.history.replaceState({}, '', url);
	}

	function App() {
		const query = new URLSearchParams(window.location.search);
		const [tab, setTab] = useState(query.get('tab') || 'import');
		const [presets, setPresets] = useState([]);
		const [drafts, setDrafts] = useState([]);
		const [jobs, setJobs] = useState([]);
		const [targets, setTargets] = useState({ core: [], acf: [] });
		const [activeDraft, setActiveDraft] = useState(null);
		const [activeJobId, setActiveJobId] = useState(Number(query.get('job') || 0) || null);
		const [busy, setBusy] = useState(true);
		const [notice, setNotice] = useState(null);
		const wizardFlush = useRef(null);

		const load = async () => {
			try {
				const [presetRows, draftRows, jobRows, targetRows] = await Promise.all([
					request('/presets'), request('/wizard/drafts'), request('/jobs'), request('/targets'),
				]);
				setPresets(presetRows); setDrafts(draftRows); setJobs(jobRows); setTargets(targetRows);
				const requested = Number(new URLSearchParams(window.location.search).get('draft') || 0);
				if (requested && !activeDraft) setActiveDraft(draftRows.find((row) => row.id === requested) || null);
			} catch (error) {
				setNotice({ status: 'error', text: error.message });
			} finally {
				setBusy(false);
			}
		};

		useEffect(() => { load(); }, []);
		const selectTab = async (name) => {
			if (activeDraft && name !== 'import' && wizardFlush.current) {
				try { await wizardFlush.current(); }
				catch (error) { setNotice({ status: 'error', text: error.message }); return; }
			}
			setTab(name); updateUrl({ tab: name, draft: null, step: null, job: null });
			if (name !== 'import') { setActiveDraft(null); setActiveJobId(null); }
		};
		const tabKeyDown = (event, index) => {
			const keys = ['ArrowLeft', 'ArrowRight', 'Home', 'End'];
			if (!keys.includes(event.key)) return;
			event.preventDefault();
			const tabs = ['import', 'presets', 'jobs', 'help'];
			let next = index;
			if (event.key === 'Home') next = 0;
			else if (event.key === 'End') next = tabs.length - 1;
			else if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
			else next = (index - 1 + tabs.length) % tabs.length;
			const button = event.currentTarget.parentElement.querySelectorAll('[role="tab"]')[next];
			if (button) button.focus();
			selectTab(tabs[next]);
		};
		const openDraft = (draft) => {
			setActiveDraft(draft); setActiveJobId(null); setTab('import');
			updateUrl({ tab: 'import', draft: draft.id, step: draft.wizard_step || 1, job: null });
		};
		const openJob = (jobId) => {
			setActiveDraft(null); setActiveJobId(jobId); setTab('import');
			updateUrl({ tab: 'import', draft: null, step: 6, job: jobId });
		};
		const createDraft = async (parentPresetId = null) => {
			try {
				const draft = await request('/wizard/drafts', {
					method: 'POST', data: parentPresetId ? { parent_preset_id: parentPresetId } : {},
				});
				setDrafts((rows) => [draft, ...rows]); openDraft(draft);
			} catch (error) { setNotice({ status: 'error', text: error.message }); }
		};

		if (busy) return h('div', { className: 'tds-loading' }, h(Spinner), t('Importer wird geladen ...', 'Loading importer ...'));
		return h('div', { className: 'tds-app' },
			h('header', { className: 'tds-header' },
				h('div', { className: 'tds-brand' },
					h('span', { className: 'tds-logo', 'aria-hidden': true }, 'TDS'),
					h('div', null, h('h1', null, 'TDS Product Importer'), h('p', null, t('CSV- und XML-Importe für WooCommerce', 'CSV and XML imports for WooCommerce')))
				),
				h('span', { className: 'tds-version' }, 'v' + window.tdsImporter.version)
			),
			notice && h(Notice, { status: notice.status, onRemove: () => setNotice(null) }, notice.text),
			h('nav', { className: 'tds-main-tabs', role: 'tablist', 'aria-label': t('Importer-Bereiche', 'Importer sections') },
				[
					['import', t('Import', 'Import')],
					['presets', t('Presets', 'Presets')],
					['jobs', t('Jobs', 'Jobs')],
					['help', t('Hilfe', 'Help')],
				].map(([name, label], index) => h('button', {
					key: name, id: `tds-tab-${name}`, role: 'tab', 'aria-selected': tab === name,
					'aria-controls': 'tds-main-panel', tabIndex: tab === name ? 0 : -1,
					className: tab === name ? 'is-active' : '', onKeyDown: (event) => tabKeyDown(event, index),
					onClick: () => selectTab(name),
				}, label))
			),
			h('main', { className: 'tds-main', id: 'tds-main-panel', role: 'tabpanel', 'aria-labelledby': `tds-tab-${tab}` },
				tab === 'import' && (activeDraft || activeJobId
					? h(Wizard, {
						key: activeDraft ? `draft-${activeDraft.id}` : `job-${activeJobId}`,
						draft: activeDraft, jobId: activeJobId, targets, notify: setNotice,
						flushRef: wizardFlush,
						onExit: async () => { setActiveDraft(null); setActiveJobId(null); updateUrl({ draft: null, step: null, job: null }); await load(); },
						onStarted: openJob,
					})
					: h(WizardLanding, { presets, drafts, createDraft, openDraft, reload: load, notify: setNotice })),
				tab === 'presets' && h(Presets, { presets, targets, createDraft, reload: load, notify: setNotice }),
				tab === 'jobs' && h(Jobs, { jobs, reload: load, notify: setNotice, openJob }),
				tab === 'help' && h(Help)
			)
		);
	}

	function WizardLanding({ presets, drafts, createDraft, openDraft, reload, notify }) {
		const discard = async (draft) => {
			if (!window.confirm(t('Diesen Entwurf verwerfen?', 'Discard this draft?'))) return;
			try {
				await request(`/wizard/drafts/${draft.id}`, { method: 'DELETE' }); await reload();
			} catch (error) { notify({ status: 'error', text: error.message }); }
		};
		return h('div', { className: 'tds-start' },
			h('section', { className: 'tds-hero' },
				h('div', null,
					h('span', { className: 'tds-eyebrow' }, t('SCHRITT-FÜR-SCHRITT', 'STEP BY STEP')),
					h('h2', null, t('Produkte sicher importieren', 'Import products safely')),
					h('p', null, t('Quelle prüfen, Felder zuordnen und den Import vor dem Start validieren.', 'Validate the source, map fields, and verify the import before it starts.')),
					h(Button, { variant: 'primary', onClick: () => createDraft() }, t('Neuer Import', 'New import'))
				),
				h('div', { className: 'tds-hero-mark', 'aria-hidden': true }, 'TDS')
			),
			drafts.length > 0 && h('section', null,
				h('div', { className: 'tds-section-title' }, h('h2', null, t('Entwürfe fortsetzen', 'Resume drafts')), h('span', null, `${drafts.length}`)),
				h('div', { className: 'tds-card-grid' }, drafts.map((draft) =>
					h(Card, { key: draft.id, className: 'tds-draft-card' }, h(CardBody, null,
						h('span', { className: 'tds-kicker' }, `${t('Schritt', 'Step')} ${draft.wizard_step || 1} / 5`),
						h('h3', null, draft.name),
						h('p', { className: 'description' }, `${t('Zuletzt gespeichert', 'Last saved')}: ${draft.updated_at}`),
						h('div', { className: 'tds-mini-progress' }, h('span', { style: { width: `${((draft.wizard_step || 1) / 5) * 100}%` } })),
						h('div', { className: 'tds-actions' },
							h(Button, { variant: 'primary', onClick: () => openDraft(draft) }, t('Fortsetzen', 'Resume')),
							h(Button, { isDestructive: true, onClick: () => discard(draft) }, t('Verwerfen', 'Discard'))
						)
					))
				))
			),
			presets.length > 0 && h('section', null,
				h('div', { className: 'tds-section-title' }, h('h2', null, t('Preset als Vorlage', 'Use a preset template'))),
				h('div', { className: 'tds-card-grid' }, presets.map((preset) =>
					h(Card, { key: preset.id }, h(CardBody, null,
						h('h3', null, preset.name),
						h('p', null, `${String(preset.config.source.type).toUpperCase()} · ${String(preset.config.format).toUpperCase()}`),
						h('p', { className: 'description' }, t('Das Original und sein Zeitplan bleiben unverändert.', 'The original and its schedule remain unchanged.')),
						h(Button, { variant: 'secondary', onClick: () => createDraft(preset.id) }, t('Als neuen Import verwenden', 'Use for new import'))
					))
				))
			)
		);
	}

	const wizardSteps = [
		[t('Quelle', 'Source'), t('Datei oder Verbindung', 'File or connection')],
		[t('Struktur', 'Structure'), t('Format und Datensätze', 'Format and records')],
		[t('Mapping', 'Mapping'), t('Produktfelder zuordnen', 'Map product fields')],
		[t('Regeln', 'Rules'), t('Importverhalten', 'Import behavior')],
		[t('Prüfung', 'Review'), t('Preflight und Freigabe', 'Preflight and approval')],
		[t('Fortschritt', 'Progress'), t('Live-Status', 'Live status')],
	];

	function Wizard({ draft, jobId, targets, notify, onExit, onStarted, flushRef }) {
		const requestedStep = Number(new URLSearchParams(window.location.search).get('step') || 0);
		const [model, setModel] = useState(() => draft ? hydrateModel(draft) : null);
		const [step, setStep] = useState(() => utils.initialWizardStep(jobId, draft?.wizard_step, requestedStep));
		const [preview, setPreview] = useState(null);
		const [suggestions, setSuggestions] = useState([]);
		const [suggestionsReviewed, setSuggestionsReviewed] = useState(true);
		const [preflight, setPreflight] = useState(null);
		const [destructiveConfirmed, setDestructiveConfirmed] = useState(false);
		const [errors, setErrors] = useState([]);
		const [busy, setBusy] = useState(false);
		const [saveState, setSaveState] = useState('saved');
		const [conflict, setConflict] = useState(null);
		const modelRef = useRef(model);
		const mounted = useRef(false);
		const resumePreviewAttempted = useRef(false);
		const saveQueue = useRef(Promise.resolve());
		const autosaveTimer = useRef(null);
		const dirty = useRef(false);
		const pendingSaves = useRef(0);
		const skipAutosave = useRef(false);
		const conflictRef = useRef(null);
		const stepHeading = useRef(null);
		const errorSummary = useRef(null);
		const conflictAction = useRef(null);
		if (model) modelRef.current = model;

		const saveNow = (wizardStep = null) => {
			if (!modelRef.current?.id) return Promise.resolve(modelRef.current);
			if (conflictRef.current) {
				return Promise.reject(new Error(t('Lösen Sie zuerst den Speicherkonflikt.', 'Resolve the save conflict before continuing.')));
			}
			if (autosaveTimer.current) {
				clearTimeout(autosaveTimer.current);
				autosaveTimer.current = null;
			}
			setSaveState('saving');
			pendingSaves.current += 1;
			saveQueue.current = saveQueue.current.catch(() => null).then(async () => {
				const current = modelRef.current;
				dirty.current = false;
				const saved = await request(`/wizard/drafts/${current.id}`, {
					method: 'PATCH',
					data: {
						name: current.name, config: current.config, revision: current.revision,
						wizard_step: wizardStep || current.wizard_step || step,
					},
				});
				if (modelRef.current === current && !dirty.current) {
					const normalized = utils.mergeSavedDraft(current, saved);
					const contentChanged = normalized.name !== current.name
						|| JSON.stringify(normalized.config) !== JSON.stringify(current.config);
					modelRef.current = normalized;
					if (contentChanged) skipAutosave.current = true;
					setModel(normalized);
				} else {
					modelRef.current = { ...modelRef.current, revision: saved.revision, wizard_step: saved.wizard_step };
					setModel((previous) => previous ? { ...previous, revision: saved.revision, wizard_step: saved.wizard_step } : saved);
				}
				setSaveState(dirty.current ? 'pending' : 'saved');
				return saved;
			}).catch((error) => {
				dirty.current = true;
				setSaveState('error');
				if (utils.isConflictError(error)) {
					const detected = {
						message: t('Dieser Entwurf wurde in einem anderen Fenster geändert. Laden Sie den Serverstand neu, bevor Sie weiterarbeiten.', 'This draft was changed in another window. Reload the server version before continuing.'),
					};
					conflictRef.current = detected;
					setConflict(detected);
				} else {
					notify({ status: 'error', text: error.message });
				}
				throw error;
			}).finally(() => {
				pendingSaves.current = Math.max(0, pendingSaves.current - 1);
			});
			return saveQueue.current;
		};

		useEffect(() => {
			if (!flushRef) return undefined;
			flushRef.current = jobId ? null : () => saveNow(Math.max(step, modelRef.current?.wizard_step || 1));
			return () => { flushRef.current = null; };
		}, [flushRef, step, jobId]);

		useEffect(() => {
			if (!model || !mounted.current) { mounted.current = true; return undefined; }
			if (skipAutosave.current) { skipAutosave.current = false; return undefined; }
			if (conflictRef.current) { dirty.current = true; setSaveState('error'); return undefined; }
			dirty.current = true;
			setSaveState('pending');
			if (autosaveTimer.current) clearTimeout(autosaveTimer.current);
			autosaveTimer.current = setTimeout(() => { saveNow().catch(() => null); }, 750);
			return () => {
				if (autosaveTimer.current) clearTimeout(autosaveTimer.current);
			};
		}, [model?.name, JSON.stringify(model?.config)]);

		useEffect(() => {
			const warnIfUnsaved = (event) => {
				if (!dirty.current && pendingSaves.current === 0 && !autosaveTimer.current) return;
				event.preventDefault();
				event.returnValue = '';
			};
			const saveWhenHidden = () => {
				if (document.visibilityState === 'hidden' && dirty.current && !pendingSaves.current) {
					saveNow().catch(() => null);
				}
			};
			window.addEventListener('beforeunload', warnIfUnsaved);
			document.addEventListener('visibilitychange', saveWhenHidden);
			return () => {
				window.removeEventListener('beforeunload', warnIfUnsaved);
				document.removeEventListener('visibilitychange', saveWhenHidden);
			};
		}, []);

		useEffect(() => {
			if (stepHeading.current) stepHeading.current.focus();
		}, [step]);
		useEffect(() => {
			if (conflict && conflictAction.current) conflictAction.current.focus();
			else if (errors.length && errorSummary.current) errorSummary.current.focus();
		}, [errors, conflict]);

		useEffect(() => {
			updateUrl({ draft: jobId ? null : model?.id || null, step, job: jobId || null });
		}, [step, model?.id, jobId]);

		useEffect(() => {
			if (jobId) setStep(6);
		}, [jobId]);

		const setConfig = (patch, resetLevel = null) => {
			const current = modelRef.current;
			if (!current) return;
			const base = { ...current, config: { ...current.config, ...patch } };
			if (!resetLevel) {
				modelRef.current = base;
				setModel(base);
				return;
			}
			const reset = utils.resetAfterChange({
				config: base.config, preview, suggestions, suggestionsReviewed, preflight,
				destructiveConfirmed, wizard_step: step,
			}, resetLevel);
			const next = {
				...base,
				config: reset.config || base.config,
				wizard_step: reset.wizard_step || base.wizard_step,
			};
			modelRef.current = next;
			setModel(next);
			setPreview(reset.preview); setSuggestions(reset.suggestions);
			setSuggestionsReviewed(reset.suggestionsReviewed); setPreflight(reset.preflight);
			setDestructiveConfirmed(reset.destructiveConfirmed);
			if (reset.wizard_step && step > reset.wizard_step) setStep(reset.wizard_step);
		};
		const setSource = (key, value) => setConfig({
			source: { ...model.config.source, [key]: value },
		}, 'source');

		const upload = async (event) => {
			const file = event.target.files?.[0];
			if (!file) return;
			setBusy(true);
			try {
				const body = new FormData(); body.append('source', file);
				const stored = await api({ path: '/tds-import/v1/upload', method: 'POST', body });
				setSource('upload_path', stored.path);
				notify({ status: 'success', text: t(`${file.name} wurde sicher gespeichert.`, `${file.name} was stored securely.`) });
			} catch (error) { notify({ status: 'error', text: error.message }); }
			finally { setBusy(false); }
		};

		const loadPreview = async () => {
			setBusy(true); setErrors([]);
			try {
				await saveNow();
				const result = await request(`/wizard/drafts/${modelRef.current.id}/source-preview`, { method: 'POST' });
				setPreview(result);
				setSuggestions([]); setSuggestionsReviewed(true); setPreflight(null);
				const config = modelRef.current.config;
				const patch = { format: result.format };
				if (result.format === 'csv') {
					patch.csv = {
						...config.csv,
						delimiter: config.csv.delimiter || result.structure?.delimiter || '',
						encoding: config.csv.encoding === 'auto' ? (result.structure?.encoding || 'auto') : config.csv.encoding,
					};
				} else if (result.format === 'xml') {
					patch.xml = { ...config.xml, record_path: config.xml.record_path || result.structure?.record_path || '' };
				}
				setModel((previous) => ({ ...previous, config: { ...previous.config, ...patch } }));
				return result;
			} catch (error) {
				setErrors([error.message]); notify({ status: 'error', text: error.message });
				if (step > 1) setStep(1);
				return null;
			} finally { setBusy(false); }
		};

		const loadSuggestions = async (sourcePreview = preview) => {
			if (!sourcePreview) return;
			const rows = await request(`/wizard/drafts/${modelRef.current.id}/mapping-suggestions`, {
				method: 'POST', data: { fields: sourcePreview.fields },
			});
			setSuggestions(rows);
			setSuggestionsReviewed(!rows.length);
		};

		const reviewSuggestions = (apply) => {
			if (apply) {
				const mappings = modelRef.current.config.mappings || [];
				const usedTargets = new Set(mappings.map((row) => row.target));
				const accepted = suggestions
					.filter((row) => !usedTargets.has(row.target))
					.map((row) => mappingRow(row.source, row.target, row.confidence));
				setConfig({ mappings: [...mappings, ...accepted] }, 'mapping');
			}
			setSuggestionsReviewed(true);
		};

		useEffect(() => {
			if (!model?.id || step < 2 || step > 5 || preview || resumePreviewAttempted.current) return;
			resumePreviewAttempted.current = true;
			loadPreview().then((result) => {
				if (result && step >= 3) loadSuggestions(result);
			});
		}, [model?.id]);

		const runPreflight = async () => {
			setBusy(true); setErrors([]);
			try {
				await saveNow(5);
				const result = await request(`/preflight/${modelRef.current.id}`, { method: 'POST' });
				setPreflight(result);
				if (!result.valid) setErrors(result.errors || []);
				return result;
			} catch (error) { setErrors([error.message]); return null; }
			finally { setBusy(false); }
		};

		const goNext = async () => {
			const validation = utils.validateStep(step, {
				config: model.config, preview, suggestions, suggestionsReviewed, preflight, destructiveConfirmed,
			}, locale);
			if (validation.length) { setErrors(validation); return; }
			setErrors([]); setBusy(true);
			try {
				if (step === 1) {
					await saveNow(2); setStep(2);
				} else if (step === 2) {
					await loadSuggestions(preview); await saveNow(3); setStep(3);
				} else if (step === 3) {
					await saveNow(4); setStep(4);
				} else if (step === 4) {
					await saveNow(5); setStep(5); await runPreflight();
				} else if (step === 5) {
					await saveNow(5);
					const result = await request(`/wizard/drafts/${modelRef.current.id}/start`, {
						method: 'POST', data: {
							revision: modelRef.current.revision,
							confirm_missing_policy: destructiveConfirmed ? modelRef.current.config.missing_policy : null,
						},
					});
					onStarted(result.job.id);
				}
			} catch (error) {
				const serverPreflight = error?.data?.preflight;
				if (serverPreflight) setPreflight(serverPreflight);
				setErrors([error.message]);
			} finally { setBusy(false); }
		};

		const navigateTo = async (nextStep) => {
			if (busy || nextStep === step) return;
			setBusy(true); setErrors([]);
			try {
				await saveNow(Math.max(step, modelRef.current?.wizard_step || 1));
				setStep(nextStep);
			} catch (error) {
				setErrors([error.message]);
			} finally { setBusy(false); }
		};
		const exitWizard = async () => {
			if (jobId || !modelRef.current?.id) { await onExit(); return; }
			setBusy(true); setErrors([]);
			try {
				await saveNow(Math.max(step, modelRef.current.wizard_step || 1));
				await onExit();
			} catch (error) {
				setErrors([error.message]); setBusy(false);
			}
		};
		const reloadServerDraft = () => {
			dirty.current = false;
			conflictRef.current = null;
			if (autosaveTimer.current) {
				clearTimeout(autosaveTimer.current);
				autosaveTimer.current = null;
			}
			window.location.reload();
		};
		const validationState = {
			config: model?.config, preview, suggestions, suggestionsReviewed, preflight, destructiveConfirmed,
		};
		const currentValidation = step >= 6 ? [] : utils.validateStep(step, validationState, locale);
		const storedReachable = Math.min(5, model?.wizard_step || 1);
		const maxReachable = jobId ? 6 : utils.previewIsValid(preview) ? storedReachable : 1;
		const canContinue = step >= 6 || currentValidation.length === 0;
		return h('div', { className: 'tds-wizard' },
			h('aside', { className: 'tds-stepper', 'aria-label': t('Importschritte', 'Import steps') },
				h('div', { className: 'tds-stepper-brand' }, h('span', { className: 'tds-logo', 'aria-hidden': true }, 'TDS'), h('strong', null, t('Importassistent', 'Import wizard'))),
				h('ol', null, wizardSteps.map(([title, subtitle], index) => {
					const number = index + 1;
					const reachable = number <= maxReachable;
					return h('li', { key: number, className: `${step === number ? 'is-current' : ''} ${number < step ? 'is-complete' : ''}` },
						h('button', {
							disabled: !reachable || busy, onClick: () => reachable && navigateTo(number),
							'aria-current': step === number ? 'step' : undefined,
						}, h('span', { className: 'tds-step-number' }, number < step ? '✓' : number),
						h('span', null, h('strong', null, title), h('small', null, subtitle)))
					);
				})),
				step < 6 && h('div', { className: 'tds-step-validation', 'aria-live': 'polite' },
					h('strong', null, currentValidation.length ? t('Noch erforderlich', 'Still required') : t('Schritt vollständig', 'Step complete')),
					currentValidation.length
						? h('ul', null, currentValidation.map((error) => h('li', { key: error }, error)))
						: h('p', null, t('Sie können fortfahren.', 'You can continue.'))
				),
				h(Button, { variant: 'tertiary', disabled: busy, onClick: exitWizard }, `← ${t('Assistent verlassen', 'Exit wizard')}`)
			),
			h('section', { className: 'tds-wizard-main' },
				h('div', { className: 'tds-mobile-progress' },
					h('span', null, `${t('Schritt', 'Step')} ${step} ${t('von', 'of')} 6`),
					h('div', null, h('span', { style: { width: `${(step / 6) * 100}%` } })),
					h('ol', { className: 'tds-mobile-steps', 'aria-label': t('Importschritte', 'Import steps') }, wizardSteps.map(([title], index) => {
						const number = index + 1;
						const reachable = number <= maxReachable;
						return h('li', { key: number }, h('button', {
							type: 'button', disabled: !reachable || busy, onClick: () => navigateTo(number),
							className: step === number ? 'is-current' : number < step ? 'is-complete' : '',
							'aria-current': step === number ? 'step' : undefined,
							'aria-label': `${number}. ${title}`,
						}, number < step ? '✓' : number));
					}))
				),
				h('header', { className: 'tds-wizard-header' },
					h('div', null, h('span', { className: 'tds-eyebrow' }, `${t('SCHRITT', 'STEP')} ${step}`), h('h2', { ref: stepHeading, tabIndex: -1 }, wizardSteps[step - 1][0]), h('p', null, wizardSteps[step - 1][1])),
					model && h('div', { className: `tds-save-state is-${saveState}`, 'aria-live': 'polite' },
						saveState === 'saving' ? h(Spinner) : null,
						saveState === 'pending' ? t('Änderungen ...', 'Changes ...') : saveState === 'saving' ? t('Speichert ...', 'Saving ...') : saveState === 'error' ? t('Speicherfehler', 'Save error') : t('Gespeichert', 'Saved')
					)
				),
				h('div', { className: 'tds-mobile-validation', 'aria-live': 'polite' },
					h('strong', null, currentValidation.length ? t('Noch erforderlich', 'Still required') : t('Schritt vollständig', 'Step complete')),
					currentValidation.length ? h('ul', null, currentValidation.map((error) => h('li', { key: error }, error))) : null
				),
				(conflict || errors.length > 0) && h('div', { ref: errorSummary, tabIndex: -1 },
					conflict
						? h(Notice, { status: 'warning', isDismissible: false, role: 'alert' },
							h('p', null, conflict.message),
							h(Button, { ref: conflictAction, variant: 'secondary', onClick: reloadServerDraft }, t('Serverstand neu laden', 'Reload server version'))
						)
						: h(Notice, { status: 'error', isDismissible: false, role: 'alert' }, h('ul', null, errors.map((error, index) => h('li', { key: index }, error))))
				),
				step === 1 && h(SourceStep, { model, preview, setModel, setSource, upload, loadPreview, busy }),
				step === 2 && h(StructureStep, { model, preview, setConfig, loadPreview, busy }),
				step === 3 && h(MappingStep, {
					model, preview, targets, suggestions, suggestionsReviewed, reviewSuggestions, setConfig,
				}),
				step === 4 && h(RulesStep, { model, setConfig }),
				step === 5 && h(ReviewStep, { model, preview, preflight, runPreflight, busy, destructiveConfirmed, setDestructiveConfirmed }),
				step === 6 && h(LiveProgress, { jobId, notify }),
				step < 6 && h('footer', { className: 'tds-wizard-actions' },
					h(Button, { variant: 'tertiary', disabled: step === 1 || busy, onClick: () => navigateTo(step - 1) }, t('Zurück', 'Back')),
					h('div', null,
						step === 2 && h(Button, { variant: 'secondary', disabled: busy, onClick: loadPreview }, t('Erneut erkennen', 'Detect again')),
						h(Button, { variant: 'primary', disabled: busy || !canContinue, onClick: goNext },
							busy ? h(Spinner) : step === 5 ? t('Import verbindlich starten', 'Start import') : t('Weiter', 'Continue'))
					)
				)
			)
		);
	}

	function SourceStep({ model, preview, setModel, setSource, upload, loadPreview, busy }) {
		const config = model.config;
		const source = config.source;
		const sourceTypes = [
			['upload', t('Datei-Upload', 'File upload'), t('CSV oder XML vom Computer', 'CSV or XML from your computer')],
			['https', 'HTTPS', t('Geschützte öffentliche URL', 'Secure public URL')],
			['sftp', 'SFTP', t('Server mit Host-Key-Prüfung', 'Server with host key verification')],
		];
		const selectSourceByKey = (event, index) => {
			const keys = ['ArrowDown', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'End', 'Home'];
			if (!keys.includes(event.key)) return;
			event.preventDefault();
			let nextIndex = index;
			if (event.key === 'Home') nextIndex = 0;
			else if (event.key === 'End') nextIndex = sourceTypes.length - 1;
			else if (event.key === 'ArrowRight' || event.key === 'ArrowDown') nextIndex = (index + 1) % sourceTypes.length;
			else nextIndex = (index - 1 + sourceTypes.length) % sourceTypes.length;
			setSource('type', sourceTypes[nextIndex][0]);
			const buttons = event.currentTarget.parentElement.querySelectorAll('[role="radio"]');
			if (buttons[nextIndex]) buttons[nextIndex].focus();
		};
		return h('div', { className: 'tds-step-content' },
			h(Card, null, h(CardBody, null,
				input(t('Name dieses Imports', 'Import name'), model.name, (name) => setModel({ ...model, name })),
				h('div', { className: 'tds-source-types', role: 'radiogroup', 'aria-label': t('Quellentyp', 'Source type') },
					sourceTypes.map(([value, title, subtitle], index) => h('button', {
						key: value, type: 'button', role: 'radio', 'aria-checked': source.type === value,
						tabIndex: source.type === value ? 0 : -1,
						className: source.type === value ? 'is-selected' : '', onClick: () => setSource('type', value),
						onKeyDown: (event) => selectSourceByKey(event, index),
					}, h('strong', null, title), h('span', null, subtitle)))
				),
				source.type === 'upload' && h('div', { className: 'tds-upload-zone' },
					h('input', { id: 'tds-source-upload', type: 'file', accept: '.csv,.xml,text/csv,application/xml,text/xml', onChange: upload }),
					h('label', { htmlFor: 'tds-source-upload' }, h('strong', null, t('CSV- oder XML-Datei auswählen', 'Choose a CSV or XML file')), h('span', null, t('Maximale Größe gemäß Serverkonfiguration', 'Maximum size depends on server configuration'))),
					source.upload_path && h('p', { className: 'tds-file-pill' }, `✓ ${sourceName(source.upload_path)}`)
				),
				source.type === 'https' && h('div', { className: 'tds-form-grid' },
					input('HTTPS-URL', source.url, (value) => setSource('url', value), 'url'),
					input(t('Basic-Auth-Benutzer (optional)', 'Basic auth username (optional)'), source.basic_username, (value) => setSource('basic_username', value)),
					input(t('Basic-Auth-Passwort (optional)', 'Basic auth password (optional)'), source.basic_password, (value) => setSource('basic_password', value), 'password')
				),
				source.type === 'sftp' && h('div', { className: 'tds-form-grid' },
					input('Host', source.host, (value) => setSource('host', value)),
					input('Port', source.port, (value) => setSource('port', Number(value)), 'number'),
					input(t('Benutzer', 'Username'), source.username, (value) => setSource('username', value)),
					input(t('Passwort / Key-Passphrase', 'Password / key passphrase'), source.password, (value) => setSource('password', value), 'password'),
					input(t('Remote-Pfad', 'Remote path'), source.remote_path, (value) => setSource('remote_path', value)),
					input(t('Host-Key-Fingerprint (verpflichtend)', 'Host key fingerprint (required)'), source.fingerprint, (value) => setSource('fingerprint', value)),
					h(TextareaControl, { label: t('Privater Schlüssel (optional)', 'Private key (optional)'), value: source.private_key || '', onChange: (value) => setSource('private_key', value) })
				),
				h('div', { className: 'tds-test-row' },
					h(Button, { variant: 'secondary', disabled: busy || !utils.sourceIsValid(config), onClick: loadPreview }, busy ? h(Spinner) : t('Verbindung testen', 'Test connection')),
					h('span', { role: 'status', 'aria-live': 'polite' }, utils.previewIsValid(preview)
						? t('✓ Quelle erfolgreich geprüft.', '✓ Source tested successfully.')
						: t('Die Quelle wird gelesen, aber noch nicht importiert.', 'The source is read but not imported yet.'))
				)
			))
		);
	}

	function StructureStep({ model, preview, setConfig, loadPreview, busy }) {
		const config = model.config;
		const format = preview?.format || config.format;
		return h('div', { className: 'tds-step-content' },
			h('div', { className: 'tds-summary-strip' },
				h('div', null, h('small', null, t('Erkanntes Format', 'Detected format')), h('strong', null, String(preview?.format || config.format).toUpperCase())),
				h('div', null, h('small', null, t('Felder', 'Fields')), h('strong', null, preview?.fields?.length || '–')),
				h('div', null, h('small', null, t('Dateigröße', 'File size')), h('strong', null, preview ? formatBytes(preview.size) : '–')),
				h(Button, { variant: 'secondary', disabled: busy, onClick: loadPreview }, t('Neu einlesen', 'Read again'))
			),
			h(Card, null, h(CardBody, null,
				format === 'csv'
					? h('div', { className: 'tds-form-grid' },
						h(SelectControl, {
							label: t('Trennzeichen', 'Delimiter'), value: config.csv.delimiter,
							options: [
								{ label: t('Komma (,)', 'Comma (,)'), value: ',' },
								{ label: t('Semikolon (;)', 'Semicolon (;)'), value: ';' },
								{ label: t('Tabulator', 'Tab'), value: '\t' },
								{ label: t('Pipe (|)', 'Pipe (|)'), value: '|' },
							],
							onChange: (value) => setConfig({ csv: { ...config.csv, delimiter: value } }, 'structure'),
						}),
						h(SelectControl, {
							label: t('Kodierung', 'Encoding'), value: config.csv.encoding,
							options: [{ label: 'UTF-8', value: 'UTF-8' }, { label: 'Windows-1252', value: 'Windows-1252' }, { label: t('Automatisch', 'Auto'), value: 'auto' }],
							onChange: (value) => setConfig({ csv: { ...config.csv, encoding: value } }, 'structure'),
						})
					)
					: input(t('XML-Datensatzpfad', 'XML record path'), config.xml.record_path, (value) => setConfig({ xml: { ...config.xml, record_path: value } }, 'structure'), 'text', { help: t('Beispiel: /catalog/product', 'Example: /catalog/product') }),
				h(SampleTable, { records: preview?.records || [] })
			))
		);
	}

	function SampleTable({ records, mapped = null }) {
		if (!records.length) return h('p', { className: 'description' }, t('Keine Beispieldaten verfügbar.', 'No sample data available.'));
		const fields = Object.keys(records[0]).slice(0, 6);
		return h('div', { className: 'tds-table-scroll' },
			h('table', { className: 'widefat striped tds-sample-table' },
				h('caption', { className: 'screen-reader-text' }, t('Vorschau der Quelldaten und Mapping-Ergebnisse', 'Preview of source data and mapping results')),
				h('thead', null, h('tr', null, fields.map((field) => h('th', { key: field, scope: 'col' }, field)), mapped && h('th', { scope: 'col' }, t('Ergebnis', 'Result')))),
				h('tbody', null, records.slice(0, 5).map((record, index) => {
					const cells = fields.map((field) => h('td', { key: field }, typeof record[field] === 'object' ? JSON.stringify(record[field]) : String(record[field] ?? '')));
					if (mapped) cells.push(h('td', { key: 'result' }, h('code', null, JSON.stringify(mapped[index] || {}))));
					return h('tr', { key: index }, ...cells);
				}))
			)
		);
	}

	function MappingStep({ model, preview, targets, suggestions = [], suggestionsReviewed = true, reviewSuggestions = null, setConfig }) {
		const mappings = model.config.mappings || [];
		const [mappedPreview, setMappedPreview] = useState([]);
		const groupLabels = mappingGroupLabels();
		const groupKeys = Object.keys(groupLabels);
		const change = (original, patch, manualFieldChange = false) => setConfig({
			mappings: mappings.map((row) => row === original ? {
				...row,
				...patch,
				ast: null,
				confidence: manualFieldChange ? null : row.confidence,
			} : row),
		}, 'mapping');
		const remove = (original) => setConfig({ mappings: mappings.filter((row) => row !== original) }, 'mapping');
		useEffect(() => {
			if (!preview?.records?.length) return undefined;
			const timer = setTimeout(async () => {
				try {
					setMappedPreview(await request('/map-preview', {
						method: 'POST', data: { config: model.config, records: preview.records.slice(0, 5) },
					}));
				} catch (_) { setMappedPreview([]); }
			}, 350);
			return () => clearTimeout(timer);
		}, [JSON.stringify(mappings), JSON.stringify(preview?.records || [])]);
		return h('div', { className: 'tds-step-content' },
			h('div', { className: 'tds-mapping-intro' },
				h('div', null, h('h3', null, t('Feldzuordnungen', 'Field mappings')), h('p', null, t('Prüfen Sie Vorschläge bewusst und bearbeiten Sie danach jede Zuordnung direkt.', 'Review suggestions explicitly, then edit each mapping directly.'))),
				h(Button, { variant: 'secondary', onClick: () => setConfig({ mappings: [...mappings, mappingRow()] }, 'mapping') }, t('Zuordnung hinzufügen', 'Add mapping'))
			),
			suggestions.length > 0 && h('section', {
				className: `tds-suggestion-review ${suggestionsReviewed ? 'is-reviewed' : ''}`,
				'aria-labelledby': 'tds-suggestions-title',
			},
				h('div', { className: 'tds-suggestion-head' },
					h('div', null,
						h('h3', { id: 'tds-suggestions-title' }, t('Automatische Vorschläge prüfen', 'Review automatic suggestions')),
						h('p', null, suggestionsReviewed
							? t('Die Vorschläge wurden geprüft.', 'The suggestions have been reviewed.')
							: t('Es wird noch nichts übernommen. Prüfen Sie alle sichtbaren Vorschläge.', 'Nothing has been applied yet. Review all visible suggestions.'))
					),
					h('span', { className: 'tds-suggestion-count' }, `${suggestions.length}`)
				),
				h('div', { className: 'tds-suggestion-groups' }, groupKeys.map((groupKey) => {
					const rows = suggestions.filter((row) => mappingGroupKey(row.target) === groupKey);
					if (!rows.length) return null;
					return h('div', { className: 'tds-suggestion-group', key: groupKey },
						h('h4', null, groupLabels[groupKey]),
						h('ul', null, rows.map((row) => h('li', { key: `${row.source}-${row.target}` },
							h('span', null, h('code', null, row.source), h('span', { 'aria-hidden': true }, ' → '), h('strong', null, row.target)),
							h('span', {
								className: `tds-confidence ${row.confidence >= .95 ? 'is-high' : ''}`,
								'aria-label': t(`Konfidenz ${Math.round(row.confidence * 100)} Prozent`, `Confidence ${Math.round(row.confidence * 100)} percent`),
							}, `${Math.round(row.confidence * 100)}%`)
						)))
					);
				})),
				reviewSuggestions && h('div', { className: 'tds-suggestion-actions' }, suggestionsReviewed
					? h('span', { className: 'tds-reviewed-status', role: 'status' }, `✓ ${t('Vorschläge geprüft', 'Suggestions reviewed')}`)
					: [
						h(Button, { key: 'apply', variant: 'primary', onClick: () => reviewSuggestions(true) }, t('Vorschläge übernehmen', 'Apply suggestions')),
						h(Button, { key: 'skip', variant: 'tertiary', onClick: () => reviewSuggestions(false) }, t('Ohne Vorschläge fortfahren', 'Continue without suggestions')),
					]
				)
			),
			h(Card, null, h(CardBody, null,
				h('div', { className: 'tds-mapping-groups' }, groupKeys.map((groupKey) => {
					const rows = mappings.filter((row) => (row._uiGroup || mappingGroupKey(row.target)) === groupKey);
					if (!rows.length) return null;
					return h('section', { className: 'tds-mapping-group', key: groupKey },
						h('h3', null, groupLabels[groupKey]),
						h('div', { className: 'tds-mapping-list' }, rows.map((row) => {
						const rowId = row._uiId || `tds-map-fallback-${mappings.indexOf(row)}`;
						const sourceId = `${rowId}-source`;
						const targetId = `${rowId}-target`;
						const expressionId = `${rowId}-expression`;
						const emptyId = `${rowId}-empty`;
						const defaultId = `${rowId}-default`;
						return h('div', { className: 'tds-map-row', key: rowId, id: rowId },
						h('div', null,
							h('label', { htmlFor: sourceId }, t('Quellfeld', 'Source field')),
							h('select', { id: sourceId, value: row.source, onChange: (event) => change(row, { source: event.target.value }, true) },
								h('option', { value: '' }, '—'),
								[...new Set([row.source, ...(preview?.fields || [])])].filter(Boolean).map((field) => h('option', { value: field, key: field }, field))
							)
						),
						h('span', { className: 'tds-map-arrow', 'aria-hidden': true }, '→'),
						h('div', null,
							h('label', { htmlFor: targetId }, t('WooCommerce-Ziel', 'WooCommerce target')),
							h('input', {
								id: targetId, value: row.target, list: 'tds-target-list',
								onChange: (event) => change(row, { target: event.target.value }, true),
								onBlur: (event) => change(row, { _uiGroup: mappingGroupKey(event.target.value) }),
							})
						),
						row.confidence && h('span', {
							className: `tds-confidence ${row.confidence >= .95 ? 'is-high' : ''}`,
							'aria-label': t(`Konfidenz ${Math.round(row.confidence * 100)} Prozent`, `Confidence ${Math.round(row.confidence * 100)} percent`),
						}, `${Math.round(row.confidence * 100)}%`),
						h('details', { className: 'tds-formula' }, h('summary', null, t('Formel', 'Formula')),
							h('label', { className: 'screen-reader-text', htmlFor: expressionId }, t('Mapping-Formel', 'Mapping formula')),
							h('input', { id: expressionId, className: 'code', value: row.expression || '', placeholder: 'trim([name])', onChange: (event) => change(row, { expression: event.target.value }) }),
							h('label', { className: 'screen-reader-text', htmlFor: emptyId }, t('Regel für leere Werte', 'Empty value rule')),
							h('select', { id: emptyId, value: row.empty || 'keep', onChange: (event) => change(row, { empty: event.target.value }) },
								h('option', { value: 'keep' }, t('Leer: behalten', 'Empty: keep')),
								h('option', { value: 'clear' }, t('Leer: löschen', 'Empty: clear')),
								h('option', { value: 'default' }, t('Leer: Standardwert', 'Empty: default'))
							),
							(row.empty || 'keep') === 'default' && [
								h('label', { className: 'screen-reader-text', htmlFor: defaultId, key: `${defaultId}-label` }, t('Standardwert', 'Default value')),
								h('input', { id: defaultId, key: defaultId, value: row.default || '', placeholder: t('Standardwert', 'Default value'), onChange: (event) => change(row, { default: event.target.value }) }),
							]
						),
						h(Button, { isDestructive: true, 'aria-label': t('Zuordnung entfernen', 'Remove mapping'), onClick: () => remove(row) }, '×')
						);
					}))
					);
				})),
				!mappings.length && h('p', { className: 'tds-empty-mapping' }, t('Noch keine Zuordnung. Übernehmen Sie Vorschläge oder fügen Sie eine Zuordnung hinzu.', 'No mappings yet. Apply suggestions or add a mapping.')),
				h('datalist', { id: 'tds-target-list' }, [...targets.core, ...targets.acf].map((target) => h('option', { key: target, value: target })))
			)),
			mappedPreview.length > 0 && h(SampleTable, { records: preview.records, mapped: mappedPreview })
		);
	}

	function RulesStep({ model, setConfig }) {
		const config = model.config;
		const schedule = config.schedule;
		return h('div', { className: 'tds-step-content tds-rules-grid' },
			h(Card, null, h(CardBody, null,
				h('h3', null, t('Produktabgleich', 'Product matching')),
				h(SelectControl, {
					label: t('Eindeutiger Identifikator', 'Unique identifier'), value: config.identity,
					options: [{ label: 'SKU', value: 'sku' }, { label: t('Externe ID', 'External ID'), value: 'external_id' }],
					onChange: (identity) => setConfig({ identity, identity_field: identity }, 'rules'),
				}),
				h(SelectControl, {
					label: t('Fehlende Produkte', 'Missing products'), value: config.missing_policy,
					options: [
						{ label: t('Unverändert lassen', 'Keep unchanged'), value: 'keep' },
						{ label: t('Auf Entwurf setzen', 'Set to draft'), value: 'draft' },
						{ label: t('Nicht vorrätig setzen', 'Set out of stock'), value: 'outofstock' },
						{ label: t('In Papierkorb verschieben', 'Move to trash'), value: 'trash' },
					],
					onChange: (missing_policy) => setConfig({ missing_policy }, 'missing-policy'),
				}),
				input(t('Batchgröße', 'Batch size'), config.batch_size, (value) => setConfig({ batch_size: Number(value) }, 'rules'), 'number', { min: 10, max: 250 }),
				input(t('Rollback-Aufbewahrung (Tage)', 'Rollback retention (days)'), config.retention_days, (value) => setConfig({ retention_days: Number(value) }, 'rules'), 'number', { min: 7, max: 365 })
			)),
			h(Card, null, h(CardBody, null,
				h('h3', null, t('Benachrichtigung und Zeitplan', 'Notifications and schedule')),
				input(t('Fehler-E-Mail', 'Error email'), config.email, (value) => setConfig({ email: value }, 'rules'), 'email'),
				h(ToggleControl, {
					label: t('Automatische Folgeimporte planen', 'Schedule recurring imports'), checked: !!schedule.enabled,
					onChange: (enabled) => setConfig({ schedule: { ...schedule, enabled } }, 'rules'),
				}),
				schedule.enabled && h('div', null,
					h(SelectControl, {
						label: t('Intervall', 'Interval'), value: schedule.period,
						options: [{ label: t('Stündlich', 'Hourly'), value: 'hourly' }, { label: t('Täglich', 'Daily'), value: 'daily' }, { label: t('Wöchentlich', 'Weekly'), value: 'weekly' }],
						onChange: (period) => setConfig({ schedule: { ...schedule, period } }, 'rules'),
					}),
					schedule.period !== 'hourly' && input(t('Lokale Uhrzeit', 'Local time'), schedule.time, (time) => setConfig({ schedule: { ...schedule, time } }, 'rules'), 'time'),
					schedule.period === 'weekly' && h(SelectControl, {
						label: t('Wochentag', 'Weekday'), value: String(schedule.weekday),
						options: [t('Sonntag', 'Sunday'), t('Montag', 'Monday'), t('Dienstag', 'Tuesday'), t('Mittwoch', 'Wednesday'), t('Donnerstag', 'Thursday'), t('Freitag', 'Friday'), t('Samstag', 'Saturday')].map((label, value) => ({ label, value: String(value) })),
						onChange: (weekday) => setConfig({ schedule: { ...schedule, weekday: Number(weekday) } }, 'rules'),
					})
				)
			))
		);
	}

	function ReviewStep({ model, preview, preflight, runPreflight, busy, destructiveConfirmed, setDestructiveConfirmed }) {
		const config = model.config;
		const destructive = utils.destructivePolicies.includes(config.missing_policy);
		return h('div', { className: 'tds-step-content' },
			h('div', { className: `tds-preflight-status ${preflight?.valid ? 'is-valid' : ''}` },
				h('span', null, preflight?.valid ? '✓' : '!'),
				h('div', null,
					h('h3', null, preflight?.valid ? t('Preflight erfolgreich', 'Preflight successful') : t('Import noch prüfen', 'Review import')),
					h('p', null, preflight?.valid ? t('Quelle, Pflichtfelder, Schlüssel und Formeln sind gültig.', 'Source, required fields, keys, and formulas are valid.') : t('Führen Sie die abschließende Prüfung mit den aktuellen Einstellungen aus.', 'Run the final validation with the current settings.'))
				),
				h(Button, { variant: 'secondary', disabled: busy, onClick: runPreflight }, t('Preflight ausführen', 'Run preflight'))
			),
			h('div', { className: 'tds-review-grid' },
				h(Card, null, h(CardBody, null,
					h('h3', null, t('Zusammenfassung', 'Summary')),
					h('dl', null,
						h('dt', null, t('Quelle', 'Source')), h('dd', null, String(config.source.type).toUpperCase()),
						h('dt', null, t('Format', 'Format')), h('dd', null, String(config.format).toUpperCase()),
						h('dt', null, t('Zuordnungen', 'Mappings')), h('dd', null, config.mappings.length),
						h('dt', null, t('Identifikator', 'Identifier')), h('dd', null, config.identity),
						h('dt', null, t('Batchgröße', 'Batch size')), h('dd', null, config.batch_size),
						h('dt', null, t('Fehlende Produkte', 'Missing products')), h('dd', null, config.missing_policy)
					)
				)),
				h(Card, null, h(CardBody, null,
					h('h3', null, t('Hinweise', 'Notices')),
					preflight?.errors?.length ? h('ul', { className: 'tds-errors' }, preflight.errors.map((error, index) => h('li', { key: index }, error))) : h('p', null, t('Keine blockierenden Fehler.', 'No blocking errors.')),
					destructive && h('div', { className: 'tds-destructive-confirm' },
						h(CheckboxControl, {
							label: t('Ich bestätige, dass fehlende Produkte entsprechend der gewählten Regel verändert werden.', 'I confirm that missing products will be changed according to the selected rule.'),
							checked: destructiveConfirmed, onChange: setDestructiveConfirmed,
						})
					)
				))
			),
			preflight?.samples?.length > 0 && h(SampleTable, { records: preflight.samples.map((sample) => sample.raw), mapped: preflight.samples.map((sample) => sample.result) })
		);
	}

	const phaseLabels = {
		fetch: [t('Quelle abrufen', 'Fetch source'), 1],
		parse: [t('Datensätze vorbereiten', 'Prepare records'), 2],
		import: [t('Produkte und Medien', 'Products and media'), 3],
		relationships: [t('Beziehungen', 'Relationships'), 4],
		missing: [t('Fehlende Produkte', 'Missing products'), 5],
		complete: [t('Abschluss', 'Finalize'), 6],
		rollback: [t('Rollback', 'Rollback'), 7],
	};

	function LiveProgress({ jobId, notify }) {
		const [job, setJob] = useState(null);
		const [loading, setLoading] = useState(true);
		const [controlBusy, setControlBusy] = useState(false);
		const [pollError, setPollError] = useState(null);
		const [pollVersion, setPollVersion] = useState(0);
		const polling = useRef(false);
		useEffect(() => {
			let cancelled = false;
			let timer = null;
			const poll = async () => {
				if (polling.current || cancelled) return;
				polling.current = true;
				let next = null;
				let permanentError = false;
				try {
					next = await request(`/jobs/${jobId}`);
					if (!cancelled) { setJob(next); setPollError(null); }
				} catch (error) {
					permanentError = utils.isPermanentHttpError(error);
					if (!cancelled && permanentError) setPollError(error.message);
					else if (!cancelled) notify({ status: 'error', text: error.message });
				} finally {
					polling.current = false;
					if (!cancelled) setLoading(false);
				}
				if (!cancelled && !permanentError && (!next || !utils.isTerminalStatus(next.status))) {
					timer = setTimeout(poll, 2000);
				}
			};
			poll();
			return () => {
				cancelled = true;
				if (timer) clearTimeout(timer);
			};
		}, [jobId, pollVersion]);
		const control = async (action) => {
			setControlBusy(true);
			try {
				const changed = await request(`/jobs/${jobId}/control`, { method: 'POST', data: { action } });
				setJob((current) => ({ ...current, ...changed }));
			} catch (error) { notify({ status: 'error', text: error.message }); }
			finally { setControlBusy(false); }
		};
		const startRollback = async () => {
			if (!window.confirm(t('Diesen Import wirklich zurückrollen?', 'Really roll back this import?'))) return;
			setControlBusy(true);
			try {
				const changed = await request(`/jobs/${jobId}/rollback`, { method: 'POST', data: {} });
				setJob((current) => ({ ...current, ...changed }));
				setPollVersion((value) => value + 1);
			} catch (error) { notify({ status: 'error', text: error.message }); }
			finally { setControlBusy(false); }
		};
		const retryPolling = () => {
			setPollError(null); setLoading(true); setPollVersion((value) => value + 1);
		};
		if (!job && pollError) return h(Card, null, h(CardBody, null,
			h(Notice, { status: 'error', isDismissible: false, role: 'alert' }, pollError),
			h(Button, { variant: 'secondary', onClick: retryPolling }, t('Erneut versuchen', 'Try again'))
		));
		if (loading || !job) return h('div', { className: 'tds-loading' }, h(Spinner), t('Job wird geladen ...', 'Loading job ...'));
		const metrics = utils.jobMetrics(job);
		const percent = metrics.progressPercent;
		const currentPhase = metrics.currentPhase || job.phase;
		const phaseIndex = phaseLabels[currentPhase]?.[1] || 1;
		const active = ['queued', 'running', 'paused'].includes(job.status) && currentPhase !== 'rollback';
		const rate = metrics.recordsPerMinute === null ? '–' : new Intl.NumberFormat(isGerman ? 'de-DE' : 'en-US', { maximumFractionDigits: 1 }).format(metrics.recordsPerMinute);
		const phaseName = phaseLabels[currentPhase]?.[0] || currentPhase;
		const recentWarnings = (job.recent_warnings || (job.logs || []).filter((log) => ['error', 'warning'].includes(log.level))).slice(0, 5);
		return h('div', { className: 'tds-step-content' },
			pollError && h(Notice, { status: 'error', isDismissible: false, role: 'alert' },
				h('p', null, pollError), h(Button, { variant: 'secondary', onClick: retryPolling }, t('Erneut versuchen', 'Try again'))
			),
			h(Card, { className: 'tds-live-card' }, h(CardBody, null,
				h('div', { className: 'tds-live-head', 'aria-live': 'polite' },
					h('div', null, h('span', { className: `tds-status tds-status-${job.status}` }, job.status), h('h3', null, `${t('Importjob', 'Import job')} #${job.id}`), h('p', null, phaseName)),
					h('strong', null, `${percent}%`)
				),
				h('div', {
					className: 'tds-progress-bar', role: 'progressbar', 'aria-label': t('Importfortschritt', 'Import progress'), 'aria-valuenow': percent,
					'aria-valuemin': 0, 'aria-valuemax': 100,
					'aria-valuetext': t(`${percent} Prozent, ${job.processed || 0} von ${job.total || 0}, Phase ${phaseName}`, `${percent} percent, ${job.processed || 0} of ${job.total || 0}, phase ${phaseName}`),
				}, h('span', { style: { width: `${percent}%` } })),
				h('div', { className: 'tds-counter-grid' },
					[[t('Gesamt', 'Total'), job.total], [t('Verarbeitet', 'Processed'), job.processed], [t('Neu', 'Created'), job.created], [t('Aktualisiert', 'Updated'), job.updated], [t('Übersprungen', 'Skipped'), job.skipped], [t('Fehler', 'Failed'), job.failed]]
						.map(([label, value]) => h('div', { key: label }, h('small', null, label), h('strong', null, value || 0)))
				),
				h('div', { className: 'tds-metric-grid', 'aria-label': t('Laufzeitmetriken', 'Runtime metrics') },
					[
						[t('Datensätze / Minute', 'Records / minute'), rate],
						[t('Geschätzte Restzeit', 'Estimated time remaining'), utils.formatDuration(metrics.etaSeconds)],
						[t('Laufzeit', 'Elapsed time'), utils.formatDuration(metrics.elapsedSeconds)],
						[t('Aktuelle Phase', 'Current phase'), phaseName],
					].map(([label, value]) => h('div', { key: label }, h('small', null, label), h('strong', null, value)))
				),
				h('ol', { className: 'tds-phases' }, Object.entries(phaseLabels).map(([key, value]) =>
					h('li', { key, className: key === currentPhase ? 'is-current' : value[1] < phaseIndex ? 'is-complete' : '' }, h('span', null, value[1] < phaseIndex ? '✓' : value[1]), value[0])
				)),
				active && h('div', { className: 'tds-actions' },
					job.status !== 'paused' && h(Button, { variant: 'secondary', disabled: controlBusy, onClick: () => control('pause') }, t('Pause', 'Pause')),
					job.status === 'paused' && h(Button, { variant: 'primary', disabled: controlBusy, onClick: () => control('resume') }, t('Fortsetzen', 'Resume')),
					h(Button, { isDestructive: true, disabled: controlBusy, onClick: () => control('cancel') }, t('Abbrechen', 'Cancel'))
				),
				utils.canRollbackJob(job.status) && h('div', { className: 'tds-actions' },
					h(Button, { isDestructive: true, disabled: controlBusy, onClick: startRollback }, t('Import zurückrollen', 'Roll back import'))
				)
			)),
			recentWarnings.length > 0 && h(Card, { className: 'tds-warning-card' }, h(CardBody, null,
				h('h3', null, t('Letzte Warnungen', 'Recent warnings')),
				h('ul', { className: 'tds-warning-list', 'aria-live': 'polite' }, recentWarnings.map((warning, index) => h('li', { key: warning.id || `${warning.message}-${index}` }, warning.message || String(warning))))
			)),
			h(Card, null, h(CardBody, null,
				h('h3', null, t('Letzte Meldungen', 'Latest messages')),
				job.logs?.length ? h('ul', { className: 'tds-log-list' }, job.logs.map((log) => h('li', { key: log.id }, h('time', null, log.created_at), h('span', { className: `is-${log.level}` }, log.level), h('span', null, log.message)))) : h('p', { className: 'description' }, t('Noch keine Meldungen.', 'No messages yet.'))
			))
		);
	}

	function Presets({ presets, targets, createDraft, reload, notify }) {
		const [editing, setEditing] = useState(null);
		if (editing) return h(PresetEditor, { preset: editing, targets, onClose: () => setEditing(null), reload, notify });
		const directStart = async (preset) => {
			try {
				await request('/jobs', { method: 'POST', data: { preset_id: preset.id } });
				notify({ status: 'success', text: t('Import wurde eingereiht.', 'Import queued.') }); await reload();
			} catch (error) { notify({ status: 'error', text: error.message }); }
		};
		const removePreset = async (preset) => {
			if (!window.confirm(t('Preset wirklich löschen?', 'Delete this preset?'))) return;
			try { await request(`/presets/${preset.id}`, { method: 'DELETE' }); await reload(); }
			catch (error) { notify({ status: 'error', text: error.message }); }
		};
		return h('div', null,
			h('div', { className: 'tds-toolbar' }, h('div', null, h('h2', null, t('Gespeicherte Presets', 'Saved presets')), h('p', null, t('Expertenoberfläche für wiederkehrende Importe.', 'Expert interface for recurring imports.'))),
				h(Button, { variant: 'secondary', onClick: () => setEditing({ name: '', enabled: true, config: emptyConfig() }) }, t('Preset anlegen', 'Create preset'))),
			h('div', { className: 'tds-card-grid' }, presets.map((preset) => h(Card, { key: preset.id }, h(CardBody, null,
				h('h3', null, preset.name),
				h('p', null, `${String(preset.config.source.type).toUpperCase()} · ${String(preset.config.format).toUpperCase()} · ID: ${preset.config.identity}`),
				h('div', { className: 'tds-actions' },
					h(Button, { variant: 'primary', onClick: () => createDraft(preset.id) }, t('Schrittweise importieren', 'Guided import')),
					h(Button, { variant: 'secondary', onClick: () => setEditing(preset) }, t('Bearbeiten', 'Edit')),
					h(Button, { variant: 'tertiary', onClick: () => directStart(preset) }, t('Direkt starten', 'Start directly')),
					h(Button, { isDestructive: true, onClick: () => removePreset(preset) }, t('Löschen', 'Delete'))
				)
			))))
		);
	}

	function PresetEditor({ preset, targets, onClose, reload, notify }) {
		const [model, setModel] = useState(() => hydrateModel(preset));
		const [busy, setBusy] = useState(false);
		const config = model.config;
		const setConfig = (patch) => setModel({ ...model, config: { ...config, ...patch } });
		const save = async (preflight = false) => {
			setBusy(true);
			try {
				const saved = await request(model.id ? `/presets/${model.id}` : '/presets', { method: model.id ? 'PUT' : 'POST', data: model });
				setModel(hydrateModel(saved)); await reload();
				if (preflight) {
					const result = await request(`/preflight/${saved.id}`, { method: 'POST' });
					notify({ status: result.valid ? 'success' : 'warning', text: result.valid ? t('Preflight erfolgreich.', 'Preflight successful.') : (result.errors || []).join(' ') });
				} else notify({ status: 'success', text: t('Preset gespeichert.', 'Preset saved.') });
			} catch (error) { notify({ status: 'error', text: error.message }); }
			finally { setBusy(false); }
		};
		return h('div', null,
			h('div', { className: 'tds-toolbar' }, h('h2', null, model.id ? model.name : t('Neues Preset', 'New preset')),
				h('div', { className: 'tds-actions' }, h(Button, { variant: 'tertiary', onClick: onClose }, t('Zurück', 'Back')), h(Button, { variant: 'secondary', disabled: busy, onClick: () => save(false) }, t('Speichern', 'Save')), h(Button, { variant: 'primary', disabled: busy, onClick: () => save(true) }, t('Speichern & Preflight', 'Save & preflight')))),
			h('div', { className: 'tds-editor-grid' },
				h(Card, null, h(CardBody, null, h('h3', null, t('Allgemein', 'General')),
					input(t('Name', 'Name'), model.name, (name) => setModel({ ...model, name })),
					h(ToggleControl, { label: t('Preset aktiv', 'Preset enabled'), checked: !!model.enabled, onChange: (enabled) => setModel({ ...model, enabled }) }),
					h(SelectControl, { label: t('Identifikator', 'Identifier'), value: config.identity, options: [{ label: 'SKU', value: 'sku' }, { label: t('Externe ID', 'External ID'), value: 'external_id' }], onChange: (identity) => setConfig({ identity, identity_field: identity }) }),
					input(t('Batchgröße', 'Batch size'), config.batch_size, (value) => setConfig({ batch_size: Number(value) }), 'number')
				)),
				h(Card, null, h(CardBody, null, h('h3', null, t('Quelle', 'Source')),
					h(SelectControl, { label: t('Quellentyp', 'Source type'), value: config.source.type, options: [{ label: t('Datei-Upload', 'File upload'), value: 'upload' }, { label: 'HTTPS', value: 'https' }, { label: 'SFTP', value: 'sftp' }], onChange: (type) => setConfig({ source: { ...config.source, type } }) }),
					config.source.type === 'upload' && input(t('Gespeicherter Pfad', 'Stored path'), config.source.upload_path, (upload_path) => setConfig({ source: { ...config.source, upload_path } })),
					config.source.type === 'https' && input('HTTPS-URL', config.source.url, (url) => setConfig({ source: { ...config.source, url } }), 'url'),
					config.source.type === 'sftp' && h('div', null, input('Host', config.source.host, (host) => setConfig({ source: { ...config.source, host } })), input(t('Remote-Pfad', 'Remote path'), config.source.remote_path, (remote_path) => setConfig({ source: { ...config.source, remote_path } })), input('Fingerprint', config.source.fingerprint, (fingerprint) => setConfig({ source: { ...config.source, fingerprint } }))),
					h(SelectControl, { label: t('Format', 'Format'), value: config.format, options: [{ label: t('Automatisch', 'Auto'), value: 'auto' }, { label: 'CSV', value: 'csv' }, { label: 'XML', value: 'xml' }], onChange: (format) => setConfig({ format }) })
				)),
				h(Card, null, h(CardBody, null, h('h3', null, t('Zeitplan', 'Schedule')),
					h(ToggleControl, { label: t('Automatischen Import aktivieren', 'Enable scheduled import'), checked: !!config.schedule.enabled, onChange: (enabled) => setConfig({ schedule: { ...config.schedule, enabled } }) }),
					h(SelectControl, { label: t('Intervall', 'Interval'), value: config.schedule.period, options: [{ label: t('Stündlich', 'Hourly'), value: 'hourly' }, { label: t('Täglich', 'Daily'), value: 'daily' }, { label: t('Wöchentlich', 'Weekly'), value: 'weekly' }], onChange: (period) => setConfig({ schedule: { ...config.schedule, period } }) }),
					input(t('Fehler-E-Mail', 'Error email'), config.email, (email) => setConfig({ email }), 'email')
				))
			),
			h(MappingStep, { model, preview: { fields: [] }, targets, suggestions: [], setConfig })
		);
	}

	function Jobs({ jobs, reload, notify, openJob }) {
		const control = async (job, action) => {
			try {
				const path = action === 'rollback' ? `/jobs/${job.id}/rollback` : `/jobs/${job.id}/control`;
				await request(path, { method: 'POST', data: action === 'rollback' ? {} : { action } }); await reload();
			} catch (error) { notify({ status: 'error', text: error.message }); }
		};
		return h('div', null,
			h('div', { className: 'tds-toolbar' }, h('h2', null, t('Import- und Rollback-Jobs', 'Import and rollback jobs')), h(Button, { onClick: reload }, t('Aktualisieren', 'Refresh'))),
			h('div', { className: 'tds-table-scroll' }, h('table', { className: 'widefat striped tds-jobs' },
				h('caption', { className: 'screen-reader-text' }, t('Import- und Rollback-Jobs', 'Import and rollback jobs')),
				h('thead', null, h('tr', null, ['ID', t('Preset', 'Preset'), t('Status', 'Status'), t('Phase', 'Phase'), t('Fortschritt', 'Progress'), t('Aktionen', 'Actions')].map((label) => h('th', { key: label, scope: 'col' }, label)))),
				h('tbody', null, jobs.map((job) => h('tr', { key: job.id },
					h('td', null, job.id), h('td', null, job.preset_name || job.preset_id), h('td', null, h('span', { className: `tds-status tds-status-${job.status}` }, job.status)),
					h('td', null, job.phase), h('td', null, `${job.processed}/${job.total}`),
					h('td', null, h('div', { className: 'tds-actions' },
						h(Button, { variant: 'secondary', onClick: () => openJob(job.id) }, t('Live anzeigen', 'View live')),
						['queued', 'running'].includes(job.status) && h(Button, { onClick: () => control(job, 'pause') }, t('Pause', 'Pause')),
						job.status === 'paused' && h(Button, { onClick: () => control(job, 'resume') }, t('Fortsetzen', 'Resume')),
						utils.canRollbackJob(job.status) && h(Button, { isDestructive: true, onClick: () => control(job, 'rollback') }, t('Rollback', 'Rollback'))
					))
				)))
			))
		);
	}

	function Help() {
		return h(Card, null, h(CardBody, null,
			h('h2', null, t('Sicherer Ablauf', 'Safe workflow')),
			h('ol', null,
				h('li', null, t('Quelle verbinden und erkannte Struktur bestätigen.', 'Connect the source and confirm its detected structure.')),
				h('li', null, t('Mapping-Vorschläge prüfen und Importregeln festlegen.', 'Review mapping suggestions and configure import rules.')),
				h('li', null, t('Preflight ausführen und den Hintergrundjob beobachten.', 'Run preflight and monitor the background job.')),
				h('li', null, t('Fehler prüfen und bei Bedarf innerhalb der Aufbewahrungsfrist zurückrollen.', 'Review errors and roll back within the retention window if needed.'))
			)
		));
	}

	const root = document.getElementById('tds-importer-admin');
	if (wp.element.createRoot) wp.element.createRoot(root).render(h(App));
	else wp.element.render(h(App), root);
}(window.wp));
