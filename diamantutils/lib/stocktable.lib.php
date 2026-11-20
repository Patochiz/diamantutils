<?php
/**
 * Tableau de stock par lots — fonctions communes
 *
 * Le tableau enregistre le colisage constaté au dépôt (pièces, pièces par colis, dimensions).
 * Dolibarr reste la référence du stock : le tableau ne crée aucun mouvement, il compare.
 *
 * Fonctions pures (sans base, testables seules) :
 *  - diamantutils_stock_num()            nombre affiché sans zéros inutiles ;
 *  - diamantutils_stock_colis()          colisage (« 5 colis de 28u ») ;
 *  - diamantutils_stock_unit()           unité du produit (type, échelle) normalisée ;
 *  - diamantutils_stock_line_qty()       quantité d'une ligne dans l'unité du produit ;
 *  - diamantutils_stock_state()          état d'une comparaison tableau / Dolibarr ;
 *  - diamantutils_stock_compare()        comparaison d'un produit, lot par lot.
 *
 * Fonctions avec base : configuration, périmètre (tags produit), lecture des données.
 */

/** États de comparaison */
if (!defined('DIAMANTUTILS_STOCK_OK')) {
	define('DIAMANTUTILS_STOCK_OK', 'ok');
	define('DIAMANTUTILS_STOCK_DIFF', 'diff');
	define('DIAMANTUTILS_STOCK_DOLI_ONLY', 'doli_only');
	define('DIAMANTUTILS_STOCK_TABLE_ONLY', 'table_only');
	define('DIAMANTUTILS_STOCK_UNKNOWN', 'unknown');
}


/*
 * Fonctions pures
 */

/**
 * Nombre affiché sans zéros inutiles (140 → « 140 », 2.5 → « 2,5 »)
 *
 * @param	float	$value		Valeur
 * @param	int		$maxdec		Décimales au plus
 * @param	string	$decsep		Séparateur décimal
 * @return	string
 */
function diamantutils_stock_num($value, $maxdec = 3, $decsep = ',')
{
	$value = round((float) $value, $maxdec);
	if ($value == 0) {
		$value = 0.0;	// pas de « -0 »
	}
	$txt = number_format($value, $maxdec, '.', '');
	if (strpos($txt, '.') !== false) {
		$txt = rtrim(rtrim($txt, '0'), '.');
	}
	return str_replace('.', $decsep, $txt);
}

/**
 * Colisage d'une ligne : colis complets + colis du reste
 *
 * - colis complets = floor(nb / per), reste = nb − complets × per ;
 * - « 5 colis de 28u » ; avec reste « 1 colis de 28u + 1 colis de 2u » ;
 * - per ≥ nb → « 1 colis de {nb}u » (warn 'partial' si per > nb) ;
 * - nombre de colis = complets + (reste > 0 ? 1 : 0).
 *
 * @param	float	$nb		Nombre de pièces
 * @param	float	$per	Pièces par colis
 * @param	string	$fmt	Format d'un groupe de colis (sprintf : nombre de colis, pièces par colis)
 * @param	string	$sep	Séparateur entre colis complets et colis du reste
 * @param	string	$decsep	Séparateur décimal
 * @return	array			array('colis' => int, 'text' => string, 'warn' => '' | 'partial' | 'invalid')
 */
function diamantutils_stock_colis($nb, $per, $fmt = '%s colis de %su', $sep = ' + ', $decsep = ',')
{
	$nb = (float) $nb;
	$per = (float) $per;
	if (!($nb > 0) || !($per > 0)) {
		return array('colis' => 0, 'text' => '', 'warn' => 'invalid');
	}

	if ($per >= $nb) {
		return array(
			'colis' => 1,
			'text' => sprintf($fmt, '1', diamantutils_stock_num($nb, 3, $decsep)),
			'warn' => ($per > $nb ? 'partial' : ''),
		);
	}

	// Tolérance flottante : 0,3 / 0,1 doit donner 3 colis complets, pas 2
	$full = (int) floor($nb / $per + 1e-9);
	$rest = round($nb - $full * $per, 6);
	if ($rest < 1e-6) {
		$rest = 0.0;
	}

	$text = sprintf($fmt, (string) $full, diamantutils_stock_num($per, 3, $decsep));
	if ($rest > 0) {
		$text .= $sep.sprintf($fmt, '1', diamantutils_stock_num($rest, 3, $decsep));
	}
	return array('colis' => $full + ($rest > 0 ? 1 : 0), 'text' => $text, 'warn' => '');
}

