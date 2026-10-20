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

if (!$user->admin) {
	accessforbidden();
}

$langs->loadLangs(array("admin", "stocks", "diamantutils@diamantutils"));

$action = GETPOST('action', 'aZ09');
$tab = GETPOST('tab', 'aZ09');
if (!in_array($tab, array('transfo', 'soustraitance', 'customlist', 'descpropal', 'lot'))) {
	$tab = 'transfo';
}


/*
 * Actions : chaque onglet n'enregistre que ses propres réglages
 */

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

print '</table>';

print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div>';

print '</form>';

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
