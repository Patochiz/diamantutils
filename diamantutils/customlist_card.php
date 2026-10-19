<?php
/**
 * Listes personnalisées : création et modification d'une liste (libellé, requête SQL),
 * avec test de la requête avant enregistrement.
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

// Écrire du SQL donne accès en lecture à toute la base : réservé aux administrateurs autorisés
if (!isModEnabled('diamantutils') || !$user->admin || !$user->hasRight('diamantutils', 'customlist', 'write')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');
$cancel = GETPOST('cancel', 'alpha');

$object = new DiamantutilsCustomList($db);
if ($id > 0 && $object->fetch($id) <= 0) {
	accessforbidden($langs->trans('ErrorRecordNotFound'));
}
if (!$id && $action != 'add' && $action != 'test') {
	$action = 'create';
}

$backurl = dol_buildpath('/diamantutils/customlist_index.php', 1);
if ($cancel) {
	header('Location: '.$backurl);
	exit;
}


/*
 * Actions
 */

$preview = null;
$previewerror = '';
if (in_array($action, array('add', 'update', 'test'))) {
	$object->label = GETPOST('label', 'alphanohtml');
	$object->description = GETPOST('description', 'restricthtml');
	// SQL brut : ni filtrage HTML ni échappement, il est contrôlé par validateSql() puis échappé à l'enregistrement
	$object->sqlquery = GETPOST('sqlquery', 'none');
	$object->position = GETPOSTINT('position');
	$object->active = GETPOSTINT('active') ? 1 : 0;

	if (GETPOST('test', 'alpha')) {
		$action = 'test';
	}

	if ($action == 'test') {
		$preview = $object->execute('', 0, 'ASC', 10, 0);
		if (!is_array($preview)) {
			$previewerror = $object->error;
			$preview = null;
		}
		$action = ($object->id > 0 ? 'edit' : 'create');
	} else {
		$result = ($action == 'add' ? $object->create($user) : $object->update($user));
		if ($result > 0) {
			setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
			header('Location: '.$backurl);
			exit;
		}
		setEventMessages($object->error, $object->errors, 'errors');
		$action = ($action == 'add' ? 'create' : 'edit');
	}
}


/*
 * Vue
 */

$title = ($object->id > 0 ? $langs->trans('DiamantutilsCLEdit') : $langs->trans('DiamantutilsNewCustomList'));
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-diamantutils page-customlist-card');

print load_fiche_titre($title, '', 'list');

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="'.($object->id > 0 ? 'update' : 'add').'">';
if ($object->id > 0) {
	print '<input type="hidden" name="id" value="'.((int) $object->id).'">';
}

print dol_get_fiche_head(array(), '');
print '<table class="border centpercent tableforfieldcreate">';

print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans('Label').'</td>';
print '<td><input type="text" class="flat minwidth300" name="label" maxlength="255" value="'.dol_escape_htmltag((string) $object->label).'" autofocus></td></tr>';

print '<tr><td>'.$langs->trans('Description').'</td>';
print '<td><textarea class="flat centpercent" name="description" rows="2">'.dol_escape_htmltag((string) $object->description).'</textarea></td></tr>';

print '<tr><td class="fieldrequired tdtop">'.$langs->trans('DiamantutilsCLSql').'</td>';
print '<td><textarea class="flat centpercent" name="sqlquery" rows="14" spellcheck="false" style="font-family: monospace;">'.dol_escape_htmltag((string) $object->sqlquery).'</textarea>';
print '<div class="opacitymedium small">'.$langs->trans('DiamantutilsCLSqlHelp', MAIN_DB_PREFIX).'</div></td></tr>';

print '<tr><td>'.$langs->trans('Position').'</td>';
print '<td><input type="number" class="flat width75" name="position" value="'.((int) $object->position).'"></td></tr>';

print '<tr><td>'.$langs->trans('Enabled').'</td>';
print '<td><input type="checkbox" name="active" value="1"'.($object->active ? ' checked' : '').'></td></tr>';

print '</table>';
print dol_get_fiche_end();

print '<div class="center">';
print '<input type="submit" class="button button-save" value="'.$langs->trans('Save').'">';
print ' &nbsp; <input type="submit" class="button" name="test" value="'.$langs->trans('DiamantutilsCLTest').'">';
print ' &nbsp; <input type="submit" class="button button-cancel" name="cancel" value="'.$langs->trans('Cancel').'">';
print '</div>';
print '</form>';

// Aperçu de la requête (10 premières lignes)
if ($previewerror !== '') {
	print '<br><div class="error">'.$langs->trans('DiamantutilsCLTestError').' : '.dol_escape_htmltag($previewerror).'</div>';
} elseif (is_array($preview)) {
	print '<br>'.load_fiche_titre($langs->trans('DiamantutilsCLPreview', $preview['total']), '', '');
	print '<div class="div-table-responsive">';
	print '<table class="tagtable liste">';
	print '<tr class="liste_titre">';
	foreach ($preview['columns'] as $column) {
		print '<th>'.dol_escape_htmltag($column).'</th>';
	}
	print '</tr>';
	foreach ($preview['rows'] as $row) {
		print '<tr class="oddeven">';
		foreach ($row as $value) {
			print '<td class="tdoverflowmax300">'.dol_escape_htmltag((string) $value).'</td>';
		}
		print '</tr>';
	}
	if (empty($preview['rows'])) {
		print '<tr><td colspan="'.max(1, count($preview['columns'])).'"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
	}
	print '</table>';
	print '</div>';
}

llxFooter();
$db->close();
