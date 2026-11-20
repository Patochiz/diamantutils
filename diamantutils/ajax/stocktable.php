<?php
/**
 * Endpoint AJAX du tableau de stock par lots (réponses JSON)
 *
 * Lecture (droit stocktable/read) :
 *   action=searchproduct&term=...   → produits des tags configurés uniquement
 *   action=product&id=...           → unité, dimensions exigées, dernières valeurs, lots Dolibarr avec stock
 *   action=group&id=...             → HTML du groupe d'un produit des tags (ouverture par « + Produit »)
 * Écriture (POST + jeton vérifié ici, NOCSRFCHECK étant défini) :
 *   action=save    (droit write)    → crée ou modifie une ligne, renvoie le HTML du groupe recalculé
 *   action=delete  (droit delete)   → supprime une ligne, renvoie le HTML du groupe recalculé
 * Toute écriture passe par DiamantutilsStockLine et sa validation. Aucune écriture de stock.
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

dol_include_once('/diamantutils/lib/stocktable.lib.php');
dol_include_once('/diamantutils/class/stockline.class.php');

top_httphead('application/json');

/**
 * Réponse JSON et fin du script
 *
 * @param	int		$code	Code HTTP
 * @param	array	$data	Données
 * @return	void
 */
function diamantutils_stocktable_json($code, $data)
{
	global $db;
	http_response_code($code);
	print json_encode($data);
	$db->close();
	exit;
}

if (!diamantutils_stocktable_enabled()) {
	diamantutils_stocktable_json(403, array('error' => 'Forbidden'));
}
if (!$user->hasRight('diamantutils', 'stocktable', 'read')) {
	diamantutils_stocktable_json(403, array('error' => 'Forbidden'));
}

$langs->loadLangs(array('products', 'stocks', 'productbatch', 'diamantutils@diamantutils'));

$action = GETPOST('action', 'aZ09');
$perms = array(
	'write' => $user->hasRight('diamantutils', 'stocktable', 'write'),
	'delete' => $user->hasRight('diamantutils', 'stocktable', 'delete'),
);

// Écritures : droit, méthode POST et jeton CSRF vérifiés avant tout
if (in_array($action, array('save', 'delete'), true)) {
	if (($action == 'save' && !$perms['write']) || ($action == 'delete' && !$perms['delete'])) {
		diamantutils_stocktable_json(403, array('error' => $langs->transnoentitiesnoconv('NotEnoughPermissions')));
	}
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		diamantutils_stocktable_json(405, array('error' => $langs->transnoentitiesnoconv('DiamantutilsStockErrMethod')));
	}
	if (GETPOST('token', 'alpha') === '' || GETPOST('token', 'alpha') !== currentToken()) {
		diamantutils_stocktable_json(403, array('error' => $langs->transnoentitiesnoconv('DiamantutilsStockErrToken')));
	}
}

/**
 * HTML recalculé du groupe d'un produit ('' si le groupe n'a plus rien à afficher)
 *
 * @param	DoliDB	$db				Base de données
 * @param	int		$fk_product		Id produit
 * @param	array	$perms			Droits
 * @return	string
 */
function diamantutils_stocktable_group_html($db, $fk_product, $perms)
{
	$groups = diamantutils_stocktable_load($db, diamantutils_stocktable_category_ids($db), $fk_product);
	if (!is_array($groups) || empty($groups[$fk_product])) {
		return '';
	}
	return diamantutils_stocktable_render_group($groups[$fk_product], $perms);
}

/**
 * Nombre saisi : null si vide, sinon float (point ou séparateur décimal de l'utilisateur)
 *
 * @param	string	$name	Paramètre
 * @return	float|null|false	false si non numérique
 */
function diamantutils_stocktable_postnum($name)
{
	$raw = trim((string) GETPOST($name, 'alpha'));
	if ($raw === '') {
		return null;
	}
	if (is_numeric($raw)) {
		return (float) $raw;
	}
	$num = price2num($raw);
	return (is_numeric($num) ? (float) $num : false);
}

if ($action == 'searchproduct') {
	$out = array();
	$scopesql = diamantutils_stocktable_scope_sql(diamantutils_stocktable_category_ids($db));
	if ($scopesql !== '') {
		$term = trim(GETPOST('term', 'alphanohtml'));
		$sql = "SELECT p.rowid, p.ref, p.label";
		$sql .= " FROM ".MAIN_DB_PREFIX."product as p";
		$sql .= " WHERE p.rowid IN (".$scopesql.")";
		$sql .= " AND p.fk_product_type = 0";
		$sql .= " AND p.entity IN (".getEntity('product').")";
		if ($term !== '') {
			$sql .= natural_search(array('p.ref', 'p.label'), $term);
		}
		$sql .= " ORDER BY p.ref";
		$sql .= $db->plimit(30);
		$resql = $db->query($sql);
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$out[] = array('id' => (int) $obj->rowid, 'ref' => (string) $obj->ref, 'label' => (string) $obj->label);
			}
			$db->free($resql);
		}
	}
	diamantutils_stocktable_json(200, $out);
}

