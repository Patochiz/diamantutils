<?php
/**
 * Listes personnalisées : sommaire des listes (menu Outils) et gestion
 * (création, modification, suppression) pour les administrateurs autorisés.
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

dol_include_once('/diamantutils/lib/diamantutils.lib.php');
dol_include_once('/diamantutils/class/customlist.class.php');

$langs->loadLangs(array('admin', 'diamantutils@diamantutils'));

if (!isModEnabled('diamantutils') || !$user->hasRight('diamantutils', 'customlist', 'read')) {
	accessforbidden();
}
if (!diamantutils_customlist_enabled()) {
	accessforbidden($langs->trans('DiamantutilsFeatureDisabled'));
}
$canwrite = ($user->admin && $user->hasRight('diamantutils', 'customlist', 'write'));

$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$id = GETPOSTINT('id');


/*
 * Actions
 */

if ($action == 'confirm_delete' && $confirm == 'yes' && $canwrite) {
	$list = new DiamantutilsCustomList($db);
	if ($list->fetch($id) > 0 && $list->delete() > 0) {
		setEventMessages($langs->trans('DiamantutilsCLDeleted', $list->label), null, 'mesgs');
	} else {
		setEventMessages($list->error ? $list->error : $langs->trans('ErrorRecordNotFound'), null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}


/*
 * Vue
 */

$form = new Form($db);
$title = $langs->trans('DiamantutilsCustomLists');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-diamantutils page-customlist-index');

if ($action == 'delete' && $canwrite) {
	$list = new DiamantutilsCustomList($db);
	if ($list->fetch($id) > 0) {
		print $form->formconfirm($_SERVER['PHP_SELF'].'?id='.((int) $list->id), $langs->trans('DiamantutilsCLDelete'), $langs->trans('DiamantutilsCLConfirmDelete', $list->label), 'confirm_delete', '', 0, 1);
	}
}

$newbutton = dolGetButtonTitle($langs->trans('DiamantutilsNewCustomList'), '', 'fa fa-plus-circle', dol_buildpath('/diamantutils/customlist_card.php', 1).'?action=create', '', $canwrite);
print load_fiche_titre($title, $newbutton, 'list');

print '<div class="opacitymedium marginbottomonly">'.$langs->trans('DiamantutilsCLIndexDesc').'</div>';

$static = new DiamantutilsCustomList($db);
$lists = $static->fetchAll($canwrite ? 0 : 1);
if (!is_array($lists)) {
	setEventMessages($static->error, null, 'errors');
	$lists = array();
}

print '<div class="div-table-responsive">';
print '<table class="tagtable liste">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans('Label').'</th>';
print '<th>'.$langs->trans('Description').'</th>';
if ($canwrite) {
	print '<th class="center">'.$langs->trans('Position').'</th>';
	print '<th class="center">'.$langs->trans('Status').'</th>';
	print '<th class="center"></th>';
}
print '</tr>';

foreach ($lists as $list) {
	print '<tr class="oddeven">';
	print '<td class="nowraponall">'.$list->getNomUrl(1).'</td>';
	print '<td>'.dol_nl2br(dol_escape_htmltag((string) $list->description)).'</td>';
	if ($canwrite) {
		print '<td class="center">'.((int) $list->position).'</td>';
		print '<td class="center">'.($list->active ? dolGetStatus($langs->trans('Enabled'), '', '', 'status4', 3) : dolGetStatus($langs->trans('Disabled'), '', '', 'status5', 3)).'</td>';
		print '<td class="center nowraponall">';
		print '<a class="editfielda paddingright" href="'.dol_buildpath('/diamantutils/customlist_card.php', 1).'?id='.((int) $list->id).'&action=edit">'.img_edit().'</a>';
		print '<a class="paddingleft" href="'.$_SERVER['PHP_SELF'].'?id='.((int) $list->id).'&action=delete&token='.newToken().'">'.img_delete().'</a>';
		print '</td>';
	}
	print '</tr>';
}

if (empty($lists)) {
	print '<tr><td colspan="'.($canwrite ? 5 : 2).'"><span class="opacitymedium">'.$langs->trans('DiamantutilsCLNone').'</span></td></tr>';
}

print '</table>';
print '</div>';

llxFooter();
$db->close();
