<?php
/**
 * Test d'intégration des lots de produits (diamantutils_lot_insert)
 *
 * Script autonome, sans PHPUnit ni Composer, à ouvrir dans le navigateur par un administrateur :
 *   /custom/diamantutils/test/lot_insert_test.php
 *
 * Tout se passe dans une transaction annulée à la fin : un devis et une commande brouillon
 * sont créés pour le tiers choisi, le lot (non enregistré) y est ajouté par la même fonction
 * que le bouton « Ajouter le lot », puis tout est annulé. Les triggers des autres modules
 * s'exécutent (PROPAL_CREATE, LINEPROPAL_INSERT…) : à éviter si l'un d'eux agit hors de la
 * base (envoi de mail, appel externe).
 *
 * Contrôles :
 *  1. devis : une ligne par produit, quantité = qté du lot × multiplicateur ;
 *  2. devis : la description pour devis (desc_propal) est appliquée par le trigger
 *     LINEPROPAL_INSERT, comme lors d'un ajout manuel ;
 *  3. devis : prix unitaire = prix de vente du produit pour ce tiers (getSellPrice) ;
 *  4. commande : lignes ajoutées, description standard (desc_propal ne concerne que les devis) ;
 *  5. tout ou rien : un lot contenant un produit inexistant n'ajoute aucune ligne (contrôle fait
 *     hors transaction de test, sur un devis brouillon supprimé ensuite).
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

require_once DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php';
require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
dol_include_once('/diamantutils/lib/lot.lib.php');
dol_include_once('/diamantutils/lib/descpropal.lib.php');
dol_include_once('/diamantutils/class/diamantlot.class.php');

$langs->loadLangs(array('products', 'propal', 'orders', 'diamantutils@diamantutils'));

$action = GETPOST('action', 'aZ09');
$fk_product = GETPOSTINT('fk_product');
$fk_product2 = GETPOSTINT('fk_product2');
$socid = GETPOSTINT('socid');
$mult = (float) price2num(GETPOST('mult', 'alpha') !== '' ? GETPOST('mult', 'alpha') : '2');

$results = array();

/**
 * Enregistre le résultat d'un contrôle
 *
 * @param	array	$results	Résultats (modifié)
 * @param	string	$label		Contrôle
 * @param	bool	$ok			Réussi ?
 * @param	string	$detail		Détail affiché
 * @return	void
 */
function diamantutils_lot_test_result(&$results, $label, $ok, $detail = '')
{
	$results[] = array('label' => $label, 'ok' => $ok, 'detail' => $detail);
}

/**
 * Affiche une valeur de test : texte échappé, retours ligne visibles
 *
 * @param	mixed	$value	Valeur
 * @return	string
 */
function diamantutils_lot_test_show($value)
{
	return '<code>'.str_replace("\n", '<span class="opacitymedium">↵</span><br>', dol_escape_htmltag((string) $value, 1, 1, '', 1)).'</code>';
}

