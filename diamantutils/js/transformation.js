/**
 * DiamantUtils — écran de transformation de stock par lots
 *
 * - saisie des lignes consommées (IN), produites (OUT) et pertes (LOSS) ;
 * - calcul en direct des quantités en mode profilé (pièces × longueur) ;
 * - indicateur d'équilibre (R4) et gestion du reste ;
 * - assistant de découpe (first-fit decreasing, lots les plus courts d'abord).
 *
 * Le serveur recalcule et contrôle tout à la validation : il fait foi.
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
	var rows = [];
	var uidSeq = 0;
	var DIRECTIONS = ['IN', 'OUT', 'LOSS'];

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

	function esc(s) {
		return $('<div>').text(s === null || s === undefined ? '' : String(s)).html();
	}

	function tolerance() {
		return Math.pow(10, -cfg.decimals) * Math.max(1, rows.length);
	}

	/** Premier entier du nom de lot (« Longueur 3000 » / « Longueur 3000mm ») */
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

	function isProfile(info) {
		return !!info && (info.mode === 'surface' || info.mode === 'size') && info.factor > 0;
	}

	function pieceQty(info, length) {
		return (isProfile(info) && length > 0) ? length * info.factor : null;
	}

	function hasBatch(info) {
		return !!info && cfg.batchEnabled && info.status_batch > 0;
	}

	function findLot(info, batch) {
		if (!info || !info.lots) {
			return null;
		}
		for (var i = 0; i < info.lots.length; i++) {
			if (info.lots[i].batch === batch) {
				return info.lots[i];
			}
		}
		return null;
	}

	function warehouse() {
		return $('#fk_warehouse').val() || 0;
	}

	/* ---------- Modèle ---------- */

	function newRow(direction, data) {
		data = data || {};
		uidSeq++;
		return {
			uid: uidSeq,
			direction: direction,
			fk_product: parseInt(data.fk_product, 10) || 0,
			batch: data.batch || '',
			nb_pieces: (data.nb_pieces === null || data.nb_pieces === undefined) ? '' : String(round(num(data.nb_pieces))),
			length_mm: (data.length_mm === null || data.length_mm === undefined) ? '' : String(round(num(data.length_mm))),
			qty: (data.qty === null || data.qty === undefined || data.qty === '') ? '' : String(round(num(data.qty))),
			batchEdited: !!data.batch
		};
	}

	function rowLength(row) {
		if (row.direction === 'IN') {
			return lotLength(row.batch);
		}
		return num(row.length_mm);
	}

	/** Quantité de la ligne, recalculée comme côté serveur */
	function computeRow(row) {
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

	/** Le mode profilé s'applique-t-il à cette ligne ? (IN : il faut une longueur lisible sur le lot) */
	function rowIsProfile(row) {
		var info = products[row.fk_product];
		if (!isProfile(info)) {
			return false;
		}
		return row.direction !== 'IN' || lotLength(row.batch) !== null;
	}

	function firstRow(direction) {
		for (var i = 0; i < rows.length; i++) {
			if (rows[i].direction === direction && rows[i].fk_product > 0) {
				return rows[i];
			}
		}
		return null;
	}

	function defaultProductId() {
		var r = firstRow('IN') || firstRow('OUT');
		return r ? r.fk_product : (cfg.defaultProduct || 0);
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

	/* ---------- Rendu ---------- */

	function headerHtml(direction) {
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

	function lotCellHtml(row, info) {
		var name = 'lines[' + row.uid + '][batch]';
		if (!info) {
			return '<input type="hidden" name="' + name + '" value="">';
		}
		if (!hasBatch(info)) {
			var h = '<input type="hidden" name="' + name + '" value="">';
			if (row.direction === 'IN') {
				h += '<span class="opacitymedium">' + esc(t('DiamantutilsStockOfLot')) + ' ' + fmt(info.stock || 0) + ' ' + esc(info.unit_short) + '</span>';
			}
			return h;
		}
		if (row.direction === 'IN') {
			var s = '<select class="flat minwidth200 transfo-batch" name="' + name + '">';
			s += '<option value="">' + esc(t('DiamantutilsChooseLot')) + '</option>';
			var found = false;
			(info.lots || []).forEach(function (lot) {
				var label = lot.batch + ' — ' + fmt(lot.qty) + ' ' + info.unit_short;
				if (lot.pieces !== null) {
					label += ' (≈ ' + fmt(lot.pieces) + ' p.' + (lot.multiple ? '' : ' ⚠') + ')';
				}
				var sel = (lot.batch === row.batch);
				found = found || sel;
				s += '<option value="' + esc(lot.batch) + '"' + (sel ? ' selected' : '') + '>' + esc(label) + '</option>';
			});
			if (row.batch && !found) {
				s += '<option value="' + esc(row.batch) + '" selected>' + esc(row.batch + ' — ' + t('DiamantutilsStockNone')) + '</option>';
			}
			s += '</select>';
			s += ' <span class="transfo-lotinfo"></span>';
			return s;
		}
		return '<input type="text" class="flat minwidth150 transfo-batch" name="' + name + '" value="' + esc(row.batch) + '" maxlength="128">';
	}

	function rowHtml(row) {
		var info = products[row.fk_product];
		var p = 'lines[' + row.uid + ']';
		var h = '<tr class="oddeven" data-uid="' + row.uid + '">';

		// Produit
		h += '<td class="nowraponall">';
		h += '<input type="hidden" name="' + p + '[direction]" value="' + row.direction + '">';
		h += '<input type="hidden" class="transfo-fk-product" name="' + p + '[fk_product]" value="' + (row.fk_product || '') + '">';
		h += '<input type="text" class="flat minwidth200 transfo-product" placeholder="' + esc(t('DiamantutilsSearchProduct')) + '" value="' + esc(info ? info.ref + ' - ' + info.label : '') + '">';
		if (info && info.nowidth) {
			h += '<br><span class="warning small">' + cfg.warningIcon + ' ' + esc(t('DiamantutilsNoWidth')) + '</span>';
		}
		h += '</td>';

		// Lot
		h += '<td>' + lotCellHtml(row, info) + '</td>';

		// Pièces / longueur
		var showNb = info && (rowIsProfile(row) || info.mode === 'qty');
		var showLen = info && isProfile(info) && row.direction !== 'IN';
		h += '<td class="right">';
		if (showNb) {
			h += '<input type="text" class="flat width50 right transfo-nb" name="' + p + '[nb_pieces]" value="' + esc(row.nb_pieces) + '">';
		} else {
			h += '<input type="hidden" name="' + p + '[nb_pieces]" value="">';
		}
		h += '</td>';
		h += '<td class="right">';
		if (showLen) {
			h += '<input type="text" class="flat width75 right transfo-len" name="' + p + '[length_mm]" value="' + esc(row.length_mm) + '">';
		} else {
			h += '<input type="hidden" name="' + p + '[length_mm]" value="">';
			if (row.direction === 'IN' && rowIsProfile(row)) {
				h += '<span class="opacitymedium" title="' + esc(t('DiamantutilsLengthFromLot')) + '">' + lotLength(row.batch) + '</span>';
			}
		}
		h += '</td>';

		// Quantité
		h += '<td class="right nowraponall"><input type="text" class="flat width75 right transfo-qty" name="' + p + '[qty]" value="' + esc(row.qty) + '"> <span class="transfo-rowwarn"></span></td>';
		h += '<td>' + esc(info ? info.unit_short : '') + '</td>';

		// Actions
		h += '<td class="right nowraponall">';
		if (row.direction === 'OUT') {
			h += '<a href="#" class="transfo-toloss marginrightonly" title="' + esc(t('DiamantutilsToLoss')) + '">→ ' + esc(t('DiamantutilsLoss')) + '</a>';
		} else if (row.direction === 'LOSS') {
			h += '<a href="#" class="transfo-tostock marginrightonly" title="' + esc(t('DiamantutilsToStock')) + '">→ ' + esc(t('DiamantutilsProduced')) + '</a>';
		}
		h += '<a href="#" class="transfo-del">' + cfg.deleteIcon + '</a>';
		h += '</td>';

		h += '</tr>';
		return h;
	}

	function render() {
		DIRECTIONS.forEach(function (direction) {
			var $table = $('#transfo-table-' + direction);
			$table.find('thead').html(headerHtml(direction));
			var html = '';
			rows.forEach(function (row) {
				if (row.direction === direction) {
					html += rowHtml(row);
				}
			});
			$table.find('tbody').html(html);
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
					var row = rowOf($input);
					if (row && ui.item) {
						setProduct(row, ui.item.id);
					}
				}
			});
		});
	}

	/** Met à jour les valeurs calculées, les avertissements et l'équilibre, sans reconstruire les lignes */
	function refresh() {
		// Stock consommé cumulé par (produit, lot)
		var used = {};
		rows.forEach(function (row) {
			if (row.direction === 'IN' && row.fk_product) {
				var key = row.fk_product + '|' + row.batch;
				used[key] = (used[key] || 0) + computeRow(row).qty;
			}
		});

		rows.forEach(function (row) {
			var $tr = $('tr[data-uid="' + row.uid + '"]');
			var info = products[row.fk_product];
			var c = computeRow(row);
			var $qty = $tr.find('.transfo-qty');
			if (c.computed) {
				row.qty = String(c.qty);
				$qty.val(row.qty).prop('readonly', true).addClass('opacitymedium');
			} else {
				$qty.prop('readonly', false).removeClass('opacitymedium');
			}

			var warn = '';
			if (row.direction === 'IN' && info) {
				var lot = hasBatch(info) ? findLot(info, row.batch) : null;
				var available = hasBatch(info) ? (lot ? lot.qty : 0) : (info.stock || 0);
				if ((!hasBatch(info) || row.batch) && round(available - (used[row.fk_product + '|' + row.batch] || 0)) < 0) {
					warn = '<span title="' + esc(t('DiamantutilsStockExceeded')) + '">' + cfg.warningIcon + '</span>';
				}
				// Infos du lot choisi : quantité + nombre de pièces équivalent
				var $info = $tr.find('.transfo-lotinfo');
				if (lot) {
					var txt = esc(t('DiamantutilsStockOfLot')) + ' ' + fmt(lot.qty) + ' ' + esc(info.unit_short);
					if (lot.pieces !== null) {
						if (lot.multiple) {
							txt += ' ≈ ' + fmt(lot.pieces) + ' p.';
						} else {
							txt += ' <span style="color: #e08000" title="' + esc(t('DiamantutilsLotNotMultiple')) + '">≈ ' + fmt(lot.pieces) + ' p.</span>';
						}
					}
					$info.html('<span class="opacitymedium small">' + txt + '</span>');
				} else {
					$info.html('');
				}
			}
			$tr.find('.transfo-rowwarn').html(warn);
		});

		refreshBalance();
		refreshAssistant();
	}

	function balance() {
		var sums = {IN: 0, OUT: 0, LOSS: 0};
		var units = {};
		var count = {IN: 0, OUT: 0, LOSS: 0};
		rows.forEach(function (row) {
			if (!row.fk_product) {
				return;
			}
			var info = products[row.fk_product];
			units[info ? (info.fk_unit || 0) : 0] = info ? info.unit_short : '';
			var q = computeRow(row).qty;
			sums[row.direction] += q;
			if (q > 0) {
				count[row.direction]++;
			}
		});
		var keys = Object.keys(units);
		var sameunit = (keys.length === 1 && keys[0] !== '0');
		var diff = round(sums.IN - sums.OUT - sums.LOSS);
		return {
			sums: sums,
			count: count,
			sameunit: sameunit,
			unit: sameunit ? units[keys[0]] : '',
			diff: diff,
			ok: !sameunit || Math.abs(diff) <= tolerance(),
			ready: count.IN > 0 && count.OUT > 0
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
		} else if (rows.some(function (r) { return r.fk_product; })) {
			h += cfg.warningIcon + ' <span class="warning">' + esc(t('DiamantutilsUnitsDiffer')) + '</span>';
		}
		if (!b.ready) {
			h += ' <span class="opacitymedium">' + esc(t('DiamantutilsNeedInOut')) + '</span>';
		}
		$('#transfo-balance').html(h);

		var restok = b.sameunit && b.diff > tolerance();
		$('#transfo-rest-loss, #transfo-rest-stock').toggleClass('butActionRefused', !restok).toggle(b.sameunit);
		$('#transfo-validate').prop('disabled', !(b.ok && b.ready));
	}

	function refreshAssistant() {
		var info = products[defaultProductId()];
		$('#transfo-assistant').toggle(isProfile(info) && hasBatch(info));
	}

	/* ---------- Actions ---------- */

	function rowOf(el) {
		var uid = parseInt($(el).closest('tr').data('uid'), 10);
		for (var i = 0; i < rows.length; i++) {
			if (rows[i].uid === uid) {
				return rows[i];
			}
		}
		return null;
	}

	function setProduct(row, id) {
		row.fk_product = parseInt(id, 10) || 0;
		row.batch = '';
		row.batchEdited = false;
		loadProduct(row.fk_product).always(function () {
			proposeBatch(row);
			render();
		});
	}

	/** Lot proposé pour une ligne produite, tant que l'opérateur ne l'a pas modifié */
	function proposeBatch(row) {
		var info = products[row.fk_product];
		if (row.direction === 'IN' || !hasBatch(info) || row.batchEdited) {
			return;
		}
		var len = num(row.length_mm);
		if (isProfile(info) && len > 0) {
			row.batch = lotName(len);
		} else if (row.direction === 'OUT') {
			// Hors profilé (peinture, changement d'unité) : même lot que la première consommation
			var first = firstRow('IN');
			row.batch = first ? first.batch : '';
		}
	}

	function addRow(direction, data) {
		var row = newRow(direction, data);
		rows.push(row);
		if (!row.fk_product && direction !== 'IN') {
			row.fk_product = defaultProductId();
		}
		loadProduct(row.fk_product).always(function () {
			proposeBatch(row);
			render();
		});
		return row;
	}

	/** Ligne reprenant le reste : 1 pièce de la longueur équivalente si elle tombe juste, sinon quantité directe */
	function restRow(direction, diff) {
		var first = firstRow('IN');
		var pid = first ? first.fk_product : defaultProductId();
		var info = products[pid];
		var data = {fk_product: pid, qty: diff};
		if (isProfile(info)) {
			var len = diff / info.factor;
			if (Math.abs(len - Math.round(len)) < 0.01) {
				data = {fk_product: pid, nb_pieces: 1, length_mm: Math.round(len), qty: diff};
			}
		}
		if (direction === 'LOSS' && first) {
			data.batch = first.batch;
		}
		var row = newRow(direction, data);
		row.batchEdited = (direction === 'LOSS');
		rows.push(row);
		proposeBatch(row);
		render();
	}

	/**
	 * Assistant de découpe : N pièces de L mm.
	 * First-fit decreasing : chaque pièce va dans la première barre entamée qui la contient,
	 * sinon on entame la barre la plus courte qui convient (chutes d'abord).
	 */
	function planCut() {
		var $msg = $('#transfo-plan-msg');
		var n = Math.round(num($('#transfo-plan-nb').val()));
		var len = num($('#transfo-plan-len').val());
		var pid = defaultProductId();
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
		if (rows.some(function (r) { return r.fk_product; }) && !window.confirm(t('DiamantutilsPlanReplace'))) {
			return;
		}

		// Barres disponibles, de la plus courte à la plus longue
		var bars = [];
		(info.lots || []).forEach(function (lot) {
			if (lot.length === null || lot.length < len || lot.pieces === null) {
				return;
			}
			var count = Math.floor(lot.pieces + 0.01);
			for (var i = 0; i < count; i++) {
				bars.push({batch: lot.batch, length: lot.length});
			}
		});
		bars.sort(function (a, b) {
			return a.length - b.length;
		});

		var opened = [];
		var placed = 0;
		for (var k = 0; k < n; k++) {
			var bin = null;
			for (var j = 0; j < opened.length; j++) {
				if (opened[j].remaining >= len) {
					bin = opened[j];
					break;
				}
			}
			if (!bin) {
				var bar = bars.shift();
				if (!bar) {
					break;
				}
				bin = {batch: bar.batch, length: bar.length, remaining: bar.length};
				opened.push(bin);
			}
			bin.remaining -= len;
			placed++;
		}

		// Lignes consommées, regroupées par lot
		var byLot = {};
		var lotOrder = [];
		var rests = {};
		var restOrder = [];
		opened.forEach(function (bin) {
			if (!byLot[bin.batch]) {
				byLot[bin.batch] = 0;
				lotOrder.push(bin.batch);
			}
			byLot[bin.batch]++;
			var r = Math.round(bin.remaining);
			if (r > 0) {
				if (!rests[r]) {
					rests[r] = 0;
					restOrder.push(r);
				}
				rests[r]++;
			}
		});

		rows = [];
		lotOrder.forEach(function (batch) {
			rows.push(newRow('IN', {fk_product: pid, batch: batch, nb_pieces: byLot[batch]}));
		});
		if (placed > 0) {
			var out = newRow('OUT', {fk_product: pid, nb_pieces: placed, length_mm: len});
			proposeBatch(out);
			rows.push(out);
		}
		restOrder.sort(function (a, b) {
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

	/* ---------- Événements ---------- */

	$(document).on('change', '#transfo-table-IN .transfo-batch', function () {
		var row = rowOf(this);
		if (row) {
			row.batch = $(this).val();
			// Les lignes produites hors profilé reprennent le lot consommé tant qu'il n'a pas été modifié
			rows.forEach(function (r) {
				if (r.direction === 'OUT') {
					proposeBatch(r);
				}
			});
			render();
		}
	});

	$(document).on('input', '#transfo-table-OUT .transfo-batch, #transfo-table-LOSS .transfo-batch', function () {
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
				proposeBatch(row);
				$el.closest('tr').find('.transfo-batch').val(row.batch);
			}
		} else {
			row.qty = $el.val();
		}
		refresh();
	});

	$(document).on('change', '.transfo-product', function () {
		// Champ vidé : la ligne n'a plus de produit
		var row = rowOf(this);
		if (row && $(this).val() === '' && row.fk_product) {
			setProduct(row, 0);
		}
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
			render();
		}
	});

	$('#transfo-add-in').on('click', function (e) {
		e.preventDefault();
		addRow('IN', {fk_product: defaultProductId()});
	});
	$('#transfo-add-out').on('click', function (e) {
		e.preventDefault();
		addRow('OUT');
	});
	$('#transfo-add-loss').on('click', function (e) {
		e.preventDefault();
		var first = firstRow('IN');
		var row = addRow('LOSS', {batch: first ? first.batch : ''});
		row.batchEdited = true;
	});

	$('#transfo-rest-loss, #transfo-rest-stock').on('click', function (e) {
		e.preventDefault();
		var b = balance();
		if (!b.sameunit || !(b.diff > tolerance())) {
			return;
		}
		restRow(this.id === 'transfo-rest-loss' ? 'LOSS' : 'OUT', b.diff);
	});

	$('#transfo-plan-go').on('click', function (e) {
		e.preventDefault();
		planCut();
	});

	// Changement d'entrepôt : recharger les lots de tous les produits
	$('#fk_warehouse').on('change', function () {
		var ids = Object.keys(products);
		var pending = ids.map(function (id) {
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

	$('#transfo-validate').on('click', function (e) {
		if (!window.confirm(t('DiamantutilsConfirmValidate'))) {
			e.preventDefault();
		}
	});

	/* ---------- Initialisation ---------- */

	(cfg.lines || []).forEach(function (l) {
		rows.push(newRow(l.direction, l));
	});
	if (!rows.length) {
		rows.push(newRow('IN', {fk_product: cfg.defaultProduct}));
		var out = newRow('OUT', {fk_product: cfg.defaultProduct});
		proposeBatch(out);
		rows.push(out);
	}
	render();
})(window.jQuery);
