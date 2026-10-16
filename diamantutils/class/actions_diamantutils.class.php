<?php
/**
 * Hooks du module DiamantUtils
 *
 * Commandes sous-traitées (livraison directe client) :
 *  - ordersupplierdispatch (fourn/commande/dispatch.php) :
 *      doActions : mémorise le MAX(rowid) des lignes ventilées avant une ventilation directe ;
 *      printCommonFooter : pré-remplissage entrepôt / lot + bandeau ;
 *  - receptioncard (reception/card.php, action=create) : pré-remplissage entrepôt / lot + bandeau.
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
		print '<script src="'.dol_buildpath('/diamantutils/js/soustraitance.js', 1).'?v=2.2"></script>'."\n";

		return 0;
	}
}