/**
 * Unité du produit normalisée pour le calcul des quantités
 *
 * Bases migrées : unit_type parfois vide, déduit du code (même table que
 * diamantutils_product_info()). Produit sans unité : compté en pièces (mode 'qty').
 *
 * @param	int			$fk_unit	Id de l'unité (llx_product.fk_unit), 0 = aucune
 * @param	string		$type		c_units.unit_type
 * @param	int|null	$scale		c_units.scale
 * @param	string		$code		c_units.code
 * @param	string		$short		c_units.short_label
 * @return	array					array('type', 'scale', 'short', 'ok' => bool, 'error' => code de langue ou '')
 */
function diamantutils_stock_unit($fk_unit, $type, $scale, $code = '', $short = '')
{
	$type = (string) $type;
	$scale = ($scale === null || $scale === '' ? null : (int) $scale);
	$code = strtoupper((string) $code);

	if (!((int) $fk_unit > 0)) {
		return array('type' => 'qty', 'scale' => 0, 'short' => 'u', 'ok' => true, 'error' => '');
	}

	if ($type === '' && $code !== '') {
		$bycode = array(
			'M2' => array('surface', 0), 'DM2' => array('surface', -2), 'CM2' => array('surface', -4), 'MM2' => array('surface', -6),
			'M' => array('size', 0), 'DM' => array('size', -1), 'CM' => array('size', -2), 'MM' => array('size', -3),
			'P' => array('qty', 0), 'SET' => array('qty', 0),
		);
		if (isset($bycode[$code])) {
			$type = $bycode[$code][0];
			if ($scale === null) {
				$scale = $bycode[$code][1];
			}
		}
	}
	if ($type === 'qty' && $scale === null) {
		$scale = 0;
	}

	$unit = array('type' => $type, 'scale' => $scale, 'short' => ((string) $short !== '' ? (string) $short : strtolower($code)), 'ok' => false, 'error' => '');
	if (!in_array($type, array('surface', 'size', 'qty'), true)) {
		$unit['error'] = 'DiamantutilsStockErrUnitType';
	} elseif ($scale === null || $scale >= 80) {
		// Échelles 88 à 99 : unités impériales, refusées
		$unit['error'] = 'DiamantutilsStockErrUnitScale';
	} else {
		$unit['ok'] = true;
	}
	return $unit;
}

/**
 * Dimensions exigées par le type d'unité
 *
 * @param	string	$type	'surface' | 'size' | 'qty'
 * @return	array			array('larg' => bool, 'long' => bool)
 */
function diamantutils_stock_dims_required($type)
{
	return array('larg' => ($type === 'surface'), 'long' => in_array($type, array('surface', 'size'), true));
}

/**
 * Quantité d'une ligne dans l'unité du produit (jamais stockée, recalculée à l'affichage)
 *
 * - surface : nb × larg_mm × long_mm × 1e-6 ÷ 10^scale ;
 * - size    : nb × long_mm × 1e-3 ÷ 10^scale ;
 * - qty     : nb.
 * Dimension manquante pour le mode exigé : null + code d'erreur, jamais 0 silencieux.
 *
 * @param	array		$unit		Résultat de diamantutils_stock_unit()
 * @param	float		$nb			Nombre de pièces
 * @param	float|null	$larg_mm	Largeur d'une pièce (mm)
 * @param	float|null	$long_mm	Longueur d'une pièce (mm)
 * @param	string		$error		Code de langue de l'erreur (sortie)
 * @return	float|null				Quantité, ou null si non calculable
 */
function diamantutils_stock_line_qty($unit, $nb, $larg_mm, $long_mm, &$error = '')
{
	$error = '';
	if (empty($unit) || empty($unit['ok'])) {
		$error = (!empty($unit['error']) ? $unit['error'] : 'DiamantutilsStockErrUnitType');
		return null;
	}
	$nb = (float) $nb;
	if (!($nb > 0)) {
		$error = 'DiamantutilsStockErrNbPieces';
		return null;
	}
	$larg = ($larg_mm === null || $larg_mm === '' ? 0.0 : (float) $larg_mm);
	$long = ($long_mm === null || $long_mm === '' ? 0.0 : (float) $long_mm);
	$div = pow(10, (int) $unit['scale']);

	if ($unit['type'] === 'surface') {
		if (!($larg > 0) || !($long > 0)) {
			$error = 'DiamantutilsStockErrDimsSurface';
			return null;
		}
		return $nb * $larg * $long * 1e-6 / $div;
	}
	if ($unit['type'] === 'size') {
		if (!($long > 0)) {
			$error = 'DiamantutilsStockErrDimsSize';
			return null;
		}
		return $nb * $long * 1e-3 / $div;
	}
	return $nb;
}

