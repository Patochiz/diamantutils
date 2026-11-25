<?php
/**
 * Tableau de stock par lots
 *
 * Un groupe repliable par produit (produits des tags configurés), une ligne par lot / colisage
 * constaté. Chaque groupe et chaque lot est comparé au stock réel Dolibarr (hors entrepôts
 * exclus). Aucun mouvement de stock : le tableau signale les écarts.
 *
 * Rendu côté serveur ; recherche, filtre « écarts seulement » et repli côté client
 * (js/stocktable.js). Saisie en ligne par ajax/stocktable.php (droit write).
 */

// Pages du module : garder le menu Produits/Stocks sélectionné quel que soit le lien d'arrivée
if (!isset($_GET['mainmenu']) && !isset($_POST['mainmenu'])) {
	$_GET['mainmenu'] = 'products';
	$_GET['leftmenu'] = 'stock';
}

$res = 0;
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
dol_include_once('/diamantutils/lib/stocktable.lib.php');

$langs->loadLangs(array('products', 'stocks', 'productbatch', 'suppliers', 'categories', 'diamantutils@diamantutils'));

if (!isModEnabled('diamantutils') || !$user->hasRight('diamantutils', 'stocktable', 'read')) {
	accessforbidden();
}

$perms = array(
	'write' => $user->hasRight('diamantutils', 'stocktable', 'write'),
	'delete' => $user->hasRight('diamantutils', 'stocktable', 'delete'),
);

$search_categ = GETPOSTINT('search_categ');

$title = $langs->trans('DiamantutilsStockTableTitle');
llxHeader('', $title, '', '', 0, 0, '', array('/diamantutils/css/stocktable.css'), '', 'mod-diamantutils page-stocktable-list');

if (!diamantutils_stocktable_enabled()) {
	print load_fiche_titre($title, '', 'stock');
	print '<div class="warning">'.$langs->trans('DiamantutilsFeatureDisabled').'</div>';
	llxFooter();
	$db->close();
	exit;
}

$setuplink = ($user->admin ? ' <a href="'.dol_buildpath('/diamantutils/admin/setup.php', 1).'?tab=stocktable">'.img_picto('', 'setup', 'class="pictofixedwidth"').$langs->trans('DiamantutilsStockTableGoToSetup').'</a>' : '');

$scopecats = diamantutils_stocktable_category_ids($db);
if (empty($scopecats)) {
	print load_fiche_titre($title, '', 'stock');
	print '<div class="warning">'.$langs->trans('DiamantutilsStockTableNoCategoryPage').$setuplink.'</div>';
	llxFooter();
	$db->close();
	exit;
}
if ($search_categ > 0 && !in_array($search_categ, $scopecats, true)) {
	$search_categ = 0;
}
$catids = diamantutils_stocktable_category_ids($db, $search_categ);

$groups = diamantutils_stocktable_load($db, $catids);
if (!is_array($groups)) {
	setEventMessages($db->lasterror() ? $db->lasterror() : 'Error', null, 'errors');
	$groups = array();
}

$nbdiff = 0;
foreach ($groups as $group) {
	if ($group['inscope'] && $group['cmp']['state'] !== DIAMANTUTILS_STOCK_OK) {
		$nbdiff++;
	}
}

// Libellés des tags du périmètre (filtre)
$taglabels = array();
$form = new Form($db);
$allcats = $form->select_all_categories(Categorie::TYPE_PRODUCT, '', '', 64, 0, 1);
if (is_array($allcats)) {
	foreach ($allcats as $id => $label) {
		if (in_array((int) $id, $scopecats, true)) {
			$taglabels[(int) $id] = $label;
		}
	}
	asort($taglabels, SORT_NATURAL | SORT_FLAG_CASE);
}

// Entrepôts exclus (rappel sous le titre)
$excluded = diamantutils_stocktable_excluded_warehouses();
$excludednames = array();
if (!empty($excluded)) {
	$resql = $db->query("SELECT e.ref FROM ".MAIN_DB_PREFIX."entrepot as e WHERE e.rowid IN (".implode(',', $excluded).") AND e.entity IN (".getEntity('stock').") ORDER BY e.ref");
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$excludednames[] = $obj->ref;
		}
		$db->free($resql);
	}
}

