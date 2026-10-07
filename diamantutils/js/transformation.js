/**
 * DiamantUtils — écran de saisie d'un ordre de transformation (brouillon)
 *
 * - « À consommer » : un ou plusieurs produits ; pour chacun, tableau de ses lots en stock
 *   dans l'entrepôt de l'ordre, à cocher (cocher = tout le stock du lot, réductible) ;
 * - « À produire » : produit (pré-rempli avec le premier consommé), lot existant ou nouveau ;
 * - « Reste / perte » : lignes de perte, reliquat en direct, indicateur d'équilibre (R4) ;
 * - assistant de découpe (mode longueur) : chaque barre ouverte est celle qui minimise la chute.
 *
 * Le serveur recalcule et contrôle tout : il fait foi.
 * Configuration fournie par la page dans window.diamantutilsTransfo.
 */
(function ($) {
	'use strict';

	var cfg = window.diamantutilsTransfo;
	if (!cfg || !$) {
		return;
	}

	var L = cfg.lang || {};
	var products = (cfg.products && !Array.isArray(cfg.products)) ? cfg.products : {};
	var groups = [];	// À consommer : [{gid, fk_product, sel: {batch: {nb_pieces, qty}}}]
	var rows = [];		// À produire (OUT) et pertes (LOSS)
	var uidSeq = 0;
	var NEWLOT = '__new__';

	/* ---------- Utilitaires ---------- */

	function t(key) {
		return (L[key] !== undefined ? L[key] : key);
	}

	function num(v) {
		if (v === null || v === undefined || v === '') {
			return 0;
		}
		var n = parseFloat(String(v).replace(/\s/g, '').replace(',', '.'));
		return isNaN(n) ? 0 : n;
	}

	function round(v) {
		var f = Math.pow(10, cfg.decimals);
		return Math.round(v * f) / f;
	}

	function fmt(v) {
		return String(round(v)).replace('.', cfg.decSep);
	}

	/** Échappement HTML, valable aussi dans un attribut */
	function esc(s) {
		return $('<div>').text(s === null || s === undefined ? '' : String(s)).html().replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	}

	function str(v) {
		return (v === null || v === undefined) ? '' : String(v);
	}

	/** Premier entier du nom de lot — utilisé seulement en mode longueur */
	function lotLength(batch) {
		var m = /(\d+)/.exec(batch || '');
		if (!m) {
			return null;
		}
		var n = parseInt(m[1], 10);
		return n > 0 ? n : null;
	}

	function lotName(length) {
		return cfg.lotFormat.replace('%d', String(Math.round(length)));
	}

	/** Mode longueur : option « Lot = longueur » cochée et unité compatible (calculé côté serveur) */
	function isProfile(info) {
		return !!info && (info.mode === 'surface' || info.mode === 'size') && info.factor > 0;
	}

	function hasBatch(info) {
		return !!info && cfg.batchEnabled && info.status_batch > 0;
	}

	function warehouse() {
		return $('#fk_warehouse').val() || 0;
	}

	function productLabel(info) {
		return info ? info.ref + ' - ' + info.label : '';
	}

	function stockLot(info, batch) {
		if (!info) {
			return null;
		}
		if (!hasBatch(info)) {
			return {batch: '', qty: info.stock || 0, pieces: null, length: null, multiple: true, eatby: '', sellby: ''};
		}
		for (var i = 0; i < (info.lots || []).length; i++) {
			if (info.lots[i].batch === batch) {
				return info.lots[i];
			}
		}
		return null;
	}

	function knownLot(info, batch) {
		return !!info && (info.alllots || []).some(function (l) {
			return l.batch === batch;
		});
	}

	function loadProduct(id, force) {
		var d = $.Deferred();
		if (!id) {
			return d.resolve(null).promise();
		}
		if (products[id] && !force) {
			return d.resolve(products[id]).promise();
		}
		$.getJSON(cfg.ajaxUrl, {action: 'product', id: id, fk_warehouse: warehouse()})
			.done(function (info) {
				if (info && info.id) {
					products[id] = info;
				}
				d.resolve(products[id] || null);
			})
			.fail(function () {
				d.resolve(products[id] || null);
			});
		return d.promise();
	}

	/* ---------- Quantités ---------- */

	/** Quantité consommée sur un lot coché */
	function inQty(info, batch, sel) {
		var len = isProfile(info) ? lotLength(batch) : null;
		var nb = num(sel.nb_pieces);
		if (isProfile(info) && len && nb > 0) {
			return round(nb * len * info.factor);
		}
		if (info && info.mode === 'qty' && nb > 0) {
			return round(nb);
		}
		return round(num(sel.qty));
	}

	function rowLength(row) {
		return num(row.length_mm);
	}

	/** Quantité d'une ligne produite ou perdue, recalculée comme côté serveur */
	function rowQty(row) {
		var info = products[row.fk_product];
		var nb = num(row.nb_pieces);
		var len = rowLength(row);
		if (isProfile(info) && nb > 0 && len > 0) {
			return {qty: round(nb * len * info.factor), computed: true};
		}
		if (info && info.mode === 'qty' && nb > 0) {
			return {qty: round(nb), computed: true};
		}
		return {qty: round(num(row.qty)), computed: false};
	}

	/** Lignes consommées à partir des lots cochés */
	function inLines() {
		var out = [];
		groups.forEach(function (g) {
			var info = products[g.fk_product];
			if (!g.fk_product) {
				return;
			}
			Object.keys(g.sel).forEach(function (batch) {
				var sel = g.sel[batch];
				out.push({fk_product: g.fk_product, batch: batch, nb_pieces: sel.nb_pieces, qty: inQty(info, batch, sel)});
			});
		});
		return out;
	}

	function firstInProduct() {
		for (var i = 0; i < groups.length; i++) {
			if (groups[i].fk_product) {
				return groups[i].fk_product;
			}
		}
		return cfg.defaultProduct || 0;
	}

	/* ---------- Bloc « À consommer » ---------- */

	function newGroup(fk_product) {
		uidSeq++;
		return {gid: uidSeq, fk_product: parseInt(fk_product, 10) || 0, sel: {}};
	}

	function groupHtml(g) {
		var info = products[g.fk_product];
		var h = '<div class="transfo-group marginbottomonly" data-gid="' + g.gid + '">';
		h += '<div class="paddingtop paddingbottom">';
		h += esc(t('Product')) + ' : <input type="text" class="flat minwidth300 transfo-product" placeholder="' + esc(t('DiamantutilsSearchProduct')) + '" value="' + esc(productLabel(info)) + '">';
		h += ' <a href="#" class="transfo-group-del" title="' + esc(t('DiamantutilsRemoveProduct')) + '">' + cfg.deleteIcon + '</a>';
		if (info && info.nowidth) {
			h += ' <span class="warning small">' + cfg.warningIcon + ' ' + esc(t('DiamantutilsNoWidth')) + '</span>';
		}
		h += '</div>';
		if (!info) {
			h += '</div>';
			return h;
		}

		var profile = isProfile(info);
		var lots = hasBatch(info) ? (info.lots || []).slice() : [stockLot(info, '')];
		// Lots cochés qui ne sont plus en stock (brouillon enregistré) : affichés à stock 0
		Object.keys(g.sel).forEach(function (batch) {
			if (!lots.some(function (l) { return l.batch === batch; })) {
				lots.push({batch: batch, qty: 0, pieces: null, length: profile ? lotLength(batch) : null, multiple: true, eatby: '', sellby: ''});
			}
		});
		var hasDates = lots.some(function (l) { return l.eatby || l.sellby; });

		h += '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
		h += '<tr class="liste_titre"><td class="width25"></td><td>' + esc(t('Batch')) + '</td>';
		if (hasDates) {
			h += '<td>' + esc(t('DiamantutilsEatSellBy')) + '</td>';
		}
		h += '<td class="right">' + esc(t('DiamantutilsStock')) + '</td>';
		if (profile) {
			h += '<td class="right">' + esc(t('DiamantutilsPiecesEq')) + '</td>';
		}
		h += '<td class="right">' + esc(profile || info.mode === 'qty' ? t('DiamantutilsNbToConsume') : t('DiamantutilsQtyToConsume')) + '</td>';
		h += '<td class="right">' + esc(t('Qty')) + '</td><td>' + esc(t('Unit')) + '</td></tr>';

		if (!lots.length) {
			h += '<tr class="oddeven"><td colspan="8"><span class="opacitymedium">' + esc(t('DiamantutilsNoLot')) + '</span></td></tr>';
		}
		lots.forEach(function (lot) {
			var sel = g.sel[lot.batch];
			var checked = !!sel;
			var bylength = (profile && lotLength(lot.batch) !== null) || info.mode === 'qty';
			h += '<tr class="oddeven" data-batch="' + esc(lot.batch) + '">';
			h += '<td><input type="checkbox" class="transfo-lot-check"' + (checked ? ' checked' : '') + '></td>';
			h += '<td>' + (lot.batch !== '' ? esc(lot.batch) : '<span class="opacitymedium">' + esc(t('DiamantutilsNoBatch')) + '</span>') + '</td>';
			if (hasDates) {
				h += '<td class="small">' + esc([lot.eatby, lot.sellby].filter(Boolean).join(' / ')) + '</td>';
			}
			h += '<td class="right nowraponall">' + fmt(lot.qty) + '</td>';
			if (profile) {
				h += '<td class="right nowraponall">';
				if (lot.pieces !== null && lot.pieces !== undefined) {
					h += lot.multiple ? fmt(lot.pieces) : '<span style="color: #e08000" title="' + esc(t('DiamantutilsLotNotMultiple')) + '">' + fmt(lot.pieces) + '</span>';
				}
				h += '</td>';
			}
			h += '<td class="right">';
			if (bylength) {
				h += '<input type="text" class="flat width50 right transfo-lot-nb" value="' + esc(sel ? str(sel.nb_pieces) : '') + '">';
			} else {
				h += '<input type="text" class="flat width75 right transfo-lot-qty" value="' + esc(sel ? str(sel.qty) : '') + '">';
			}
			h += '</td>';
			h += '<td class="right nowraponall"><span class="transfo-lot-computed"></span> <span class="transfo-lot-warn"></span></td>';
			h += '<td>' + esc(info.unit_short) + '</td>';
			h += '</tr>';
		});
		h += '</table></div></div>';
		return h;
	}

	/** Cocher un lot : tout le stock du lot (en pièces entières en mode longueur) */
	function checkLot(g, batch) {
		var info = products[g.fk_product];
		var lot = stockLot(info, batch) || {qty: 0, pieces: null};
		if (isProfile(info) && lotLength(batch) !== null) {
			var pieces = (lot.pieces !== null && lot.pieces !== undefined) ? Math.floor(lot.pieces + 0.01) : 0;
			g.sel[batch] = {nb_pieces: pieces > 0 ? String(pieces) : '', qty: ''};
		} else if (info && info.mode === 'qty') {
			g.sel[batch] = {nb_pieces: String(round(lot.qty)), qty: ''};
		} else {
			g.sel[batch] = {nb_pieces: '', qty: String(round(lot.qty))};
		}
	}

	/* ---------- Blocs « À produire » et « Reste / perte » ---------- */

	function newRow(direction, data) {
		data = data || {};
		uidSeq++;
		return {
			uid: uidSeq,
			direction: direction,
			fk_product: parseInt(data.fk_product, 10) || 0,
			batch: data.batch || '',
			newlot: !!data.newlot,
			nb_pieces: (data.nb_pieces === null || data.nb_pieces === undefined) ? '' : String(round(num(data.nb_pieces))),
			length_mm: (data.length_mm === null || data.length_mm === undefined) ? '' : String(round(num(data.length_mm))),
			qty: (data.qty === null || data.qty === undefined || data.qty === '') ? '' : String(round(num(data.qty))),
			batchEdited: !!data.batch
		};
	}

	/**
	 * Lot proposé pour une ligne produite, tant que l'opérateur ne l'a pas choisi :
	 * - mode longueur : lot existant de même longueur, sinon nouveau lot nommé selon le format ;
	 * - mode libre : le lot consommé s'il est unique (le bain suit la pièce), sinon nouveau lot.
	 */
	function proposeBatch(row) {
		var info = products[row.fk_product];
		if (!hasBatch(info) || row.batchEdited) {
			return;
		}
		var name = '';
		if (isProfile(info)) {
			var len = num(row.length_mm);
			if (len > 0) {
				var same = (info.alllots || []).filter(function (l) { return l.length === Math.round(len); });
				name = same.length ? same[0].batch : lotName(len);
			}
		} else if (row.direction === 'OUT') {
			var consumed = inLines();
			var batches = {};
			consumed.forEach(function (l) {
				if (l.batch !== '') {
					batches[l.batch] = 1;
				}
			});
			var keys = Object.keys(batches);
			name = (keys.length === 1) ? keys[0] : '';
		}
		row.batch = name;
		row.newlot = !knownLot(info, name);
	}

	function lotCellHtml(row, info) {
		if (!hasBatch(info)) {
			return '';
		}
		if (row.direction === 'LOSS') {
			return '<input type="text" class="flat minwidth150 transfo-batch-text" value="' + esc(row.batch) + '" maxlength="128">';
		}
		var s = '<select class="flat minwidth200 transfo-batch-select">';
		(info.alllots || []).forEach(function (l) {
			s += '<option value="' + esc(l.batch) + '"' + (!row.newlot && l.batch === row.batch ? ' selected' : '') + '>' + esc(l.batch) + '</option>';
		});
		s += '<option value="' + NEWLOT + '"' + (row.newlot ? ' selected' : '') + '>' + esc(t('DiamantutilsNewLot')) + '</option>';
		s += '</select>';
		if (row.newlot) {
			s += ' <input type="text" class="flat minwidth150 transfo-batch-text" placeholder="' + esc(t('DiamantutilsNewLotName')) + '" value="' + esc(row.batch) + '" maxlength="128">';
		}
		return s;
	}

	function headerHtml() {
		var h = '<tr class="liste_titre">';
		h += '<td>' + esc(t('Product')) + '</td>';
		h += '<td>' + esc(t('Batch')) + '</td>';
		h += '<td class="right">' + esc(t('DiamantutilsPieces')) + '</td>';
		h += '<td class="right">' + esc(t('DiamantutilsLengthMm')) + '</td>';
		h += '<td class="right">' + esc(t('Qty')) + '</td>';
		h += '<td>' + esc(t('Unit')) + '</td>';
		h += '<td></td>';
		h += '</tr>';
		return h;
	}

	function rowHtml(row) {
		var info = products[row.fk_product];
		var h = '<tr class="oddeven" data-uid="' + row.uid + '">';
		h += '<td class="nowraponall"><input type="text" class="flat minwidth200 transfo-product" placeholder="' + esc(t('DiamantutilsSearchProduct')) + '" value="' + esc(productLabel(info)) + '">';
		if (info && info.nowidth) {
			h += '<br><span class="warning small">' + cfg.warningIcon + ' ' + esc(t('DiamantutilsNoWidth')) + '</span>';
		}
		h += '</td>';
		h += '<td class="nowraponall">' + lotCellHtml(row, info) + '</td>';
		var showNb = info && (isProfile(info) || info.mode === 'qty');
		h += '<td class="right">' + (showNb ? '<input type="text" class="flat width50 right transfo-nb" value="' + esc(row.nb_pieces) + '">' : '') + '</td>';
		h += '<td class="right">' + (info && isProfile(info) ? '<input type="text" class="flat width75 right transfo-len" value="' + esc(row.length_mm) + '">' : '') + '</td>';
		h += '<td class="right nowraponall"><input type="text" class="flat width75 right transfo-qty" value="' + esc(row.qty) + '"></td>';
		h += '<td>' + esc(info ? info.unit_short : '') + '</td>';
		h += '<td class="right nowraponall">';
		if (row.direction === 'OUT') {
			h += '<a href="#" class="transfo-toloss marginrightonly" title="' + esc(t('DiamantutilsToLoss')) + '">→ ' + esc(t('DiamantutilsLoss')) + '</a>';
		} else {
			h += '<a href="#" class="transfo-tostock marginrightonly" title="' + esc(t('DiamantutilsToStock')) + '">→ ' + esc(t('DiamantutilsProduced')) + '</a>';
		}
		h += '<a href="#" class="transfo-del">' + cfg.deleteIcon + '</a>';
		h += '</td></tr>';
		return h;
	}

	/* ---------- Rendu ---------- */

	function render() {
		$('#transfo-in-groups').html(groups.map(groupHtml).join(''));
		['OUT', 'LOSS'].forEach(function (direction) {
			var $table = $('#transfo-table-' + direction);
			$table.find('thead').html(headerHtml());
			$table.find('tbody').html(rows.filter(function (r) {
				return r.direction === direction;
			}).map(rowHtml).join(''));
		});
		bindAutocomplete();
		refresh();
	}

	function bindAutocomplete() {
		if (!$.fn.autocomplete) {
			return;
		}
		$('.transfo-product').each(function () {
			var $input = $(this);
			if ($input.data('transfo-ac')) {
				return;
			}
			$input.data('transfo-ac', 1);
			$input.autocomplete({
				minLength: 1,
				delay: 300,
				source: function (request, response) {
					$.getJSON(cfg.ajaxUrl, {action: 'searchproduct', term: request.term}).done(response).fail(function () {
						response([]);
					});
				},
				select: function (event, ui) {
					if (ui.item) {
						setProduct($input, ui.item.id);
					}
				}
			});
		});
	}

	/** Valeurs calculées, alertes de stock et équilibre, sans reconstruire les tableaux */
	function refresh() {
		groups.forEach(function (g) {
			var info = products[g.fk_product];
			$('.transfo-group[data-gid="' + g.gid + '"] tr[data-batch]').each(function () {
				var $tr = $(this);
				var batch = String($tr.attr('data-batch'));
				var sel = g.sel[batch];
				var q = sel ? inQty(info, batch, sel) : 0;
				var lot = stockLot(info, batch);
				var available = lot ? lot.qty : 0;
				$tr.find('.transfo-lot-computed').text(sel ? fmt(q) : '');
				$tr.find('.transfo-lot-warn').html(sel && round(available - q) < 0 ? '<span title="' + esc(t('DiamantutilsStockExceeded')) + '">' + cfg.warningIcon + '</span>' : '');
			});
		});

		rows.forEach(function (row) {
			var c = rowQty(row);
			var $qty = $('tr[data-uid="' + row.uid + '"] .transfo-qty');
			if (c.computed) {
				row.qty = String(c.qty);
				$qty.val(row.qty).prop('readonly', true).addClass('opacitymedium');
			} else {
				$qty.prop('readonly', false).removeClass('opacitymedium');
			}
		});

		refreshBalance();
		var info = products[firstInProduct()];
		$('#transfo-assistant').toggle(isProfile(info) && hasBatch(info));
	}

	function balance() {
		var sums = {IN: 0, OUT: 0, LOSS: 0};
		var count = {IN: 0, OUT: 0, LOSS: 0};
		var units = {};
		function add(direction, fk_product, q) {
			var info = products[fk_product];
			units[info ? (info.fk_unit || 0) : 0] = info ? info.unit_short : '';
			sums[direction] += q;
			if (q > 0) {
				count[direction]++;
			}
		}
		inLines().forEach(function (l) {
			add('IN', l.fk_product, l.qty);
		});
		rows.forEach(function (row) {
			if (row.fk_product) {
				add(row.direction, row.fk_product, rowQty(row).qty);
			}
		});
		var keys = Object.keys(units);
		var sameunit = (keys.length === 1 && keys[0] !== '0');
		var n = count.IN + count.OUT + count.LOSS;
		var diff = round(sums.IN - sums.OUT - sums.LOSS);
		return {
			sums: sums,
			sameunit: sameunit,
			unit: sameunit ? units[keys[0]] : '',
			diff: diff,
			tolerance: Math.pow(10, -cfg.decimals) * Math.max(1, n),
			ok: !sameunit || Math.abs(diff) <= Math.pow(10, -cfg.decimals) * Math.max(1, n),
			ready: count.IN > 0 && count.OUT > 0,
			any: keys.length > 0
		};
	}

	function refreshBalance() {
		var b = balance();
		var h = '';
		if (b.sameunit) {
			h += esc(t('DiamantutilsConsumed')) + ' : <b>' + fmt(b.sums.IN) + '</b> ' + esc(b.unit);
			h += ' — ' + esc(t('DiamantutilsProduced')) + ' : <b>' + fmt(b.sums.OUT) + '</b>';
			h += ' — ' + esc(t('DiamantutilsLoss')) + ' : <b>' + fmt(b.sums.LOSS) + '</b>';
			h += ' — ' + esc(t('DiamantutilsRemaining')) + ' : <b>' + fmt(b.diff) + '</b> ' + esc(b.unit) + ' ';
			h += b.ok ? '<span class="badge badge-status4">' + esc(t('DiamantutilsBalanceOk')) + '</span>'
				: '<span class="badge badge-status8">' + esc(t('DiamantutilsBalanceKo')) + '</span>';
		} else if (b.any) {
			h += cfg.warningIcon + ' <span class="warning">' + esc(t('DiamantutilsUnitsDiffer')) + '</span>';
		}
		if (!b.ready) {
			h += ' <span class="opacitymedium">' + esc(t('DiamantutilsNeedInOut')) + '</span>';
		}
		$('#transfo-balance').html(h);

		var restok = b.sameunit && b.diff > b.tolerance;
		$('#transfo-rest-loss, #transfo-rest-stock').toggleClass('butActionRefused', !restok).toggle(b.sameunit);
		$('#transfo-validate').prop('disabled', !(b.ok && b.ready));
	}

	/* ---------- Actions ---------- */

	function groupOf(el) {
		var gid = parseInt($(el).closest('.transfo-group').data('gid'), 10);
		for (var i = 0; i < groups.length; i++) {
			if (groups[i].gid === gid) {
				return groups[i];
			}
		}
		return null;
	}

	function rowOf(el) {
		var uid = parseInt($(el).closest('tr').data('uid'), 10);
		for (var i = 0; i < rows.length; i++) {
			if (rows[i].uid === uid) {
				return rows[i];
			}
		}
		return null;
	}

	function setProduct($input, id) {
		id = parseInt(id, 10) || 0;
		var g = groupOf($input);
		var row = g ? null : rowOf($input);
		loadProduct(id).always(function () {
			if (g) {
				g.fk_product = id;
				g.sel = {};
			} else if (row) {
				row.fk_product = id;
				row.batch = '';
				row.newlot = false;
				row.batchEdited = false;
				proposeBatch(row);
			}
			render();
		});
	}

	/** Lignes produites pas encore choisies par l'opérateur : relancer la proposition de lot */
	function reproposeOut() {
		rows.forEach(function (r) {
			if (r.direction === 'OUT') {
				proposeBatch(r);
			}
		});
	}

	function addRow(direction, data) {
		var row = newRow(direction, data);
		if (!row.fk_product) {
			row.fk_product = firstInProduct();
		}
		rows.push(row);
		loadProduct(row.fk_product).always(function () {
			proposeBatch(row);
			render();
		});
		return row;
	}

	/** Ligne reprenant le reste : 1 pièce de la longueur équivalente si elle tombe juste, sinon quantité directe */
	function restRow(direction, diff) {
		var pid = firstInProduct();
		var info = products[pid];
		var data = {fk_product: pid, qty: diff};
		if (isProfile(info)) {
			var len = diff / info.factor;
			if (Math.abs(len - Math.round(len)) < 0.01) {
				data = {fk_product: pid, nb_pieces: 1, length_mm: Math.round(len), qty: diff};
			}
		}
		if (direction === 'LOSS') {
			var first = inLines()[0];
			data.batch = first ? first.batch : '';
		}
		var row = newRow(direction, data);
		row.batchEdited = (direction === 'LOSS');
		rows.push(row);
		proposeBatch(row);
		render();
	}

	/**
	 * Assistant de découpe : N pièces de L mm.
	 * Pour chaque barre à ouvrir, on choisit parmi les longueurs de lot disponibles celle qui
	 * minimise la chute = Lbarre − min(⌊Lbarre / L⌋, pièces restantes) × L ; à chute égale, la plus courte.
	 */
	function planCut() {
		var $msg = $('#transfo-plan-msg');
		var n = Math.round(num($('#transfo-plan-nb').val()));
		var len = num($('#transfo-plan-len').val());
		var pid = firstInProduct();
		var info = products[pid];
		if (!pid || !info) {
			$msg.html(esc(t('DiamantutilsPlanNoProduct')));
			return;
		}
		if (!isProfile(info)) {
			$msg.html(esc(t('DiamantutilsPlanNotProfile')));
			return;
		}
		if (!(n > 0) || !(len > 0)) {
			return;
		}
		var hasLines = inLines().length > 0 || rows.some(function (r) { return r.fk_product; });
		if (hasLines && !window.confirm(t('DiamantutilsPlanReplace'))) {
			return;
		}

		// Barres disponibles par lot
		var avail = [];
		(info.lots || []).forEach(function (lot) {
			var l = lotLength(lot.batch);
			if (l === null || l < len || lot.pieces === null || lot.pieces === undefined) {
				return;
			}
			var count = Math.floor(lot.pieces + 0.01);
			if (count > 0) {
				avail.push({batch: lot.batch, length: l, count: count});
			}
		});

		var used = {};
		var usedOrder = [];
		var rests = {};
		var left = n;
		while (left > 0) {
			var best = null;
			avail.forEach(function (a) {
				if (a.count <= 0) {
					return;
				}
				var k = Math.min(Math.floor(a.length / len), left);
				var waste = a.length - k * len;
				if (!best || waste < best.waste || (waste === best.waste && a.length < best.a.length)) {
					best = {a: a, k: k, waste: waste};
				}
			});
			if (!best) {
				break;
			}
			best.a.count--;
			left -= best.k;
			if (!used[best.a.batch]) {
				used[best.a.batch] = 0;
				usedOrder.push(best.a.batch);
			}
			used[best.a.batch]++;
			var r = Math.round(best.waste);
			if (r > 0) {
				rests[r] = (rests[r] || 0) + 1;
			}
		}
		var placed = n - left;

		var g = newGroup(pid);
		usedOrder.forEach(function (batch) {
			g.sel[batch] = {nb_pieces: String(used[batch]), qty: ''};
		});
		groups = [g];
		rows = [];
		if (placed > 0) {
			var out = newRow('OUT', {fk_product: pid, nb_pieces: placed, length_mm: len});
			proposeBatch(out);
			rows.push(out);
		}
		Object.keys(rests).map(Number).sort(function (a, b) {
			return b - a;
		}).forEach(function (r) {
			var rest = newRow('OUT', {fk_product: pid, nb_pieces: rests[r], length_mm: r});
			proposeBatch(rest);
			rows.push(rest);
		});

		render();
		if (placed < n) {
			$msg.html('<span class="error">' + esc(t('DiamantutilsPlanShortage').replace('{n}', String(n - placed))) + '</span>');
		} else {
			$msg.html('<span class="ok">' + esc(t('DiamantutilsPlanDone')) + '</span>');
		}
	}

	/** Champs envoyés au serveur, construits à l'envoi du formulaire */
	function buildPosted() {
		var $c = $('#transfo-posted').empty();
		var i = 0;
		function add(l) {
			i++;
			['direction', 'fk_product', 'batch', 'nb_pieces', 'length_mm', 'qty'].forEach(function (k) {
				$c.append($('<input type="hidden">').attr('name', 'lines[' + i + '][' + k + ']').val(str(l[k])));
			});
		}
		groups.forEach(function (g) {
			if (!g.fk_product) {
				return;
			}
			var info = products[g.fk_product];
			Object.keys(g.sel).forEach(function (batch) {
				var sel = g.sel[batch];
				add({direction: 'IN', fk_product: g.fk_product, batch: batch, nb_pieces: sel.nb_pieces, length_mm: '', qty: inQty(info, batch, sel)});
			});
		});
		['OUT', 'LOSS'].forEach(function (direction) {
			rows.forEach(function (row) {
				if (row.direction === direction && row.fk_product) {
					add({direction: direction, fk_product: row.fk_product, batch: row.batch, nb_pieces: row.nb_pieces, length_mm: row.length_mm, qty: rowQty(row).qty});
				}
			});
		});
	}

	/* ---------- Événements ---------- */

	$(document).on('change', '.transfo-lot-check', function () {
		var g = groupOf(this);
		var batch = String($(this).closest('tr').attr('data-batch'));
		if (!g) {
			return;
		}
		if (this.checked) {
			checkLot(g, batch);
		} else {
			delete g.sel[batch];
		}
		reproposeOut();
		render();
	});

	$(document).on('input', '.transfo-lot-nb, .transfo-lot-qty', function () {
		var g = groupOf(this);
		var $tr = $(this).closest('tr');
		var batch = String($tr.attr('data-batch'));
		if (!g) {
			return;
		}
		if (!g.sel[batch]) {
			g.sel[batch] = {nb_pieces: '', qty: ''};
			$tr.find('.transfo-lot-check').prop('checked', true);
		}
		if ($(this).hasClass('transfo-lot-nb')) {
			g.sel[batch].nb_pieces = $(this).val();
		} else {
			g.sel[batch].qty = $(this).val();
		}
		refresh();
	});

	$(document).on('change', '.transfo-batch-select', function () {
		var row = rowOf(this);
		if (!row) {
			return;
		}
		row.batchEdited = true;
		if ($(this).val() === NEWLOT) {
			row.newlot = true;
			row.batch = '';
		} else {
			row.newlot = false;
			row.batch = $(this).val();
		}
		render();
	});

	$(document).on('input', '.transfo-batch-text', function () {
		var row = rowOf(this);
		if (row) {
			row.batch = $(this).val();
			row.batchEdited = true;
		}
	});

	$(document).on('input', '.transfo-nb, .transfo-len, .transfo-qty', function () {
		var row = rowOf(this);
		if (!row) {
			return;
		}
		var $el = $(this);
		if ($el.hasClass('transfo-nb')) {
			row.nb_pieces = $el.val();
		} else if ($el.hasClass('transfo-len')) {
			row.length_mm = $el.val();
			if (!row.batchEdited) {
				var before = row.batch + '|' + row.newlot;
				proposeBatch(row);
				if (before !== row.batch + '|' + row.newlot) {
					// Réafficher la cellule lot sans perdre le focus du champ longueur
					$el.closest('tr').find('td').eq(1).html(lotCellHtml(row, products[row.fk_product]));
				}
			}
		} else {
			row.qty = $el.val();
		}
		refresh();
	});

	$(document).on('change', '.transfo-product', function () {
		// Champ vidé : plus de produit
		if ($(this).val() === '') {
			setProduct($(this), 0);
		}
	});

	$(document).on('click', '.transfo-group-del', function (e) {
		e.preventDefault();
		var g = groupOf(this);
		groups = groups.filter(function (x) {
			return x !== g;
		});
		reproposeOut();
		render();
	});

	$(document).on('click', '.transfo-del', function (e) {
		e.preventDefault();
		var row = rowOf(this);
		rows = rows.filter(function (r) {
			return r !== row;
		});
		render();
	});

	$(document).on('click', '.transfo-toloss, .transfo-tostock', function (e) {
		e.preventDefault();
		var row = rowOf(this);
		if (row) {
			row.direction = $(this).hasClass('transfo-toloss') ? 'LOSS' : 'OUT';
			if (row.direction === 'OUT') {
				row.newlot = !knownLot(products[row.fk_product], row.batch);
			}
			render();
		}
	});

	$('#transfo-add-in').on('click', function (e) {
		e.preventDefault();
		groups.push(newGroup(0));
		render();
	});
	$('#transfo-add-out').on('click', function (e) {
		e.preventDefault();
		addRow('OUT');
	});
	$('#transfo-add-loss').on('click', function (e) {
		e.preventDefault();
		var first = inLines()[0];
		var row = addRow('LOSS', {batch: first ? first.batch : ''});
		row.batchEdited = true;
	});

	$('#transfo-rest-loss, #transfo-rest-stock').on('click', function (e) {
		e.preventDefault();
		var b = balance();
		if (b.sameunit && b.diff > b.tolerance) {
			restRow(this.id === 'transfo-rest-loss' ? 'LOSS' : 'OUT', b.diff);
		}
	});

	$('#transfo-plan-go').on('click', function (e) {
		e.preventDefault();
		planCut();
	});

	// Changement d'entrepôt : recharger les lots de tous les produits
	$('#fk_warehouse').on('change', function () {
		var pending = Object.keys(products).map(function (id) {
			return loadProduct(parseInt(id, 10), true);
		});
		$.when.apply($, pending).always(render);
	});

	// Commande client : charger ses lignes
	$('#fk_commande').on('change', function () {
		var $sel = $('#fk_commandedet');
		$sel.empty().append('<option value="-1">&nbsp;</option>');
		var fk = parseInt($(this).val(), 10);
		if (fk > 0) {
			$.getJSON(cfg.ajaxUrl, {action: 'orderlines', fk_commande: fk}).done(function (lines) {
				(lines || []).forEach(function (l) {
					$sel.append($('<option>').val(l.id).text(l.label));
				});
				$sel.trigger('change');
			});
		} else {
			$sel.trigger('change');
		}
	});

	$('#transfoform').on('submit', buildPosted);

	/* ---------- Initialisation ---------- */

	(cfg.lines || []).forEach(function (l) {
		if (l.direction === 'IN') {
			var pid = parseInt(l.fk_product, 10) || 0;
			var g = null;
			groups.forEach(function (x) {
				if (x.fk_product === pid) {
					g = x;
				}
			});
			if (!g) {
				g = newGroup(pid);
				groups.push(g);
			}
			var batch = l.batch || '';
			var prev = g.sel[batch];
			g.sel[batch] = {
				nb_pieces: str(prev ? num(prev.nb_pieces) + num(l.nb_pieces) || '' : (l.nb_pieces === null || l.nb_pieces === undefined ? '' : round(num(l.nb_pieces)))),
				qty: str(prev ? round(num(prev.qty) + num(l.qty)) : round(num(l.qty)))
			};
		} else {
			var row = newRow(l.direction, l);
			row.newlot = !knownLot(products[row.fk_product], row.batch);
			rows.push(row);
		}
	});
	if (!groups.length) {
		groups.push(newGroup(cfg.defaultProduct));
	}
	if (!rows.some(function (r) { return r.direction === 'OUT'; })) {
		var out = newRow('OUT', {fk_product: firstInProduct()});
		proposeBatch(out);
		rows.push(out);
	}
	render();
})(window.jQuery);