/**
 * État d'une comparaison tableau / Dolibarr (arrondi de comparaison : 3 décimales)
 *
 * @param	float|null	$table		Quantité du tableau (null = non calculable)
 * @param	float		$doli		Stock Dolibarr
 * @param	float		$tolerance	Écart toléré
 * @param	bool		$intable	Présent dans le tableau
 * @return	string					DIAMANTUTILS_STOCK_*
 */
function diamantutils_stock_state($table, $doli, $tolerance, $intable)
{
	$doli = round((float) $doli, 3);
	$tolerance = abs((float) $tolerance) + 1e-9;
	if ($intable && $table === null) {
		return DIAMANTUTILS_STOCK_UNKNOWN;
	}
	$table = round((float) $table, 3);
	$indoli = (abs($doli) > $tolerance);

	if (!$intable) {
		return ($indoli ? DIAMANTUTILS_STOCK_DOLI_ONLY : DIAMANTUTILS_STOCK_OK);
	}
	if (!$indoli) {
		return (abs($table) > $tolerance ? DIAMANTUTILS_STOCK_TABLE_ONLY : DIAMANTUTILS_STOCK_OK);
	}
	return (abs($table - $doli) <= $tolerance ? DIAMANTUTILS_STOCK_OK : DIAMANTUTILS_STOCK_DIFF);
}

/**
 * Comparaison d'un produit, lot par lot
 *
 * Les lignes d'un même lot sont additionnées. Produit sans gestion de lot : une seule clé ''
 * comparée au stock total. Produit à lots : la part du stock Dolibarr sans lot
 * (total − somme des lots) est rendue à part ('doli_nolot') et comparée aux lignes sans lot.
 *
 * @param	array	$unit		Résultat de diamantutils_stock_unit()
 * @param	array	$lines		Lignes : array('batch', 'nb_pieces', 'larg_mm', 'long_mm', ...) ; reçoivent 'qty' et 'qty_error'
 * @param	array	$doli_lots	Stock Dolibarr par lot : array(batch => qty)
 * @param	float	$doli_total	Stock Dolibarr total du produit
 * @param	bool	$tobatch	Produit géré en lots
 * @param	float	$tolerance	Écart toléré
 * @return	array				array('lots' => array(batch => array('lines', 'table', 'doli', 'diff', 'state')),
 *								'table' => float|null, 'doli' => float, 'diff' => float|null, 'state' => string, 'doli_nolot' => float)
 */
function diamantutils_stock_compare($unit, $lines, $doli_lots, $doli_total, $tobatch, $tolerance)
{
	$doli_total = (float) $doli_total;
	$doli = array();
	$doli_nolot = 0.0;
	if ($tobatch) {
		$sumlots = 0.0;
		foreach ($doli_lots as $batch => $qty) {
			if ((string) $batch === '') {
				continue;
			}
			$doli[(string) $batch] = (float) $qty;
			$sumlots += (float) $qty;
		}
		$doli_nolot = round($doli_total - $sumlots, 6);
		if (abs($doli_nolot) > 1e-6) {
			$doli[''] = $doli_nolot;
		} else {
			$doli_nolot = 0.0;
		}
	} else {
		$doli[''] = $doli_total;
	}

	// Lignes du tableau regroupées par lot
	$lots = array();
	foreach ($lines as $line) {
		$batch = ($tobatch ? (string) $line['batch'] : '');
		$err = '';
		$line['qty'] = diamantutils_stock_line_qty($unit, $line['nb_pieces'], $line['larg_mm'], $line['long_mm'], $err);
		$line['qty_error'] = $err;
		if (!isset($lots[$batch])) {
			$lots[$batch] = array('lines' => array(), 'table' => 0.0);
		}
		$lots[$batch]['lines'][] = $line;
		if ($line['qty'] === null || $lots[$batch]['table'] === null) {
			$lots[$batch]['table'] = null;
		} else {
			$lots[$batch]['table'] += $line['qty'];
		}
	}

	// Lots Dolibarr absents du tableau (stock non nul seulement)
	foreach ($doli as $batch => $qty) {
		if (!isset($lots[$batch]) && abs($qty) > 1e-6) {
			$lots[$batch] = array('lines' => array(), 'table' => 0.0);
		}
	}

	$table_total = 0.0;
	foreach ($lots as $batch => $lot) {
		$batch = (string) $batch;
		$d = (isset($doli[$batch]) ? $doli[$batch] : 0.0);
		$intable = !empty($lot['lines']);
		$lots[$batch]['doli'] = $d;
		$lots[$batch]['diff'] = ($lot['table'] === null ? null : round($lot['table'] - $d, 6));
		$lots[$batch]['state'] = diamantutils_stock_state($lot['table'], $d, $tolerance, $intable);
		if ($lot['table'] === null || $table_total === null) {
			$table_total = null;
		} else {
			$table_total += $lot['table'];
		}
	}

	// Ordre naturel des lots (« Lot 2 » avant « Lot 10 »), sans lot en premier
	uksort($lots, function ($a, $b) {
		return strnatcasecmp((string) $a, (string) $b);
	});

	return array(
		'lots' => $lots,
		'table' => $table_total,
		'doli' => $doli_total,
		'diff' => ($table_total === null ? null : round($table_total - $doli_total, 6)),
		'state' => diamantutils_stock_state($table_total, $doli_total, $tolerance, ($table_total === null || abs($table_total) > 1e-9)),
		'doli_nolot' => $doli_nolot,
	);
}


