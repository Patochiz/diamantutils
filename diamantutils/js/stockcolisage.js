/**
 * DiamantUtils — colisage des lots : onglet Stock de la fiche produit et tableau de stock par lots
 *
 * Sans bibliothèque externe. Configuration : attribut data-config du bloc #dut-stockcolisage
 * (onglet Stock) ou du bloc .dut-sc-standalone (tableau de stock par lots : pas de placement,
 * produit de chaque cellule lu sur l'élément [data-product] qui la contient).
 *  - place le bloc juste avant le tableau natif, l'affiche, puis masque le tableau natif ;
 *    tableau natif introuvable ou erreur : le bloc est retiré, le tableau natif reste visible ;
 *  - repli / dépli des lots par entrepôt, « Afficher tous les lots » / « Masquer les lots » ;
 *  - édition en ligne (ajax/lotcolisage.php) du colisage en mode manuel, des pièces par colis
 *    et de la qté par pièce : Entrée ou sortie du champ = enregistrer, Échap = annuler. Un
 *    champ numérique enregistré renvoie le colisage recalculé de chaque ligne du lot.
 */
(function () {
	'use strict';

	var block = document.getElementById('dut-stockcolisage');
	var standalone = false;
	if (!block) {
		block = document.querySelector('.dut-sc-standalone');
		standalone = !!block;
	}
	if (!block) {
		return;
	}

	var cfg;
	try {
		cfg = JSON.parse(block.getAttribute('data-config') || '{}');
	} catch (e) {
		if (!standalone) {
			block.parentNode.removeChild(block);
		}
		return;
	}
	var lang = cfg.lang || {};

	/* Tableau natif : bloc .div-table-responsive qui contient #show_all, placé après le nôtre */
	function findNative() {
		var anchor = document.getElementById('show_all') || document.getElementById('hide_all');
		if (!anchor) {
			return null;
		}
		var wrap = anchor.closest('div.div-table-responsive');
		if (!wrap || wrap === block || block.contains(wrap)) {
			return null;
		}
		return wrap;
	}

	function place() {
		var native = null;
		try {
			native = findNative();
			if (!native) {
				throw new Error('native stock table not found');
			}
			native.parentNode.insertBefore(block, native);
			block.style.display = '';
			bindCollapse();
			if (cfg.canEdit || cfg.canEditNum) {
				bindEdit();
			}
			native.style.display = 'none';
			native.classList.add('dut-sc-native-hidden');
		} catch (e) {
			// Repli : le tableau natif reste (ou redevient) visible
			if (window.console) {
				console.log('DiamantUtils stockcolisage: ' + e.message);
			}
			if (native) {
				native.style.display = '';
			}
			if (block.parentNode) {
				block.parentNode.removeChild(block);
			}
		}
	}

	/* Repli des lots par entrepôt */
	function lotRows(warehouse) {
		return block.querySelectorAll('tr.dut-sc-wh' + warehouse);
	}
	function setToggleAll(allShown) {
		var show = block.querySelector('.dut-sc-showall');
		var hide = block.querySelector('.dut-sc-hideall');
		if (show && hide) {
			show.classList.toggle('hideobject', allShown);
			hide.classList.toggle('hideobject', !allShown);
		}
	}
	function setWarehouse(link, open) {
		var rows = lotRows(link.getAttribute('data-warehouse'));
		for (var i = 0; i < rows.length; i++) {
			rows[i].style.display = open ? '' : 'none';
		}
		link.textContent = open ? '(-)' : '(+)';
	}
	function bindCollapse() {
		var toggles = block.querySelectorAll('a.dut-sc-toggle');
		Array.prototype.forEach.call(toggles, function (link) {
			link.addEventListener('click', function (e) {
				e.preventDefault();
				var open = link.textContent.indexOf('+') >= 0;
				setWarehouse(link, open);
				if (open) {
					setToggleAll(true);
				}
			});
		});
		var all = function (open) {
			return function (e) {
				e.preventDefault();
				Array.prototype.forEach.call(toggles, function (link) {
					setWarehouse(link, open);
				});
				setToggleAll(open);
			};
		};
		var show = block.querySelector('.dut-sc-showall');
		var hide = block.querySelector('.dut-sc-hideall');
		if (show) {
			show.addEventListener('click', all(true));
		}
		if (hide) {
			hide.addEventListener('click', all(false));
		}
	}

	/* Édition en ligne : colisage (mode manuel), pièces par colis, qté par pièce */
	function productOf(td) {
		var holder = td.closest('[data-product]');
		return holder ? String(holder.getAttribute('data-product')) : String(cfg.product);
	}
	function cellsOf(product, batch, field) {
		return Array.prototype.filter.call(block.querySelectorAll('td[data-field="' + field + '"]'), function (td) {
			return td.getAttribute('data-batch') === batch && productOf(td) === product;
		});
	}

	function setView(td, text) {
		td.innerHTML = '';
		var span = document.createElement('span');
		span.className = 'dut-sc-text';
		span.textContent = text;
		td.appendChild(span);
		td.classList.toggle('dut-sc-empty', text === '');
		if (td.getAttribute('data-field') === 'colisage') {
			if (text === '') {
				td.setAttribute('title', lang.editTitle || '');
			} else {
				td.setAttribute('title', text);
			}
		}
		td.classList.remove('dut-sc-editing', 'dut-sc-saving');
	}

	function flash(td, text, isError) {
		var old = td.querySelector('.dut-sc-msg');
		if (old) {
			old.parentNode.removeChild(old);
		}
		var msg = document.createElement('span');
		msg.className = 'dut-sc-msg ' + (isError ? 'dut-sc-msg-error' : 'dut-sc-msg-ok');
		msg.textContent = text;
		td.appendChild(msg);
		window.setTimeout(function () {
			if (msg.parentNode) {
				msg.parentNode.removeChild(msg);
			}
		}, isError ? 6000 : (text.length > 2 ? 6000 : 1500));
	}

	/* Colisage d'une ligne en mode automatique (lecture seule) ou manuel (modifiable) */
	function setColisageAuto(td, text, warn) {
		setView(td, text);
		td.classList.add('dut-sc-auto');
		td.classList.remove('dut-sc-editable', 'dut-sc-empty');
		td.removeAttribute('tabindex');
		td.setAttribute('title', (text ? text + ' — ' : '') + (lang.autoTitle || ''));
		if (warn) {
			var w = document.createElement('span');
			w.className = 'dut-sc-calcwarn';
			w.textContent = '⚠ ' + warn;
			td.appendChild(w);
		}
	}
	function setColisageManual(td, text, isHtml) {
		td.classList.remove('dut-sc-auto');
		setView(td, text);
		if (cfg.canEdit) {
			td.classList.add('dut-sc-editable');
			td.setAttribute('tabindex', '0');
			td.setAttribute('data-html', isHtml ? '1' : '0');
		}
	}

	/* Réponse d'un champ numérique : valeurs du lot et colisage de toutes ses lignes
	   (ligne d'un entrepôt, ou ligne « 0 » = quantité totale du lot dans le tableau de stock) */
	function applyNumResult(product, batch, data) {
		cellsOf(product, batch, 'pieces_par_colis').forEach(function (td) {
			setView(td, data.ppc || '');
			td.setAttribute('title', data.numTitle || '');
		});
		cellsOf(product, batch, 'qte_par_piece').forEach(function (td) {
			setView(td, data.qpp || '');
			td.setAttribute('title', data.numTitle || '');
		});
		var rows = {};
		(data.rows || []).forEach(function (r) {
			rows[String(r.warehouse)] = r;
		});
		cellsOf(product, batch, 'colisage').forEach(function (td) {
			if (data.mode === 'auto') {
				var wh = td.getAttribute('data-warehouse');
				var r = (wh === '0') ? {text: data.colisage || '', warn: data.warn || ''} : (rows[wh] || {text: '', warn: ''});
				setColisageAuto(td, r.text, r.warn);
			} else {
				setColisageManual(td, data.colisage || '', !!data.colisageHtml);
			}
		});
	}

	function save(td, input, value, original) {
		var field = td.getAttribute('data-field') || 'colisage';
		var batch = td.getAttribute('data-batch');
		var product = productOf(td);
		td.classList.add('dut-sc-saving');
		input.disabled = true;
		var body = new URLSearchParams();
		body.append('token', cfg.token);
		body.append('fk_product', product);
		body.append('batch', batch);
		body.append('field', field);
		body.append(field === 'colisage' ? 'colisage' : 'value', value);

		fetch(cfg.url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {'Accept': 'application/json'},
			body: body
		}).then(function (resp) {
			return resp.text().then(function (txt) {
				var data = null;
				try {
					data = JSON.parse(txt);
				} catch (e) {
					// Réponse non JSON : refus de la protection anti-injection de Dolibarr (403) ou erreur serveur
					data = {ok: false, error: (resp.status === 403 && lang.refused) ? lang.refused : (lang.network || 'Error') + ' (HTTP ' + resp.status + ')'};
				}
				return data;
			});
		}).then(function (data) {
			if (data && data.ok) {
				if (field === 'colisage') {
					setView(td, typeof data.value === 'string' ? data.value : value);
					td.setAttribute('data-html', '0');
					flash(td, '✓', false);
				} else {
					applyNumResult(product, batch, data);
					flash(td, data.replaced ? '✓ ' + (lang.replaced || '').replace('%s', data.replaced) : '✓', false);
				}
			} else {
				setView(td, original);
				flash(td, (data && data.error) ? data.error : (lang.error || 'Error'), true);
			}
		}).catch(function (err) {
			setView(td, original);
			flash(td, (lang.network || 'Error') + (err && err.message ? ' : ' + err.message : ''), true);
		});
	}

	function startEdit(td) {
		if (!td.classList.contains('dut-sc-editable') || td.classList.contains('dut-sc-editing') || td.classList.contains('dut-sc-saving')) {
			return;
		}
		var field = td.getAttribute('data-field') || 'colisage';
		var textNode = td.querySelector('.dut-sc-text');
		var original = textNode ? textNode.textContent : '';
		var done = false;

		td.classList.add('dut-sc-editing');
		td.innerHTML = '';
		var input = document.createElement('input');
		input.type = 'text';
		input.className = 'flat dut-sc-input' + (field === 'colisage' ? '' : ' dut-sc-input-num right');
		input.maxLength = (field === 'colisage' ? (cfg.maxLength || 200) : 30);
		input.value = original;
		if (field === 'colisage') {
			input.placeholder = lang.placeholder || '';
		} else {
			input.setAttribute('inputmode', 'decimal');
		}
		input.setAttribute('aria-label', lang.editTitle || '');
		td.appendChild(input);
		if (field === 'colisage' && td.getAttribute('data-html') === '1') {
			var warn = document.createElement('div');
			warn.className = 'dut-sc-warn';
			warn.textContent = lang.htmlWarning || '';
			td.appendChild(warn);
		}
		input.focus();
		input.select();

		function finish(commit) {
			if (done) {
				return;
			}
			done = true;
			var value = input.value.replace(/[\r\n\t]+/g, ' ').trim();
			if (!commit || value === original) {
				setView(td, original);
				return;
			}
			save(td, input, value, original);
		}

		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				finish(true);
			} else if (e.key === 'Escape' || e.key === 'Esc') {
				e.preventDefault();
				finish(false);
				td.focus();
			}
		});
		input.addEventListener('blur', function () {
			finish(true);
		});
	}

	/* Délégation : les cellules changent de mode (manuel ↔ automatique) sans rechargement */
	function bindEdit() {
		Array.prototype.forEach.call(block.querySelectorAll('td.dut-sc-colisage.dut-sc-editable'), function (td) {
			td.setAttribute('title', td.getAttribute('title') || lang.editTitle || '');
		});
		block.addEventListener('click', function (e) {
			if (e.target && e.target.tagName === 'INPUT') {
				return;
			}
			var td = e.target && e.target.closest ? e.target.closest('td.dut-sc-editable') : null;
			if (td && block.contains(td)) {
				startEdit(td);
			}
		});
		block.addEventListener('keydown', function (e) {
			var td = e.target;
			if (td && td.tagName === 'TD' && td.classList.contains('dut-sc-editable') && (e.key === 'Enter' || e.key === 'F2')) {
				e.preventDefault();
				startEdit(td);
			}
		});
	}

	function init() {
		if (!standalone) {
			place();
		} else if (cfg.canEdit || cfg.canEditNum) {
			bindEdit();
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
