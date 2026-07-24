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

if (!$user->admin) {
	accessforbidden();
}

$langs->load("diamantutils@diamantutils");

$action = GETPOST('action', 'aZ09');

if ($action == 'update') {
	$mode = GETPOST('DIAMANTUTILS_INVOICE_CHECK_MODE', 'alpha');
	$allowed = array('DESACTIVE', 'MASQUER', 'AFFICHER', 'AFFICHER_BLOQUER');
	if (in_array($mode, $allowed)) {
		dolibarr_set_const($db, 'DIAMANTUTILS_INVOICE_CHECK_MODE', $mode, 'chaine', 0, '', $conf->entity);
		setEventMessages($langs->trans('DiamantutilsConfigUpdated'), null);
	}
}

llxHeader('', $langs->trans('DiamantutilsSetupTitle'));

print load_fiche_titre($langs->trans('DiamantutilsSetupTitle'), '', 'title_setup');

print '<form method="POST" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('DiamantutilsFeature').'</td><td>'.$langs->trans('DiamantutilsSetting').'</td></tr>';

print '<tr class="oddeven"><td>';
print $langs->trans('DiamantutilsInvoiceCheckLabel').'<br>';
print '<span class="opacitymedium">'.$langs->trans('DiamantutilsInvoiceCheckDesc').'</span>';
print '</td><td>';

$mode = getDolGlobalString('DIAMANTUTILS_INVOICE_CHECK_MODE', 'AFFICHER');

$options = array(
	'DESACTIVE' => $langs->trans('DiamantutilsModeDisabled'),
	'MASQUER' => $langs->trans('DiamantutilsModeHide'),
	'AFFICHER' => $langs->trans('DiamantutilsModeShow'),
	'AFFICHER_BLOQUER' => $langs->trans('DiamantutilsModeShowBlock'),
);

print '<select name="DIAMANTUTILS_INVOICE_CHECK_MODE" class="flat">';
foreach ($options as $key => $label) {
	$sel = ($key == $mode) ? ' selected' : '';
	print '<option value="'.dol_escape_htmltag($key).'"'.$sel.'>'.dol_escape_htmltag($label).'</option>';
}
print '</select>';

print '</td></tr>';
print '</table>';

print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div>';

print '</form>';

print '<br>';

// Diagnostic hooks
print load_fiche_titre("Diagnostic", '', '');
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Composant</td><td>Statut</td></tr>';

// Check hook constant in database
$sql = "SELECT name, value, entity FROM ".MAIN_DB_PREFIX."const WHERE name LIKE '%DIAMANTUTILS%HOOKS%' ORDER BY name, entity";
$resql = $db->query($sql);
$hookRows = array();
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$hookRows[] = $obj;
	}
	$db->free($resql);
}
if (!empty($hookRows)) {
	foreach ($hookRows as $row) {
		print '<tr class="oddeven"><td>Constante '.dol_escape_htmltag($row->name).' (entity='.((int) $row->entity).')</td>';
		print '<td><span class="badge badge-status4">OK</span> = '.dol_escape_htmltag($row->value).'</td></tr>';
	}
} else {
	print '<tr class="oddeven"><td>Hook invoicecard</td>';
	print '<td><span class="badge badge-status8">NON ENREGISTRE</span> — Desactivez puis reactivez le module</td></tr>';
}

// Check actions class file
$classFile = dol_buildpath('/diamantutils/class/actions_diamantutils.class.php');
print '<tr class="oddeven"><td>Fichier actions_diamantutils.class.php</td>';
if (file_exists($classFile)) {
	print '<td><span class="badge badge-status4">OK</span> = '.dol_escape_htmltag($classFile).'</td>';
} else {
	print '<td><span class="badge badge-status8">INTROUVABLE</span> = '.dol_escape_htmltag($classFile).'</td>';
}
print '</tr>';

// Check modules_parts in $conf (module name is a KEY, not a value)
print '<tr class="oddeven"><td>$conf->modules_parts[hooks]</td>';
if (!empty($conf->modules_parts['hooks'])) {
	if (isset($conf->modules_parts['hooks']['diamantutils'])) {
		$val = $conf->modules_parts['hooks']['diamantutils'];
		$display = is_array($val) ? implode(', ', $val) : $val;
		print '<td><span class="badge badge-status4">OK</span> diamantutils = '.dol_escape_htmltag($display).'</td>';
	} else {
		print '<td><span class="badge badge-status1">ABSENT</span> diamantutils absent des cles modules_parts[hooks]<br>';
		print '<pre>'.dol_escape_htmltag(print_r($conf->modules_parts['hooks'], true)).'</pre></td>';
	}
} else {
	print '<td><span class="badge badge-status8">VIDE</span> Aucun hook enregistre</td>';
}
print '</tr>';

// Check isModEnabled
print '<tr class="oddeven"><td>isModEnabled(\'diamantutils\')</td>';
if (isModEnabled('diamantutils')) {
	print '<td><span class="badge badge-status4">OK</span> Module actif</td>';
} else {
	print '<td><span class="badge badge-status8">NON</span> Module non actif selon isModEnabled()</td>';
}
print '</tr>';

print '</table>';

llxFooter();
