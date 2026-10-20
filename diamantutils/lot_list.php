<?php
/**
 * Lots de produits : liste paginée (libellé, nombre de lignes, actif, date de création)
 */

// Pages du module : garder le menu Commerce sélectionné quel que soit le lien d'arrivée
if (!isset($_GET['mainmenu']) && !isset($_POST['mainmenu'])) {
	$_GET['mainmenu'] = 'commercial';
	$_GET['leftmenu'] = 'diamantutils_lot';
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

dol_include_once('/diamantutils/lib/lot.lib.php');
dol_include_once('/diamantutils/class/diamantlot.class.php');

$langs->loadLangs(array('products', 'diamantutils@diamantutils'));

if (!isModEnabled('diamantutils') || !$user->hasRight('diamantutils', 'lot', 'read')) {
	accessforbidden();
}
if (!diamantutils_lot_enabled()) {
	accessforbidden($langs->trans('DiamantutilsFeatureDisabled'));
}

// Filtres
$search_label = GETPOST('search_label', 'alpha');
$search_active = GETPOST('search_active', 'intcomma');

// Tri et pagination
$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT('page');
if (empty($page) || $page < 0 || GETPOST('button_search', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$page = 0;
}
$offset = $limit * $page;
$allowedsort = array('l.label', 'nb_lines', 'l.active', 'l.datec');
if (!in_array($sortfield, $allowedsort)) {
	$sortfield = 'l.label';
}
if (!$sortorder) {
	$sortorder = 'ASC';
}

// Suppression des filtres
if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$search_label = '';
	$search_active = '';
}

$form = new Form($db);
$lotstatic = new DiamantLot($db);


/*
 * Requête
 */

$sqlwhere = " WHERE l.entity IN (".getEntity('diamantutils_lot').")";
if ($search_label !== '') {
	$sqlwhere .= natural_search('l.label', $search_label);
}
if ($search_active !== '' && $search_active != '-1') {
	$sqlwhere .= " AND l.active = ".((int) $search_active);
}

// Nombre total
$nbtotalofrecords = '';
if (!getDolGlobalInt('MAIN_DISABLE_FULL_SCANLIST')) {
	$resql = $db->query("SELECT COUNT(l.rowid) as nb FROM ".MAIN_DB_PREFIX."diamantutils_lot as l".$sqlwhere);
	if ($resql) {
		$objforcount = $db->fetch_object($resql);
		$nbtotalofrecords = (int) $objforcount->nb;
		$db->free($resql);
	}
	if (($page * $limit) > $nbtotalofrecords) {
		$page = 0;
		$offset = 0;
	}
}

$sql = "SELECT l.rowid, l.label, l.active, l.datec,";
$sql .= " (SELECT COUNT(d.rowid) FROM ".MAIN_DB_PREFIX."diamantutils_lot_det as d WHERE d.fk_lot = l.rowid) as nb_lines";
$sql .= " FROM ".MAIN_DB_PREFIX."diamantutils_lot as l";
$sql .= $sqlwhere;
$sql .= $db->order($sortfield.($sortfield != 'l.label' ? ',l.label' : ''), $sortorder.($sortfield != 'l.label' ? ',ASC' : ''));
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

$title = $langs->trans('DiamantutilsLots');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-diamantutils page-lot-list');

$param = '';
if ($limit > 0 && $limit != $conf->liste_limit) {
	$param .= '&limit='.((int) $limit);
}
if ($search_label !== '') {
	$param .= '&search_label='.urlencode($search_label);
}
if ($search_active !== '' && $search_active != '-1') {
	$param .= '&search_active='.urlencode($search_active);
}

$newcardbutton = dolGetButtonTitle($langs->trans('DiamantutilsNewLot'), '', 'fa fa-plus-circle', dol_buildpath('/diamantutils/lot_card.php', 1).'?action=create', '', $user->hasRight('diamantutils', 'lot', 'write'));

print '<form method="POST" id="searchFormList" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print '<input type="hidden" name="sortfield" value="'.dol_escape_htmltag($sortfield).'">';
print '<input type="hidden" name="sortorder" value="'.dol_escape_htmltag($sortorder).'">';
print '<input type="hidden" name="page" value="'.((int) $page).'">';

print_barre_liste($title, $page, $_SERVER['PHP_SELF'], $param, $sortfield, $sortorder, '', $num, $nbtotalofrecords, 'list', 0, $newcardbutton, '', $limit, 0, 0, 1);

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste">';

// Ligne des filtres
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre"><input type="text" class="flat maxwidth200" name="search_label" value="'.dol_escape_htmltag($search_label).'"></td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre center">'.$form->selectarray('search_active', array('1' => $langs->trans('Enabled'), '0' => $langs->trans('Disabled')), $search_active, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth100').'</td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre center maxwidthsearch">'.$form->showFilterButtons().'</td>';
print '</tr>';

// Titres
print '<tr class="liste_titre">';
print_liste_field_titre('Label', $_SERVER['PHP_SELF'], 'l.label', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('DiamantutilsLotNbLines', $_SERVER['PHP_SELF'], 'nb_lines', '', $param, '', $sortfield, $sortorder, 'right ');
print_liste_field_titre('Status', $_SERVER['PHP_SELF'], 'l.active', '', $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre('DateCreation', $_SERVER['PHP_SELF'], 'l.datec', '', $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre('', $_SERVER['PHP_SELF'], '', '', $param, '', $sortfield, $sortorder, 'maxwidthsearch ');
print '</tr>';

$i = 0;
while ($i < min($num, $limit)) {
	$obj = $db->fetch_object($resql);
	$i++;

	$lotstatic->id = (int) $obj->rowid;
	$lotstatic->label = $obj->label;

	print '<tr class="oddeven">';
	print '<td class="tdoverflowmax300">'.$lotstatic->getNomUrl(1).'</td>';
	print '<td class="right">'.((int) $obj->nb_lines).'</td>';
	print '<td class="center">'.($obj->active ? dolGetStatus($langs->trans('Enabled'), '', '', 'status4', 3) : dolGetStatus($langs->trans('Disabled'), '', '', 'status5', 3)).'</td>';
	print '<td class="center">'.dol_print_date($db->jdate($obj->datec), 'day').'</td>';
	print '<td></td>';
	print '</tr>';
}
$db->free($resql);

if ($num == 0) {
	print '<tr><td colspan="5"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
}

print '</table>';
print '</div>';
print '</form>';

llxFooter();
$db->close();
