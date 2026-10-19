<?php
/**
 * Listes personnalisées : affichage du résultat d'une liste
 * (recherche, tri par colonne, pagination, export CSV).
 */

// Pages du module : garder le menu Outils sélectionné quel que soit le lien d'arrivée
if (!isset($_GET['mainmenu']) && !isset($_POST['mainmenu'])) {
	$_GET['mainmenu'] = 'tools';
	$_GET['leftmenu'] = 'diamantutils_cl';
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

dol_include_once('/diamantutils/class/customlist.class.php');

$langs->loadLangs(array('admin', 'diamantutils@diamantutils'));

if (!isModEnabled('diamantutils') || !$user->hasRight('diamantutils', 'customlist', 'read')) {
	accessforbidden();
}
$canwrite = ($user->admin && $user->hasRight('diamantutils', 'customlist', 'write'));

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');

$object = new DiamantutilsCustomList($db);
if ($object->fetch($id) <= 0 || (!$object->active && !$canwrite)) {
	accessforbidden($langs->trans('ErrorRecordNotFound'));
}

// Recherche, tri (numéro de colonne) et pagination
$search_all = trim(GETPOST('search_all', 'alphanohtml'));
$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$sortfield = GETPOSTINT('sortfield');
$sortorder = (strtoupper(GETPOST('sortorder', 'aZ09')) === 'DESC' ? 'DESC' : 'ASC');
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT('page');
if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$search_all = '';
}
if (empty($page) || $page < 0 || GETPOST('button_search', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$page = 0;
}
if ($sortfield < 0) {
	$sortfield = 0;
}


/*
 * Export CSV (avant tout affichage)
 */

if ($action == 'exportcsv') {
	$result = $object->execute($search_all, $sortfield, $sortorder, 0, 0);
	if (!is_array($result)) {
		setEventMessages($object->error, null, 'errors');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.((int) $object->id));
		exit;
	}

	$filename = dol_sanitizeFileName(dol_string_unaccent($object->label)).'_'.dol_print_date(dol_now(), '%Y%m%d_%H%M').'.csv';
	header('Content-Type: text/csv; charset=UTF-8');
	header('Content-Disposition: attachment; filename="'.$filename.'"');
	header('Cache-Control: private, max-age=0');

	$out = fopen('php://output', 'w');
	fwrite($out, "\xEF\xBB\xBF"); // BOM : Excel lit l'UTF-8
	fputcsv($out, $result['columns'], ';', '"', '');
	foreach ($result['rows'] as $row) {
		$line = array();
		foreach ($row as $value) {
			$value = (string) $value;
			if (preg_match('/^-?\d+\.\d+$/', $value)) {
				$value = str_replace('.', ',', $value); // décimales à la française pour Excel
			} elseif ($value !== '' && ($value[0] === '=' || $value[0] === '@')) {
				$value = "'".$value; // pas de formule exécutée à l'ouverture
			}
			$line[] = $value;
		}
		fputcsv($out, $line, ';', '"', '');
	}
	fclose($out);
	$db->close();
	exit;
}


/*
 * Requête
 */

$offset = $limit * $page;
$result = $object->execute($search_all, $sortfield, $sortorder, $limit, $offset);
if (is_array($result) && $offset > 0 && $offset >= $result['total']) {
	// Page au-delà du résultat (après une recherche) : retour à la première page
	$page = 0;
	$offset = 0;
	$result = $object->execute($search_all, $sortfield, $sortorder, $limit, 0);
}
if (!is_array($result) && $sortfield > 0) {
	// Tri impossible (colonnes de même nom, colonne inexistante) : ordre de la requête
	setEventMessages($langs->trans('DiamantutilsCLSortFailed'), null, 'warnings');
	$sortfield = 0;
	$result = $object->execute($search_all, 0, $sortorder, $limit, $offset);
}


/*
 * Vue
 */

$title = $object->label;
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-diamantutils page-customlist-view');

