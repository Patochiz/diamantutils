/**
 * DiamantUtils — tableau de stock par lots (stocktable_list.php)
 *
 * Sans bibliothèque externe. Configuration injectée par la page : window.diamantutilsStockTable.
 *  - repli / dépli des groupes produit, mémorisé dans localStorage (confort seulement) ;
 *  - recherche instantanée (produit, lot, colisage) ; sections par tag repliables.
 *  La modification des attributs du lot est faite par js/stockcolisage.js (mode tableau).
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
	var empty = table.querySelector('tbody.dst-empty');

	function applyFilters() {
		var terms = (search ? search.value : '').toLowerCase().split(/\s+/).filter(Boolean);
		var visible = 0;
		var filtering = (terms.length > 0);
		var stats = {};
		groups().forEach(function (g) {
			var hay = g.getAttribute('data-search') || '';
			var show = terms.every(function (t) {
				return hay.indexOf(t) !== -1;
			});
			if (g.querySelector('.dut-sc-editing, .dut-sc-saving')) {
				show = true;	// une modification en cours reste visible
			}
			g.classList.toggle('dst-hidden', !show);
			if (show) {
				visible++;
			}
			var sid = g.getAttribute('data-section');
			var st = stats[sid] || (stats[sid] = { nb: 0, visible: 0 });
			st.nb++;
			if (show) {
				st.visible++;
			}
			// Section repliée : ses produits sont masqués
			var sec = sectionOf(g);
			g.classList.toggle('dst-sec-hidden', !!(sec && sec.classList.contains('dst-section-collapsed')));
		});
		sections().forEach(function (sec) {
			var st = stats[sec.getAttribute('data-section')] || { nb: 0, visible: 0 };
			var count = sec.querySelector('.dst-section-count');
			if (count && cfg.labels && cfg.labels.products) {
				count.textContent = '(' + cfg.labels.products.replace('%s', String(filtering ? st.visible : st.nb)) + ')';
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
				setCollapsed(g, true);
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
})();
