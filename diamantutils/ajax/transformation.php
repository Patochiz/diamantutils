<?php
/**
 * Endpoint AJAX de l'écran de transformation (lecture seule, réponses JSON)
 *
 * action=searchproduct&term=...                    → produits correspondants
 * action=product&id=...&fk_warehouse=...           → infos produit, lots en stock, tous les lots connus
 * action=orderlines&fk_commande=...                → lignes d'une commande client
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

dol_include_once('/diamantutils/lib/diamantutils.lib.php');

top_httphead('application/json');

if (!isModEnabled('diamantutils') || !$user->hasRight('diamantutils', 'transformation', 'read')) {
	http_response_code(403);
	print json_encode(array('error' => 'Forbidden'));
	exit;
}

$langs->loadLangs(array('products', 'stocks', 'diamantutils@diamantutils'));

$action = GETPOST('action', 'aZ09');
$out = array();

if ($action == 'searchproduct') {
	// Recherche de produits (type produit, non service) par ref ou libellé
	$term = trim(GETPOST('term', 'alphanohtml'));
	$sql = "SELECT p.rowid, p.ref, p.label, p.tobatch";
	$sql .= " FROM ".MAIN_DB_PREFIX."product as p";
	$sql .= " WHERE p.entity IN (".getEntity('product').")";
	$sql .= " AND p.fk_product_type = 0";
	if ($term !== '') {
		$sql .= natural_search(array('p.ref', 'p.label'), $term);
	}
	$sql .= " ORDER BY p.ref";
	$sql .= $db->plimit(30);

	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$out[] = array(
				'id' => (int) $obj->rowid,
				'value' => $obj->ref,
				'label' => $obj->ref.' - '.$obj->label,
			);
		}
		$db->free($resql);
	}
} elseif ($action == 'product') {
	// Infos produit + lots disponibles dans l'entrepôt
	$fk_product = GETPOSTINT('id');
	$fk_warehouse = GETPOSTINT('fk_warehouse');
	$info = diamantutils_product_info($db, $fk_product);
	if (empty($info)) {
		http_response_code(404);
		$out = array('error' => $langs->trans('DiamantutilsErrorProductNotFound', $fk_product));
	} else {
		$out = $info;
		$out['lots'] = ($fk_warehouse > 0 ? diamantutils_product_lots($db, $fk_product, $fk_warehouse) : array());
		$out['stock'] = ($fk_warehouse > 0 ? diamantutils_stock_qty($db, $fk_product, $fk_warehouse) : 0);
		$out['alllots'] = diamantutils_product_all_lots($db, $fk_product);
	}
} elseif ($action == 'orderlines') {
	// Lignes de produit d'une commande client
	$fk_commande = GETPOSTINT('fk_commande');
	$sql = "SELECT cd.rowid, cd.qty, cd.description, p.ref, p.label";
	$sql .= " FROM ".MAIN_DB_PREFIX."commandedet as cd";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."commande as c ON c.rowid = cd.fk_commande";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = cd.fk_product";
	$sql .= " WHERE cd.fk_commande = ".((int) $fk_commande);
	$sql .= " AND c.entity IN (".getEntity('commande').")";
	$sql .= " AND cd.product_type = 0";
	$sql .= " ORDER BY cd.rang, cd.rowid";

	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$label = ($obj->ref ? $obj->ref : dol_trunc(dol_string_nohtmltag($obj->description), 40));
			$out[] = array(
				'id' => (int) $obj->rowid,
				'label' => $label.' × '.price2num($obj->qty, 'MS'),
			);
		}
		$db->free($resql);
	}
} else {
	http_response_code(400);
	$out = array('error' => 'Bad action');
}

print json_encode($out);

$db->close();
