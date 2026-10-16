<?php
/**
 * Hooks du module DiamantUtils
 *
 * Commandes sous-traitées (livraison directe client) :
 *  - ordersupplierdispatch (fourn/commande/dispatch.php) :
 *      doActions : mémorise le MAX(rowid) des lignes ventilées avant une ventilation directe ;
 *      printCommonFooter : pré-remplissage entrepôt / lot + bandeau ;
 *  - receptioncard (reception/card.php, action=create) : pré-remplissage entrepôt / lot + bandeau ;
 *  - ordershipmentcard (expedition/shipment.php) : entrepôt Sous-traitance présélectionné + bandeau ;
 *  - expeditioncard (expedition/card.php, action=create) : bandeau + lots non liés signalés.
 */

class ActionsDiamantutils
{
	/** @var DoliDB */
	public $db;

	public $error = '';

	public $errors = array();

	public $results = array();

	public $resprints = '';

	/**
	 * @param	DoliDB	$db		Base de données
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * @param	array		$parameters		Paramètres du hook
	 * @param	Object		$object			Objet de la page
	 * @param	string		$action			Action
	 * @param	HookManager	$hookmanager	Gestionnaire de hooks
	 * @return	int							0 = traitement standard poursuivi
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		$contexts = explode(':', (string) $parameters['context']);

		if (in_array('ordersupplierdispatch', $contexts) && $action == 'dispatch' && !empty($object->id)) {
			dol_include_once('/diamantutils/lib/soustraitance.lib.php');
			if (diamantutils_st_enabled()) {
				dol_include_once('/diamantutils/class/soustraitance.class.php');
				$st = new DiamantutilsSoustraitance($this->db);
				$st->rememberDispatchMaxRowid($object->id);
			}
		}

		return 0;
	}

	/**
	 * Pied de page : injecte le pré-remplissage des écrans de réception
	 *
	 * @param	array		$parameters		Paramètres du hook
	 * @param	Object		$object			Objet (non fourni par printCommonFooter)
	 * @param	string		$action			Action
	 * @param	HookManager	$hookmanager	Gestionnaire de hooks
	 * @return	int							0
	 */
	public function printCommonFooter($parameters, &$object, &$action, $hookmanager)
	{
		static $done = false;

		if ($done || empty($GLOBALS['conf']->use_javascript_ajax)) {
			return 0;
		}
		$contexts = explode(':', (string) $parameters['context']);

		// Expédition : choix de l'entrepôt (onglet Expéditions de la commande) et création du BL
		if (in_array('ordershipmentcard', $contexts) || in_array('expeditioncard', $contexts)) {
			return $this->printShipmentPrefill($contexts);
		}

		$fk_cf = 0;
		$mode = '';
		if (in_array('ordersupplierdispatch', $contexts)) {
			$fk_cf = GETPOSTINT('id');
			$mode = 'dispatch';
		} elseif (in_array('receptioncard', $contexts) && $GLOBALS['action'] == 'create' && GETPOST('origin', 'aZ09') == 'supplierorder') {
			$fk_cf = GETPOSTINT('originid');
			$mode = 'reception';
		}
		if ($fk_cf <= 0) {
			return 0;
		}

		dol_include_once('/diamantutils/lib/soustraitance.lib.php');
		if (!diamantutils_st_enabled()) {
			return 0;
		}
		dol_include_once('/diamantutils/class/soustraitance.class.php');
		$st = new DiamantutilsSoustraitance($this->db);
		$cf = $st->getSupplierOrder($fk_cf);
		if (empty($cf) || !$cf['direct']) {
			return 0;
		}

		global $langs;
		$langs->load('diamantutils@diamantutils');
		$done = true;

		$config = array(
			'mode' => $mode,
			'warehouse' => diamantutils_st_warehouse_id(),
			'lot' => $cf['ref'],
			'banner' => $langs->transnoentitiesnoconv('DiamantutilsSTBannerReception', $cf['ref']),
		);
		print '<script>var diamantutilsST = '.json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).';</script>'."\n";
		print '<script src="'.dol_buildpath('/diamantutils/js/soustraitance.js', 1).'?v=2.2b"></script>'."\n";

		return 0;
	}

	/**
	 * Pré-remplissage expédition : entrepôt Sous-traitance présélectionné (shipment.php),
	 * bandeau des lots CF disponibles, lots non liés signalés (expedition/card.php?action=create)
	 *
	 * @param	string[]	$contexts	Contextes de la page
	 * @return	int						0
	 */
	private function printShipmentPrefill($contexts)
	{
		global $langs;

		$action = (string) $GLOBALS['action'];
		if (in_array('ordershipmentcard', $contexts)) {
			$mode = 'shipment';
			$fk_commande = GETPOSTINT('id');
		} else {
			if ($action != 'create' || (GETPOST('origin', 'aZ09') && GETPOST('origin', 'aZ09') != 'commande')) {
				return 0;
			}
			$mode = 'expedition';
			$fk_commande = GETPOSTINT('origin_id') ? GETPOSTINT('origin_id') : GETPOSTINT('object_id');
		}
		if ($fk_commande <= 0) {
			return 0;
		}

		dol_include_once('/diamantutils/lib/soustraitance.lib.php');
		if (!diamantutils_st_enabled()) {
			return 0;
		}
		dol_include_once('/diamantutils/class/soustraitance.class.php');
		$st = new DiamantutilsSoustraitance($this->db);
		$expected = $st->getExpectedLots($fk_commande);
		if (empty($expected)) {
			return 0;
		}

		$langs->load('diamantutils@diamantutils');

		// Lots CF disponibles dans l'entrepôt Sous-traitance
		$available = array();
		foreach ($st->getWarehouseLots($expected) as $lot) {
			$available[] = $lot['batch'].' ('.$lot['product_ref'].' : '.price2num($lot['qty'], 'MS').')';
		}
		$banner = $langs->transnoentitiesnoconv('DiamantutilsSTBannerShipment', implode(', ', $expected));
		$banner .= ' '.(empty($available) ? $langs->transnoentitiesnoconv('DiamantutilsSTBannerShipmentNoStock') : $langs->transnoentitiesnoconv('DiamantutilsSTBannerShipmentStock', implode(' ; ', $available)));

		// Lots de l'entrepôt Sous-traitance non attendus pour cette commande : rowid product_batch => lot
		$foreign = array();
		if ($mode == 'expedition') {
			foreach ($st->getWarehouseLots() as $lot) {
				if (!in_array($lot['batch'], $expected, true)) {
					$foreign[$lot['fk_product_batch']] = $lot['batch'];
				}
			}
		}

		$config = array(
			'mode' => $mode,
			'warehouse' => diamantutils_st_warehouse_id(),
			'banner' => $banner,
			'foreign' => (object) $foreign,
			'foreignLabel' => $langs->transnoentitiesnoconv('DiamantutilsSTForeignLot'),
		);
		print '<script>var diamantutilsST = '.json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).';</script>'."\n";
		print '<script src="'.dol_buildpath('/diamantutils/js/soustraitance.js', 1).'?v=2.2b"></script>'."\n";

		return 0;
	}
}