// Fournisseurs proposés à la saisie
$suppliers = array();
if ($perms['write']) {
	$sql = "SELECT s.rowid, s.nom FROM ".MAIN_DB_PREFIX."societe as s";
	$sql .= " WHERE s.fournisseur = 1 AND s.status = 1 AND s.entity IN (".getEntity('societe').")";
	$sql .= " ORDER BY s.nom";
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$suppliers[] = array((int) $obj->rowid, (string) $obj->nom);
		}
		$db->free($resql);
	}
}


/*
 * Vue
 */

$morehtmlright = '';
if ($perms['write']) {
	$morehtmlright = '<button type="button" class="butAction dst-addproduct">'.$langs->trans('DiamantutilsStockTableAddProduct').'</button>';
}
print load_fiche_titre($title.' <span class="opacitymedium dst-count">('.$langs->trans('DiamantutilsStockTableProducts', count($groups), $nbdiff).')</span>', $morehtmlright, 'stock');

print '<span class="opacitymedium">'.$langs->trans('DiamantutilsStockTableDesc');
if (!empty($excludednames)) {
	print ' '.$langs->trans('DiamantutilsStockTableExcluded', dol_escape_htmltag(implode(', ', $excludednames))).'.';
}
print '</span>'.$setuplink;

// Barre d'outils
print '<form method="GET" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" class="dst-toolbar">';
print '<input type="hidden" name="mainmenu" value="products"><input type="hidden" name="leftmenu" value="stock">';
print '<input type="search" class="flat minwidth250 dst-search" placeholder="'.dol_escape_htmltag($langs->trans('DiamantutilsStockTableSearch')).'" autocomplete="off">';
print '<label class="dst-onlydiff-label"><input type="checkbox" class="dst-onlydiff"> '.$langs->trans('DiamantutilsStockTableOnlyDiffs').'</label>';
if (count($taglabels) > 1) {
	print '<select name="search_categ" class="flat minwidth150 dst-categ" onchange="this.form.submit()">';
	print '<option value="0">'.$langs->trans('DiamantutilsStockTableAllTags').'</option>';
	foreach ($taglabels as $id => $label) {
		print '<option value="'.((int) $id).'"'.($id == $search_categ ? ' selected' : '').'>'.dol_escape_htmltag($label).'</option>';
	}
	print '</select>';
}
print '<span class="dst-toolbar-right">';
print '<button type="button" class="button smallpaddingimp dst-collapseall">'.$langs->trans('DiamantutilsStockTableCollapseAll').'</button>';
print '<button type="button" class="button smallpaddingimp dst-expandall">'.$langs->trans('DiamantutilsStockTableExpandAll').'</button>';
print '</span>';
print '</form>';

// Zone de recherche « + Produit » (remplie par le JS)
if ($perms['write']) {
	print '<div class="dst-productpicker hidden"></div>';
}

print '<div class="dst-wrap">';
print '<table class="noborder centpercent dst-table">';
print '<thead><tr class="liste_titre">';
print '<th>'.$langs->trans('DiamantutilsStockColLot').'</th>';
print '<th>'.$langs->trans('DiamantutilsStockColColisage').'</th>';
print '<th class="right">'.$langs->trans('DiamantutilsStockColPieces').'</th>';
print '<th class="right">'.$langs->trans('DiamantutilsStockColPerColis').'</th>';
print '<th class="right">'.$langs->trans('DiamantutilsStockColLarg').'</th>';
print '<th class="right">'.$langs->trans('DiamantutilsStockColLong').'</th>';
print '<th class="right">'.$langs->trans('DiamantutilsStockColQty').'</th>';
print '<th>'.$langs->trans('DiamantutilsStockColSupplier').'</th>';
print '<th class="right">'.$langs->trans('DiamantutilsStockColDoli').'</th>';
print '<th class="right">'.$langs->trans('DiamantutilsStockColDiff').'</th>';
print '<th class="center"></th>';
print '<th></th>';
print '</tr></thead>';