if ($action == 'run' && $fk_product > 0 && $socid > 0 && GETPOST('token', 'alpha') === currentToken()) {
	$product = new Product($db);
	$product->fetch($fk_product);
	$product->fetch_optionals();

	$lotlines = array();
	$line = new DiamantLotLine();
	$line->fk_product = $fk_product;
	$line->qty = 3;
	$lotlines[] = $line;
	if ($fk_product2 > 0) {
		$line = new DiamantLotLine();
		$line->fk_product = $fk_product2;
		$line->qty = 1.5;
		$lotlines[] = $line;
	}

	$db->begin();

	// --- Devis
	$propal = new Propal($db);
	$propal->socid = $socid;
	$propal->date = dol_now();
	$propal->duree_validite = 15;
	if ($propal->create($user) <= 0) {
		diamantutils_lot_test_result($results, 'Création du devis de test', false, $propal->error.' '.implode(' ', (array) $propal->errors));
	} else {
		$propal->fetch($propal->id);
		$propal->fetch_thirdparty();
		$error = '';
		$errors = array();
		$nb = diamantutils_lot_insert($db, $propal, $lotlines, $mult, $user, $error, $errors);
		diamantutils_lot_test_result($results, 'Devis : ajout du lot', $nb == count($lotlines), 'retour '.$nb.($error ? ' — '.$error.' '.implode(' ', $errors) : ''));

		$propal->fetch($propal->id);
		$first = (!empty($propal->lines) ? $propal->lines[0] : null);
		diamantutils_lot_test_result($results, 'Devis : nombre de lignes', count($propal->lines) == count($lotlines), count($propal->lines).' ligne(s)');

		if ($first) {
			diamantutils_lot_test_result($results, 'Devis : quantité = 3 × '.$mult, abs((float) $first->qty - 3 * $mult) < 0.00001, 'qté '.$first->qty);

			$price = $product->getSellPrice($mysoc, $propal->thirdparty);
			$expected = (float) price2num($price['price_base_type'] == 'TTC' ? $price['pu_ttc'] : $price['pu_ht'], 'MU');
			$got = (float) ($price['price_base_type'] == 'TTC' ? $first->subprice * (1 + (float) $first->tva_tx / 100) : $first->subprice);
			diamantutils_lot_test_result($results, 'Devis : prix unitaire ('.$price['price_base_type'].') = getSellPrice', abs($got - $expected) < 0.01, 'attendu '.price($expected).', obtenu '.price($got).' ; TVA '.$first->tva_tx.' %');

			// Description pour devis : même contrôle que lors d'un ajout manuel
			$standard = diamantutils_lot_product_desc($db, $product, $propal->thirdparty);
			$descerror = '';
			$descpropal = diamantutils_descpropal_resolve($db, $product, $descerror);
			$normline = diamantutils_descpropal_normalize($first->desc);
			if (!diamantutils_descpropal_enabled()) {
				diamantutils_lot_test_result($results, 'Devis : description pour devis', $normline === diamantutils_descpropal_normalize($standard), 'Fonction « Description pour devis » désactivée : description standard attendue. Activez-la pour tester la compatibilité.<br>Ligne : '.diamantutils_lot_test_show($first->desc));
			} elseif ((string) $descpropal === '' || diamantutils_descpropal_normalize($descpropal) === '') {
				diamantutils_lot_test_result($results, 'Devis : description pour devis', $normline === diamantutils_descpropal_normalize($standard), 'Le produit n\'a pas de description pour devis : choisissez-en un qui en a une.<br>Ligne : '.diamantutils_lot_test_show($first->desc));
			} else {
				$normdp = diamantutils_descpropal_normalize($descpropal);
				$ok = (strpos($normline, $normdp) === 0 && $normline !== diamantutils_descpropal_normalize($standard));
				diamantutils_lot_test_result($results, 'Devis : description pour devis appliquée (mode '.diamantutils_descpropal_mode().')', $ok, 'Attendu en début de ligne : '.diamantutils_lot_test_show($descpropal).'<br>Ligne : '.diamantutils_lot_test_show($first->desc));
			}
		}
	}

	// --- Commande
	if (isModEnabled('order')) {
		$order = new Commande($db);
		$order->socid = $socid;
		$order->date = dol_now();
		$order->date_commande = dol_now();
		if ($order->create($user) <= 0) {
			diamantutils_lot_test_result($results, 'Création de la commande de test', false, $order->error.' '.implode(' ', (array) $order->errors));
		} else {
			$order->fetch($order->id);
			$order->fetch_thirdparty();
			$nb = diamantutils_lot_insert($db, $order, $lotlines, $mult, $user, $error, $errors);
			$order->fetch($order->id);
			diamantutils_lot_test_result($results, 'Commande : ajout du lot', $nb == count($lotlines) && count($order->lines) == count($lotlines), 'retour '.$nb.', '.count($order->lines).' ligne(s)'.($error ? ' — '.$error : ''));
			if (!empty($order->lines)) {
				$standard = diamantutils_lot_product_desc($db, $product, $order->thirdparty);
				diamantutils_lot_test_result($results, 'Commande : description standard du produit', diamantutils_descpropal_normalize($order->lines[0]->desc) === diamantutils_descpropal_normalize($standard), 'Ligne : '.diamantutils_lot_test_show($order->lines[0]->desc));
			}
		}
	}

	$db->rollback();

	// --- Tout ou rien, hors transaction de test : dans une transaction englobante, le
	// rollback() de Dolibarr ne fait que décrémenter le compteur et n'annulerait rien.
	// Devis brouillon créé puis supprimé (son id reste consommé).
	$propal = new Propal($db);
	$propal->socid = $socid;
	$propal->date = dol_now();
	$propal->duree_validite = 15;
	if ($propal->create($user) > 0) {
		$propal->fetch($propal->id);
		$propal->fetch_thirdparty();
		$bad = new DiamantLotLine();
		$bad->fk_product = 999999999;
		$bad->qty = 1;
		$error = '';
		$errors = array();
		$nb = diamantutils_lot_insert($db, $propal, array($lotlines[0], $bad), 1, $user, $error, $errors);
		$propal->fetch($propal->id);
		diamantutils_lot_test_result($results, 'Devis : lot invalide (2e produit inexistant) annulé en entier', $nb < 0 && count($propal->lines) == 0, 'retour '.$nb.', '.count($propal->lines).' ligne(s) restante(s) ; message : '.dol_escape_htmltag($error));
		$propal->delete($user);
	}
}


