<?php
/**
 * Tests unitaires du rendu de la description pour devis (diamantutils_render_desc)
 *
 * Script autonome, sans PHPUnit ni Composer, à ouvrir dans le navigateur par un administrateur :
 *   /custom/diamantutils/test/descpropal_render_test.php
 * Aucune écriture en base : seules les fonctions de rendu et de vérification sont appelées.
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

dol_include_once('/diamantutils/lib/diamantutils_descpropal.lib.php');
dol_include_once('/diamantutils/test/descpropal_render_cases.php');

/**
 * Affiche une valeur de test : texte échappé, retours ligne visibles
 *
 * @param	mixed	$value	Valeur
 * @return	string
 */
function diamantutils_test_show($value)
{
	return '<code>'.str_replace("\n", '<span class="opacitymedium">↵</span><br>', dol_escape_htmltag((string) $value, 1, 1, '', 1)).'</code>';
}

llxHeader('', 'DiamantUtils - Tests description pour devis');
print load_fiche_titre('DiamantUtils - Tests de diamantutils_render_desc()', '', 'generic');

$product = (object) array('ref' => 'PROF-TEST', 'label' => 'Profilé de test');
$nbok = 0;
$nbko = 0;

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Test</td><td>Modèle</td><td>Attendu</td><td>Obtenu</td><td>Avertissements</td><td class="center">Résultat</td></tr>';
foreach (diamantutils_test_cases() as $case) {
	list($name, $template, $attributes, $expected, $nbwarnings) = $case;
	$warnings = array();
	$result = diamantutils_render_desc($template, $attributes, $product, $warnings);
	$ok = ($result === $expected && count($warnings) == $nbwarnings);
	$ok ? $nbok++ : $nbko++;
	print '<tr class="oddeven"><td>'.dol_escape_htmltag($name).'</td>';
	print '<td>'.diamantutils_test_show($template).'</td>';
	print '<td>'.diamantutils_test_show($expected).'</td>';
	print '<td>'.diamantutils_test_show($result).'</td>';
	print '<td>'.count($warnings).' / '.((int) $nbwarnings).' attendu(s)'.(count($warnings) ? '<br><span class="opacitymedium small">'.dol_escape_htmltag(implode(' | ', $warnings)).'</span>' : '').'</td>';
	print '<td class="center">'.($ok ? img_picto('', 'tick').' OK' : img_warning().' <span class="error">ÉCHEC</span>').'</td></tr>';
}
foreach (diamantutils_test_check_cases() as $case) {
	list($name, $template, $known, $nberrors) = $case;
	$errors = diamantutils_descpropal_check($template, $known);
	$ok = (count($errors) == $nberrors);
	$ok ? $nbok++ : $nbko++;
	print '<tr class="oddeven"><td>Vérification : '.dol_escape_htmltag($name).'</td>';
	print '<td>'.diamantutils_test_show($template).'</td>';
	print '<td>'.((int) $nberrors).' erreur(s)</td>';
	print '<td>'.count($errors).' erreur(s)'.(count($errors) ? '<br><span class="opacitymedium small">'.dol_escape_htmltag(implode(' | ', $errors)).'</span>' : '').'</td><td></td>';
	print '<td class="center">'.($ok ? img_picto('', 'tick').' OK' : img_warning().' <span class="error">ÉCHEC</span>').'</td></tr>';
}
print '</table><br>';

if ($nbko) {
	print '<div class="error">'.$nbko.' test(s) en échec, '.$nbok.' réussi(s).</div>';
} else {
	print '<div class="ok">'.$nbok.' tests réussis.</div>';
}

llxFooter();
$db->close();