/*
 * Configuration et périmètre
 */

/**
 * Fonctionnalité « Tableau de stock par lots » active ? (désactivée par défaut)
 *
 * @return	bool
 */
function diamantutils_stocktable_enabled()
{
	return isModEnabled('diamantutils') && !empty(getDolGlobalString('DIAMANTUTILS_STOCKTABLE_ENABLED', '0'));
}

/**
 * Écart toléré entre tableau et Dolibarr (unité du produit)
 *
 * @return	float
 */
function diamantutils_stocktable_tolerance()
{
	$tol = (float) price2num(getDolGlobalString('DIAMANTUTILS_STOCKTABLE_TOLERANCE', '0.01'));
	return ($tol >= 0 ? $tol : 0.01);
}

/**
 * Entrepôts ignorés par le comparatif.
 * Constante absente : l'entrepôt Sous-traitance (stock transitoire). '0' = aucun.
 *
 * @return	int[]
 */
function diamantutils_stocktable_excluded_warehouses()
{
	$raw = getDolGlobalString('DIAMANTUTILS_STOCKTABLE_EXCLUDE_WAREHOUSES', 'default');
	if ($raw === 'default') {
		dol_include_once('/diamantutils/lib/soustraitance.lib.php');
		$st = diamantutils_st_warehouse_id();
		return ($st > 0 ? array($st) : array());
	}
	return diamantutils_stocktable_ids($raw);
}

/**
 * Liste d'identifiants « 1,5,7 » → array(1, 5, 7) (positifs, sans doublon)
 *
 * @param	string|array	$raw	Liste
 * @return	int[]
 */
function diamantutils_stocktable_ids($raw)
{
	$ids = array();
	foreach ((is_array($raw) ? $raw : explode(',', (string) $raw)) as $id) {
		$id = (int) $id;
		if ($id > 0) {
			$ids[$id] = $id;
		}
	}
	return array_values($ids);
}

/**
 * Tags produit du périmètre : tags configurés, avec leurs sous-catégories si l'option est active.
 * Aucun tag configuré = aucun produit.
 *
 * @param	DoliDB	$db		Base de données
 * @param	int		$only	Restreindre à ce tag (filtre de l'écran) ; ignoré s'il est hors périmètre
 * @return	int[]			Ids de catégories (vide = aucun produit)
 */
function diamantutils_stocktable_category_ids($db, $only = 0)
{
	static $tree = null;

	$roots = diamantutils_stocktable_ids(getDolGlobalString('DIAMANTUTILS_STOCKTABLE_CATEGORIES'));
	if (empty($roots)) {
		return array();
	}

	// Arbre des catégories produit (type 0), lu une fois
	if ($tree === null) {
		$tree = array();
		$sql = "SELECT c.rowid, c.fk_parent FROM ".MAIN_DB_PREFIX."categorie as c";
		$sql .= " WHERE c.type = 0 AND c.entity IN (".getEntity('category').")";
		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__FUNCTION__.' '.$db->lasterror(), LOG_ERR);
			return array();
		}
		while ($obj = $db->fetch_object($resql)) {
			$tree[(int) $obj->rowid] = (int) $obj->fk_parent;
		}
		$db->free($resql);
	}

	$withsub = !empty(getDolGlobalString('DIAMANTUTILS_STOCKTABLE_SUBCATEGORIES', '1'));
	$scope = diamantutils_stocktable_expand($tree, $roots, $withsub);
	if ($only > 0) {
		if (!in_array((int) $only, $scope, true)) {
			return $scope;
		}
		return array_values(array_intersect(diamantutils_stocktable_expand($tree, array((int) $only), $withsub), $scope));
	}
	return $scope;
}

