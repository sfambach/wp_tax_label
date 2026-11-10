/**
 * Taxmod Label — the label editor (docs/konzept.md §6). Plain JavaScript, no build, no CDN (D-1292).
 *
 * The drawing always comes from the server (one renderer for preview and print, §5): this script only holds the document
 * {label, elements}, moves boxes over the drawing and asks /preview again after every change.
 */
(function () {
	'use strict';

	const root = document.getElementById('taxmod-label-editor');
	if (!root) {
		return;
	}
	const setup = JSON.parse(root.dataset.setup || '{}');
	const { __, sprintf } = window.wp && window.wp.i18n ? window.wp.i18n : { __: (s) => s, sprintf: (s, ...a) => a.reduce((t, v) => t.replace(/%(\d\$)?[sd]/, v), s) };
	const T = (s) => __(s, 'taxmod-label');

	const GRID = 0.5;
	const TYPES = {
		text: { label: T('Text'), w: 24, h: 6, extra: { field: 'title', pt: 10 } },
		qr: { label: T('QR'), w: 15, h: 15, extra: { field: 'code' } },
		datamatrix: { label: T('DataMatrix'), w: 10, h: 10, extra: { field: 'code' } },
		code128: { label: T('Code128'), w: 40, h: 12, extra: { field: 'code' } },
		ean13: { label: T('EAN-13'), w: 38, h: 20, extra: { field: 'ean', example: '400638133393' } },
		code39: { label: T('Code39'), w: 50, h: 12, extra: { field: 'code' } },
		image: { label: T('Bild'), w: 15, h: 15, extra: {} },
		line: { label: T('Linie'), w: 30, h: 0.3, extra: {} },
		rect: { label: T('Rahmen'), w: 20, h: 10, extra: {} },
	};
	const CODES = ['qr', 'datamatrix', 'code128', 'ean13', 'code39'];
	const BARS = ['code128', 'ean13', 'code39'];

	const given = setup.label || {};
	const state = {
		id: Number(given.id || 0),
		name: String(given.name || ''),
		key: String(given.key || ''),
		node: Number(given.node || 0),
		rolls: (given.rolls || []).map(Number),
		doc: readDocument(given),
		selected: -1,
		rotation: 0,
		scale: 8,
		dpi: 203,
		problems: [],
		history: [],
		future: [],
		saved: '',
		svg: '',
	};
	state.saved = snapshot();

	/** The stored layout with the fields as its «label» head (§3): one document, each fact once. */
	function readDocument(label) {
		let parsed = null;
		try {
			parsed = label.layout ? JSON.parse(label.layout) : null;
		} catch (e) {
			parsed = null;
		}
		const elements = Array.isArray(parsed) ? parsed : parsed && Array.isArray(parsed.elements) ? parsed.elements : [];
		const head = parsed && !Array.isArray(parsed) && parsed.label ? parsed.label : {};
		return {
			label: {
				w: Number(label.width || head.w || 62),
				h: Number(label.height || head.h || 30),
				roll: Number(label.roll || head.roll || 0),
				copies: Number(label.copies || head.copies || 1),
			},
			elements: elements.map((e) => Object.assign({}, e)),
		};
	}

	function snapshot() {
		return JSON.stringify({ n: state.name, k: state.key, c: state.node, r: state.more || [], d: state.doc });
	}

	function documentJson() {
		const head = Object.assign({}, state.doc.label);
		if (!head.roll) {
			delete head.roll;
		}
		const lines = state.doc.elements.map((e) => '    ' + JSON.stringify(clean(e)));
		return '{\n  "label": ' + JSON.stringify(head) + ',\n  "elements": [\n' + lines.join(',\n') + (lines.length ? '\n' : '') + '  ]\n}';
	}

	function clean(element) {
		const out = {};
		Object.keys(element).forEach((k) => {
			const v = element[k];
			if (v !== '' && v !== null && v !== undefined) {
				out[k] = v;
			}
		});
		return out;
	}

	const round = (v) => Math.round(v / GRID) * GRID;
	const el = (tag, attrs, ...children) => {
		const node = document.createElement(tag);
		Object.entries(attrs || {}).forEach(([k, v]) => {
			if (k === 'class') {
				node.className = v;
			} else if (k.startsWith('on')) {
				node.addEventListener(k.slice(2), v);
			} else if (v !== false && v !== null && v !== undefined) {
				node.setAttribute(k, v === true ? '' : v);
			}
		});
		children.flat().forEach((c) => node.append(c instanceof Node ? c : document.createTextNode(String(c))));
		return node;
	};

	/* ---------- frame ---------- */

	const ui = {};
	const field = (label, control) => el('label', { class: 'tl-field' }, el('span', {}, label), control);

	ui.name = el('input', { type: 'text', value: state.name, required: true, class: 'regular-text' });
	ui.key = el('input', { type: 'text', value: state.key, pattern: '[A-Za-z0-9_\\-]+', size: 10 });
	ui.node = el('select', {});
	(setup.categories || []).forEach((c) => ui.node.append(el('option', { value: c.node, selected: Number(c.node) === state.node }, c.name || T('ohne Kategorie'))));
	ui.w = el('input', { type: 'number', min: 1, max: 500, step: 0.5, value: state.doc.label.w });
	ui.h = el('input', { type: 'number', min: 1, max: 500, step: 0.5, value: state.doc.label.h });
	ui.roll = el('input', { type: 'number', min: 0, max: 500, step: 1, value: state.doc.label.roll || '' });
	ui.copies = el('input', { type: 'number', min: 1, max: 50, step: 1, value: state.doc.label.copies });
	ui.dpi = el('select', {});
	const dpis = new Map([[203, '203 dpi']]);
	(setup.printers || []).forEach((p) => p.dpi && dpis.set(Number(p.dpi), p.dpi + ' dpi · ' + p.name));
	[300, 600].forEach((d) => dpis.has(d) || dpis.set(d, d + ' dpi'));
	dpis.forEach((text, value) => ui.dpi.append(el('option', { value }, text)));
	// The rolls only ever gain a label here (LabelChanges::update); taking one away is done at the roll.
	const allowing = (setup.rolls || []).filter((r) => state.rolls.includes(Number(r.id)));
	const others = (setup.rolls || []).filter((r) => !state.rolls.includes(Number(r.id)));
	ui.rolls = el('select', { multiple: true, size: Math.min(4, Math.max(2, others.length)) });
	others.forEach((r) => ui.rolls.append(el('option', { value: r.id }, r.name)));
	state.more = [];

	ui.save = el('button', { type: 'button', class: 'button button-primary', onclick: save }, T('Speichern'));
	ui.pdf = el('button', { type: 'button', class: 'button', onclick: pdf }, T('PDF'));
	ui.rotate = el('button', { type: 'button', class: 'button', title: T('Ansicht drehen (der Drucker dreht selbst)'), onclick: rotate }, '⟳ 90°');
	ui.undo = el('button', { type: 'button', class: 'button', onclick: undo, title: T('Rückgängig') }, '↶');
	ui.redo = el('button', { type: 'button', class: 'button', onclick: redo, title: T('Wiederherstellen') }, '↷');
	ui.zoom = el('input', { type: 'range', min: 3, max: 20, step: 1, value: state.scale, title: T('Zoom'), oninput: () => { state.scale = Number(ui.zoom.value); layoutStage(); } });
	ui.status = el('span', { class: 'tl-status', role: 'status', 'aria-live': 'polite' });

	let dragging = '';
	ui.palette = el('div', { class: 'tl-palette', 'aria-label': T('Elemente') });
	Object.entries(TYPES).forEach(([type, def]) => {
		const button = el('button', { type: 'button', class: 'button', draggable: true, 'data-type': type, title: T('Auf das Etikett ziehen oder klicken') }, def.label);
		button.addEventListener('dragstart', (ev) => { dragging = type; ev.dataTransfer.setData('text/plain', type); ev.dataTransfer.effectAllowed = 'copy'; });
		button.addEventListener('dragend', () => { dragging = ''; });
		button.addEventListener('click', () => add(type, 1, 1));
		ui.palette.append(button);
	});

	ui.drawing = el('img', { class: 'tl-drawing', alt: '' });
	ui.boxes = el('div', { class: 'tl-boxes' });
	ui.paper = el('div', { class: 'tl-paper' }, ui.drawing, ui.boxes);
	ui.stage = el('div', { class: 'tl-stage', tabindex: 0 }, ui.paper);
	ui.stage.addEventListener('dragover', (ev) => ev.preventDefault());
	ui.stage.addEventListener('drop', drop);
	ui.stage.addEventListener('keydown', keys);
	ui.stage.addEventListener('pointerdown', (ev) => {
		if (ev.target === ui.stage || ev.target === ui.drawing) {
			select(-1);
		}
	});

	ui.props = el('div', { class: 'tl-props' });
	ui.json = el('textarea', { class: 'tl-json code', spellcheck: false, rows: 18, 'aria-label': T('Layout als JSON') });
	ui.json.addEventListener('input', fromJson);
	ui.problems = el('ul', { class: 'tl-problems' });

	root.append(
		el('div', { class: 'tl-toolbar' }, field(T('Name'), ui.name), field(T('Größe-Schlüssel'), ui.key), field(T('Kategorie'), ui.node), ui.undo, ui.redo, ui.rotate, ui.zoom, ui.pdf, ui.save, ui.status),
		el('div', { class: 'tl-settings' }, field(T('Breite (mm)'), ui.w), field(T('Höhe (mm)'), ui.h), field(T('Rolle (mm)'), ui.roll), field(T('Anzahl'), ui.copies), field(T('Prüfen für'), ui.dpi), el('div', { class: 'tl-field' }, el('span', {}, T('Erlaubt auf')), el('span', { class: 'tl-allowing' }, allowing.map((r) => r.name).join(', ') || '—')), others.length ? field(T('Auch erlauben auf'), ui.rolls) : ''),
		ui.palette,
		el('div', { class: 'tl-work' },
			el('div', { class: 'tl-left' }, ui.stage, ui.problems, ui.props),
			el('div', { class: 'tl-right' }, el('h3', {}, T('Code')), ui.json)
		)
	);

	[[ui.w, 'w'], [ui.h, 'h'], [ui.roll, 'roll'], [ui.copies, 'copies']].forEach(([input, key]) => input.addEventListener('change', () => {
		change(() => { state.doc.label[key] = Number(input.value) || 0; });
	}));
	ui.name.addEventListener('change', () => change(() => { state.name = ui.name.value.trim(); }, false));
	ui.key.addEventListener('change', () => change(() => { state.key = ui.key.value.trim(); }, false));
	ui.node.addEventListener('change', () => change(() => { state.node = Number(ui.node.value); }, false));
	ui.rolls.addEventListener('change', () => change(() => { state.more = Array.from(ui.rolls.selectedOptions).map((o) => Number(o.value)); }, false));
	ui.dpi.addEventListener('change', () => { state.dpi = Number(ui.dpi.value); preview(); });
	window.addEventListener('beforeunload', (ev) => {
		if (snapshot() !== state.saved) {
			ev.preventDefault();
			ev.returnValue = '';
		}
	});

	/* ---------- changes and history ---------- */

	function change(work, redraw = true) {
		state.history.push(snapshot());
		if (state.history.length > 100) {
			state.history.shift();
		}
		state.future = [];
		work();
		render(redraw);
	}

	function restore(from, to) {
		if (!from.length) {
			return;
		}
		to.push(snapshot());
		const s = JSON.parse(from.pop());
		Object.assign(state, { name: s.n, key: s.k, node: s.c, more: s.r, doc: s.d });
		ui.name.value = state.name;
		ui.key.value = state.key;
		ui.node.value = String(state.node);
		Array.from(ui.rolls.options).forEach((o) => { o.selected = state.more.includes(Number(o.value)); });
		state.selected = Math.min(state.selected, state.doc.elements.length - 1);
		render(true);
	}

	function undo() {
		restore(state.history, state.future);
	}

	function redo() {
		restore(state.future, state.history);
	}

	function add(type, x, y) {
		const def = TYPES[type];
		const make = (media) => change(() => {
			const w = type === 'line' ? Math.max(1, state.doc.label.w - 2 * x) : def.w;
			const element = Object.assign({ type, x: round(x), y: round(y), w, h: def.h }, def.extra, media ? { media: 'media:' + media } : {});
			state.doc.elements.push(element);
			state.selected = state.doc.elements.length - 1;
		});
		if (type === 'image') {
			chooseMedia(make);
			return;
		}
		make(0);
	}

	function chooseMedia(then) {
		if (!window.wp || !window.wp.media) {
			then(0);
			return;
		}
		const frame = window.wp.media({ title: T('Bild wählen'), library: { type: ['image/jpeg', 'image/png'] }, multiple: false, button: { text: T('Übernehmen') } });
		frame.on('select', () => then(frame.state().get('selection').first().get('id')));
		frame.open();
	}

	function drop(ev) {
		ev.preventDefault();
		const type = dragging || ev.dataTransfer.getData('text/plain');
		dragging = '';
		if (!TYPES[type]) {
			return;
		}
		const [x, y] = toLabel(ev.clientX, ev.clientY);
		add(type, Math.max(0, x), Math.max(0, y));
	}

	function select(index) {
		state.selected = index;
		drawBoxes();
		drawProps();
	}

	function keys(ev) {
		const i = state.selected;
		if (i < 0) {
			return;
		}
		const step = ev.shiftKey ? 5 : GRID;
		const move = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }[ev.key];
		if (move) {
			ev.preventDefault();
			const [dx, dy] = fromScreen(move[0], move[1]);
			change(() => {
				const e = state.doc.elements[i];
				e.x = Math.max(0, round(e.x + dx));
				e.y = Math.max(0, round(e.y + dy));
			});
		} else if (ev.key === 'Delete' || ev.key === 'Backspace') {
			ev.preventDefault();
			remove(i);
		}
	}

	function remove(i) {
		change(() => {
			state.doc.elements.splice(i, 1);
			state.selected = -1;
		});
	}

	/* ---------- rotation: only the view turns (D-1191) ---------- */

	function rotate() {
		state.rotation = (state.rotation + 90) % 360;
		layoutStage();
	}

	/** A movement on the screen as a movement on the label. */
	function fromScreen(sx, sy) {
		switch (state.rotation) {
			case 90: return [sy, -sx];
			case 180: return [-sx, -sy];
			case 270: return [-sy, sx];
			default: return [sx, sy];
		}
	}

	/** A point on the screen as mm on the label. */
	function toLabel(clientX, clientY) {
		const r = ui.stage.getBoundingClientRect();
		const sx = (clientX - r.left - 16) / state.scale;
		const sy = (clientY - r.top - 16) / state.scale;
		const { w, h } = state.doc.label;
		switch (state.rotation) {
			case 90: return [sy, h - sx];
			case 180: return [w - sx, h - sy];
			case 270: return [w - sy, sx];
			default: return [sx, sy];
		}
	}

	function layoutStage() {
		const s = state.scale;
		const { w, h } = state.doc.label;
		const turned = state.rotation % 180 !== 0;
		ui.stage.style.width = (turned ? h : w) * s + 32 + 'px';
		ui.stage.style.height = (turned ? w : h) * s + 32 + 'px';
		ui.paper.style.width = w * s + 'px';
		ui.paper.style.height = h * s + 'px';
		const shift = { 0: '', 90: 'translate(' + h * s + 'px,0) rotate(90deg)', 180: 'translate(' + w * s + 'px,' + h * s + 'px) rotate(180deg)', 270: 'translate(0,' + w * s + 'px) rotate(270deg)' }[state.rotation];
		ui.paper.style.transform = shift;
		ui.paper.style.backgroundSize = GRID * 2 * s + 'px ' + GRID * 2 * s + 'px';
		drawBoxes();
	}

	/* ---------- boxes over the drawing ---------- */

	function drawBoxes() {
		const s = state.scale;
		ui.boxes.replaceChildren();
		const wrong = new Set(state.problems.filter((p) => p.error).map((p) => p.element - 1));
		state.doc.elements.forEach((e, i) => {
			const box = el('div', {
				class: 'tl-box' + (e.type === 'rect' || e.type === 'line' ? ' is-frame' : '') + (i === state.selected ? ' is-selected' : '') + (wrong.has(i) ? ' is-wrong' : ''),
				title: (TYPES[e.type] ? TYPES[e.type].label : e.type) + (e.field ? ' · ' + e.field : e.text ? ' · ' + e.text : ''),
			});
			Object.assign(box.style, { left: e.x * s + 'px', top: e.y * s + 'px', width: Math.max(e.w * s, 4) + 'px', height: Math.max(e.h * s, 4) + 'px' });
			box.addEventListener('pointerdown', (ev) => drag(ev, i, 'move'));
			if (i === state.selected) {
				const grip = el('span', { class: 'tl-grip', title: T('Größe ändern') });
				grip.addEventListener('pointerdown', (ev) => drag(ev, i, 'size'));
				box.append(grip);
			}
			ui.boxes.append(box);
		});
	}

	function drag(ev, i, how) {
		ev.preventDefault();
		ev.stopPropagation();
		if (state.selected !== i) {
			select(i);
		}
		ui.stage.focus();
		const start = [ev.clientX, ev.clientY];
		const e = state.doc.elements[i];
		const from = { x: e.x, y: e.y, w: e.w, h: e.h };
		const before = snapshot();
		let moved = false;
		const onMove = (mv) => {
			const [dx, dy] = fromScreen((mv.clientX - start[0]) / state.scale, (mv.clientY - start[1]) / state.scale);
			moved = moved || Math.abs(dx) + Math.abs(dy) > GRID / 2;
			if (how === 'move') {
				e.x = Math.max(0, round(from.x + dx));
				e.y = Math.max(0, round(from.y + dy));
			} else {
				e.w = Math.max(GRID, round(from.w + dx));
				e.h = Math.max(e.type === 'line' ? 0.1 : GRID, round(from.h + dy));
			}
			drawBoxes();
			showJson();
		};
		const onUp = () => {
			window.removeEventListener('pointermove', onMove);
			window.removeEventListener('pointerup', onUp);
			if (moved) {
				state.history.push(before);
				state.future = [];
				render(true);
			}
		};
		window.addEventListener('pointermove', onMove);
		window.addEventListener('pointerup', onUp);
	}

	/* ---------- the chosen element's properties ---------- */

	function drawProps() {
		ui.props.replaceChildren();
		const i = state.selected;
		const e = state.doc.elements[i];
		if (!e) {
			ui.props.append(el('p', { class: 'description' }, T('Ein Element anklicken, um es zu bearbeiten; aus der Leiste oben ziehen, um eines hinzuzufügen.')));
			return;
		}
		const set = (key, value) => change(() => {
			if (value === '' || value === false || value === null) {
				delete e[key];
			} else {
				e[key] = value;
			}
		});
		const number = (key, label, step, fallback) => field(label, el('input', { type: 'number', step: step || GRID, value: e[key] ?? '', placeholder: fallback ?? '', onchange: (ev) => set(key, ev.target.value === '' ? '' : Number(ev.target.value)) }));
		const text = (key, label, hint) => field(label, el('input', { type: 'text', value: e[key] ?? '', placeholder: hint || '', onchange: (ev) => set(key, ev.target.value.trim()) }));
		const check = (key, label, unset) => el('label', { class: 'tl-check' }, el('input', { type: 'checkbox', checked: unset ? e[key] !== false : !!e[key], onchange: (ev) => set(key, unset ? (ev.target.checked ? '' : false) : ev.target.checked) }), ' ' + label);
		const rows = [
			el('h3', {}, sprintf(T('Element %1$d: %2$s'), i + 1, TYPES[e.type] ? TYPES[e.type].label : e.type)),
			el('div', { class: 'tl-row' }, number('x', 'x (mm)'), number('y', 'y (mm)'), number('w', T('Breite (mm)')), number('h', T('Höhe (mm)'), e.type === 'line' ? 0.1 : GRID)),
		];
		if (e.type === 'text' || CODES.includes(e.type)) {
			rows.push(el('div', { class: 'tl-row' }, text('field', T('Feld'), 'title|name'), e.type === 'text' ? text('text', T('Fester Text')) : '', text('example', T('Beispielwert'))));
		}
		if (e.type === 'text') {
			const fit = el('select', { onchange: (ev) => set('fit', ev.target.value === 'shrink' ? '' : ev.target.value) });
			[['shrink', T('an Rahmen anpassen')], ['grow-down', T('erweitern nach unten')], ['grow-right', T('erweitern nach rechts')]].forEach(([v, l]) => fit.append(el('option', { value: v, selected: (e.fit || 'shrink') === v }, l)));
			const align = el('select', { onchange: (ev) => change(() => { delete e.center; delete e.right; if (ev.target.value) { e[ev.target.value] = true; } }) });
			[['', T('links')], ['center', T('mittig')], ['right', T('rechts')]].forEach(([v, l]) => align.append(el('option', { value: v, selected: v === '' ? !e.center && !e.right : !!e[v] }, l)));
			rows.push(el('div', { class: 'tl-row' }, number('pt', T('Schrift (pt)'), 0.5, '12'), number('lines', T('Zeilen'), 1, '1'), field(T('Passen'), fit), field(T('Ausrichtung'), align)));
			rows.push(el('div', { class: 'tl-row' }, check('bold', T('fett')), check('bottom', T('unten bündig')), check('short', T('Kurzform (24k, 4n7)'))));
		}
		if (BARS.includes(e.type)) {
			rows.push(check('human', T('Klartext darunter'), true));
		}
		if (e.type === 'datamatrix') {
			rows.push(el('label', { class: 'tl-check' }, el('input', { type: 'checkbox', checked: e.shape === 'any', onchange: (ev) => set('shape', ev.target.checked ? 'any' : '') }), ' ' + T('auch rechteckig')));
		}
		if (e.type === 'rect') {
			rows.push(number('stroke', T('Strichstärke (mm)'), 0.1, '0.3'));
		}
		if (e.type === 'image') {
			rows.push(el('div', { class: 'tl-row' }, el('span', {}, e.media || T('kein Bild')), el('button', { type: 'button', class: 'button', onclick: () => chooseMedia((id) => id && set('media', 'media:' + id)) }, T('Bild wählen'))));
		}
		rows.push(el('p', {}, el('button', { type: 'button', class: 'button-link button-link-delete', onclick: () => remove(i) }, T('Element entfernen'))));
		ui.props.append(...rows);
	}

	/* ---------- JSON both ways ---------- */

	function showJson() {
		if (document.activeElement !== ui.json) {
			ui.json.value = documentJson();
		}
	}

	let jsonTimer = 0;
	function fromJson() {
		clearTimeout(jsonTimer);
		jsonTimer = setTimeout(() => {
			let parsed;
			try {
				parsed = JSON.parse(ui.json.value);
			} catch (e) {
				preview(ui.json.value);
				return;
			}
			const doc = readDocument({ layout: JSON.stringify(parsed) });
			state.history.push(snapshot());
			state.future = [];
			state.doc = doc;
			ui.w.value = doc.label.w;
			ui.h.value = doc.label.h;
			ui.roll.value = doc.label.roll || '';
			ui.copies.value = doc.label.copies;
			state.selected = Math.min(state.selected, doc.elements.length - 1);
			layoutStage();
			drawProps();
			preview();
		}, 400);
	}

	/* ---------- the server draws ---------- */

	let previewTimer = 0;
	let asked = 0;
	function preview(raw) {
		clearTimeout(previewTimer);
		previewTimer = setTimeout(async () => {
			const mine = ++asked;
			try {
				const answer = await call('/preview', { layout: raw ?? documentJson(), dpi: state.dpi, as: 'svg' });
				if (mine !== asked) {
					return;
				}
				state.problems = answer.problems || [];
				if (answer.svg) {
					ui.drawing.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(answer.svg);
				}
			} catch (error) {
				state.problems = [{ element: 0, error: true, text: error.message }];
			}
			drawProblems();
			drawBoxes();
		}, 250);
	}

	function drawProblems() {
		ui.problems.replaceChildren();
		state.problems.forEach((p) => {
			const item = el('li', { class: p.error ? 'is-error' : 'is-warning' }, (p.error ? '✖ ' : '⚠ ') + p.text);
			if (p.element > 0) {
				item.addEventListener('click', () => select(p.element - 1));
			}
			ui.problems.append(item);
		});
		const blocked = state.problems.some((p) => p.error);
		ui.save.disabled = blocked;
		ui.save.title = blocked ? T('Erst die Fehler beheben.') : '';
		if (!state.problems.length) {
			ui.problems.append(el('li', { class: 'is-ok' }, '✔ ' + T('Keine Probleme: das Etikett lässt sich drucken und scannen.')));
		}
	}

	async function call(path, body) {
		const response = await fetch(setup.rest + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': setup.nonce },
			body: JSON.stringify(body),
		});
		const answer = await response.json().catch(() => ({}));
		if (!response.ok) {
			const error = new Error(answer.message || response.statusText);
			error.problems = answer.data && answer.data.problems;
			throw error;
		}
		return answer;
	}

	function say(text, bad) {
		ui.status.textContent = text;
		ui.status.classList.toggle('is-error', !!bad);
	}

	async function save() {
		if (!state.name) {
			say(T('Ein Etikett braucht einen Namen.'), true);
			ui.name.focus();
			return;
		}
		const body = { name: state.name, key: state.key || state.doc.label.w + 'x' + state.doc.label.h, layout: documentJson(), rolls: state.more };
		if (state.id > 0) {
			body.id = state.id;
		} else {
			body.category = state.node;
		}
		ui.save.disabled = true;
		say(T('Speichert …'));
		try {
			const answer = await call('/save', body);
			state.saved = snapshot();
			if (state.id === 0 && answer.id) {
				window.location.href = setup.open + answer.id;
				return;
			}
			state.key = body.key;
			ui.key.value = state.key;
			state.saved = snapshot();
			say(T('Gespeichert.'));
		} catch (error) {
			if (error.problems) {
				state.problems = error.problems;
				drawProblems();
			}
			say(error.message, true);
		} finally {
			ui.save.disabled = state.problems.some((p) => p.error);
		}
	}

	async function pdf() {
		say(T('Zeichnet …'));
		try {
			const answer = await call('/preview', { layout: documentJson(), dpi: state.dpi, as: 'pdf' });
			if (!answer.pdf) {
				state.problems = answer.problems || [];
				drawProblems();
				say(T('Kein PDF: erst die Fehler beheben.'), true);
				return;
			}
			const bytes = Uint8Array.from(atob(answer.pdf), (c) => c.charCodeAt(0));
			window.open(URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' })), '_blank', 'noopener');
			say('');
		} catch (error) {
			say(error.message, true);
		}
	}

	function render(redraw) {
		layoutStage();
		drawProps();
		showJson();
		if (redraw) {
			preview();
		}
	}

	render(true);
}());