/*
 * Vue
 */

llxHeader('', 'DiamantUtils - Tests lots de produits');
print load_fiche_titre('DiamantUtils - Test d\'ajout d\'un lot sur devis et commande', '', 'generic');

print '<div class="warning">Le test crée un devis et une commande brouillon dans une transaction annulée à la fin, puis un devis brouillon supprimé aussitôt (contrôle « tout ou rien ») : rien n\'est conservé en base. Les triggers des autres modules s\'exécutent.</div><br>';

$form = new Form($db);
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="run">';
print '<table class="border centpercent">';
print '<tr><td class="titlefield fieldrequired">Produit avec description pour devis</td><td>';
$form->select_produits($fk_product, 'fk_product', '', getDolGlobalInt('PRODUIT_LIMIT_SIZE'), 0, 1, 2, '', 0, array(), 0, '1', 0, 'minwidth300');
print '</td></tr>';
print '<tr><td>Second produit (facultatif)</td><td>';
$form->select_produits($fk_product2, 'fk_product2', '', getDolGlobalInt('PRODUIT_LIMIT_SIZE'), 0, 1, 2, '', 0, array(), 0, '1', 0, 'minwidth300');
print '</td></tr>';
print '<tr><td class="fieldrequired">Client</td><td>'.$form->select_company($socid, 'socid', '(s.client:IN:1,2,3)', 1, 0, 0, array(), 0, 'minwidth300').'</td></tr>';
print '<tr><td>Multiplicateur</td><td><input type="text" class="flat width50" name="mult" value="'.dol_escape_htmltag((string) $mult).'"></td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button" value="Lancer le test"></div>';
print '</form>';

if (!empty($results)) {
	$nbok = 0;
	print '<br><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>Contrôle</td><td class="center">Résultat</td><td>Détail</td></tr>';
	foreach ($results as $result) {
		$nbok += ($result['ok'] ? 1 : 0);
		print '<tr class="oddeven"><td>'.dol_escape_htmltag($result['label']).'</td>';
		print '<td class="center">'.($result['ok'] ? img_picto('', 'tick').' OK' : img_error().' <span class="error">ÉCHEC</span>').'</td>';
		print '<td class="small">'.$result['detail'].'</td></tr>';
	}
	print '</table>';
	print '<br><div class="'.($nbok == count($results) ? 'ok' : 'error').'">'.$nbok.' / '.count($results).' contrôle(s) réussi(s). Aucune donnée conservée.</div>';
}

llxFooter();
$db->close();