/**
 * Catégories racines + descendants (catégories supprimées ignorées)
 *
 * @param	array	$tree		array(id => id_parent)
 * @param	int[]	$roots		Racines
 * @param	bool	$withsub	Inclure les sous-catégories
 * @return	int[]
 */
function diamantutils_stocktable_expand($tree, $roots, $withsub)
{
	$out = array();
	foreach ($roots as $root) {
		if (isset($tree[$root])) {
			$out[$root] = $root;
		}
	}
	if ($withsub) {
		$children = array();
		foreach ($tree as $id => $parent) {
			$children[$parent][] = $id;
		}
		$todo = array_values($out);
		while (!empty($todo)) {
			$id = array_pop($todo);
			if (empty($children[$id])) {
				continue;
			}
			foreach ($children[$id] as $child) {
				if (!isset($out[$child])) {
					$out[$child] = $child;
					$todo[] = $child;
				}
			}
		}
	}
	return array_values($out);
}

/**
 * Sous-requête des produits du périmètre (à utiliser dans « IN (...) »)
 *
 * @param	int[]	$catids		Catégories (diamantutils_stocktable_category_ids())
 * @return	string				Sous-requête SQL, ou '' si aucun tag (aucun produit)
 */
function diamantutils_stocktable_scope_sql($catids)
{
	$catids = diamantutils_stocktable_ids($catids);
	if (empty($catids)) {
		return '';
	}
	return "SELECT cp.fk_product FROM ".MAIN_DB_PREFIX."categorie_product as cp WHERE cp.fk_categorie IN (".implode(',', $catids).")";
}

/**
 * Produit pour la saisie : existence, type, lots, unité et appartenance au périmètre
 *
 * @param	DoliDB	$db				Base de données
 * @param	int		$fk_product		Id produit
 * @return	array|null				array('id', 'ref', 'label', 'type', 'tobatch', 'unit', 'inscope') ou null si introuvable
 */
function diamantutils_stocktable_product($db, $fk_product)
{
	$fk_product = (int) $fk_product;
	if ($fk_product <= 0) {
		return null;
	}
	$scopesql = diamantutils_stocktable_scope_sql(diamantutils_stocktable_category_ids($db));

	$sql = "SELECT p.rowid, p.ref, p.label, p.fk_product_type, p.tobatch, p.fk_unit,";
	$sql .= " u.code as unit_code, u.scale as unit_scale, u.unit_type, u.short_label as unit_short,";
	$sql .= ($scopesql !== '' ? " (CASE WHEN p.rowid IN (".$scopesql.") THEN 1 ELSE 0 END)" : " 0")." as inscope";
	$sql .= " FROM ".MAIN_DB_PREFIX."product as p";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."c_units as u ON u.rowid = p.fk_unit";
	$sql .= " WHERE p.rowid = ".$fk_product;
	$sql .= " AND p.entity IN (".getEntity('product').")";
	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog(__FUNCTION__.' '.$db->lasterror(), LOG_ERR);
		return null;
	}
	$obj = $db->fetch_object($resql);
	$db->free($resql);
	if (!$obj) {
		return null;
	}
	return array(
		'id' => (int) $obj->rowid,
		'ref' => (string) $obj->ref,
		'label' => (string) $obj->label,
		'type' => (int) $obj->fk_product_type,
		'tobatch' => ((int) $obj->tobatch > 0),
		'unit' => diamantutils_stock_unit($obj->fk_unit, $obj->unit_type, $obj->unit_scale, $obj->unit_code, $obj->unit_short),
		'inscope' => ((int) $obj->fk_product_type === 0 && !empty($obj->inscope)),
	);
}

/**
 * Lots Dolibarr d'un produit pour la liste de saisie : lots connus (llx_product_lot)
 * et lots en stock, avec leur stock hors entrepôts exclus
 *
 * @param	DoliDB	$db				Base de données
 * @param	int		$fk_product		Id produit
 * @return	array					array(batch => qty), tri naturel
 */
