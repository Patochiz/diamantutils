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
			groups().forEach(function (g) {
				setCollapsed(g, false);
			});
			saveCollapsed();
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
		setCollapsed: setCollapsed,
		saveCollapsed: saveCollapsed,
		applyFilters: applyFilters
	};
})();
