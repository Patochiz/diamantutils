<?php
/**
 * Tableau de stock par lots
 *
 * Données Dolibarr seules : un groupe repliable par produit des tags configurés (stock physique,
 * commandes client et fournisseur en cours, stock virtuel), une ligne par lot en stock avec son
 * colisage, ses pièces par colis et sa qté par pièce (attributs du lot) et son stock physique
 * (hors entrepôts ignorés du colisage, dont le stock est affiché sur une ligne à part).
 *
 * Rendu côté serveur ; recherche, filtre par tag et repli côté client (js/stocktable.js).
 * Attributs du lot modifiables en ligne (js/stockcolisage.js, ajax/lotcolisage.php), avec les
 * mêmes droits que l'onglet Stock de la fiche produit. Aucun mouvement de stock.
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
dol_include_once('/diamantutils/lib/stockcolisage.lib.php');

$langs->loadLangs(array('products', 'stocks', 'productbatch', 'suppliers', 'categories', 'diamantutils@diamantutils'));

if (!isModEnabled('diamantutils') || !$user->hasRight('diamantutils', 'stocktable', 'read')) {
	accessforbidden();
}

// Modification des attributs du lot : fonctionnalité « Stock produit » active, droit produit / créer
$code = diamantutils_stockcolisage_extrafield_code();
$hasfield = diamantutils_stockcolisage_enabled() && $code !== '' && diamantutils_stockcolisage_extrafield_exists($db, $code);
$auto = diamantutils_stockcolisage_auto_enabled($db);
$perms = array(
	'colisage' => ($hasfield && $user->hasRight('produit', 'creer')),
	'num' => ($auto && $user->hasRight('produit', 'creer')),
	'auto' => $auto,
);

$search_categ = GETPOSTINT('search_categ');

$title = $langs->trans('DiamantutilsStockTableTitle');
llxHeader('', $title, '', '', 0, 0, '', array('/diamantutils/css/stocktable.css', '/diamantutils/css/stockcolisage.css'), '', 'mod-diamantutils page-stocktable-list');

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

// Entrepôts ignorés (rappel sous le titre)
$excluded = diamantutils_stockcolisage_excluded_warehouses();
$excludednames = array();
if (!empty($excluded)) {
	$resql = $db->query("SELECT e.ref FROM ".MAIN_DB_PREFIX."entrepot as e WHERE e.rowid IN (".implode(',', array_map('intval', $excluded)).") AND e.entity IN (".getEntity('stock').") ORDER BY e.ref");
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$excludednames[] = $obj->ref;
		}
		$db->free($resql);
	}
}


$perms['excluded'] = implode(', ', $excludednames);


/*
 * Vue
 */

print load_fiche_titre($title.' <span class="opacitymedium dst-count">('.$langs->trans('DiamantutilsStockTableNbProducts', count($groups)).')</span>', '', 'stock');

print '<span class="opacitymedium">'.$langs->trans('DiamantutilsStockTableDescDoli');
if (!empty($excludednames)) {
	print ' '.$langs->trans('DiamantutilsStockTableExcluded', dol_escape_htmltag(implode(', ', $excludednames))).'.';
}
if (!$auto) {
	print ' '.$langs->trans('DiamantutilsStockTableAutoOff');
}
print '</span>'.$setuplink;

// Barre d'outils
print '<form method="GET" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" class="dst-toolbar">';
print '<input type="hidden" name="mainmenu" value="products"><input type="hidden" name="leftmenu" value="stock">';
print '<input type="search" class="flat minwidth250 dst-search" placeholder="'.dol_escape_htmltag($langs->trans('DiamantutilsStockTableSearchDoli')).'" autocomplete="off">';
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

// Configuration de l'édition en ligne (js/stockcolisage.js, mode tableau)
$editconfig = array(
	'url' => dol_buildpath('/diamantutils/ajax/lotcolisage.php', 1),
	'token' => newToken(),
	'product' => 0,
	'canEdit' => $perms['colisage'],
	'canEditNum' => $perms['num'],
	'maxLength' => 200,
	'lang' => diamantutils_stockcolisage_js_lang(),
);

print '<div class="dst-wrap dut-sc-standalone" data-config="'.htmlspecialchars(json_encode($editconfig), ENT_QUOTES, 'UTF-8').'">';
print '<table class="noborder centpercent dst-table">';
print '<thead><tr class="liste_titre">';
print '<th>'.$langs->trans('DiamantutilsStockColLot').'</th>';
print '<th>'.$langs->trans('DiamantutilsStockColColisage').'</th>';
print '<th class="right">'.$langs->trans('DiamantutilsColisagePpcCol').'</th>';
print '<th class="right">'.$langs->trans('DiamantutilsColisageQppCol').'</th>';
print '<th class="right">'.$langs->trans('DiamantutilsStockTablePhysical').'</th>';
print '<th class="right">'.$langs->trans('DiamantutilsStockTableCustomerOrders').'</th>';
print '<th class="right">'.$langs->trans('DiamantutilsStockTableSupplierOrders').'</th>';
print '<th class="right">'.$langs->trans('DiamantutilsStockTableVirtual').'</th>';
print '</tr></thead>';

// Une section par tag configuré (toutes sans filtre, sinon celles qui ont des produits)
$sections = diamantutils_stocktable_sections($db);
$bysection = array();
foreach ($groups as $pid => $group) {
	$bysection[(int) $group['section']][] = $group;
}
foreach ($sections as $sectionid => $sectionlabel) {
	$list = (isset($bysection[$sectionid]) ? $bysection[$sectionid] : array());
	if (empty($list) && $search_categ > 0) {
		continue;
	}
	print diamantutils_stocktable_render_section($sectionid, $sectionlabel, count($list));
	foreach ($list as $group) {
		print diamantutils_stocktable_render_group($group, $perms);
	}
}
print '<tbody class="dst-empty'.(empty($groups) ? '' : ' hidden').'"><tr><td colspan="'.DIAMANTUTILS_STOCKTABLE_NBCOLS.'"><span class="opacitymedium">'.(empty($groups) ? $langs->trans('DiamantutilsStockTableEmptyDoli') : $langs->trans('NoRecordFound')).'</span></td></tr></tbody>';

print '</table>';
print '</div>';

// Configuration du script de la liste
$jsconfig = array(
	'entity' => (int) $conf->entity,
	'labels' => array(
		'products' => diamantutils_stocktable_rawtrans('DiamantutilsStockTableNbProducts'),
	),
);
print '<script>window.diamantutilsStockTable = '.json_encode($jsconfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).';</script>';
print '<script src="'.dol_buildpath('/diamantutils/js/stocktable.js', 1).'?v=2.14"></script>';
print '<script src="'.dol_buildpath('/diamantutils/js/stockcolisage.js', 1).'?v=2.14"></script>';

llxFooter();
$db->close();
