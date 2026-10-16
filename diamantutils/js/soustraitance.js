/**
 * DiamantUtils — commandes sous-traitées (livraison directe client)
 *
 * Pré-remplit les écrans de réception d'une commande fournisseur à « Livraison directe client » = OUI :
 *  - dispatch.php        : select « Forcer vers » fk_default_warehouse, entrepot_{n}_{i}, lot_number_{n}_{i} ;
 *  - reception/card.php  : entl{N}, batch{N} (action=create).
 * Les lignes ajoutées ensuite (bouton d'éclatement) sont aussi pré-remplies.
 * Un lot déjà saisi n'est jamais écrasé ; un entrepôt n'est présélectionné qu'une fois par champ.
 *
 * Configuration injectée par le hook printCommonFooter : window.diamantutilsST =
 *   { mode: 'dispatch'|'reception', warehouse: id, lot: 'CF…', banner: 'texte' }
 */
jQuery(function ($) {
	var cfg = window.diamantutilsST;
	if (!cfg || !cfg.warehouse) {
		return;
	}
	var warehouse = String(cfg.warehouse);

	var reWarehouse = (cfg.mode === 'dispatch') ? /^entrepot_\d+_\d+$/ : /^entl\d+$/;
	var reLot = (cfg.mode === 'dispatch') ? /^lot_number_\d+_\d+$/ : /^batch\d+$/;

	function byName($scope, selector, re) {
		return $scope.find(selector).filter(function () {
			return re.test(this.name || '');
		});
	}

	function setSelect($select) {
		if ($select.find('option[value="' + warehouse + '"]').length) {
			$select.val(warehouse).trigger('change');
		}
	}

	function fill($scope) {
		byName($scope, 'select', reWarehouse).each(function () {
			var $s = $(this);
			if ($s.data('diamantutilsST')) {
				return;
			}
			$s.data('diamantutilsST', 1);
			setSelect($s);
		});
		byName($scope, 'input', reLot).each(function () {
			var $i = $(this);
			if ($i.data('diamantutilsST')) {
				return;
			}
			$i.data('diamantutilsST', 1);
			if ($.trim($i.val()) === '') {
				$i.val(cfg.lot);
			}
		});
	}

	var $fields = byName($(document), 'select', reWarehouse).add(byName($(document), 'input', reLot));
	if (!$fields.length) {
		return;
	}
	var $form = $fields.first().closest('form');

	// Bandeau d'information
	if (cfg.banner) {
		$('<div class="info diamantutils-st-banner"></div>').text(cfg.banner).insertBefore($form.length ? $form : $fields.first().closest('table'));
	}

	// Ventilation : select global « Forcer vers » (le core propage la valeur à tous les entrepot_*)
	if (cfg.mode === 'dispatch') {
		var $force = $('select[name="fk_default_warehouse"]');
		if ($force.length && !$force.prop('disabled')) {
			setSelect($force);
		}
	}

	fill($(document));

	// Lignes ajoutées dynamiquement (éclatement d'une ligne de ventilation)
	if (window.MutationObserver && $form.length) {
		var timer = null;
		new MutationObserver(function () {
			clearTimeout(timer);
			timer = setTimeout(function () {
				fill($form);
			}, 50);
		}).observe($form[0], { childList: true, subtree: true });
	}
});
