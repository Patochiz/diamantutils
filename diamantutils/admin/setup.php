<?php
// Page de configuration du module DiamantUtils

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
dol_include_once('/diamantutils/lib/soustraitance.lib.php');

if (!$user->admin) {
	accessforbidden();
}

$langs->loadLangs(array("admin", "stocks", "diamantutils@diamantutils"));

$action = GETPOST('action', 'aZ09');

if ($action == 'update') {
	$error = 0;

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
	} else {
		if (dolibarr_set_const($db, 'DIAMANTUTILS_TRANSFO_LOT_FORMAT', $lotformat, 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}
	}

	// Commandes sous-traitées : activation et entrepôt
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

	if (!$error) {
		setEventMessages($langs->trans('DiamantutilsConfigUpdated'), null);
	}
}

$formproduct = new FormProduct($db);
$form = new Form($db);

llxHeader('', $langs->trans('DiamantutilsSetupTitle'));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans('DiamantutilsSetupTitle'), $linkback, 'title_setup');

print '<form method="POST" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('DiamantutilsFeature').'</td><td>'.$langs->trans('DiamantutilsSetting').'</td></tr>';

// Entrepôt par défaut
print '<tr class="oddeven"><td>';
print $langs->trans('DiamantutilsTransfoDefaultWarehouse').'<br>';
print '<span class="opacitymedium">'.$langs->trans('DiamantutilsTransfoDefaultWarehouseDesc').'</span>';
print '</td><td>';
print $formproduct->selectWarehouses(getDolGlobalInt('DIAMANTUTILS_TRANSFO_DEFAULT_WAREHOUSE'), 'DIAMANTUTILS_TRANSFO_DEFAULT_WAREHOUSE', 'warehouseopen', 1);
print '</td></tr>';

// Format du nom de lot
print '<tr class="oddeven"><td>';
print $langs->trans('DiamantutilsTransfoLotFormat').'<br>';
print '<span class="opacitymedium">'.$langs->trans('DiamantutilsTransfoLotFormatDesc', '%d', 'Longueur %dmm').'</span>';
print '</td><td>';
print '<input type="text" class="flat minwidth200" name="DIAMANTUTILS_TRANSFO_LOT_FORMAT" value="'.dol_escape_htmltag(getDolGlobalString('DIAMANTUTILS_TRANSFO_LOT_FORMAT', 'Longueur %dmm')).'">';
print '</td></tr>';

// Commandes sous-traitées
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('DiamantutilsSoustraitance').'</td></tr>';

print '<tr class="oddeven"><td>';
print $langs->trans('DiamantutilsSoustraitanceEnabled').'<br>';
print '<span class="opacitymedium">'.$langs->trans('DiamantutilsSoustraitanceEnabledDesc').'</span>';
print '</td><td>';
print $form->selectyesno('DIAMANTUTILS_SOUSTRAITANCE_ENABLED', getDolGlobalInt('DIAMANTUTILS_SOUSTRAITANCE_ENABLED'), 1);
print '</td></tr>';

print '<tr class="oddeven"><td>';
print $langs->trans('DiamantutilsSoustraitanceWarehouse').'<br>';
print '<span class="opacitymedium">'.$langs->trans('DiamantutilsSoustraitanceWarehouseHelp').'</span>';
print '</td><td>';
print $formproduct->selectWarehouses(diamantutils_st_warehouse_id(), 'DIAMANTUTILS_SOUSTRAITANCE_WAREHOUSE_ID', 'warehouseopen', 1);
print '</td></tr>';

// État de l'installation
print '<tr class="oddeven"><td>'.$langs->trans('DiamantutilsSoustraitanceStatus').'</td><td>';
if (diamantutils_st_extrafield_exists($db)) {
	print img_picto('', 'tick', 'class="pictofixedwidth"').$langs->trans('DiamantutilsSoustraitanceExtrafieldOk', DIAMANTUTILS_ST_EXTRAFIELD);
} else {
	print img_warning('', 'class="pictofixedwidth"').'<span class="warning">'.$langs->trans('DiamantutilsSoustraitanceExtrafieldMissing', DIAMANTUTILS_ST_EXTRAFIELD).'</span>';
}
print '<br>';
$st_entrepot = new Entrepot($db);
if (diamantutils_st_warehouse_id() > 0 && $st_entrepot->fetch(diamantutils_st_warehouse_id()) > 0) {
	print img_picto('', 'tick', 'class="pictofixedwidth"').$langs->trans('DiamantutilsSoustraitanceWarehouseOk').' '.$st_entrepot->getNomUrl(1);
} else {
	print img_warning('', 'class="pictofixedwidth"').'<span class="warning">'.$langs->trans('DiamantutilsSoustraitanceWarehouseMissing').'</span>';
}
print '<br>';
if (diamantutils_st_enabled()) {
	print img_picto('', 'tick', 'class="pictofixedwidth"').$langs->trans('DiamantutilsSoustraitanceActive');
} else {
	print '<span class="opacitymedium">'.$langs->trans('DiamantutilsSoustraitanceInactive').'</span>';
}
print '</td></tr>';

print '</table>';

print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div>';

print '</form>';

// Produits saisis en pièces × longueur (option « Lot = longueur » cochée)
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

llxFooter();
$db->close();
