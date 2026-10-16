/**
 * DiamantUtils — commandes sous-traitées (livraison directe client)
 *
 * Pré-remplit les écrans de réception d'une commande fournisseur à « Livraison directe client » = OUI :
 *  - dispatch.php        : select « Forcer vers » fk_default_warehouse, entrepot_{n}_{i}, lot_number_{n}_{i} ;
 *  - reception/card.php  : entl{N}, batch{N} (action=create).
 * Expédition d'une commande client liée à une CF à OUI :
 *  - expedition/shipment.php : entrepôt source entrepot_id présélectionné + bandeau des lots CF ;
 *  - expedition/card.php     : bandeau + avertissement sur les lots non liés (aucune quantité modifiée).
 * Les lignes ajoutées ensuite (bouton d'éclatement) sont aussi pré-remplies.
 * Un lot déjà saisi n'est jamais écrasé ; un entrepôt n'est présélectionné qu'une fois par champ.
 *
 * Configuration injectée par le hook printCommonFooter : window.diamantutilsST =
 *   { mode: 'dispatch'|'reception', warehouse: id, lot: 'CF…', banner: 'texte' }
 *   { mode: 'shipment'|'expedition', warehouse: id, banner: 'texte', foreign: {rowid: lot}, foreignLabel: '… %s …' }
 */
jQuery(function ($) {
	var cfg = window.diamantutilsST;
	if (!cfg || !cfg.warehouse) {
		return;
	}
	var warehouse = String(cfg.warehouse);

	function banner($before) {
		if (cfg.banner && $before.length) {
			$('<div class="info diamantutils-st-banner"></div>').text(cfg.banner).insertBefore($before.first());
		}
	}

	// Onglet Expéditions de la commande : entrepôt source présélectionné
	if (cfg.mode === 'shipment') {
		var $wh = $('select[name="entrepot_id"]');
		if ($wh.length) {
			if ($wh.find('option[value="' + warehouse + '"]').length) {
				$wh.val(warehouse).trigger('change');
			}
			banner($wh.closest('form'));
		}
		return;
	}

	// Création du BL : lots de l'entrepôt Sous-traitance non liés à la commande signalés
	// (batchl{i}_{j} = rowid llx_product_batch, résolu en nom de lot côté serveur)
	if (cfg.mode === 'expedition') {
		var foreign = cfg.foreign || {};
		var $batches = $('input[name^="batchl"]').filter(function () {
			return /^batchl\d+_\d+$/.test(this.name);
		});
		$batches.each(function () {
			var lot = foreign[$(this).val()];
			if (lot === undefined) {
				return;
			}
			var $qty = $('input[name="' + this.name.replace(/^batchl/, 'qtyl') + '"]');
			$('<span class="warning diamantutils-st-foreign"></span>').text(' ' + cfg.foreignLabel.replace('%s', lot)).insertAfter($qty.length ? $qty : $(this));
		});
		banner($batches.length ? $batches.first().closest('form') : $('form[name="formexpedition"], form').filter(function () {
			return $(this).find('input[name="action"][value="add"]').length > 0;
		}));
		return;
	}

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