// Une section par tag configuré (toutes sans filtre, sinon celles qui ont des produits),
// puis « hors périmètre » ; les groupes sont déjà triés dans cet ordre
$sections = diamantutils_stocktable_sections($db);
$bysection = array();
foreach ($groups as $pid => $group) {
	$bysection[(int) $group['section']][] = $group;
}
$sections[0] = $langs->transnoentitiesnoconv('DiamantutilsStockTableOutOfScopeSection');
foreach ($sections as $sectionid => $sectionlabel) {
	$list = (isset($bysection[$sectionid]) ? $bysection[$sectionid] : array());
	if (empty($list) && ($sectionid == 0 || $search_categ > 0)) {
		continue;
	}
	$nbsectiondiff = 0;
	foreach ($list as $group) {
		if ($sectionid > 0 && $group['cmp']['state'] !== DIAMANTUTILS_STOCK_OK) {
			$nbsectiondiff++;
		}
	}
	print diamantutils_stocktable_render_section($sectionid, $sectionlabel, count($list), $nbsectiondiff);
	foreach ($list as $group) {
		print diamantutils_stocktable_render_group($group, $perms);
	}
}
print '<tbody class="dst-empty'.(empty($groups) ? '' : ' hidden').'"><tr><td colspan="'.DIAMANTUTILS_STOCKTABLE_NBCOLS.'"><span class="opacitymedium">'.(empty($groups) ? $langs->trans('DiamantutilsStockTableEmpty') : $langs->trans('NoRecordFound')).'</span></td></tr></tbody>';

print '</table>';
print '</div>';

// Configuration du script
$decsep = $langs->transnoentitiesnoconv('SeparatorDecimal');
$jsconfig = array(
	'ajaxUrl' => dol_buildpath('/diamantutils/ajax/stocktable.php', 1),
	'token' => newToken(),
	'entity' => (int) $conf->entity,
	'write' => (bool) $perms['write'],
	'delete' => (bool) $perms['delete'],
	'decsep' => ($decsep === 'SeparatorDecimal' || $decsep === '' ? ',' : $decsep),
	'suppliers' => $suppliers,
	'colisFmt' => diamantutils_stocktable_rawtrans('DiamantutilsStockColisFmt'),
	'labels' => array(
		'products' => diamantutils_stocktable_rawtrans('DiamantutilsStockTableProducts'),
		'save' => $langs->transnoentitiesnoconv('Save'),
		'cancel' => $langs->transnoentitiesnoconv('Cancel'),
		'chooseLot' => $langs->transnoentitiesnoconv('DiamantutilsStockTableChooseLot'),
		'otherLot' => $langs->transnoentitiesnoconv('DiamantutilsStockTableOtherLot'),
		'otherLotName' => $langs->transnoentitiesnoconv('DiamantutilsStockTableOtherLotName'),
		'otherLotWarn' => $langs->transnoentitiesnoconv('DiamantutilsStockTableOtherLotWarn'),
		'lotStock' => diamantutils_stocktable_rawtrans('DiamantutilsStockTableLotStock'),
		'noLot' => $langs->transnoentitiesnoconv('DiamantutilsStockTableNoLot'),
		'noSupplier' => $langs->transnoentitiesnoconv('DiamantutilsStockTableNoSupplier'),
		'note' => $langs->transnoentitiesnoconv('DiamantutilsStockTableNote'),
		'confirmDelete' => $langs->transnoentitiesnoconv('DiamantutilsStockTableConfirmDelete'),
		'searchProduct' => $langs->transnoentitiesnoconv('DiamantutilsStockTableSearchProduct'),
		'noProduct' => $langs->transnoentitiesnoconv('DiamantutilsStockTableNoProductFound'),
		'liveHint' => $langs->transnoentitiesnoconv('DiamantutilsStockTableLiveHint'),
		'network' => diamantutils_stocktable_rawtrans('DiamantutilsStockTableErrorNetwork'),
		'partial' => $langs->transnoentitiesnoconv('DiamantutilsStockColisPartial'),
		'pieces' => $langs->transnoentitiesnoconv('DiamantutilsStockColPieces'),
		'perColis' => $langs->transnoentitiesnoconv('DiamantutilsStockColPerColis'),
		'larg' => $langs->transnoentitiesnoconv('DiamantutilsStockColLarg'),
		'long' => $langs->transnoentitiesnoconv('DiamantutilsStockColLong'),
		'dimsSurface' => $langs->transnoentitiesnoconv('DiamantutilsStockErrDimsSurface'),
		'dimsSize' => $langs->transnoentitiesnoconv('DiamantutilsStockErrDimsSize'),
	),
);
print '<script>window.diamantutilsStockTable = '.json_encode($jsconfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).';</script>';
print '<script src="'.dol_buildpath('/diamantutils/js/stocktable.js', 1).'?v=2.10"></script>';

llxFooter();
$db->close();
