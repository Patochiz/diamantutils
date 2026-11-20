/**
 * DiamantUtils — tableau de stock par lots (stocktable_list.php)
 *
 * Sans bibliothèque externe. Configuration injectée par la page : window.diamantutilsStockTable.
 *  - repli / dépli des groupes produit, mémorisé dans localStorage (confort seulement) ;
 *  - recherche instantanée (produit, lot, fournisseur) et filtre « écarts seulement ».
 */
(function () {
	'use strict';

	var cfg = window.diamantutilsStockTable;
	var table = document.querySelector('.dst-table');
	if (!cfg || !table) {
		return;
	}

	var storeKey = 'diamantutils_stocktable_collapsed_' + cfg.entity;

	/* Mémoire du repli (localStorage indisponible : sans effet) */
	function loadCollapsed() {
		try {
			var raw = window.localStorage.getItem(storeKey);
			var list = raw ? JSON.parse(raw) : [];
			return Array.isArray(list) ? list.map(String) : [];
		} catch (e) {
			return [];
		}
	}
	function saveCollapsed() {
		var list = [];
		groups().forEach(function (g) {
			if (g.classList.contains('dst-collapsed')) {
				list.push(g.getAttribute('data-pid'));
			}
		});
		try {
			window.localStorage.setItem(storeKey, JSON.stringify(list));
		} catch (e) {
			/* stockage refusé : rien d'essentiel */
		}
	}

	function groups() {
		return Array.prototype.slice.call(table.querySelectorAll('tbody.dst-group'));
	}
	function sections() {
		return Array.prototype.slice.call(table.querySelectorAll('tbody.dst-section'));
	}

	/* Sections (une par tag) : repli mémorisé à part */
	var sectionKey = storeKey + '_sections';
	function loadCollapsedSections() {
		try {
			var list = JSON.parse(window.localStorage.getItem(sectionKey) || '[]');
			return Array.isArray(list) ? list.map(String) : [];
		} catch (e) {
			return [];
		}
	}
	function saveCollapsedSections() {
		var list = sections().filter(function (s) {
			return s.classList.contains('dst-section-collapsed');
		}).map(function (s) {
			return s.getAttribute('data-section');
		});
		try {
			window.localStorage.setItem(sectionKey, JSON.stringify(list));
		} catch (e) {
			/* stockage refusé : rien d'essentiel */
		}
	}
	function setSectionCollapsed(section, collapsed) {
		section.classList.toggle('dst-section-collapsed', collapsed);
		var btn = section.querySelector('.dst-section-toggle');
		if (btn) {
			btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
		}
	}
	function sectionOf(group) {
		return table.querySelector('tbody.dst-section[data-section="' + group.getAttribute('data-section') + '"]');
	}

	function setCollapsed(group, collapsed) {
		group.classList.toggle('dst-collapsed', collapsed);
		var btn = group.querySelector('.dst-toggle');
		if (btn) {
			btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
		}
	}

	/* Filtres côté client */
	var search = document.querySelector('.dst-search');
	var onlyDiff = document.querySelector('.dst-onlydiff');
	var empty = table.querySelector('tbody.dst-empty');

	function applyFilters() {
		var terms = (search ? search.value : '').toLowerCase().split(/\s+/).filter(Boolean);
		var diffOnly = onlyDiff && onlyDiff.checked;
		var visible = 0;
		var filtering = (terms.length > 0 || diffOnly);
		var stats = {};
		groups().forEach(function (g) {
			var hay = g.getAttribute('data-search') || '';
			var show = terms.every(function (t) {
				return hay.indexOf(t) !== -1;
			});
			if (show && diffOnly) {
				show = (g.getAttribute('data-state') !== 'ok');
			}
			if (g.querySelector('.dst-editrow')) {
				show = true;	// une saisie en cours reste visible
			}
			g.classList.toggle('dst-hidden', !show);
			if (show) {
				visible++;
			}
			var sid = g.getAttribute('data-section');
			var st = stats[sid] || (stats[sid] = { nb: 0, diff: 0, visible: 0 });
			st.nb++;
			if (['ok', 'outofscope'].indexOf(g.getAttribute('data-state')) === -1) {
				st.diff++;
			}
			if (show) {
				st.visible++;
			}
			// Section repliée : ses produits sont masqués (sauf saisie en cours)
			var sec = sectionOf(g);
			g.classList.toggle('dst-sec-hidden', !!(sec && sec.classList.contains('dst-section-collapsed') && !g.querySelector('.dst-editrow')));
		});
		sections().forEach(function (sec) {
			var st = stats[sec.getAttribute('data-section')] || { nb: 0, diff: 0, visible: 0 };
			var count = sec.querySelector('.dst-section-count');
			if (count && cfg.labels && cfg.labels.products) {
				var i = 0;
				var args = [st.nb, st.diff];
				count.textContent = '(' + cfg.labels.products.replace(/%s/g, function () {
					return String(args[i++]);
				}) + ')';
			}
			sec.classList.toggle('dst-hidden', filtering && st.visible === 0);
		});
		if (empty) {
			empty.classList.toggle('hidden', visible > 0);
		}
	}

	/* Initialisation d'un groupe (page ou groupe rendu par l'AJAX) */
	function initGroup(group, collapsedList) {
		if (collapsedList && collapsedList.indexOf(group.getAttribute('data-pid')) !== -1) {
			setCollapsed(group, true);
		}
	}

	table.addEventListener('click', function (ev) {
		var sbtn = ev.target.closest('.dst-section-toggle');
		if (sbtn) {
			var section = sbtn.closest('tbody.dst-section');
			setSectionCollapsed(section, !section.classList.contains('dst-section-collapsed'));
			saveCollapsedSections();
			applyFilters();
			return;
		}
		var btn = ev.target.closest('.dst-toggle');
		if (btn) {
			var group = btn.closest('tbody.dst-group');
			setCollapsed(group, !group.classList.contains('dst-collapsed'));
			saveCollapsed();
		}
	});

	var collapseAll = document.querySelector('.dst-collapseall');
	var expandAll = document.querySelector('.dst-expandall');
	if (collapseAll) {
		collapseAll.addEventListener('click', function () {
			groups().forEach(function (g) {
				if (!g.querySelector('.dst-editrow')) {
					setCollapsed(g, true);
				}
			});
			saveCollapsed();
		});
	}
	if (expandAll) {
		expandAll.addEventListener('click', function () {
			sections().forEach(function (sec) {
				setSectionCollapsed(sec, false);
			});
			groups().forEach(function (g) {
				setCollapsed(g, false);
			});
			saveCollapsedSections();
			saveCollapsed();
			applyFilters();
		});
	}
	if (search) {
		search.addEventListener('input', applyFilters);
		search.addEventListener('keydown', function (ev) {
			if (ev.key === 'Enter') {
				ev.preventDefault();	// pas d'envoi du formulaire des filtres
			}
		});
	}
	if (onlyDiff) {
		onlyDiff.addEventListener('change', applyFilters);
	}

	var collapsedSections = loadCollapsedSections();
	sections().forEach(function (sec) {
		if (collapsedSections.indexOf(sec.getAttribute('data-section')) !== -1) {
			setSectionCollapsed(sec, true);
		}
	});

	var collapsed = loadCollapsed();
	groups().forEach(function (g) {
		initGroup(g, collapsed);
	});
	applyFilters();

	/* Accès pour la saisie (phase suivante) */
	window.diamantutilsStockTableUI = {
		cfg: cfg,
		table: table,
		groups: groups,
		sectionOf: sectionOf,
		setSectionCollapsed: setSectionCollapsed,
		saveCollapsedSections: saveCollapsedSections,
		setCollapsed: setCollapsed,
		saveCollapsed: saveCollapsed,
		applyFilters: applyFilters
	};
})();