if ($action == 'product') {
	$fk_product = GETPOSTINT('id');
	$product = diamantutils_stocktable_product($db, $fk_product);
	if (empty($product)) {
		diamantutils_stocktable_json(404, array('error' => $langs->transnoentitiesnoconv('DiamantutilsStockErrProduct', $fk_product)));
	}
	if (!$product['inscope']) {
		diamantutils_stocktable_json(403, array('error' => $langs->transnoentitiesnoconv('DiamantutilsStockErrOutOfScope', $product['ref'])));
	}
	$lots = array();
	if ($product['tobatch']) {
		foreach (diamantutils_stocktable_product_lots($db, $fk_product) as $batch => $qty) {
			$lots[] = array('batch' => (string) $batch, 'qty' => round($qty, 6), 'qtytxt' => diamantutils_stocktable_qty_text($qty, $product['unit']));
		}
	}
	$unit = $product['unit'];
	diamantutils_stocktable_json(200, array(
		'id' => $product['id'],
		'ref' => $product['ref'],
		'label' => $product['label'],
		'tobatch' => $product['tobatch'],
		'unit' => array(
			'type' => $unit['type'],
			'scale' => $unit['scale'],
			'label' => diamantutils_stocktable_unit_label($unit),
			'ok' => $unit['ok'],
			'error' => ($unit['ok'] ? '' : $langs->transnoentitiesnoconv($unit['error'], $product['ref'])),
		),
		'dims' => diamantutils_stock_dims_required($unit['type']),
		'decimals' => ($unit['type'] === 'size'),
		'last' => diamantutils_stocktable_last_values($db, $fk_product),
		'lots' => $lots,
	));
}

if ($action == 'group') {
	$fk_product = GETPOSTINT('id');
	$product = diamantutils_stocktable_product($db, $fk_product);
	if (empty($product) || !$product['inscope']) {
		diamantutils_stocktable_json(403, array('error' => $langs->transnoentitiesnoconv('DiamantutilsStockErrOutOfScope', empty($product) ? $fk_product : $product['ref'])));
	}
	diamantutils_stocktable_json(200, array('pid' => $fk_product, 'html' => diamantutils_stocktable_group_html($db, $fk_product, $perms)));
}

if ($action == 'save') {
	$id = GETPOSTINT('id');
	$line = new DiamantutilsStockLine($db);
	if ($id > 0) {
		if ($line->fetch($id) <= 0) {
			diamantutils_stocktable_json(404, array('error' => $langs->transnoentitiesnoconv('DiamantutilsStockErrLineNotFound', $id)));
		}
	}
	// Le produit vient de la requête ; la classe refuse un changement de produit sur une ligne existante
	$line->fk_product = GETPOSTINT('fk_product');
	$line->batch = trim(GETPOST('batch', 'alpha'));
	$line->fk_soc = GETPOSTINT('fk_soc');
	$line->note = GETPOST('note', 'alphanohtml');

	$labels = array('nb_pieces' => 'DiamantutilsStockColPieces', 'pieces_per_colis' => 'DiamantutilsStockColPerColis', 'larg_mm' => 'DiamantutilsStockColLarg', 'long_mm' => 'DiamantutilsStockColLong');
	foreach ($labels as $field => $label) {
		$value = diamantutils_stocktable_postnum($field);
		if ($value === false) {
			diamantutils_stocktable_json(422, array('error' => $langs->transnoentitiesnoconv('DiamantutilsStockErrPositive', $langs->transnoentitiesnoconv($label))));
		}
		$line->$field = $value;
	}

	$allow_new_batch = (GETPOSTINT('newbatch') > 0);
	$result = ($id > 0 ? $line->update($user, $allow_new_batch) : $line->create($user, $allow_new_batch));
	if ($result < 0) {
		diamantutils_stocktable_json(422, array('error' => dol_string_nohtmltag($line->error)));
	}
	diamantutils_stocktable_json(200, array(
		'ok' => 1,
		'id' => (int) $line->id,
		'pid' => (int) $line->fk_product,
		'message' => $langs->transnoentitiesnoconv('DiamantutilsStockTableSaved'),
		'html' => diamantutils_stocktable_group_html($db, (int) $line->fk_product, $perms),
	));
}

if ($action == 'delete') {
	$id = GETPOSTINT('id');
	$line = new DiamantutilsStockLine($db);
	if ($line->fetch($id) <= 0) {
		diamantutils_stocktable_json(404, array('error' => $langs->transnoentitiesnoconv('DiamantutilsStockErrLineNotFound', $id)));
	}
	if ($line->delete($user) < 0) {
		diamantutils_stocktable_json(500, array('error' => $line->error));
	}
	diamantutils_stocktable_json(200, array(
		'ok' => 1,
		'pid' => (int) $line->fk_product,
		'message' => $langs->transnoentitiesnoconv('DiamantutilsStockTableDeleted'),
		'html' => diamantutils_stocktable_group_html($db, (int) $line->fk_product, $perms),
	));
}

diamantutils_stocktable_json(400, array('error' => 'Bad action'));
