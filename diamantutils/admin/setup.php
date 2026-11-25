<?php
// Page de configuration du module DiamantUtils : un onglet par fonctionnalité,
// chacun avec son activation et ses réglages.

$res = 0;
$res = @include "../../main.inc.php";
if (!$res) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/html.formproduct.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/product.lib.php';
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';
dol_include_once('/diamantutils/lib/diamantutils.lib.php');
dol_include_once('/diamantutils/lib/soustraitance.lib.php');
dol_include_once('/diamantutils/class/customlist.class.php');
dol_include_once('/diamantutils/lib/descpropal.lib.php');
dol_include_once('/diamantutils/lib/lot.lib.php');
dol_include_once('/diamantutils/class/diamantlot.class.php');
dol_include_once('/diamantutils/lib/stocktable.lib.php');
dol_include_once('/diamantutils/lib/stockcolisage.lib.php');
require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';

if (!$user->admin) {
	accessforbidden();
}

$langs->loadLangs(array("admin", "stocks", "productbatch", "diamantutils@diamantutils"));

$action = GETPOST('action', 'aZ09');
$tab = GETPOST('tab', 'aZ09');
if (!in_array($tab, array('transfo', 'soustraitance', 'customlist', 'descpropal', 'lot', 'costprice', 'linkso', 'stocktable', 'stockcolisage'))) {
	$tab = 'transfo';
}


/*
 * Actions : chaque onglet n'enregistre que ses propres réglages
 */

// Colisage calculé : recalcul de tous les lots (POST, jeton vérifié, administrateur)
$recalcreport = null;
if ($action == 'recalccolisage' && $tab == 'stockcolisage') {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || GETPOST('token', 'alpha') === '' || GETPOST('token', 'alpha') !== currentToken()) {
		setEventMessages($langs->trans('DiamantutilsStockColisageErrToken'), null, 'errors');
	} elseif (!diamantutils_stockcolisage_auto_enabled($db)) {
		setEventMessages($langs->trans('DiamantutilsColisageRecalcUnavailable'), null, 'errors');
	} else {
		$recalcreport = diamantutils_stockcolisage_recompute_all($db);
		setEventMessages($langs->trans('DiamantutilsColisageRecalcDone', $recalcreport['updated'], $recalcreport['unchanged'], count($recalcreport['warnings'])), null, ($recalcreport['errors'] ? 'warnings' : 'mesgs'));
		if ($recalcreport['errors']) {
			setEventMessages($langs->trans('DiamantutilsColisageRecalcErrors', $recalcreport['errors']), null, 'errors');
		}
	}
	$action = '';
}

