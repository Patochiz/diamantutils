<?php
// Page de configuration du module DiamantUtils

require '../../main.inc.php';

if (!$user->hasRight('diamantutils', 'lire') && !$user->admin) {
	accessforbidden();
}

$langs->load("diamantutils@diamantutils");

$action = GETPOST('action', 'aZ09');

if ($action == 'update') {
	$mode = GETPOST('DIAMANTUTILS_INVOICE_CHECK_MODE', 'alpha');
	$allowed = array('DESACTIVE', 'MASQUER', 'AFFICHER', 'AFFICHER_BLOQUER');
	if (in_array($mode, $allowed)) {
		dolibarr_set_const($db, 'DIAMANTUTILS_INVOICE_CHECK_MODE', $mode, 'chaine', 0, '', $conf->entity);
		setEventMessages("Configuration mise à jour", null);
	}
}

llxHeader('', "DiamantUtils - Configuration");

print load_fiche_titre("Configuration DiamantUtils", '', 'title_setup');

print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Fonctionnalité</td><td>Réglage</td></tr>';

print '<tr class="oddeven"><td>';
print 'Contrôle du déjà-facturé sur les lignes de commande<br>';
print '<span class="opacitymedium">Appliqué à chaque création de facture depuis une ou plusieurs commandes (unitaire ou groupée)</span>';
print '</td><td>';

$mode = !empty($conf->global->DIAMANTUTILS_INVOICE_CHECK_MODE) ? $conf->global->DIAMANTUTILS_INVOICE_CHECK_MODE : 'AFFICHER';

$options = array(
	'DESACTIVE' => 'Désactivé — aucun contrôle',
	'MASQUER' => 'Masquer — retire silencieusement les lignes déjà entièrement facturées',
	'AFFICHER' => 'Afficher — ajoute une note "déjà facturé" sur la ligne, sans bloquer',
	'AFFICHER_BLOQUER' => 'Afficher + Bloquer — ajoute la note et refuse la création si une ligne dépasse le reliquat',
);

print '<select name="DIAMANTUTILS_INVOICE_CHECK_MODE" class="flat">';
foreach ($options as $key => $label) {
	$sel = ($key == $mode) ? ' selected' : '';
	print '<option value="'.$key.'"'.$sel.'>'.$label.'</option>';
}
print '</select>';

print '</td></tr>';
print '</table>';

print '<div class="center"><input type="submit" class="button button-save" value="Enregistrer"></div>';

print '</form>';

llxFooter();