if (!is_array($result)) {
	print load_fiche_titre($title, '', 'list');
	print '<div class="error">'.$langs->trans('DiamantutilsCLTestError').' : '.dol_escape_htmltag($object->error).'</div>';
	if ($canwrite) {
		print '<div class="tabsAction"><a class="butAction" href="'.dol_buildpath('/diamantutils/customlist_card.php', 1).'?id='.((int) $object->id).'&action=edit">'.$langs->trans('Modify').'</a></div>';
	}
	llxFooter();
	$db->close();
	exit;
}

$columns = $result['columns'];
$rows = $result['rows'];
$num = count($rows);
$nbtotalofrecords = $result['total'];

$param = '&id='.((int) $object->id);
if ($limit > 0 && $limit != $conf->liste_limit) {
	$param .= '&limit='.((int) $limit);
}
if ($search_all !== '') {
	$param .= '&search_all='.urlencode($search_all);
}

// Colonnes numériques (sur la page affichée) : alignées à droite
$numeric = array();
foreach (array_keys($columns) as $i) {
	$isnum = ($num > 0);
	foreach ($rows as $row) {
		$value = $row[$i] ?? null;
		if ($value !== null && $value !== '' && !is_numeric($value)) {
			$isnum = false;
			break;
		}
	}
	$numeric[$i] = $isnum;
}

$buttons = dolGetButtonTitle($langs->trans('DiamantutilsCLExportCsv'), '', 'fa fa-file-csv', $_SERVER['PHP_SELF'].'?action=exportcsv&token='.newToken().$param.($sortfield ? '&sortfield='.$sortfield.'&sortorder='.$sortorder : ''), '', 1);
if ($canwrite) {
	$buttons .= dolGetButtonTitle($langs->trans('Modify'), '', 'fa fa-pen', dol_buildpath('/diamantutils/customlist_card.php', 1).'?id='.((int) $object->id).'&action=edit', '', 1);
}

print '<form method="POST" id="searchFormList" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="id" value="'.((int) $object->id).'">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print '<input type="hidden" name="sortfield" value="'.((int) $sortfield).'">';
print '<input type="hidden" name="sortorder" value="'.dol_escape_htmltag($sortorder).'">';
print '<input type="hidden" name="page" value="'.((int) $page).'">';

print_barre_liste($title, $page, $_SERVER['PHP_SELF'], $param, (string) $sortfield, $sortorder, '', $num, $nbtotalofrecords, 'list', 0, $buttons, '', $limit, 0, 0, 1);

if ((string) $object->description !== '') {
	print '<div class="opacitymedium marginbottomonly">'.dol_nl2br(dol_escape_htmltag($object->description)).'</div>';
}

// Recherche globale
print '<div class="divsearchfield">';
print img_picto('', 'search', 'class="pictofixedwidth"');
print '<input type="text" class="flat minwidth200" name="search_all" value="'.dol_escape_htmltag($search_all).'" placeholder="'.dol_escape_htmltag($langs->trans('Search')).'">';
print ' <input type="submit" class="button small" name="button_search" value="'.dol_escape_htmltag($langs->trans('Search')).'">';
if ($search_all !== '') {
	print ' <input type="submit" class="button small button-cancel" name="button_removefilter" value="'.dol_escape_htmltag($langs->trans('RemoveFilter')).'">';
}
print '</div>';

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste">';
print '<tr class="liste_titre">';
foreach ($columns as $i => $column) {
	// Tri par numéro de colonne (ORDER BY n) : fonctionne quel que soit le nom de la colonne
	print_liste_field_titre(dol_escape_htmltag($column), $_SERVER['PHP_SELF'], (string) ($i + 1), '', $param, '', (string) $sortfield, $sortorder, ($numeric[$i] ? 'right ' : ''));
}
print '</tr>';

foreach ($rows as $row) {
	print '<tr class="oddeven">';
	foreach (array_keys($columns) as $i) {
		$value = (string) ($row[$i] ?? '');
		print '<td class="'.($numeric[$i] ? 'right nowraponall' : 'tdoverflowmax300').'" title="'.dol_escape_htmltag($value).'">'.dol_escape_htmltag($value).'</td>';
	}
	print '</tr>';
}

if ($num == 0) {
	print '<tr><td colspan="'.max(1, count($columns)).'"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
}

print '</table>';
print '</div>';
print '</form>';

llxFooter();
$db->close();
