(function (wp) {
	'use strict';

	const rawH = wp.element.createElement;
	const isGerman = (document.documentElement.lang || '').toLowerCase().startsWith('de');
	const translations = {
		'Importer wird geladen …': 'Loading importer …',
		'CSV/XML-Importe für große WooCommerce-Kataloge': 'CSV/XML imports for large WooCommerce catalogs',
		'Presets': 'Presets',
		'Jobs': 'Jobs',
		'Hilfe': 'Help',
		'Gespeicherte Presets': 'Saved presets',
		'Preset anlegen': 'Create preset',
		'Noch kein Preset vorhanden.': 'No preset exists yet.',
		'Kein Zeitplan': 'No schedule',
		'Bearbeiten': 'Edit',
		'Import starten': 'Start import',
		'Löschen': 'Delete',
		'Preset wirklich löschen?': 'Delete this preset?',
		'Neues Preset': 'New preset',
		'Zurück': 'Back',
		'Speichern': 'Save',
		'Speichern & Preflight': 'Save & preflight',
		'Allgemein': 'General',
		'Name': 'Name',
		'Preset aktiv': 'Preset enabled',
		'Identifikator': 'Identifier',
		'Externe ID': 'External ID',
		'Fehlende Produkte': 'Missing products',
		'Unverändert': 'Keep unchanged',
		'Entwurf': 'Draft',
		'Nicht vorrätig': 'Out of stock',
		'Papierkorb': 'Trash',
		'Fehler-E-Mail': 'Error email',
		'Rollback-Aufbewahrung (Tage)': 'Rollback retention (days)',
		'Batchgröße': 'Batch size',
		'Quelle': 'Source',
		'Quellentyp': 'Source type',
		'Datei-Upload': 'File upload',
		'Basic-Auth-Benutzer': 'Basic Auth username',
		'Basic-Auth-Passwort': 'Basic Auth password',
		'Benutzer': 'Username',
		'Passwort / Key-Passphrase': 'Password / key passphrase',
		'Privater Schlüssel (optional)': 'Private key (optional)',
		'Remote-Pfad': 'Remote path',
		'Host-Key-Fingerprint (SHA-256/MD5 hex)': 'Host key fingerprint (OpenSSH SHA256 or MD5)',
		'Format': 'Format',
		'Automatisch': 'Auto detect',
		'XML-Datensatzpfad (z. B. /catalog/product)': 'XML record path (for example /catalog/product)',
		'CSV-Trennzeichen': 'CSV delimiter',
		'Kodierung': 'Encoding',
		'Zeitplan': 'Schedule',
		'Automatischen Import aktivieren': 'Enable scheduled import',
		'Intervall': 'Interval',
		'Stündlich': 'Hourly',
		'Täglich': 'Daily',
		'Wöchentlich': 'Weekly',
		'Lokale Uhrzeit': 'Local time',
		'Wochentag': 'Weekday',
		'Sonntag': 'Sunday',
		'Montag': 'Monday',
		'Dienstag': 'Tuesday',
		'Mittwoch': 'Wednesday',
		'Donnerstag': 'Thursday',
		'Freitag': 'Friday',
		'Samstag': 'Saturday',
		'Mapping & Formeln': 'Mapping & formulas',
		'Felder mit Leerzeichen in Formeln als [Feldname] schreiben.': 'Write fields containing spaces as [Field name] in formulas.',
		'Mapping hinzufügen': 'Add mapping',
		'Ziel': 'Target',
		'Quellfeld': 'Source field',
		'Visueller Assistent / Formel': 'Visual assistant / formula',
		'Leerwert': 'Empty value',
		'Regel wählen …': 'Choose rule …',
		'Direkte Zuordnung': 'Direct mapping',
		'Leerraum entfernen': 'Trim whitespace',
		'Großschreibung': 'Uppercase',
		'Kleinschreibung': 'Lowercase',
		'Deutsche Zahl': 'Localized number',
		'Fallback': 'Fallback',
		'Bedingung': 'Condition',
		'Direkt: ': 'Direct: ',
		'Behalten': 'Keep',
		'Leeren': 'Clear',
		'Standard': 'Default',
		'Standardwert': 'Default value',
		'Preflight erfolgreich': 'Preflight successful',
		'Preflight-Fehler': 'Preflight errors',
		'Nr.': 'No.',
		'Quelldatensatz': 'Source record',
		'Mapping-Ergebnis': 'Mapping result',
		'Import- und Rollback-Jobs': 'Import and rollback jobs',
		'Aktualisieren': 'Refresh',
		'Preset': 'Preset',
		'Status': 'Status',
		'Phase': 'Phase',
		'Fortschritt': 'Progress',
		'Erstellt': 'Created',
		'Aktualisiert': 'Updated',
		'Fehler': 'Errors',
		'Aktionen': 'Actions',
		'Pause': 'Pause',
		'Fortsetzen': 'Resume',
		'Abbruch': 'Cancel',
		'Rollback starten? Neuere Produktänderungen werden geschützt.': 'Start rollback? Newer product changes will be protected.',
		'Rollback': 'Rollback',
		'Sicherer Ablauf': 'Safe workflow',
		'Quelle konfigurieren und Mapping anlegen.': 'Configure a source and create the mapping.',
		'Preset speichern und den verpflichtenden Preflight ausführen.': 'Save the preset and run the required preflight.',
		'Import starten und den Jobstatus beobachten.': 'Start the import and monitor its job status.',
		'Fehlerprotokoll prüfen; bei Bedarf innerhalb der Aufbewahrungsfrist zurückrollen.': 'Review the error log and roll back within the retention window if necessary.',
		'Formelbeispiele: ': 'Formula examples: ',
		'Hinweis: Für zuverlässige automatische Läufe sollte WordPress-Cron serverseitig regelmäßig ausgelöst werden.': 'Note: For reliable scheduled runs, trigger WordPress Cron regularly from the server.',
	};
	const tr = (value) => {
		if (isGerman || typeof value !== 'string') return value;
		if (translations[value]) return translations[value];
		return value
			.replace(/^Gespeichert: /, 'Stored: ')
			.replace(/ wurde geschützt gespeichert\.$/, ' was stored securely.')
			.replace(/^Zeitplan: /, 'Schedule: ')
			.replace(/^Import wurde eingereiht\.$/, 'Import was queued.')
			.replace(/^Preset wurde gespeichert\.$/, 'Preset was saved.')
			.replace(/^Preflight enthält Fehler\.$/, 'Preflight contains errors.');
	};
	const h = (type, props, ...children) => {
		const translatedProps = props ? { ...props } : props;
		if (translatedProps) {
			for (const key of ['label', 'placeholder', 'title']) {
				if (typeof translatedProps[key] === 'string') translatedProps[key] = tr(translatedProps[key]);
			}
			if (Array.isArray(translatedProps.options)) {
				translatedProps.options = translatedProps.options.map((option) => typeof option === 'object' ? { ...option, label: tr(option.label) } : option);
			}
		}
		return rawH(type, translatedProps, ...children.map((child) => typeof child === 'string' ? tr(child) : child));
	};
	const { useEffect, useMemo, useState } = wp.element;
	const {
		Button, Card, CardBody, CheckboxControl, Notice, SelectControl, Spinner,
		TabPanel, TextControl, TextareaControl, ToggleControl,
	} = wp.components;
	const api = wp.apiFetch;
	api.use(api.createNonceMiddleware(window.tdsImporter.nonce));

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
		mappings: [
			{ target: 'sku', source: 'sku', expression: '', ast: null, empty: 'keep', default: '' },
			{ target: 'name', source: 'name', expression: '', ast: null, empty: 'keep', default: '' },
		],
		missing_policy: 'keep',
		schedule: { enabled: false, period: 'daily', time: '02:00', weekday: 1 },
		email: '',
		retention_days: 30,
		batch_size: 50,
	});

	const request = (path, options = {}) => api({ path: '/tds-import/v1' + path, ...options });
	const field = (label, value, onChange, type = 'text', props = {}) =>
		h(TextControl, { label, value: value ?? '', type, onChange, ...props });
	const sourceRef = (name) => `[${String(name || '').replaceAll(']', '')}]`;

	function App() {
		const [presets, setPresets] = useState([]);
		const [jobs, setJobs] = useState([]);
		const [targets, setTargets] = useState({ core: [], acf: [] });
		const [busy, setBusy] = useState(true);
		const [notice, setNotice] = useState(null);

		const load = async () => {
			try {
				const [presetRows, jobRows, targetRows] = await Promise.all([
					request('/presets'), request('/jobs'), request('/targets'),
				]);
				setPresets(presetRows); setJobs(jobRows); setTargets(targetRows);
			} catch (error) {
				setNotice({ status: 'error', text: error.message });
			} finally { setBusy(false); }
		};
		useEffect(() => {
			load();
			const timer = setInterval(async () => {
				try { setJobs(await request('/jobs')); } catch (_) { /* keep current state */ }
			}, 5000);
			return () => clearInterval(timer);
		}, []);

		if (busy) return h('div', { className: 'tds-loading' }, h(Spinner), ' Importer wird geladen …');
		return h('div', { className: 'tds-app' },
			h('div', { className: 'tds-header' },
				h('div', null, h('h1', null, 'TDS Product Importer'), h('p', null, 'CSV/XML-Importe für große WooCommerce-Kataloge')),
				h('span', { className: 'tds-version' }, 'v' + window.tdsImporter.version)
			),
			notice && h(Notice, { status: notice.status, onRemove: () => setNotice(null) }, notice.text),
			h(TabPanel, {
				className: 'tds-tabs',
				tabs: [
					{ name: 'presets', title: 'Presets' },
					{ name: 'jobs', title: 'Jobs' },
					{ name: 'help', title: 'Hilfe' },
				],
			}, (tab) => tab.name === 'presets'
				? h(Presets, { presets, targets, reload: load, notify: setNotice })
				: tab.name === 'jobs'
					? h(Jobs, { jobs, reload: load, notify: setNotice })
					: h(Help))
		);
	}

	function Presets({ presets, targets, reload, notify }) {
		const [editing, setEditing] = useState(null);
		if (editing) {
			return h(PresetEditor, {
				preset: editing,
				targets,
				onClose: () => setEditing(null),
				onSaved: async () => { await reload(); },
				notify,
			});
		}
		return h('div', null,
			h('div', { className: 'tds-toolbar' },
				h('h2', null, 'Gespeicherte Presets'),
				h(Button, {
					variant: 'primary',
					onClick: () => setEditing({ name: '', enabled: true, config: emptyConfig() }),
				}, 'Preset anlegen')
			),
			presets.length === 0
				? h(Card, null, h(CardBody, null, 'Noch kein Preset vorhanden.'))
				: h('div', { className: 'tds-card-grid' }, presets.map((preset) =>
					h(Card, { key: preset.id },
						h(CardBody, null,
							h('h3', null, preset.name),
							h('p', null, `${preset.config.source.type.toUpperCase()} · ${preset.config.format.toUpperCase()} · ID: ${preset.config.identity}`),
							h('p', { className: 'description' }, preset.config.schedule.enabled ? `Zeitplan: ${preset.config.schedule.period}` : 'Kein Zeitplan'),
							h('div', { className: 'tds-actions' },
								h(Button, { variant: 'secondary', onClick: () => setEditing(preset) }, 'Bearbeiten'),
								h(Button, {
									variant: 'primary',
									onClick: async () => {
										try {
											await request('/jobs', { method: 'POST', data: { preset_id: preset.id } });
											notify({ status: 'success', text: 'Import wurde eingereiht.' });
											await reload();
										} catch (error) { notify({ status: 'error', text: error.message }); }
									},
								}, 'Import starten'),
								h(Button, {
									isDestructive: true,
									onClick: async () => {
										if (!window.confirm('Preset wirklich löschen?')) return;
										try { await request(`/presets/${preset.id}`, { method: 'DELETE' }); await reload(); }
										catch (error) { notify({ status: 'error', text: error.message }); }
									},
								}, 'Löschen')
							)
						)
					)
				))
		);
	}

	function PresetEditor({ preset, targets, onClose, onSaved, notify }) {
		const [model, setModel] = useState(JSON.parse(JSON.stringify(preset)));
		const [saving, setSaving] = useState(false);
		const [preflight, setPreflight] = useState(null);
		const [mappedPreview, setMappedPreview] = useState([]);
		const config = model.config;
		const sourceFields = useMemo(() => {
			const first = preflight?.samples?.[0]?.raw || {};
			return Object.keys(first);
		}, [preflight]);
		const updateConfig = (key, value) => setModel({ ...model, config: { ...config, [key]: value } });
		const updateSource = (key, value) => updateConfig('source', { ...config.source, [key]: value });
		const updateSchedule = (key, value) => updateConfig('schedule', { ...config.schedule, [key]: value });

		useEffect(() => {
			if (!preflight?.samples?.length) return;
			const timer = setTimeout(async () => {
				try {
					const values = await request('/map-preview', {
						method: 'POST',
						data: { config, records: preflight.samples.map((row) => row.raw) },
					});
					setMappedPreview(values);
				} catch (_) { /* formula errors stay visible on preflight */ }
			}, 350);
			return () => clearTimeout(timer);
		}, [JSON.stringify(config.mappings)]);

		const save = async () => {
			setSaving(true);
			try {
				const saved = await request(model.id ? `/presets/${model.id}` : '/presets', { method: model.id ? 'PUT' : 'POST', data: model });
				setModel(saved); await onSaved();
				notify({ status: 'success', text: 'Preset wurde gespeichert.' });
				return saved;
			} catch (error) { notify({ status: 'error', text: error.message }); return null; }
			finally { setSaving(false); }
		};
		const runPreflight = async () => {
			const saved = await save();
			if (!saved) return;
			setSaving(true);
			try {
				const result = await request(`/preflight/${saved.id}`, { method: 'POST' });
				setPreflight(result);
				setMappedPreview(result.samples.map((sample) => sample.result));
				notify({ status: result.valid ? 'success' : 'warning', text: result.valid ? 'Preflight erfolgreich.' : 'Preflight enthält Fehler.' });
			} catch (error) { notify({ status: 'error', text: error.message }); }
			finally { setSaving(false); }
		};
		const upload = async (event) => {
			const selected = event.target.files?.[0];
			if (!selected) return;
			const body = new FormData(); body.append('source', selected);
			setSaving(true);
			try {
				const result = await api({ path: '/tds-import/v1/upload', method: 'POST', body });
				updateSource('upload_path', result.path);
				notify({ status: 'success', text: `${selected.name} wurde geschützt gespeichert.` });
			} catch (error) { notify({ status: 'error', text: error.message }); }
			finally { setSaving(false); }
		};

		return h('div', null,
			h('div', { className: 'tds-toolbar' }, h('h2', null, model.id ? `Preset: ${model.name}` : 'Neues Preset'),
				h('div', null, h(Button, { variant: 'tertiary', onClick: onClose }, 'Zurück'), ' ',
					h(Button, { variant: 'secondary', disabled: saving, onClick: save }, saving ? h(Spinner) : 'Speichern'), ' ',
					h(Button, { variant: 'primary', disabled: saving, onClick: runPreflight }, 'Speichern & Preflight'))),
			h('div', { className: 'tds-editor-grid' },
				h(Card, null, h(CardBody, null,
					h('h3', null, 'Allgemein'),
					field('Name', model.name, (name) => setModel({ ...model, name })),
					h(ToggleControl, { label: 'Preset aktiv', checked: !!model.enabled, onChange: (enabled) => setModel({ ...model, enabled }) }),
					h(SelectControl, { label: 'Identifikator', value: config.identity, options: [{ label: 'SKU', value: 'sku' }, { label: 'Externe ID', value: 'external_id' }], onChange: (v) => updateConfig('identity', v) }),
					h(SelectControl, { label: 'Fehlende Produkte', value: config.missing_policy, options: [
						{ label: 'Unverändert', value: 'keep' }, { label: 'Entwurf', value: 'draft' },
						{ label: 'Nicht vorrätig', value: 'outofstock' }, { label: 'Papierkorb', value: 'trash' },
					], onChange: (v) => updateConfig('missing_policy', v) }),
					field('Fehler-E-Mail', config.email, (v) => updateConfig('email', v), 'email'),
					field('Rollback-Aufbewahrung (Tage)', config.retention_days, (v) => updateConfig('retention_days', Number(v)), 'number', { min: 7, max: 365 }),
					field('Batchgröße', config.batch_size, (v) => updateConfig('batch_size', Number(v)), 'number', { min: 10, max: 250 })
				)),
				h(Card, null, h(CardBody, null,
					h('h3', null, 'Quelle'),
					h(SelectControl, { label: 'Quellentyp', value: config.source.type, options: [
						{ label: 'Datei-Upload', value: 'upload' }, { label: 'HTTPS', value: 'https' }, { label: 'SFTP', value: 'sftp' },
					], onChange: (v) => updateSource('type', v) }),
					config.source.type === 'upload' && h('div', null,
						h('input', { type: 'file', accept: '.csv,.xml,text/csv,application/xml,text/xml', onChange: upload }),
						config.source.upload_path && h('p', { className: 'description' }, 'Gespeichert: ' + config.source.upload_path.split(/[\\/]/).pop())
					),
					config.source.type === 'https' && h('div', null,
						field('HTTPS-URL', config.source.url, (v) => updateSource('url', v), 'url'),
						field('Basic-Auth-Benutzer', config.source.basic_username, (v) => updateSource('basic_username', v)),
						field('Basic-Auth-Passwort', config.source.basic_password, (v) => updateSource('basic_password', v), 'password')
					),
					config.source.type === 'sftp' && h('div', null,
						field('Host', config.source.host, (v) => updateSource('host', v)),
						field('Port', config.source.port, (v) => updateSource('port', Number(v)), 'number'),
						field('Benutzer', config.source.username, (v) => updateSource('username', v)),
						field('Passwort / Key-Passphrase', config.source.password, (v) => updateSource('password', v), 'password'),
						h(TextareaControl, { label: 'Privater Schlüssel (optional)', value: config.source.private_key || '', onChange: (v) => updateSource('private_key', v) }),
						field('Remote-Pfad', config.source.remote_path, (v) => updateSource('remote_path', v)),
						field('Host-Key-Fingerprint (SHA-256/MD5 hex)', config.source.fingerprint, (v) => updateSource('fingerprint', v))
					),
					h(SelectControl, { label: 'Format', value: config.format, options: [{ label: 'Automatisch', value: 'auto' }, { label: 'CSV', value: 'csv' }, { label: 'XML', value: 'xml' }], onChange: (v) => updateConfig('format', v) }),
					(config.format === 'xml' || config.format === 'auto') && field('XML-Datensatzpfad (z. B. /catalog/product)', config.xml.record_path, (v) => updateConfig('xml', { ...config.xml, record_path: v })),
					(config.format === 'csv' || config.format === 'auto') && h('div', { className: 'tds-inline' },
						field('CSV-Trennzeichen', config.csv.delimiter, (v) => updateConfig('csv', { ...config.csv, delimiter: v })),
						h(SelectControl, { label: 'Kodierung', value: config.csv.encoding, options: [{ label: 'Automatisch', value: 'auto' }, { label: 'UTF-8', value: 'UTF-8' }, { label: 'Windows-1252', value: 'Windows-1252' }], onChange: (v) => updateConfig('csv', { ...config.csv, encoding: v }) })
					)
				)),
				h(Card, null, h(CardBody, null,
					h('h3', null, 'Zeitplan'),
					h(ToggleControl, { label: 'Automatischen Import aktivieren', checked: !!config.schedule.enabled, onChange: (v) => updateSchedule('enabled', v) }),
					h(SelectControl, { label: 'Intervall', value: config.schedule.period, options: [{ label: 'Stündlich', value: 'hourly' }, { label: 'Täglich', value: 'daily' }, { label: 'Wöchentlich', value: 'weekly' }], onChange: (v) => updateSchedule('period', v) }),
					config.schedule.period !== 'hourly' && field('Lokale Uhrzeit', config.schedule.time, (v) => updateSchedule('time', v), 'time'),
					config.schedule.period === 'weekly' && h(SelectControl, { label: 'Wochentag', value: String(config.schedule.weekday), options: ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'].map((label, value) => ({ label, value: String(value) })), onChange: (v) => updateSchedule('weekday', Number(v)) })
				))
			),
			h(MappingEditor, { config, updateConfig, targets, sourceFields }),
			preflight && h(Preview, { preflight, mappedPreview })
		);
	}

	function MappingEditor({ config, updateConfig, targets, sourceFields }) {
		const mappings = config.mappings || [];
		const update = (index, patch) => updateConfig('mappings', mappings.map((row, i) => i === index ? { ...row, ...patch, ast: null } : row));
		const targetOptions = [...targets.core, ...targets.acf].map((value) => ({ label: value, value }));
		return h(Card, { className: 'tds-mappings' }, h(CardBody, null,
			h('div', { className: 'tds-toolbar' }, h('div', null, h('h3', null, 'Mapping & Formeln'), h('p', { className: 'description' }, 'Felder mit Leerzeichen in Formeln als [Feldname] schreiben.')),
				h(Button, { variant: 'secondary', onClick: () => updateConfig('mappings', [...mappings, { target: '', source: '', expression: '', ast: null, empty: 'keep', default: '' }]) }, 'Mapping hinzufügen')),
			h('div', { className: 'tds-mapping-head' }, h('strong', null, 'Ziel'), h('strong', null, 'Quellfeld'), h('strong', null, 'Visueller Assistent / Formel'), h('strong', null, 'Leerwert'), h('span')),
			mappings.map((row, index) => h('div', { className: 'tds-mapping-row', key: index },
				h('div', null,
					h('input', { className: 'regular-text', list: 'tds-targets', value: row.target, placeholder: 'z. B. regular_price', onChange: (e) => update(index, { target: e.target.value }) }),
					index === 0 && h('datalist', { id: 'tds-targets' }, targetOptions.map((o) => h('option', { key: o.value, value: o.value })))
				),
				h('select', { value: row.source, onChange: (e) => update(index, { source: e.target.value }) },
					h('option', { value: '' }, '—'), sourceFields.map((name) => h('option', { value: name, key: name }, name))),
				h('div', { className: 'tds-expression' },
					h('select', {
						value: '',
						onChange: (e) => {
							const ref = sourceRef(row.source);
							const templates = { direct: '', trim: `trim(${ref})`, upper: `upper(${ref})`, lower: `lower(${ref})`, number: `number(${ref}, ",", ".")`, default: `coalesce(${ref}, "${row.default || ''}")`, condition: `if(${ref} == "1", "yes", "no")` };
							update(index, { expression: templates[e.target.value] ?? row.expression });
						},
					}, h('option', { value: '' }, 'Regel wählen …'),
						h('option', { value: 'direct' }, 'Direkte Zuordnung'),
						h('option', { value: 'trim' }, 'Leerraum entfernen'),
						h('option', { value: 'upper' }, 'Großschreibung'),
						h('option', { value: 'lower' }, 'Kleinschreibung'),
						h('option', { value: 'number' }, 'Deutsche Zahl'),
						h('option', { value: 'default' }, 'Fallback'),
						h('option', { value: 'condition' }, 'Bedingung')),
					h('input', { className: 'large-text code', value: row.expression || '', placeholder: row.source ? 'Direkt: ' + row.source : 'concat([brand], " ", [name])', onChange: (e) => update(index, { expression: e.target.value }) })
				),
				h('div', null,
					h('select', { value: row.empty, onChange: (e) => update(index, { empty: e.target.value }) },
						h('option', { value: 'keep' }, 'Behalten'), h('option', { value: 'clear' }, 'Leeren'), h('option', { value: 'default' }, 'Standard')),
					row.empty === 'default' && h('input', { value: row.default || '', placeholder: 'Standardwert', onChange: (e) => update(index, { default: e.target.value }) })
				),
				h(Button, { isDestructive: true, onClick: () => updateConfig('mappings', mappings.filter((_, i) => i !== index)) }, '×')
			))
		));
	}

	function Preview({ preflight, mappedPreview }) {
		return h(Card, { className: 'tds-preview' }, h(CardBody, null,
			h('h3', null, preflight.valid ? 'Preflight erfolgreich' : 'Preflight-Fehler'),
			preflight.errors?.length > 0 && h('ul', { className: 'tds-errors' }, preflight.errors.map((error, i) => h('li', { key: i }, error))),
			preflight.samples?.length > 0 && h('div', { className: 'tds-preview-scroll' },
				h('table', { className: 'widefat striped' }, h('thead', null, h('tr', null, h('th', null, 'Nr.'), h('th', null, 'Quelldatensatz'), h('th', null, 'Mapping-Ergebnis'))),
					h('tbody', null, preflight.samples.map((sample, i) => h('tr', { key: i }, h('td', null, i + 1), h('td', null, h('pre', null, JSON.stringify(sample.raw, null, 2))), h('td', null, h('pre', null, JSON.stringify(mappedPreview[i] || sample.result, null, 2)))))))
			)
		));
	}

	function Jobs({ jobs, reload, notify }) {
		const act = async (job, action) => {
			try {
				const path = action === 'rollback' ? `/jobs/${job.id}/rollback` : `/jobs/${job.id}/control`;
				await request(path, { method: 'POST', data: action === 'rollback' ? {} : { action } });
				await reload();
			} catch (error) { notify({ status: 'error', text: error.message }); }
		};
		const logs = async (job) => {
			try {
				const rows = await request(`/jobs/${job.id}/logs`);
				const csv = ['Zeit,Level,Code,Nachricht', ...rows.map((r) => [r.created_at, r.level, r.code || '', r.message].map((v) => `"${String(v).replaceAll('"', '""')}"`).join(','))].join('\n');
				const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
				const link = document.createElement('a'); link.href = url; link.download = `tds-import-${job.id}-log.csv`; link.click(); URL.revokeObjectURL(url);
			} catch (error) { notify({ status: 'error', text: error.message }); }
		};
		return h('div', null,
			h('div', { className: 'tds-toolbar' }, h('h2', null, 'Import- und Rollback-Jobs'), h(Button, { onClick: reload }, 'Aktualisieren')),
			h('table', { className: 'widefat striped tds-jobs' },
				h('thead', null, h('tr', null, ['ID', 'Preset', 'Status', 'Phase', 'Fortschritt', 'Erstellt', 'Aktualisiert', 'Fehler', 'Aktionen'].map((x) => h('th', { key: x }, x)))),
				h('tbody', null, jobs.map((job) => h('tr', { key: job.id },
					h('td', null, job.id), h('td', null, job.preset_name || job.preset_id),
					h('td', null, h('span', { className: 'tds-status tds-status-' + job.status }, job.status)),
					h('td', null, job.phase), h('td', null, `${job.processed}/${job.total || '?'}`),
					h('td', null, job.created), h('td', null, job.updated), h('td', null, job.failed),
					h('td', null,
						['queued', 'running'].includes(job.status) && h(Button, { size: 'small', onClick: () => act(job, 'pause') }, 'Pause'),
						job.status === 'paused' && h(Button, { size: 'small', onClick: () => act(job, 'resume') }, 'Fortsetzen'),
						['queued', 'running', 'paused'].includes(job.status) && h(Button, { size: 'small', isDestructive: true, onClick: () => act(job, 'cancel') }, 'Abbruch'),
						['completed', 'partial', 'failed', 'cancelled'].includes(job.status) && h(Button, { size: 'small', onClick: () => window.confirm('Rollback starten? Neuere Produktänderungen werden geschützt.') && act(job, 'rollback') }, 'Rollback'),
						h(Button, { size: 'small', variant: 'link', onClick: () => logs(job) }, 'Log CSV')
					)
				)))
			)
		);
	}

	function Help() {
		return h(Card, null, h(CardBody, null,
			h('h2', null, 'Sicherer Ablauf'),
			h('ol', null, h('li', null, 'Quelle konfigurieren und Mapping anlegen.'), h('li', null, 'Preset speichern und den verpflichtenden Preflight ausführen.'), h('li', null, 'Import starten und den Jobstatus beobachten.'), h('li', null, 'Fehlerprotokoll prüfen; bei Bedarf innerhalb der Aufbewahrungsfrist zurückrollen.')),
			h('p', null, 'Formelbeispiele: ', h('code', null, 'concat([brand], " ", [name])'), ', ', h('code', null, 'if([stock] > 0, "instock", "outofstock")'), ', ', h('code', null, 'number([price], ",", ".")')),
			h('p', null, 'Hinweis: Für zuverlässige automatische Läufe sollte WordPress-Cron serverseitig regelmäßig ausgelöst werden.')
		));
	}

	wp.element.createRoot(document.getElementById('tds-importer-admin')).render(h(App));
})(window.wp);
