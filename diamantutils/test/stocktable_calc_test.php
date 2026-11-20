<?php
/**
 * Tests des calculs du tableau de stock par lots (colisage, quantités, états, comparaison)
 *
 * Script autonome, sans PHPUnit ni Composer, à ouvrir dans le navigateur par un administrateur :
 *   /custom/diamantutils/test/stocktable_calc_test.php
 * Aucune lecture ni écriture en base : seules les fonctions pures de lib/stocktable.lib.php sont appelées.
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

dol_include_once('/diamantutils/lib/stocktable.lib.php');
dol_include_once('/diamantutils/test/stocktable_calc_cases.php');

llxHeader('', 'DiamantUtils - Tests tableau de stock');
print load_fiche_titre('DiamantUtils - Tests des calculs du tableau de stock par lots', '', 'generic');

$nbok = 0;
$nbko = 0;
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Groupe</td><td>Test</td><td>Attendu</td><td>Obtenu</td><td class="center">Résultat</td></tr>';
foreach (diamantutils_stocktable_test_run() as $r) {
	$r['ok'] ? $nbok++ : $nbko++;
	print '<tr class="oddeven"><td>'.dol_escape_htmltag($r['group']).'</td><td>'.dol_escape_htmltag($r['name']).'</td>';
	print '<td><code>'.dol_escape_htmltag($r['expected']).'</code></td><td><code>'.dol_escape_htmltag($r['got']).'</code></td>';
	print '<td class="center">'.($r['ok'] ? img_picto('', 'tick').' OK' : img_warning().' <span class="error">ÉCHEC</span>').'</td></tr>';
}
print '</table><br>';

if ($nbko) {
	print '<div class="error">'.$nbko.' test(s) en échec, '.$nbok.' réussi(s).</div>';
} else {
	print '<div class="ok">'.$nbok.' tests réussis.</div>';
}

llxFooter();
$db->close();
