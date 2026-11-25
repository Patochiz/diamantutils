<?php
/**
 * Tests du calcul du colisage (diamantutils_colisage_compute)
 *
 * Script autonome, sans PHPUnit ni Composer, à ouvrir dans le navigateur par un administrateur :
 *   /custom/diamantutils/test/stockcolisage_compute_test.php
 * Aucune lecture ni écriture en base : seule la fonction de calcul est appelée.
 */

$res = 0;
$res = @include "../../main.inc.php";
if (!$res) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

if (!$user->admin) {
	accessforbidden();
}

dol_include_once('/diamantutils/lib/stockcolisage.lib.php');
$langs->loadLangs(array('diamantutils@diamantutils'));

// array(nom, quantité, qté par pièce, pièces par colis, mode attendu, texte attendu, avertissement attendu)
$cases = array(
	array('Colis complets', '60.48', '0.36', '28', 'auto', '6 colis de 28px', false),
	array('Moins qu\'un colis', '2.16', '0.36', '28', 'auto', '1 colis de 6px', false),
	array('Un colis complet', '10.08', '0.36', '28', 'auto', '1 colis de 28px', false),
	array('Colis + reste', '10.80', '0.36', '28', 'auto', '1 colis de 28px + 1 colis de 2px', false),
	array('Non multiple de la pièce', '2.17', '0.36', '28', 'auto', '', true),
	array('Quantité nulle', '0', '0.36', '28', 'auto', '', false),
	array('Quantité négative', '-0.72', '0.36', '28', 'auto', '', true),
	array('Pièces par colis vide', '60.48', '0.36', '', 'manual', '', false),
	array('Qté par pièce vide', '60.48', null, '28', 'manual', '', false),
	array('Qté par pièce nulle', '60.48', '0', '28', 'manual', '', false),
	array('Pièces par colis non numérique', '60.48', '0.36', 'abc', 'manual', '', false),
	array('Virgule décimale', '60,48', '0,36', '28', 'auto', '6 colis de 28px', false),
	array('Flottant 60,48 / 0,36 = 168 pièces', 60.48, 0.36, 28, 'auto', '6 colis de 28px', false),
	array('Tolérance 0,02 pièce (167,99)', '60.476', '0.36', '28', 'auto', '6 colis de 28px', false),
	array('Hors tolérance (167,9)', '60.44', '0.36', '28', 'auto', '', true),
	array('Unité pièce', '57', '1', '28', 'auto', '2 colis de 28px + 1 colis de 1px', false),
);

llxHeader('', 'DiamantUtils - Tests du colisage calculé');
print load_fiche_titre('DiamantUtils - Tests de diamantutils_colisage_compute()', '', 'generic');

$nbok = 0;
$nbko = 0;
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Test</td><td class="right">Quantité</td><td class="right">Qté / pièce</td><td class="right">Pièces / colis</td><td>Attendu</td><td>Obtenu</td><td class="center">Résultat</td></tr>';
foreach ($cases as $case) {
	list($name, $qty, $qpp, $ppc, $mode, $text, $warn) = $case;
	$r = diamantutils_colisage_compute($qty, $qpp, $ppc);
	$ok = ($r['mode'] === $mode && $r['text'] === $text && (($r['warn'] !== '') === $warn));
	$ok ? $nbok++ : $nbko++;
	print '<tr class="oddeven"><td>'.dol_escape_htmltag($name).'</td>';
	print '<td class="right">'.dol_escape_htmltag(var_export($qty, true)).'</td>';
	print '<td class="right">'.dol_escape_htmltag(var_export($qpp, true)).'</td>';
	print '<td class="right">'.dol_escape_htmltag(var_export($ppc, true)).'</td>';
	print '<td>'.dol_escape_htmltag($mode).' · <code>'.dol_escape_htmltag($text).'</code>'.($warn ? ' · avertissement' : '').'</td>';
	print '<td>'.dol_escape_htmltag($r['mode']).' · <code>'.dol_escape_htmltag($r['text']).'</code>'.($r['warn'] !== '' ? ' · <span class="warning">'.dol_escape_htmltag($r['warn']).'</span>' : '').'</td>';
	print '<td class="center">'.($ok ? img_picto('', 'tick').' OK' : img_warning().' <span class="error">ÉCHEC</span>').'</td></tr>';
}
print '</table>';
print '<br><div class="'.($nbko ? 'error' : 'ok').'"><strong>'.$nbok.' OK, '.$nbko.' échec(s)</strong></div>';

llxFooter();
$db->close();
