<?php
// Page de configuration du module DiamantUtils

$res = @include "../../main.inc.php";
if (!$res) {
	$res = @include "../../../main.inc.php";
}

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

llxFooter();