/**
 * Saisie en ligne (droit write) : « + » d'un groupe, modification, suppression, « Ajouter au
 * tableau » d'un lot Dolibarr seul, « + Produit ». Le produit d'une ligne est toujours fixé par
 * son groupe. Calculs affichés pendant la frappe à titre indicatif : le serveur recalcule et
 * renvoie le groupe complet.
 */
(function () {
	'use strict';

	var ui = window.diamantutilsStockTableUI;
	if (!ui) {
		return;
	}
	var cfg = ui.cfg;
	var table = ui.table;
	var L = cfg.labels || {};
	var productCache = {};
	var NBCOLS = 12;

	/* Outils */
	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if (k === 'text') {
				node.textContent = attrs[k];
			} else if (k === 'className') {
				node.className = attrs[k];
			} else {
				node.setAttribute(k, attrs[k]);
			}
		});
		(children || []).forEach(function (c) {
			if (c) {
				node.appendChild(c);
			}
		});
		return node;
	}
	function fmt(str) {
		var args = Array.prototype.slice.call(arguments, 1);
		return String(str).replace(/%s/g, function () {
			return args.length ? String(args.shift()) : '';
		});
	}
	function num(value, maxdec) {
		var v = Math.round(Number(value) * Math.pow(10, maxdec)) / Math.pow(10, maxdec);
		if (v === 0) {
			v = 0;
		}
		var txt = v.toFixed(maxdec);
		if (txt.indexOf('.') !== -1) {
			txt = txt.replace(/0+$/, '').replace(/\.$/, '');
		}
		return txt.replace('.', cfg.decsep);
	}
	function qtyText(v) {
		var txt = (Math.round(v * 100) / 100).toFixed(2).replace('.', cfg.decsep);
		return txt.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
	}
	function parseNum(str) {
		str = String(str || '').trim().replace(/\s/g, '');
		if (str === '') {
			return null;
		}
		str = str.replace(',', '.');
		return /^-?\d*\.?\d+$/.test(str) ? parseFloat(str) : NaN;
	}
	function notify(message, type) {
		if (window.jQuery && window.jQuery.jnotify) {
			window.jQuery.jnotify(message, type === 'error' ? 'error' : 'ok', type === 'error');
		} else if (type === 'error') {
			window.alert(message);
		}
	}

	/* Requêtes AJAX : GET en lecture, POST avec jeton en écriture */
	function request(method, params) {
		var url = cfg.ajaxUrl;
		var opts = { method: method, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } };
		if (method === 'GET') {
			url += '?' + new URLSearchParams(params).toString();
		} else {
			var body = new URLSearchParams(params);
			body.append('token', cfg.token);
			opts.body = body;
		}
		return fetch(url, opts).then(function (resp) {
			return resp.json().catch(function () {
				return { error: fmt(L.network, resp.status) };
			}).then(function (data) {
				if (!resp.ok || (data && data.error)) {
					throw new Error((data && data.error) || fmt(L.network, resp.status));
				}
				return data;
			});
		}, function (err) {
			throw new Error(fmt(L.network, err.message));
		});
	}
	function loadProduct(pid) {
		if (productCache[pid]) {
			return Promise.resolve(productCache[pid]);
		}
		return request('GET', { action: 'product', id: pid }).then(function (data) {
			productCache[pid] = data;
			return data;
		});
	}

	/* Calculs (miroir de lib/stocktable.lib.php, affichage seulement) */
	function colis(nb, per) {
		if (!(nb > 0) || !(per > 0)) {
			return { text: '', warn: 'invalid' };
		}
		if (per >= nb) {
			return { text: fmt(cfg.colisFmt, '1', num(nb, 3)), warn: per > nb ? 'partial' : '' };
		}
		var full = Math.floor(nb / per + 1e-9);
		var rest = Math.round((nb - full * per) * 1e6) / 1e6;
		if (rest < 1e-6) {
			rest = 0;
		}
		var text = fmt(cfg.colisFmt, String(full), num(per, 3));
		if (rest > 0) {
			text += ' + ' + fmt(cfg.colisFmt, '1', num(rest, 3));
		}
		return { text: text, warn: '' };
	}
	function lineQty(unit, nb, larg, long) {
		if (!unit.ok || !(nb > 0)) {
			return { qty: null, error: unit.ok ? '' : unit.error };
		}
		var div = Math.pow(10, unit.scale || 0);
		if (unit.type === 'surface') {
			if (!(larg > 0) || !(long > 0)) {
				return { qty: null, error: L.dimsSurface };
			}
			return { qty: nb * larg * long * 1e-6 / div, error: '' };
		}
		if (unit.type === 'size') {
			if (!(long > 0)) {
				return { qty: null, error: L.dimsSize };
			}
			return { qty: nb * long * 1e-3 / div, error: '' };
		}
		return { qty: nb, error: '' };
	}

	/* Éditeur : une seule ligne de saisie à la fois */
	var editor = null;

	function closeEditor() {
		if (!editor) {
			return;
		}
		if (editor.hiddenRow) {
			editor.hiddenRow.classList.remove('hidden');
		}
		editor.row.parentNode.removeChild(editor.row);
		editor = null;
		ui.applyFilters();
	}

	function input(cls, value, required, extra) {
		var attrs = { type: 'text', inputmode: 'decimal', className: 'flat right ' + cls, autocomplete: 'off' };
		if (extra) {
			Object.keys(extra).forEach(function (k) {
				attrs[k] = extra[k];
			});
		}
		var node = el('input', attrs);
		node.value = (value === null || value === undefined || value === '') ? '' : num(value, 3);
		if (required) {
			node.required = true;
		}
		return node;
	}

	/**
	 * Ouvre la saisie dans un groupe
	 * @param {HTMLElement} group     tbody du groupe
	 * @param {HTMLElement|null} line ligne modifiée (null = nouvelle ligne)
	 * @param {string|null} batch     lot pré-choisi (« Ajouter au tableau »)
	 * @param {HTMLElement|null} after ligne après laquelle insérer
	 */
	function openEditor(group, line, batch, after) {
		closeEditor();
		var pid = group.getAttribute('data-pid');
		var sec = ui.sectionOf(group);
		if (sec && sec.classList.contains('dst-section-collapsed')) {
			ui.setSectionCollapsed(sec, false);
			ui.saveCollapsedSections();
		}
		ui.setCollapsed(group, false);
		ui.applyFilters();

		var row = el('tr', { className: 'dst-editrow' });
		var loading = el('td', { colspan: String(NBCOLS), className: 'opacitymedium', text: '…' });
		row.appendChild(loading);
		var anchor = after || (line || group.querySelector('tr.dst-head'));
		anchor.parentNode.insertBefore(row, anchor.nextSibling);
		if (line) {
			line.classList.add('hidden');
		}
		editor = { row: row, group: group, hiddenRow: line, pid: pid, id: line ? line.getAttribute('data-id') : '' };
		var current = editor;

		loadProduct(pid).then(function (product) {
			if (editor !== current) {
				return;
			}
			buildEditor(current, product, line, batch);
		}, function (err) {
			closeEditor();
			notify(err.message, 'error');
		});
	}

	function buildEditor(ed, product, line, batch) {
		var row = ed.row;
		row.innerHTML = '';
		var unit = product.unit;
		var last = product.last || {};
		var values = line ? {
			batch: line.getAttribute('data-batch'),
			nb: line.getAttribute('data-nb'),
			per: line.getAttribute('data-per'),
			larg: line.getAttribute('data-larg'),
			long: line.getAttribute('data-long'),
			soc: line.getAttribute('data-soc'),
			note: line.getAttribute('data-note')
		} : {
			batch: batch || '',
			nb: '',
			per: last.pieces_per_colis,
			larg: last.larg_mm,
			long: last.long_mm,
			soc: last.fk_soc || '',
			note: ''
		};

		// 1. Lot : liste des lots Dolibarr du produit, « Autre lot » explicite
		var tdLot = el('td', { className: 'nowraponall' });
		var lotSelect = null;
		var newLot = null;
		var lotWarn = el('span', { className: 'dst-warn hidden', text: L.otherLotWarn });
		if (product.tobatch) {
			lotSelect = el('select', { className: 'flat dst-f-lot' });
			lotSelect.appendChild(el('option', { value: '', text: L.chooseLot }));
			var known = false;
			(product.lots || []).forEach(function (lot) {
				var opt = el('option', { value: lot.batch, text: lot.batch + (Math.abs(lot.qty) > 1e-6 ? ' — ' + fmt(L.lotStock, lot.qtytxt) : '') });
				if (lot.batch === values.batch) {
					opt.selected = true;
					known = true;
				}
				lotSelect.appendChild(opt);
			});
			lotSelect.appendChild(el('option', { value: '__new__', text: L.otherLot }));
			newLot = el('input', { type: 'text', className: 'flat dst-f-newlot hidden', maxlength: '128', placeholder: L.otherLotName });
			if (values.batch && !known) {
				lotSelect.value = '__new__';
				newLot.value = values.batch;
			}
			tdLot.appendChild(lotSelect);
			tdLot.appendChild(newLot);
			tdLot.appendChild(lotWarn);
		} else {
			tdLot.appendChild(el('span', { className: 'opacitymedium', text: L.noLot }));
		}
		row.appendChild(tdLot);

		// 2. Colisage calculé
		var tdColis = el('td', {});
		var colisOut = el('span', { className: 'dst-colis' });
		var colisWarn = el('span', { className: 'dst-warn hidden', text: L.partial });
		tdColis.appendChild(colisOut);
		tdColis.appendChild(colisWarn);
		row.appendChild(tdColis);

		// 3-6. Pièces, pièces / colis, largeur, longueur
		var fNb = input('dst-f-nb', values.nb, true, { 'aria-label': L.pieces, title: L.pieces });
		var fPer = input('dst-f-per', values.per, true, { 'aria-label': L.perColis, title: L.perColis });
		var fLarg = input('dst-f-larg', values.larg, product.dims.larg, { 'aria-label': L.larg, title: L.larg });
		var fLong = input('dst-f-long', values.long, product.dims.long, { 'aria-label': L.long, title: L.long });
		[fNb, fPer, fLarg, fLong].forEach(function (f) {
			var td = el('td', { className: 'right nowraponall' + (f.required ? ' dst-required' : '') }, [f]);
			row.appendChild(td);
		});

		// 7. Quantité calculée
		var tdQty = el('td', { className: 'right nowraponall' });
		var qtyOut = el('span', { className: 'dst-qty' });
		tdQty.appendChild(qtyOut);
		row.appendChild(tdQty);

		// 8. Fournisseur et note
		var tdSoc = el('td', {});
		var socSelect = el('select', { className: 'flat dst-f-soc' });
		socSelect.appendChild(el('option', { value: '', text: L.noSupplier }));
		(cfg.suppliers || []).forEach(function (s) {
			var opt = el('option', { value: String(s[0]), text: s[1] });
			if (String(s[0]) === String(values.soc)) {
				opt.selected = true;
			}
			socSelect.appendChild(opt);
		});
		var fNote = el('input', { type: 'text', className: 'flat dst-f-note', maxlength: '255', placeholder: L.note });
		fNote.value = values.note || '';
		tdSoc.appendChild(socSelect);
		tdSoc.appendChild(el('br'));
		tdSoc.appendChild(fNote);
		row.appendChild(tdSoc);

		// 9-11. Messages
		var tdMsg = el('td', { colspan: '3' });
		var errOut = el('span', { className: 'dst-err' });
		tdMsg.appendChild(errOut);
		tdMsg.appendChild(el('span', { className: 'dst-hint', text: L.liveHint }));
		row.appendChild(tdMsg);

		// 12. Boutons
		var btnSave = el('button', { type: 'button', className: 'button smallpaddingimp dst-save', text: L.save });
		var btnCancel = el('button', { type: 'button', className: 'button button-cancel smallpaddingimp dst-cancel', text: L.cancel });
		row.appendChild(el('td', { className: 'right nowraponall' }, [btnSave, btnCancel]));

		function lotValue() {
			if (!lotSelect) {
				return { batch: '', isNew: false };
			}
			if (lotSelect.value === '__new__') {
				return { batch: newLot.value.trim(), isNew: true };
			}
			return { batch: lotSelect.value, isNew: false };
		}

		function refresh() {
			if (lotSelect) {
				var isNew = (lotSelect.value === '__new__');
				newLot.classList.toggle('hidden', !isNew);
				lotWarn.classList.toggle('hidden', !isNew);
			}
			var nb = parseNum(fNb.value);
			var per = parseNum(fPer.value);
			var c = colis(nb, per);
			colisOut.textContent = c.text;
			colisWarn.classList.toggle('hidden', c.warn !== 'partial');
			var q = lineQty(unit, nb, parseNum(fLarg.value), parseNum(fLong.value));
			qtyOut.textContent = (q.qty === null ? '—' : qtyText(q.qty) + ' ' + unit.label);
			qtyOut.title = q.error || '';
			errOut.textContent = (!unit.ok ? unit.error : '');
		}

		function save() {
			var lot = lotValue();
			errOut.textContent = '';
			btnSave.disabled = true;
			var params = {
				action: 'save',
				id: ed.id || '',
				fk_product: ed.pid,
				batch: lot.batch,
				newbatch: lot.isNew ? '1' : '0',
				nb_pieces: normalized(fNb.value),
				pieces_per_colis: normalized(fPer.value),
				larg_mm: normalized(fLarg.value),
				long_mm: normalized(fLong.value),
				fk_soc: socSelect.value,
				note: fNote.value
			};
			request('POST', params).then(function (data) {
				productCache[ed.pid] = null;	// dernières valeurs et lots à relire
				editor = null;
				replaceGroup(ed.group, data.html);
				notify(data.message, 'ok');
			}, function (err) {
				btnSave.disabled = false;
				errOut.textContent = err.message;
			});
		}

		row.addEventListener('input', refresh);
		row.addEventListener('change', refresh);
		row.addEventListener('keydown', function (ev) {
			if (ev.key === 'Enter' && ev.target.tagName !== 'SELECT') {
				ev.preventDefault();
				save();
			} else if (ev.key === 'Escape') {
				ev.preventDefault();
				closeEditor();
			}
		});
		btnSave.addEventListener('click', save);
		btnCancel.addEventListener('click', closeEditor);
		refresh();

		var focus = (lotSelect && !lotSelect.value) ? lotSelect : fNb;
		focus.focus();
	}

	function normalized(str) {
		var v = parseNum(str);
		if (v === null) {
			return '';
		}
		return isNaN(v) ? String(str) : String(v);
	}

	/* Remplacement d'un groupe par le HTML recalculé du serveur */
	function htmlToGroup(html) {
		if (!html) {
			return null;
		}
		var tmp = document.createElement('table');
		tmp.innerHTML = html;
		return tmp.querySelector('tbody.dst-group');
	}
	function replaceGroup(group, html) {
		var fresh = htmlToGroup(html);
		if (fresh) {
			group.parentNode.replaceChild(fresh, group);
		} else {
			group.parentNode.removeChild(group);
		}
		ui.applyFilters();
		return fresh;
	}

	/* Insertion d'un nouveau groupe dans sa section, à sa place (ordre des réf.) */
	function insertGroup(fresh) {
		var ref = (fresh.getAttribute('data-ref') || '').toLowerCase();
		var sid = fresh.getAttribute('data-section');
		var same = ui.groups().filter(function (g) {
			return g.getAttribute('data-section') === sid;
		});
		var before = null;
		same.some(function (g) {
			if ((g.getAttribute('data-ref') || '').toLowerCase().localeCompare(ref, undefined, { numeric: true }) > 0) {
				before = g;
				return true;
			}
			return false;
		});
		var section = table.querySelector('tbody.dst-section[data-section="' + sid + '"]');
		if (!before) {
			var last = same.length ? same[same.length - 1] : section;
			before = last ? last.nextElementSibling : table.querySelector('tbody.dst-empty');
		}
		table.insertBefore(fresh, before || table.querySelector('tbody.dst-empty'));
		ui.applyFilters();
	}

	/* Clics dans le tableau */
	table.addEventListener('click', function (ev) {
		var btn = ev.target.closest('button');
		if (!btn || !table.contains(btn)) {
			return;
		}
		var group = btn.closest('tbody.dst-group');
		var line = btn.closest('tr');
		if (btn.classList.contains('dst-add')) {
			openEditor(group, null, null, null);
		} else if (btn.classList.contains('dst-addlot')) {
			openEditor(group, null, line.getAttribute('data-batch'), line);
		} else if (btn.classList.contains('dst-edit')) {
			openEditor(group, line, null, null);
		} else if (btn.classList.contains('dst-delete')) {
			if (!window.confirm(L.confirmDelete)) {
				return;
			}
			btn.disabled = true;
			request('POST', { action: 'delete', id: line.getAttribute('data-id') }).then(function (data) {
				if (editor && editor.group === group) {
					editor = null;
				}
				replaceGroup(group, data.html);
				notify(data.message, 'ok');
			}, function (err) {
				btn.disabled = false;
				notify(err.message, 'error');
			});
		}
	});

	/* « + Produit » : recherche limitée aux produits des tags */
	var addProduct = document.querySelector('.dst-addproduct');
	var picker = document.querySelector('.dst-productpicker');
	if (addProduct && picker) {
		var search = el('input', { type: 'search', className: 'flat minwidth300', placeholder: L.searchProduct, autocomplete: 'off' });
		var list = el('ul', {});
		var close = el('button', { type: 'button', className: 'button button-cancel smallpaddingimp', text: L.cancel });
		picker.appendChild(search);
		picker.appendChild(close);
		picker.appendChild(list);
		var timer = null;
		var seq = 0;

		function runSearch() {
			var mine = ++seq;
			request('GET', { action: 'searchproduct', term: search.value }).then(function (rows) {
				if (mine !== seq) {
					return;
				}
				list.innerHTML = '';
				if (!rows.length) {
					list.appendChild(el('li', { className: 'opacitymedium', text: L.noProduct }));
				}
				rows.forEach(function (p) {
					var li = el('li', { 'data-id': String(p.id) }, [el('strong', { text: p.ref }), document.createTextNode(' ' + p.label)]);
					list.appendChild(li);
				});
			}, function (err) {
				notify(err.message, 'error');
			});
		}
		function choose(pid) {
			picker.classList.add('hidden');
			var existing = table.querySelector('tbody.dst-group[data-pid="' + pid + '"]');
			if (existing) {
				existing.classList.remove('dst-hidden');
				existing.scrollIntoView({ block: 'center' });
				openEditor(existing, null, null, null);
				return;
			}
			request('GET', { action: 'group', id: pid }).then(function (data) {
				var fresh = htmlToGroup(data.html);
				if (!fresh) {
					return;
				}
				insertGroup(fresh);
				fresh.scrollIntoView({ block: 'center' });
				openEditor(fresh, null, null, null);
			}, function (err) {
				notify(err.message, 'error');
			});
		}

		addProduct.addEventListener('click', function () {
			picker.classList.toggle('hidden');
			if (!picker.classList.contains('hidden')) {
				search.value = '';
				search.focus();
				runSearch();
			}
		});
		close.addEventListener('click', function () {
			picker.classList.add('hidden');
		});
		search.addEventListener('input', function () {
			clearTimeout(timer);
			timer = setTimeout(runSearch, 250);
		});
		search.addEventListener('keydown', function (ev) {
			if (ev.key === 'Enter') {
				ev.preventDefault();
				var first = list.querySelector('li[data-id]');
				if (first) {
					choose(first.getAttribute('data-id'));
				}
			} else if (ev.key === 'Escape') {
				picker.classList.add('hidden');
			}
		});
		list.addEventListener('click', function (ev) {
			var li = ev.target.closest('li[data-id]');
			if (li) {
				choose(li.getAttribute('data-id'));
			}
		});
	}
})();
