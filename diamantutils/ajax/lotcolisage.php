<?php
/**
 * Endpoint AJAX : enregistre le colisage d'un lot (onglet Stock de la fiche produit)
 *
 * POST fk_product, batch, token et :
 *  - field=colisage (défaut), colisage=<texte> : colisage saisi à la main (lot en mode manuel)
 *      → {ok: true, field, mode: 'manual', value: "<texte affiché>"} ;
 *  - field=pieces_par_colis|qte_par_piece, value=<nombre ou vide> : attribut du lot, puis
 *    colisage recalculé (quantité totale du lot) dans la même écriture
 *      → {ok: true, field, mode, value, ppc, qpp, colisage, warn (quantité totale), replaced, numTitle,
 *         rows: [{warehouse, text, warn}]} ;
 *  - erreur → {ok: false, error: "<message>"}.
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

// Champ modifié
$field = GETPOST('field', 'aZ09');
if ($field === '') {
	$field = 'colisage';
}
if (!in_array($field, array('colisage', DIAMANTUTILS_STOCKCOLISAGE_PPC, DIAMANTUTILS_STOCKCOLISAGE_QPP), true)) {
	diamantutils_lotcolisage_json(400, array('ok' => false, 'error' => $langs->transnoentitiesnoconv('DiamantutilsColisageErrField')));
}
$auto = diamantutils_stockcolisage_auto_enabled($db);
if ($field != 'colisage' && !$auto) {
	diamantutils_lotcolisage_json(400, array('ok' => false, 'error' => $langs->transnoentitiesnoconv('DiamantutilsColisageErrAutoOff')));
}

// Valeur validée avant toute recherche
$maxlength = 200;
if ($field == 'colisage') {
	$text = diamantutils_stockcolisage_clean_input(GETPOST('colisage', 'none'));
	if (dol_strlen($text) > $maxlength) {
		diamantutils_lotcolisage_json(400, array('ok' => false, 'error' => $langs->transnoentitiesnoconv('DiamantutilsStockColisageErrLength', $maxlength)));
	}
} else {
	$numerror = '';
	$num = diamantutils_stockcolisage_parse_num($field, GETPOST('value', 'alphanohtml'), $numerror);
	if ($num === false) {
		diamantutils_lotcolisage_json(400, array('ok' => false, 'error' => $numerror));
	}
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
try {
	if ($field == 'colisage') {
		// Colisage calculé : pas de saisie à la main
		if ($auto && $lot['lotid'] > 0 && diamantutils_stockcolisage_lot_was_auto($db, $lot['lotid'])) {
			diamantutils_lotcolisage_json(400, array('ok' => false, 'error' => $langs->transnoentitiesnoconv('DiamantutilsColisageErrIsAuto')));
		}
		$value = diamantutils_stockcolisage_save($db, $user, $product->id, $lot, $text, $code, $error);
		if ($value === false) {
			dol_syslog('diamantutils lotcolisage: '.$error, LOG_ERR);
			diamantutils_lotcolisage_json(500, array('ok' => false, 'error' => ($error !== '' ? $error : $langs->transnoentitiesnoconv('Error'))));
		}
		diamantutils_lotcolisage_json(200, array('ok' => true, 'field' => 'colisage', 'mode' => 'manual', 'value' => $value));
	}

	$res = diamantutils_stockcolisage_save_num($db, $user, $product->id, $lot, $field, $num, $error);
	if ($res === false) {
		dol_syslog('diamantutils lotcolisage: '.$error, LOG_ERR);
		diamantutils_lotcolisage_json(500, array('ok' => false, 'error' => ($error !== '' ? $error : $langs->transnoentitiesnoconv('Error'))));
	}

	// Lignes de l'onglet Stock à mettre à jour : texte calculé avec la quantité de chaque ligne
	$rows = array();
	foreach (diamantutils_stockcolisage_lot_rows($db, $product->id, $lot['batch']) as $warehouse => $qty) {
		$calc = diamantutils_colisage_compute($qty, $res['qpp'], $res['ppc']);
		$rows[] = array('warehouse' => (int) $warehouse, 'text' => $calc['text'], 'warn' => $calc['warn']);
	}
	diamantutils_lotcolisage_json(200, array(
		'ok' => true,
		'field' => $field,
		'mode' => $res['mode'],
		'value' => diamantutils_stockcolisage_num_display($field, $res[$field == DIAMANTUTILS_STOCKCOLISAGE_PPC ? 'ppc' : 'qpp']),
		'ppc' => diamantutils_stockcolisage_num_display(DIAMANTUTILS_STOCKCOLISAGE_PPC, $res['ppc']),
		'qpp' => diamantutils_stockcolisage_num_display(DIAMANTUTILS_STOCKCOLISAGE_QPP, $res['qpp']),
		'colisage' => diamantutils_stockcolisage_text($res['colisage']),
		'warn' => $res['warn'],
		'colisageHtml' => diamantutils_stockcolisage_is_html($res['colisage']),
		'replaced' => $res['replaced'],
		'numTitle' => ($res['mode'] == 'auto' ? '' : diamantutils_stockcolisage_num_title($res['colisage'])),
		'rows' => $rows,
	));
} catch (Throwable $e) {
	dol_syslog('diamantutils lotcolisage: '.$e->getMessage(), LOG_ERR);
	diamantutils_lotcolisage_json(500, array('ok' => false, 'error' => $langs->transnoentitiesnoconv('Error')));
}