function diamantutils_stocktable_product_lots($db, $fk_product)
{
	$lots = array();
	$exclude = diamantutils_stocktable_excluded_warehouses();

	$sql = "SELECT pl.batch, 0 as qty FROM ".MAIN_DB_PREFIX."product_lot as pl";
	$sql .= " WHERE pl.fk_product = ".((int) $fk_product);
	$sql .= " AND pl.entity IN (".getEntity('productlot').")";
	$sql .= " UNION ALL";
	$sql .= " SELECT pb.batch, pb.qty FROM ".MAIN_DB_PREFIX."product_batch as pb";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_stock as ps ON ps.rowid = pb.fk_product_stock";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."entrepot as e ON e.rowid = ps.fk_entrepot";
	$sql .= " WHERE ps.fk_product = ".((int) $fk_product);
	$sql .= " AND e.entity IN (".getEntity('stock').")";
	if (!empty($exclude)) {
		$sql .= " AND ps.fk_entrepot NOT IN (".implode(',', $exclude).")";
	}
	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog(__FUNCTION__.' '.$db->lasterror(), LOG_ERR);
		return $lots;
	}
	while ($obj = $db->fetch_object($resql)) {
		$batch = (string) $obj->batch;
		if ($batch === '') {
			continue;
		}
		$lots[$batch] = (isset($lots[$batch]) ? $lots[$batch] : 0.0) + (float) $obj->qty;
	}
	$db->free($resql);
	uksort($lots, 'strnatcasecmp');
	return $lots;
}

/**
 * Lot connu de Dolibarr pour ce produit (llx_product_lot ou stock par lot)
 *
 * @param	DoliDB	$db				Base de données
 * @param	int		$fk_product		Id produit
 * @param	string	$batch			Lot
 * @return	bool
 */
function diamantutils_stocktable_batch_exists($db, $fk_product, $batch)
{
	$sql = "SELECT 1 as found FROM ".MAIN_DB_PREFIX."product_lot as pl";
	$sql .= " WHERE pl.fk_product = ".((int) $fk_product)." AND pl.batch = '".$db->escape($batch)."'";
	$sql .= " AND pl.entity IN (".getEntity('productlot').")";
	$sql .= " UNION ALL";
	$sql .= " SELECT 1 as found FROM ".MAIN_DB_PREFIX."product_batch as pb";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_stock as ps ON ps.rowid = pb.fk_product_stock";
	$sql .= " WHERE ps.fk_product = ".((int) $fk_product)." AND pb.batch = '".$db->escape($batch)."'";
	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog(__FUNCTION__.' '.$db->lasterror(), LOG_ERR);
		return false;
	}
	$found = ($db->num_rows($resql) > 0);
	$db->free($resql);
	return $found;
}

/**
 * Dernières valeurs saisies pour un produit (pré-remplissage)
 *
 * @param	DoliDB	$db				Base de données
 * @param	int		$fk_product		Id produit
 * @return	array					array('pieces_per_colis', 'larg_mm', 'long_mm', 'fk_soc') ; valeurs null si aucune ligne
 */
function diamantutils_stocktable_last_values($db, $fk_product)
{
	$out = array('pieces_per_colis' => null, 'larg_mm' => null, 'long_mm' => null, 'fk_soc' => null);
	$sql = "SELECT l.pieces_per_colis, l.larg_mm, l.long_mm, l.fk_soc FROM ".MAIN_DB_PREFIX."diamantutils_stockline as l";
	$sql .= " WHERE l.fk_product = ".((int) $fk_product);
	$sql .= " AND l.entity IN (".getEntity('diamantutils_stockline').")";
	$sql .= " ORDER BY l.rowid DESC";
	$sql .= $db->plimit(1);
	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog(__FUNCTION__.' '.$db->lasterror(), LOG_ERR);
		return $out;
	}
	$obj = $db->fetch_object($resql);
	$db->free($resql);
	if ($obj) {
		$out['pieces_per_colis'] = (float) $obj->pieces_per_colis;
		$out['larg_mm'] = ($obj->larg_mm === null ? null : (float) $obj->larg_mm);
		$out['long_mm'] = ($obj->long_mm === null ? null : (float) $obj->long_mm);
		$out['fk_soc'] = ($obj->fk_soc > 0 ? (int) $obj->fk_soc : null);
	}
	return $out;
}
