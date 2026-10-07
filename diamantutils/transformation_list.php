<?php
/**
 * Liste des transformations de stock par lots
 */

// Pages du module : garder le menu GPAO sélectionné quel que soit le lien d'arrivée
if (!isset($_GET['mainmenu']) && !isset($_POST['mainmenu'])) {
	$_GET['mainmenu'] = 'mrp';
	$_GET['leftmenu'] = 'diamantutils_ot';
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

require_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';
dol_include_once('/diamantutils/class/transformation.class.php');
dol_include_once('/diamantutils/lib/diamantutils.lib.php');

$langs->loadLangs(array('products', 'stocks', 'orders', 'productbatch', 'diamantutils@diamantutils'));

if (!isModEnabled('diamantutils') || !$user->hasRight('diamantutils', 'transformation', 'read')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

// Filtres
$search_ref = GETPOST('search_ref', 'alpha');
$search_type = GETPOST('search_type', 'aZ09');
$search_product_in = GETPOST('search_product_in', 'alpha');
$search_product_out = GETPOST('search_product_out', 'alpha');
$search_batch = GETPOST('search_batch', 'alpha');
$search_order = GETPOST('search_order', 'alpha');
$search_status = GETPOST('search_status', 'intcomma');
$search_date_startday = GETPOSTINT('search_date_startday');
$search_date_startmonth = GETPOSTINT('search_date_startmonth');
$search_date_startyear = GETPOSTINT('search_date_startyear');
$search_date_endday = GETPOSTINT('search_date_endday');
$search_date_endmonth = GETPOSTINT('search_date_endmonth');
$search_date_endyear = GETPOSTINT('search_date_endyear');
$search_date_start = dol_mktime(0, 0, 0, $search_date_startmonth, $search_date_startday, $search_date_startyear);
$search_date_end = dol_mktime(23, 59, 59, $search_date_endmonth, $search_date_endday, $search_date_endyear);

// Tri et pagination
$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT('page');
if (empty($page) || $page < 0 || GETPOST('button_search', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$page = 0;
}
$offset = $limit * $page;
if (!$sortfield) {
	$sortfield = 't.date_creation';
}
if (!$sortorder) {
	$sortorder = 'DESC';
}
$allowedsort = array('t.ref', 't.date_creation', 't.date_valid', 't.date_consume', 't.type', 'e.ref', 'c.ref', 't.status', 't.label');
if (!in_array($sortfield, $allowedsort)) {
	$sortfield = 't.date_creation';
}

// Suppression des filtres
if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$search_ref = '';
	$search_type = '';
	$search_product_in = '';
	$search_product_out = '';
	$search_batch = '';
	$search_order = '';
	$search_status = '';
	$search_date_start = '';
	$search_date_end = '';
}

$object = new Transformation($db);
$types = Transformation::getTypes();
$form = new Form($db);


/*
 * Requête
 */

$sql = "SELECT t.rowid, t.ref, t.label, t.type, t.status, t.date_creation, t.date_valid, t.date_consume, t.fk_warehouse, t.fk_commande,";
$sql .= " e.ref as warehouse_ref, c.ref as order_ref";
$sql .= " FROM ".MAIN_DB_PREFIX."diamantutils_transfo as t";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."entrepot as e ON e.rowid = t.fk_warehouse";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."commande as c ON c.rowid = t.fk_commande";
$sql .= " WHERE t.entity IN (".getEntity('diamantutils_transfo').")";
if ($search_ref) {
	$sql .= natural_search('t.ref', $search_ref);
}
if ($search_type && $search_type != '-1') {
	$sql .= " AND t.type = '".$db->escape($search_type)."'";
}
if ($search_status !== '' && $search_status != '-1') {
	$sql .= " AND t.status IN (".$db->sanitize($search_status).")";
}
if ($search_order) {
	$sql .= natural_search('c.ref', $search_order);
}
if ($search_date_start) {
	$sql .= " AND t.date_creation >= '".$db->idate($search_date_start)."'";
}
if ($search_date_end) {
	$sql .= " AND t.date_creation <= '".$db->idate($search_date_end)."'";
}
// Produit consommé / produit (recherche sur ref ou libellé)
foreach (array('IN' => $search_product_in, 'OUT' => $search_product_out) as $direction => $search) {
	if ($search) {
		$sql .= " AND EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."diamantutils_transfo_det as dp";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = dp.fk_product";
		$sql .= " WHERE dp.fk_transfo = t.rowid AND dp.direction = '".$db->escape($direction)."'".natural_search(array('p.ref', 'p.label'), $search).")";
	}
}
if ($search_batch) {
	$sql .= " AND EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."diamantutils_transfo_det as dt";
	$sql .= " WHERE dt.fk_transfo = t.rowid".natural_search('dt.batch', $search_batch).")";
}

// Nombre total
$nbtotalofrecords = '';
if (!getDolGlobalInt('MAIN_DISABLE_FULL_SCANLIST')) {
	$sqlforcount = preg_replace('/^SELECT[a-z0-9\._\s\(\),]+FROM/i', 'SELECT COUNT(*) as nbtotalofrecords FROM', $sql);
	$resql = $db->query($sqlforcount);
	if ($resql) {
		$objforcount = $db->fetch_object($resql);
		$nbtotalofrecords = (int) $objforcount->nbtotalofrecords;
		$db->free($resql);
	}
	if (($page * $limit) > $nbtotalofrecords) {
		$page = 0;
		$offset = 0;
	}
}

$sql .= $db->order($sortfield, $sortorder);
$sql .= $db->plimit($limit + 1, $offset);

$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	exit;
}
$num = $db->num_rows($resql);


/*
 * Vue
 */

$title = $langs->trans('DiamantutilsTransformations');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-diamantutils page-transformation-list');

$param = '';
if ($limit > 0 && $limit != $conf->liste_limit) {
	$param .= '&limit='.((int) $limit);
}
foreach (array('search_ref', 'search_type', 'search_product_in', 'search_product_out', 'search_batch', 'search_order', 'search_status') as $key) {
	if ($$key !== '' && $$key !== null) {
		$param .= '&'.$key.'='.urlencode((string) $$key);
	}
}
if ($search_date_start) {
	$param .= '&search_date_startday='.$search_date_startday.'&search_date_startmonth='.$search_date_startmonth.'&search_date_startyear='.$search_date_startyear;
}
if ($search_date_end) {
	$param .= '&search_date_endday='.$search_date_endday.'&search_date_endmonth='.$search_date_endmonth.'&search_date_endyear='.$search_date_endyear;
}

$newcardbutton = dolGetButtonTitle($langs->trans('DiamantutilsNewTransformation'), '', 'fa fa-plus-circle', dol_buildpath('/diamantutils/transformation_card.php', 1).'?action=create', '', $user->hasRight('diamantutils', 'transformation', 'write'));

print '<form method="POST" id="searchFormList" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print '<input type="hidden" name="sortfield" value="'.dol_escape_htmltag($sortfield).'">';
print '<input type="hidden" name="sortorder" value="'.dol_escape_htmltag($sortorder).'">';
print '<input type="hidden" name="page" value="'.((int) $page).'">';

print_barre_liste($title, $page, $_SERVER['PHP_SELF'], $param, $sortfield, $sortorder, '', $num, $nbtotalofrecords, 'stock', 0, $newcardbutton, '', $limit, 0, 0, 1);

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste">';

// Ligne des filtres
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_ref" value="'.dol_escape_htmltag($search_ref).'"></td>';
print '<td class="liste_titre"><div class="nowrapfordate">';
print $form->selectDate($search_date_start ? $search_date_start : -1, 'search_date_start', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('From'));
print '</div><div class="nowrapfordate">';
print $form->selectDate($search_date_end ? $search_date_end : -1, 'search_date_end', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('to'));
print '</div></td>';
print '<td class="liste_titre">'.$form->selectarray('search_type', $types, $search_type, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth100').'</td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_product_in" value="'.dol_escape_htmltag($search_product_in).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_product_out" value="'.dol_escape_htmltag($search_product_out).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_batch" value="'.dol_escape_htmltag($search_batch).'"></td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_order" value="'.dol_escape_htmltag($search_order).'"></td>';
print '<td class="liste_titre"></td>';
$statuses = array(
	Transformation::STATUS_DRAFT => $langs->trans('Draft'),
	Transformation::STATUS_VALIDATED => $langs->trans('Validated'),
	Transformation::STATUS_CONSUMED => $langs->trans('DiamantutilsStatusConsumed'),
	Transformation::STATUS_CANCELED => $langs->trans('Canceled'),
);
print '<td class="liste_titre center">'.$form->selectarray('search_status', $statuses, $search_status, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth100').'</td>';
print '<td class="liste_titre center maxwidthsearch">'.$form->showFilterButtons().'</td>';
print '</tr>';

// Titres
print '<tr class="liste_titre">';
print_liste_field_titre('Ref', $_SERVER['PHP_SELF'], 't.ref', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('DateCreation', $_SERVER['PHP_SELF'], 't.date_creation', '', $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre('Type', $_SERVER['PHP_SELF'], 't.type', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('DiamantutilsConsumedProducts', $_SERVER['PHP_SELF'], '', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('DiamantutilsProducedProducts', $_SERVER['PHP_SELF'], '', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('Batch', $_SERVER['PHP_SELF'], '', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('Warehouse', $_SERVER['PHP_SELF'], 'e.ref', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('DiamantutilsCustomerOrder', $_SERVER['PHP_SELF'], 'c.ref', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('DiamantutilsDateConsume', $_SERVER['PHP_SELF'], 't.date_consume', '', $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre('Status', $_SERVER['PHP_SELF'], 't.status', '', $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre('', $_SERVER['PHP_SELF'], '', '', $param, '', $sortfield, $sortorder, 'maxwidthsearch ');
print '</tr>';

// Lignes
$records = array();
$i = 0;
while ($i < min($num, $limit)) {
	$records[] = $db->fetch_object($resql);
	$i++;
}
$db->free($resql);

// Produits et lots de chaque transformation affichée (une seule requête)
$details = array();
if (!empty($records)) {
	$ids = array();
	foreach ($records as $obj) {
		$ids[] = (int) $obj->rowid;
	}
	$sql = "SELECT d.fk_transfo, d.direction, d.batch, p.ref";
	$sql .= " FROM ".MAIN_DB_PREFIX."diamantutils_transfo_det as d";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = d.fk_product";
	$sql .= " WHERE d.fk_transfo IN (".$db->sanitize(implode(',', $ids)).")";
	$sql .= " AND d.direction IN ('IN', 'OUT')";
	$sql .= " ORDER BY d.rang, d.rowid";
	$resdet = $db->query($sql);
	if ($resdet) {
		while ($det = $db->fetch_object($resdet)) {
			$details[$det->fk_transfo]['products'][$det->direction][$det->ref] = $det->ref;
			if ((string) $det->batch !== '') {
				$details[$det->fk_transfo]['batches'][$det->direction][$det->batch] = $det->batch;
			}
		}
		$db->free($resdet);
	}
}

$warehousestatic = new Entrepot($db);
foreach ($records as $obj) {
	$object->id = $obj->rowid;
	$object->ref = $obj->ref;
	$object->status = $obj->status;

	print '<tr class="oddeven">';
	print '<td class="nowraponall">'.$object->getNomUrl(1).'</td>';
	print '<td class="center">'.dol_print_date($db->jdate($obj->date_creation), 'dayhour').'</td>';
	print '<td>'.dol_escape_htmltag($types[$obj->type] ?? $obj->type).'</td>';

	// Produits consommés, produits produits, puis lots : consommés → produits
	foreach (array('IN', 'OUT') as $direction) {
		$text = (isset($details[$obj->rowid]['products'][$direction]) ? implode(', ', $details[$obj->rowid]['products'][$direction]) : '');
		print '<td class="tdoverflowmax200" title="'.dol_escape_htmltag($text).'">'.dol_escape_htmltag($text).'</td>';
	}
	$in = (isset($details[$obj->rowid]['batches']['IN']) ? implode(', ', $details[$obj->rowid]['batches']['IN']) : '');
	$out = (isset($details[$obj->rowid]['batches']['OUT']) ? implode(', ', $details[$obj->rowid]['batches']['OUT']) : '');
	$text = $in.($out !== '' ? ' → '.$out : '');
	print '<td class="tdoverflowmax200" title="'.dol_escape_htmltag($text).'">'.dol_escape_htmltag($text).'</td>';

	print '<td class="tdoverflowmax150">';
	if ($obj->fk_warehouse > 0) {
		$warehousestatic->id = $obj->fk_warehouse;
		$warehousestatic->ref = $obj->warehouse_ref;
		$warehousestatic->label = $obj->warehouse_ref;
		print $warehousestatic->getNomUrl(1);
	}
	print '</td>';
	print '<td class="nowraponall">';
	if ($obj->fk_commande > 0) {
		print '<a href="'.DOL_URL_ROOT.'/commande/card.php?id='.((int) $obj->fk_commande).'">'.img_picto('', 'order', 'class="pictofixedwidth"').dol_escape_htmltag($obj->order_ref).'</a>';
	}
	print '</td>';
	print '<td class="center">'.dol_print_date($db->jdate($obj->date_consume), 'dayhour').'</td>';
	print '<td class="center">'.$object->getLibStatut(5).'</td>';
	print '<td></td>';
	print '</tr>';
}

if (empty($records)) {
	print '<tr><td colspan="11"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
}

print '</table>';
print '</div>';
print '</form>';

llxFooter();
$db->close();
