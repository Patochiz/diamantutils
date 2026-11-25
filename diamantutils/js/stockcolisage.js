/**
 * DiamantUtils — onglet Stock de la fiche produit : tableau avec colonne Colisage
 *
 * Sans bibliothèque externe. Configuration : attribut data-config du bloc #dut-stockcolisage.
 *  - place le bloc juste avant le tableau natif, l'affiche, puis masque le tableau natif ;
 *    tableau natif introuvable ou erreur : le bloc est retiré, le tableau natif reste visible ;
 *  - repli / dépli des lots par entrepôt, « Afficher tous les lots » / « Masquer les lots » ;
 *  - édition en ligne du colisage (ajax/lotcolisage.php) : Entrée ou sortie du champ =
 *    enregistrer, Échap = annuler.
 */
(function () {
	'use strict';

	var block = document.getElementById('dut-stockcolisage');
	if (!block) {
		return;
	}

	var cfg;
	try {
		cfg = JSON.parse(block.getAttribute('data-config') || '{}');
	} catch (e) {
		block.parentNode.removeChild(block);
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
			if (cfg.canEdit) {
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

	/* Édition en ligne du colisage */
	function setView(td, text) {
		td.innerHTML = '';
		var span = document.createElement('span');
		span.className = 'dut-sc-text';
		span.textContent = text;
		td.appendChild(span);
		td.classList.toggle('dut-sc-empty', text === '');
		if (text === '') {
			td.setAttribute('title', lang.editTitle || '');
		} else {
			td.setAttribute('title', text);
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
		}, isError ? 6000 : 1500);
	}

	function save(td, input, value, original) {
		td.classList.add('dut-sc-saving');
		input.disabled = true;
		var body = new URLSearchParams();
		body.append('token', cfg.token);
		body.append('fk_product', String(cfg.product));
		body.append('batch', td.getAttribute('data-batch'));
		body.append('colisage', value);

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
				setView(td, typeof data.value === 'string' ? data.value : value);
				td.setAttribute('data-html', '0');
				flash(td, '✓', false);
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
		if (td.classList.contains('dut-sc-editing') || td.classList.contains('dut-sc-saving')) {
			return;
		}
		var textNode = td.querySelector('.dut-sc-text');
		var original = textNode ? textNode.textContent : '';
		var done = false;

		td.classList.add('dut-sc-editing');
		td.innerHTML = '';
		var input = document.createElement('input');
		input.type = 'text';
		input.className = 'flat dut-sc-input';
		input.maxLength = cfg.maxLength || 200;
		input.value = original;
		input.placeholder = lang.placeholder || '';
		input.setAttribute('aria-label', lang.editTitle || '');
		td.appendChild(input);
		if (td.getAttribute('data-html') === '1') {
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

	function bindEdit() {
		var cells = block.querySelectorAll('td.dut-sc-editable');
		Array.prototype.forEach.call(cells, function (td) {
			td.setAttribute('title', td.getAttribute('title') || lang.editTitle || '');
			td.addEventListener('click', function (e) {
				if (e.target && e.target.tagName === 'INPUT') {
					return;
				}
				startEdit(td);
			});
			td.addEventListener('keydown', function (e) {
				if (e.target === td && (e.key === 'Enter' || e.key === 'F2')) {
					e.preventDefault();
					startEdit(td);
				}
			});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', place);
	} else {
		place();
	}
})();
