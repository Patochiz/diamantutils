<?php
/**
 * Endpoint AJAX : enregistre le colisage d'un lot (onglet Stock de la fiche produit)
 *
 * POST fk_product, batch, colisage, token → {ok: true, value: "<texte affiché>"}
 *                                           ou {ok: false, error: "<message>"}
 * Le lot est retrouvé ici à partir de (produit, numéro de lot, entité) : aucun identifiant de
 * lot envoyé par le navigateur n'est utilisé. Jeton CSRF vérifié ici (NOCSRFCHECK défini).
 * Droit : produit / créer (comme productlot_card.php). Aucun mouvement de stock.
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}
if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', '1');
}

$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

dol_include_once('/diamantutils/lib/stockcolisage.lib.php');
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

top_httphead('application/json');

/**
 * Réponse JSON et fin du script
 *
 * @param	int		$code	Code HTTP
 * @param	array	$data	Données
 * @return	void
 */
function diamantutils_lotcolisage_json($code, $data)
{
	global $db;
	http_response_code($code);
	print json_encode($data);
	$db->close();
	exit;
}

$langs->loadLangs(array('errors', 'products', 'productbatch', 'diamantutils@diamantutils'));

// Fonctionnalité et droit avant tout
if (!diamantutils_stockcolisage_enabled() || !isModEnabled('productbatch')) {
	diamantutils_lotcolisage_json(403, array('ok' => false, 'error' => $langs->transnoentitiesnoconv('DiamantutilsFeatureDisabled')));
}
if (!$user->hasRight('produit', 'creer')) {
	diamantutils_lotcolisage_json(403, array('ok' => false, 'error' => $langs->transnoentitiesnoconv('NotEnoughPermissions')));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	diamantutils_lotcolisage_json(405, array('ok' => false, 'error' => $langs->transnoentitiesnoconv('DiamantutilsStockColisageErrMethod')));
}
// Jeton de la page (newToken()) : égal au jeton de session courant, ou au suivant si
// MAIN_SECURITY_CSRF_TOKEN_RENEWAL_ON_EACH_CALL renouvelle le jeton à chaque page
$token = GETPOST('token', 'alpha');
if ($token === '' || ($token !== currentToken() && $token !== newToken())) {
	diamantutils_lotcolisage_json(403, array('ok' => false, 'error' => $langs->transnoentitiesnoconv('DiamantutilsStockColisageErrToken')));
}

// Attribut du lot
$code = diamantutils_stockcolisage_extrafield_code();
if ($code === '' || !diamantutils_stockcolisage_extrafield_exists($db, $code)) {
	diamantutils_lotcolisage_json(400, array('ok' => false, 'error' => $langs->transnoentitiesnoconv('DiamantutilsStockColisageErrExtrafield', ($code !== '' ? $code : '?'))));
}

// Produit de l'entité, géré par lots
$fk_product = GETPOSTINT('fk_product');
$product = new Product($db);
if ($fk_product <= 0 || $product->fetch($fk_product) <= 0 || empty($product->id) || !$product->hasbatch()) {
	diamantutils_lotcolisage_json(400, array('ok' => false, 'error' => $langs->transnoentitiesnoconv('DiamantutilsStockColisageErrProduct')));
}

// Valeur : texte sur une ligne, 200 caractères au plus
$maxlength = 200;
$text = diamantutils_stockcolisage_clean_input(GETPOST('colisage', 'none'));
if (dol_strlen($text) > $maxlength) {
	diamantutils_lotcolisage_json(400, array('ok' => false, 'error' => $langs->transnoentitiesnoconv('DiamantutilsStockColisageErrLength', $maxlength)));
}

// Lot retrouvé côté serveur (stock ou lots connus du produit)
$batch = trim(GETPOST('batch', 'none'));
$lot = diamantutils_stockcolisage_find_lot($db, $product->id, $batch);
if ($lot === false) {
	diamantutils_lotcolisage_json(500, array('ok' => false, 'error' => $langs->transnoentitiesnoconv('Error')));
}
if ($lot === null) {
	diamantutils_lotcolisage_json(400, array('ok' => false, 'error' => $langs->transnoentitiesnoconv('DiamantutilsStockColisageErrBatch', dol_trunc($batch, 64))));
}

$error = '';
$value = diamantutils_stockcolisage_save($db, $user, $product->id, $lot, $text, $code, $error);
if ($value === false) {
	dol_syslog('diamantutils lotcolisage: '.$error, LOG_ERR);
	diamantutils_lotcolisage_json(500, array('ok' => false, 'error' => ($error !== '' ? $error : $langs->transnoentitiesnoconv('Error'))));
}

diamantutils_lotcolisage_json(200, array('ok' => true, 'value' => $value));