if ($action == 'update') {
	$error = 0;

	if ($tab == 'transfo') {
		$enabled = GETPOSTINT('DIAMANTUTILS_TRANSFO_ENABLED') ? 1 : 0;
		if (dolibarr_set_const($db, 'DIAMANTUTILS_TRANSFO_ENABLED', $enabled, 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}

		// Entrepôt par défaut des transformations
		$fk_warehouse = GETPOSTINT('DIAMANTUTILS_TRANSFO_DEFAULT_WAREHOUSE');
		if ($fk_warehouse > 0) {
			$res = dolibarr_set_const($db, 'DIAMANTUTILS_TRANSFO_DEFAULT_WAREHOUSE', $fk_warehouse, 'chaine', 0, '', $conf->entity);
		} else {
			$res = dolibarr_del_const($db, 'DIAMANTUTILS_TRANSFO_DEFAULT_WAREHOUSE', $conf->entity);
		}
		if ($res < 0) {
			$error++;
		}

		// Format du nom de lot généré : doit contenir un %d pour la longueur en mm
		$lotformat = trim(GETPOST('DIAMANTUTILS_TRANSFO_LOT_FORMAT', 'restricthtml'));
		if ($lotformat === '') {
			$lotformat = 'Longueur %dmm';
		}
		if (substr_count($lotformat, '%d') != 1 || preg_match('/%(?!d)/', $lotformat)) {
			setEventMessages($langs->trans('DiamantutilsLotFormatInvalid', '%d'), null, 'errors');
			$error++;
		} elseif (dolibarr_set_const($db, 'DIAMANTUTILS_TRANSFO_LOT_FORMAT', $lotformat, 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}
	}

	if ($tab == 'soustraitance') {
		$st_enabled = GETPOSTINT('DIAMANTUTILS_SOUSTRAITANCE_ENABLED') ? 1 : 0;
		$st_warehouse = GETPOSTINT('DIAMANTUTILS_SOUSTRAITANCE_WAREHOUSE_ID');
		if ($st_warehouse > 0) {
			if (dolibarr_set_const($db, 'DIAMANTUTILS_SOUSTRAITANCE_WAREHOUSE_ID', $st_warehouse, 'chaine', 0, '', $conf->entity) < 0) {
				$error++;
			}
		}
		if ($st_enabled) {
			// Installation complétée si besoin (extrafield supprimé, entrepôt non défini)
			$errmsg = '';
			if (diamantutils_st_install_extrafield($db, $errmsg) < 0) {
				setEventMessages($errmsg, null, 'errors');
				$error++;
			}
			if (!($st_warehouse > 0) && diamantutils_st_install_warehouse($db, $user, $errmsg) < 0) {
				setEventMessages($errmsg, null, 'errors');
				$error++;
			}
		}
		if (dolibarr_set_const($db, 'DIAMANTUTILS_SOUSTRAITANCE_ENABLED', $st_enabled, 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}
	}

	if ($tab == 'customlist') {
		$enabled = GETPOSTINT('DIAMANTUTILS_CUSTOMLIST_ENABLED') ? 1 : 0;
		if (dolibarr_set_const($db, 'DIAMANTUTILS_CUSTOMLIST_ENABLED', $enabled, 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}
		$exportmax = GETPOSTINT('DIAMANTUTILS_CUSTOMLIST_EXPORT_MAX');
		if ($exportmax < 1) {
			$exportmax = DiamantutilsCustomList::EXPORT_MAX_ROWS;
		}
		if (dolibarr_set_const($db, 'DIAMANTUTILS_CUSTOMLIST_EXPORT_MAX', $exportmax, 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}
	}

	if ($tab == 'descpropal') {
		$enabled = GETPOSTINT('DIAMANTUTILS_DESC_PROPAL') ? 1 : 0;
		if ($enabled) {
			// Installation complétée si besoin (extrafield supprimé à la main)
			$errmsg = '';
			if (diamantutils_descpropal_install_extrafield($db, $errmsg) < 0) {
				setEventMessages($errmsg, null, 'errors');
				$error++;
			}
		}
		if (dolibarr_set_const($db, 'DIAMANTUTILS_DESC_PROPAL', $enabled, 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}
		$mode = (GETPOST('DIAMANTUTILS_DESC_PROPAL_MODE', 'aZ09') == 'PREPEND' ? 'PREPEND' : 'REPLACE');
		if (dolibarr_set_const($db, 'DIAMANTUTILS_DESC_PROPAL_MODE', $mode, 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}
	}

	if ($tab == 'lot') {
		$enabled = GETPOSTINT('DIAMANTUTILS_LOT_ENABLED') ? 1 : 0;
		if (dolibarr_set_const($db, 'DIAMANTUTILS_LOT_ENABLED', $enabled, 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}
	}

	if ($tab == 'costprice') {
		$enabled = GETPOSTINT('DIAMANTUTILS_COSTPRICE_FROM_RECEIPT') ? 1 : 0;
		if (dolibarr_set_const($db, 'DIAMANTUTILS_COSTPRICE_FROM_RECEIPT', $enabled, 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}
	}

	if ($tab == 'linkso') {
		$enabled = GETPOSTINT('DIAMANTUTILS_LINK_SUPPLIER_ORDERS') ? 1 : 0;
		if (dolibarr_set_const($db, 'DIAMANTUTILS_LINK_SUPPLIER_ORDERS', $enabled, 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}
		$months = max(0, GETPOSTINT('DIAMANTUTILS_LINK_SUPPLIER_ORDERS_MONTHS'));
		if (dolibarr_set_const($db, 'DIAMANTUTILS_LINK_SUPPLIER_ORDERS_MONTHS', $months, 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}
	}

	if ($tab == 'stocktable') {
		$enabled = GETPOSTINT('DIAMANTUTILS_STOCKTABLE_ENABLED') ? 1 : 0;
		if (dolibarr_set_const($db, 'DIAMANTUTILS_STOCKTABLE_ENABLED', $enabled, 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}

		// Tags produit : aucun tag = aucun produit (constante supprimée)
		$cats = diamantutils_stocktable_ids(GETPOST('DIAMANTUTILS_STOCKTABLE_CATEGORIES', 'array'));
		if (!empty($cats)) {
			$res = dolibarr_set_const($db, 'DIAMANTUTILS_STOCKTABLE_CATEGORIES', implode(',', $cats), 'chaine', 0, '', $conf->entity);
		} else {
			$res = dolibarr_del_const($db, 'DIAMANTUTILS_STOCKTABLE_CATEGORIES', $conf->entity);
		}
		if ($res < 0) {
			$error++;
		}
		$withsub = GETPOSTINT('DIAMANTUTILS_STOCKTABLE_SUBCATEGORIES') ? 1 : 0;
		if (dolibarr_set_const($db, 'DIAMANTUTILS_STOCKTABLE_SUBCATEGORIES', $withsub, 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}

		// Tolérance : nombre ≥ 0, refusé sinon
		$tolerance = price2num(GETPOST('DIAMANTUTILS_STOCKTABLE_TOLERANCE', 'alpha'));
		if ($tolerance === '' || !is_numeric($tolerance) || (float) $tolerance < 0) {
			setEventMessages($langs->trans('DiamantutilsStockTableToleranceInvalid'), null, 'errors');
			$error++;
		} elseif (dolibarr_set_const($db, 'DIAMANTUTILS_STOCKTABLE_TOLERANCE', (string) ((float) $tolerance), 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}

		// Entrepôts exclus : '0' = aucun (constante absente = entrepôt Sous-traitance)
		$warehouses = diamantutils_stocktable_ids(GETPOST('DIAMANTUTILS_STOCKTABLE_EXCLUDE_WAREHOUSES', 'array'));
		if (dolibarr_set_const($db, 'DIAMANTUTILS_STOCKTABLE_EXCLUDE_WAREHOUSES', (empty($warehouses) ? '0' : implode(',', $warehouses)), 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}
	}

	if ($tab == 'stockcolisage') {
		$enabled = GETPOSTINT('DIAMANTUTILS_STOCKCOLISAGE_ENABLED') ? 1 : 0;
		if (dolibarr_set_const($db, 'DIAMANTUTILS_STOCKCOLISAGE_ENABLED', $enabled, 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}
		// Code de l'attribut du lot : lettres, chiffres et _ (comme les codes d'extrafields)
		$code = trim(GETPOST('DIAMANTUTILS_STOCKCOLISAGE_EXTRAFIELD', 'alphanohtml'));
		if ($code === '') {
			$code = 'colisage';
		}
		if (!preg_match('/^[a-z0-9_]{1,64}$/i', $code)) {
			setEventMessages($langs->trans('DiamantutilsStockColisageCodeInvalid'), null, 'errors');
			$error++;
		} elseif (dolibarr_set_const($db, 'DIAMANTUTILS_STOCKCOLISAGE_EXTRAFIELD', $code, 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}

		// Colisage calculé : attributs créés si besoin (supprimés à la main)
		$auto = GETPOSTINT('DIAMANTUTILS_STOCKCOLISAGE_AUTO') ? 1 : 0;
		if ($auto) {
			$errmsg = '';
			if (diamantutils_colisage_install_extrafields($db, $errmsg) < 0) {
				setEventMessages($errmsg, null, 'errors');
				$error++;
			}
		}
		if (dolibarr_set_const($db, 'DIAMANTUTILS_STOCKCOLISAGE_AUTO', $auto, 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}
		// Entrepôts ignorés pour la quantité du lot : '0' = aucun (constante absente = Sous-traitance)
		$warehouses = diamantutils_stockcolisage_ids(GETPOST('DIAMANTUTILS_STOCKCOLISAGE_EXCLUDE_WAREHOUSES', 'array'));
		if (dolibarr_set_const($db, 'DIAMANTUTILS_STOCKCOLISAGE_EXCLUDE_WAREHOUSES', (empty($warehouses) ? '0' : implode(',', $warehouses)), 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}
	}

	if (!$error) {
		setEventMessages($langs->trans('DiamantutilsConfigUpdated'), null);
		header('Location: '.$_SERVER['PHP_SELF'].'?tab='.$tab);
		exit;
	}
}


/*
 * Vue
 */

$formproduct = new FormProduct($db);
$form = new Form($db);

llxHeader('', $langs->trans('DiamantutilsSetupTitle'), '', '', 0, 0, '', '', '', 'mod-diamantutils page-admin-setup');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans('DiamantutilsSetupTitle'), $linkback, 'title_setup');

print dol_get_fiche_head(diamantutils_admin_prepare_head(), $tab, '', -1, '');

print '<form method="POST" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';
print '<input type="hidden" name="tab" value="'.dol_escape_htmltag($tab).'">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';

/**
 * Ligne de réglage : libellé, aide, champ
 *
 * @param	string	$label	Libellé
 * @param	string	$help	Aide (HTML déjà traduit) ou ''
 * @param	string	$input	Champ HTML
 * @return	void
 */
function diamantutils_setup_row($label, $help, $input)
{
	print '<tr class="oddeven"><td class="titlefieldmiddle">'.$label;
	if ($help !== '') {
		print '<br><span class="opacitymedium">'.$help.'</span>';
	}
	print '</td><td>'.$input.'</td></tr>';
}

if ($tab == 'transfo') {
	diamantutils_setup_row(
		$langs->trans('DiamantutilsFeatureEnabled'),
		$langs->trans('DiamantutilsTransfoEnabledDesc'),
		$form->selectyesno('DIAMANTUTILS_TRANSFO_ENABLED', diamantutils_transfo_enabled() ? 1 : 0, 1)
	);
	diamantutils_setup_row(
		$langs->trans('DiamantutilsTransfoDefaultWarehouse'),
		$langs->trans('DiamantutilsTransfoDefaultWarehouseDesc'),
		$formproduct->selectWarehouses(getDolGlobalInt('DIAMANTUTILS_TRANSFO_DEFAULT_WAREHOUSE'), 'DIAMANTUTILS_TRANSFO_DEFAULT_WAREHOUSE', 'warehouseopen', 1)
	);
	diamantutils_setup_row(
		$langs->trans('DiamantutilsTransfoLotFormat'),
		$langs->trans('DiamantutilsTransfoLotFormatDesc', '%d', 'Longueur %dmm'),
		'<input type="text" class="flat minwidth200" name="DIAMANTUTILS_TRANSFO_LOT_FORMAT" value="'.dol_escape_htmltag(getDolGlobalString('DIAMANTUTILS_TRANSFO_LOT_FORMAT', 'Longueur %dmm')).'">'
	);
}

if ($tab == 'soustraitance') {
	diamantutils_setup_row(
		$langs->trans('DiamantutilsFeatureEnabled'),
		$langs->trans('DiamantutilsSoustraitanceEnabledDesc'),
		$form->selectyesno('DIAMANTUTILS_SOUSTRAITANCE_ENABLED', getDolGlobalInt('DIAMANTUTILS_SOUSTRAITANCE_ENABLED'), 1)
	);
	diamantutils_setup_row(
		$langs->trans('DiamantutilsSoustraitanceWarehouse'),
		$langs->trans('DiamantutilsSoustraitanceWarehouseHelp'),
		$formproduct->selectWarehouses(diamantutils_st_warehouse_id(), 'DIAMANTUTILS_SOUSTRAITANCE_WAREHOUSE_ID', 'warehouseopen', 1)
	);

	// État de l'installation
	$status = '';
	if (diamantutils_st_extrafield_exists($db)) {
		$status .= img_picto('', 'tick', 'class="pictofixedwidth"').$langs->trans('DiamantutilsSoustraitanceExtrafieldOk', DIAMANTUTILS_ST_EXTRAFIELD);
	} else {
		$status .= img_warning('', 'class="pictofixedwidth"').'<span class="warning">'.$langs->trans('DiamantutilsSoustraitanceExtrafieldMissing', DIAMANTUTILS_ST_EXTRAFIELD).'</span>';
	}
	$status .= '<br>';
	$st_entrepot = new Entrepot($db);
	if (diamantutils_st_warehouse_id() > 0 && $st_entrepot->fetch(diamantutils_st_warehouse_id()) > 0) {
		$status .= img_picto('', 'tick', 'class="pictofixedwidth"').$langs->trans('DiamantutilsSoustraitanceWarehouseOk').' '.$st_entrepot->getNomUrl(1);
	} else {
		$status .= img_warning('', 'class="pictofixedwidth"').'<span class="warning">'.$langs->trans('DiamantutilsSoustraitanceWarehouseMissing').'</span>';
	}
	$status .= '<br>';
	if (diamantutils_st_enabled()) {
		$status .= img_picto('', 'tick', 'class="pictofixedwidth"').$langs->trans('DiamantutilsSoustraitanceActive');
	} else {
		$status .= '<span class="opacitymedium">'.$langs->trans('DiamantutilsSoustraitanceInactive').'</span>';
	}
	diamantutils_setup_row($langs->trans('DiamantutilsSoustraitanceStatus'), '', $status);
}

if ($tab == 'customlist') {
	diamantutils_setup_row(
		$langs->trans('DiamantutilsFeatureEnabled'),
		$langs->trans('DiamantutilsCLEnabledDesc'),
		$form->selectyesno('DIAMANTUTILS_CUSTOMLIST_ENABLED', diamantutils_customlist_enabled() ? 1 : 0, 1)
	);
	diamantutils_setup_row(
		$langs->trans('DiamantutilsCLExportMax'),
		$langs->trans('DiamantutilsCLExportMaxDesc'),
		'<input type="number" min="1" class="flat width100" name="DIAMANTUTILS_CUSTOMLIST_EXPORT_MAX" value="'.((int) getDolGlobalInt('DIAMANTUTILS_CUSTOMLIST_EXPORT_MAX', DiamantutilsCustomList::EXPORT_MAX_ROWS)).'">'
	);
	$static = new DiamantutilsCustomList($db);
	$lists = $static->fetchAll(0);
	$nb = (is_array($lists) ? count($lists) : 0);
	$nbactive = 0;
	if (is_array($lists)) {
		foreach ($lists as $list) {
			$nbactive += ($list->active ? 1 : 0);
		}
	}
	diamantutils_setup_row(
		$langs->trans('DiamantutilsCustomLists'),
		'',
		$langs->trans('DiamantutilsCLCount', $nb, $nbactive).' &nbsp; <a href="'.dol_buildpath('/diamantutils/customlist_index.php', 1).'">'.img_picto('', 'list', 'class="pictofixedwidth"').$langs->trans('DiamantutilsCLManage').'</a>'
	);
}

if ($tab == 'descpropal') {
	diamantutils_setup_row(
		$langs->trans('DiamantutilsFeatureEnabled'),
		$langs->trans('DiamantutilsDescPropalEnabledDesc'),
		$form->selectyesno('DIAMANTUTILS_DESC_PROPAL', getDolGlobalInt('DIAMANTUTILS_DESC_PROPAL'), 1)
	);
	diamantutils_setup_row(
		$langs->trans('DiamantutilsDescPropalMode'),
		$langs->trans('DiamantutilsDescPropalModeDesc'),
		$form->selectarray('DIAMANTUTILS_DESC_PROPAL_MODE', array(
			'REPLACE' => $langs->trans('DiamantutilsDescPropalModeREPLACE'),
			'PREPEND' => $langs->trans('DiamantutilsDescPropalModePREPEND'),
		), diamantutils_descpropal_mode())
	);

	// État de l'installation
	if (diamantutils_descpropal_extrafield_exists($db)) {
		$status = img_picto('', 'tick', 'class="pictofixedwidth"').$langs->trans('DiamantutilsDescPropalExtrafieldOk', DIAMANTUTILS_DESCPROPAL_EXTRAFIELD);
	} else {
		$status = img_warning('', 'class="pictofixedwidth"').'<span class="warning">'.$langs->trans('DiamantutilsDescPropalExtrafieldMissing', DIAMANTUTILS_DESCPROPAL_EXTRAFIELD).'</span>';
	}
	diamantutils_setup_row($langs->trans('DiamantutilsDescPropalStatus'), '', $status);
}

if ($tab == 'lot') {
	diamantutils_setup_row(
		$langs->trans('DiamantutilsFeatureEnabled'),
		$langs->trans('DiamantutilsLotEnabledDesc'),
		$form->selectyesno('DIAMANTUTILS_LOT_ENABLED', diamantutils_lot_enabled() ? 1 : 0, 1)
	);
	$static = new DiamantLot($db);
	$lots = $static->fetchAll(0);
	$nb = (is_array($lots) ? count($lots) : 0);
	$nbactive = 0;
	if (is_array($lots)) {
		foreach ($lots as $lot) {
			$nbactive += ($lot->active ? 1 : 0);
		}
	}
	diamantutils_setup_row(
		$langs->trans('DiamantutilsLots'),
		$langs->trans('DiamantutilsLotSetupRights'),
		$langs->trans('DiamantutilsLotCount', $nb, $nbactive).' &nbsp; <a href="'.dol_buildpath('/diamantutils/lot_list.php', 1).'">'.img_picto('', 'list', 'class="pictofixedwidth"').$langs->trans('DiamantutilsLotManage').'</a>'
	);
}

if ($tab == 'costprice') {
	diamantutils_setup_row(
		$langs->trans('DiamantutilsCostPriceEnabled'),
		$langs->trans('DiamantutilsCostPriceEnabledDesc'),
		$form->selectyesno('DIAMANTUTILS_COSTPRICE_FROM_RECEIPT', getDolGlobalInt('DIAMANTUTILS_COSTPRICE_FROM_RECEIPT'), 1)
	);

	// Prérequis Dolibarr : prix modifiable à la réception
	if (getDolGlobalInt('SUPPLIER_ORDER_EDIT_BUYINGPRICE_DURING_RECEIPT')) {
		$status = img_picto('', 'tick', 'class="pictofixedwidth"').$langs->trans('DiamantutilsCostPricePrereqOk');
	} else {
		$status = img_warning('', 'class="pictofixedwidth"').'<span class="warning">'.$langs->trans('DiamantutilsCostPricePrereqMissing').'</span>';
	}
	diamantutils_setup_row($langs->trans('DiamantutilsCostPricePrereq'), $langs->trans('DiamantutilsCostPricePrereqDesc'), $status);
}

if ($tab == 'linkso') {
	diamantutils_setup_row(
		$langs->trans('DiamantutilsLinkSOEnabled'),
		$langs->trans('DiamantutilsLinkSOEnabledDesc'),
		$form->selectyesno('DIAMANTUTILS_LINK_SUPPLIER_ORDERS', getDolGlobalInt('DIAMANTUTILS_LINK_SUPPLIER_ORDERS'), 1)
	);
	diamantutils_setup_row(
		$langs->trans('DiamantutilsLinkSOMonths'),
		$langs->trans('DiamantutilsLinkSOMonthsDesc'),
		'<input type="number" min="0" class="flat width75" name="DIAMANTUTILS_LINK_SUPPLIER_ORDERS_MONTHS" value="'.((int) getDolGlobalInt('DIAMANTUTILS_LINK_SUPPLIER_ORDERS_MONTHS', 24)).'">'
	);

	// État : hook enregistré (module réactivé), saisie par référence
	$hooks = (isset($conf->modules_parts['hooks']['diamantutils']) ? $conf->modules_parts['hooks']['diamantutils'] : array());
	if (!is_array($hooks)) {
		$hooks = explode(':', (string) $hooks);
	}
	if (in_array('ordersuppliercard', $hooks)) {
		$status = img_picto('', 'tick', 'class="pictofixedwidth"').$langs->trans('DiamantutilsLinkSOHookOk');
	} else {
		$status = img_warning('', 'class="pictofixedwidth"').'<span class="warning">'.$langs->trans('DiamantutilsLinkSOHookMissing').'</span>';
	}
	$status .= '<br>';
	if (getDolGlobalString('MAIN_LINK_BY_REF_IN_LINKTO')) {
		$status .= img_picto('', 'tick', 'class="pictofixedwidth"').$langs->trans('DiamantutilsLinkSOByRefOk');
	} else {
		$status .= '<span class="opacitymedium">'.$langs->trans('DiamantutilsLinkSOByRefOff').'</span>';
	}
	diamantutils_setup_row($langs->trans('DiamantutilsLinkSOStatus'), '', $status);
}

if ($tab == 'stocktable') {
	diamantutils_setup_row(
		$langs->trans('DiamantutilsFeatureEnabled'),
		$langs->trans('DiamantutilsStockTableEnabledDesc'),
		$form->selectyesno('DIAMANTUTILS_STOCKTABLE_ENABLED', diamantutils_stocktable_enabled() ? 1 : 0, 1)
	);

	// Tags produit (catégories de type produit)
	$allcats = $form->select_all_categories(Categorie::TYPE_PRODUCT, '', '', 64, 0, 1);
	$selectedcats = diamantutils_stocktable_ids(getDolGlobalString('DIAMANTUTILS_STOCKTABLE_CATEGORIES'));
	diamantutils_setup_row(
		$langs->trans('DiamantutilsStockTableCategories'),
		$langs->trans('DiamantutilsStockTableCategoriesDesc'),
		(is_array($allcats) && count($allcats) ? $form->multiselectarray('DIAMANTUTILS_STOCKTABLE_CATEGORIES', $allcats, $selectedcats, 0, 0, 'minwidth300', 0, '90%') : '<span class="warning">'.$langs->trans('NoCategoriesDefined').'</span>')
	);
	diamantutils_setup_row(
		$langs->trans('DiamantutilsStockTableSubcategories'),
		$langs->trans('DiamantutilsStockTableSubcategoriesDesc'),
		$form->selectyesno('DIAMANTUTILS_STOCKTABLE_SUBCATEGORIES', !empty(getDolGlobalString('DIAMANTUTILS_STOCKTABLE_SUBCATEGORIES', '1')) ? 1 : 0, 1)
	);
	diamantutils_setup_row(
		$langs->trans('DiamantutilsStockTableTolerance'),
		$langs->trans('DiamantutilsStockTableToleranceDesc'),
		'<input type="text" class="flat width75" name="DIAMANTUTILS_STOCKTABLE_TOLERANCE" value="'.dol_escape_htmltag(diamantutils_stock_num(diamantutils_stocktable_tolerance(), 6, $langs->transnoentitiesnoconv('SeparatorDecimal'))).'">'
	);

	// Entrepôts exclus du comparatif
	$allwarehouses = array();
	$sql = "SELECT e.rowid, e.ref, e.statut FROM ".MAIN_DB_PREFIX."entrepot as e";
	$sql .= " WHERE e.entity IN (".getEntity('stock').")";
	$sql .= " ORDER BY e.ref";
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$allwarehouses[(int) $obj->rowid] = $obj->ref.(empty($obj->statut) ? ' ('.$langs->transnoentities('Closed').')' : '');
		}
		$db->free($resql);
	}
	diamantutils_setup_row(
		$langs->trans('DiamantutilsStockTableExcludeWarehouses'),
		$langs->trans('DiamantutilsStockTableExcludeWarehousesDesc'),
		$form->multiselectarray('DIAMANTUTILS_STOCKTABLE_EXCLUDE_WAREHOUSES', $allwarehouses, diamantutils_stocktable_excluded_warehouses(), 0, 0, 'minwidth300', 0, '90%')
	);

	// État : périmètre et table
	$catids = diamantutils_stocktable_category_ids($db);
	$nbproducts = 0;
	if (!empty($catids)) {
		$sql = "SELECT COUNT(DISTINCT p.rowid) as nb FROM ".MAIN_DB_PREFIX."product as p";
		$sql .= " WHERE p.rowid IN (".diamantutils_stocktable_scope_sql($catids).")";
		$sql .= " AND p.fk_product_type = 0 AND p.entity IN (".getEntity('product').")";
		$resql = $db->query($sql);
		if ($resql) {
			$obj = $db->fetch_object($resql);
			$nbproducts = ($obj ? (int) $obj->nb : 0);
			$db->free($resql);
		}
	}
	if (empty($catids)) {
		$status = img_warning('', 'class="pictofixedwidth"').'<span class="warning">'.$langs->trans('DiamantutilsStockTableNoCategory').'</span>';
	} else {
		$status = img_picto('', 'tick', 'class="pictofixedwidth"').$langs->trans('DiamantutilsStockTableScopeCount', count($catids), $nbproducts);
	}
	$status .= '<br>';
	$resql = $db->query("SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."diamantutils_stockline WHERE entity IN (".getEntity('diamantutils_stockline').")");
	if ($resql) {
		$obj = $db->fetch_object($resql);
		$db->free($resql);
		$status .= img_picto('', 'tick', 'class="pictofixedwidth"').$langs->trans('DiamantutilsStockTableLineCount', ($obj ? (int) $obj->nb : 0));
		if (diamantutils_stocktable_enabled()) {
			$status .= ' &nbsp; <a href="'.dol_buildpath('/diamantutils/stocktable_list.php', 1).'?mainmenu=products&leftmenu=stock">'.img_picto('', 'stock', 'class="pictofixedwidth"').$langs->trans('DiamantutilsStockTableTitle').'</a>';
		}
	} else {
		$status .= img_warning('', 'class="pictofixedwidth"').'<span class="warning">'.$langs->trans('DiamantutilsStockTableNoTable').'</span>';
	}
	diamantutils_setup_row($langs->trans('DiamantutilsStockTableStatus'), $langs->trans('DiamantutilsStockTableRights'), $status);
}

if ($tab == 'stockcolisage') {
	diamantutils_setup_row(
		$langs->trans('DiamantutilsFeatureEnabled'),
		$langs->trans('DiamantutilsStockColisageEnabledDesc'),
		$form->selectyesno('DIAMANTUTILS_STOCKCOLISAGE_ENABLED', diamantutils_stockcolisage_enabled() ? 1 : 0, 1)
	);
	$sc_code = diamantutils_stockcolisage_extrafield_code();
	diamantutils_setup_row(
		$langs->trans('DiamantutilsStockColisageExtrafield'),
		$langs->trans('DiamantutilsStockColisageExtrafieldDesc'),
		'<input type="text" class="flat minwidth150" maxlength="64" name="DIAMANTUTILS_STOCKCOLISAGE_EXTRAFIELD" value="'.dol_escape_htmltag(getDolGlobalString('DIAMANTUTILS_STOCKCOLISAGE_EXTRAFIELD', 'colisage')).'">'
	);

	diamantutils_setup_row(
		$langs->trans('DiamantutilsColisageAuto'),
		$langs->trans('DiamantutilsColisageAutoDesc'),
		$form->selectyesno('DIAMANTUTILS_STOCKCOLISAGE_AUTO', getDolGlobalInt('DIAMANTUTILS_STOCKCOLISAGE_AUTO', 1) ? 1 : 0, 1)
	);

	// Entrepôts ignorés pour la quantité du lot
	$sc_warehouses = array();
	$sql = "SELECT e.rowid, e.ref, e.statut FROM ".MAIN_DB_PREFIX."entrepot as e";
	$sql .= " WHERE e.entity IN (".getEntity('stock').")";
	$sql .= " ORDER BY e.ref";
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$sc_warehouses[(int) $obj->rowid] = $obj->ref.(empty($obj->statut) ? ' ('.$langs->transnoentities('Closed').')' : '');
		}
		$db->free($resql);
	}
	diamantutils_setup_row(
		$langs->trans('DiamantutilsColisageExcludeWarehouses'),
		$langs->trans('DiamantutilsColisageExcludeWarehousesDesc'),
		$form->multiselectarray('DIAMANTUTILS_STOCKCOLISAGE_EXCLUDE_WAREHOUSES', $sc_warehouses, diamantutils_stockcolisage_excluded_warehouses(), 0, 0, 'minwidth300', 0, '90%')
	);

	// État : attributs du lot, module Lot/série, hook enregistré (module réactivé)
	if ($sc_code !== '' && diamantutils_stockcolisage_extrafield_exists($db, $sc_code)) {
		$status = img_picto('', 'tick', 'class="pictofixedwidth"').$langs->trans('DiamantutilsStockColisageExtrafieldOk', $sc_code);
	} else {
		$status = img_warning('', 'class="pictofixedwidth"').'<span class="warning">'.$langs->trans('DiamantutilsStockColisageExtrafieldMissing', ($sc_code !== '' ? $sc_code : '?')).'</span>';
	}
	$status .= '<br>';
	foreach (diamantutils_colisage_fields_status($db) as $sc_field => $sc_ok) {
		if ($sc_ok) {
			$status .= img_picto('', 'tick', 'class="pictofixedwidth"').$langs->trans('DiamantutilsStockColisageExtrafieldOk', $sc_field);
		} else {
			$status .= img_warning('', 'class="pictofixedwidth"').'<span class="warning">'.$langs->trans('DiamantutilsColisageFieldMissing', $sc_field).'</span>';
		}
		$status .= '<br>';
	}
	if (isModEnabled('productbatch')) {
		$status .= img_picto('', 'tick', 'class="pictofixedwidth"').$langs->trans('DiamantutilsStockColisageBatchOk');
	} else {
		$status .= img_warning('', 'class="pictofixedwidth"').'<span class="warning">'.$langs->trans('DiamantutilsStockColisageBatchMissing').'</span>';
	}
	$status .= '<br>';
	$hooks = (isset($conf->modules_parts['hooks']['diamantutils']) ? $conf->modules_parts['hooks']['diamantutils'] : array());
	if (!is_array($hooks)) {
		$hooks = explode(':', (string) $hooks);
	}
	if (in_array('stockproductcard', $hooks)) {
		$status .= img_picto('', 'tick', 'class="pictofixedwidth"').$langs->trans('DiamantutilsStockColisageHookOk');
	} else {
		$status .= img_warning('', 'class="pictofixedwidth"').'<span class="warning">'.$langs->trans('DiamantutilsStockColisageHookMissing').'</span>';
	}
	diamantutils_setup_row($langs->trans('DiamantutilsStockColisageStatus'), $langs->trans('DiamantutilsStockColisageRights'), $status);
}

print '</table>';

print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div>';

print '</form>';

// Colisage calculé : recalcul de tous les lots, et rapport du dernier recalcul
if ($tab == 'stockcolisage') {
	print '<br>';
	print load_fiche_titre($langs->trans('DiamantutilsColisageRecalcTitle'), '', 'technic');
	print '<span class="opacitymedium">'.$langs->trans('DiamantutilsColisageRecalcDesc').'</span><br><br>';
	print '<form method="POST" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="recalccolisage">';
	print '<input type="hidden" name="tab" value="stockcolisage">';
	print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('DiamantutilsColisageRecalcButton')).'"'.(diamantutils_stockcolisage_auto_enabled($db) ? '' : ' disabled title="'.dol_escape_htmltag($langs->trans('DiamantutilsColisageRecalcUnavailable')).'"').'>';
	print '</form>';

	if (is_array($recalcreport)) {
		print '<br><table class="noborder centpercent">';
		print '<tr class="liste_titre"><td colspan="4">'.$langs->trans('DiamantutilsColisageRecalcDone', $recalcreport['updated'], $recalcreport['unchanged'], count($recalcreport['warnings'])).'</td></tr>';
		if (count($recalcreport['warnings'])) {
			print '<tr class="liste_titre"><td>'.$langs->trans('Product').'</td><td>'.$langs->trans('Batch').'</td><td class="right">'.$langs->trans('Quantity').'</td><td>'.$langs->trans('Warning').'</td></tr>';
			foreach ($recalcreport['warnings'] as $w) {
				print '<tr class="oddeven">';
				print '<td>'.dol_escape_htmltag($w['product']).'</td>';
				print '<td><a href="'.DOL_URL_ROOT.'/product/stock/productlot_card.php?id='.((int) $w['lotid']).'">'.img_picto('', 'lot', 'class="pictofixedwidth"').dol_escape_htmltag($w['batch']).'</a></td>';
				print '<td class="right">'.dol_escape_htmltag(price2num($w['qty'], 'MS')).'</td>';
				print '<td>'.img_warning('', 'class="pictofixedwidth"').dol_escape_htmltag($w['warn']).'</td>';
				print '</tr>';
			}
		} else {
			print '<tr class="oddeven"><td colspan="4"><span class="opacitymedium">'.$langs->trans('DiamantutilsColisageRecalcNoWarning').'</span></td></tr>';
		}
		print '</table>';
	}
}

// Transformations : produits saisis en pièces × longueur (option « Lot = longueur » cochée)
if ($tab == 'transfo') {
	print '<br>';
	print load_fiche_titre($langs->trans('DiamantutilsLotLongueurProducts'), '', 'product');
	print '<span class="opacitymedium">'.$langs->trans('DiamantutilsLotLongueurProductsDesc').'</span><br><br>';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Product').'</td><td>'.$langs->trans('Unit').'</td><td>'.$langs->trans('Width').'</td></tr>';
	$nbproducts = 0;
	$sql = "SELECT p.rowid, p.ref, p.label, p.width, p.width_units, u.short_label as unit";
	$sql .= " FROM ".MAIN_DB_PREFIX."product as p";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_extrafields as ef ON ef.fk_object = p.rowid";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."c_units as u ON u.rowid = p.fk_unit";
	$sql .= " WHERE ef.diamantutils_lotlongueur = 1";
	$sql .= " AND p.entity IN (".getEntity('product').")";
	$sql .= " ORDER BY p.ref";
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$nbproducts++;
			print '<tr class="oddeven">';
			print '<td><a href="'.DOL_URL_ROOT.'/product/card.php?id='.((int) $obj->rowid).'">'.img_picto('', 'product', 'class="pictofixedwidth"').dol_escape_htmltag($obj->ref).'</a> <span class="opacitymedium">'.dol_escape_htmltag($obj->label).'</span></td>';
			print '<td>'.dol_escape_htmltag((string) $obj->unit).'</td>';
			print '<td>'.($obj->width > 0 ? dol_escape_htmltag(price2num($obj->width).' '.measuringUnitString(0, 'size', $obj->width_units, 1)) : '<span class="warning">'.$langs->trans('DiamantutilsNoWidthShort').'</span>').'</td>';
			print '</tr>';
		}
		$db->free($resql);
	}
	if (!$nbproducts) {
		print '<tr class="oddeven"><td colspan="3"><span class="opacitymedium">'.$langs->trans('None').'</span></td></tr>';
	}
	print '</table>';
}

print dol_get_fiche_end();

llxFooter();
$db->close();
