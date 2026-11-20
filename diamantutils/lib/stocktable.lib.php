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
	$roots = diamantutils_stocktable_ids(getDolGlobalString('DIAMANTUTILS_STOCKTABLE_CATEGORIES'));
	if (empty($roots)) {
		return array();
	}
	$tree = diamantutils_stocktable_tree($db);

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
 * Arbre des catégories produit (type 0) de l'entité, lu une fois
 *
 * @param	DoliDB	$db		Base de données
 * @return	array			array(id => id_parent)
 */
function diamantutils_stocktable_tree($db)
{
	static $tree = null;
	if ($tree !== null) {
		return $tree;
	}
	$tree = array();
	$sql = "SELECT c.rowid, c.fk_parent FROM ".MAIN_DB_PREFIX."categorie as c";
	$sql .= " WHERE c.type = 0 AND c.entity IN (".getEntity('category').")";
	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog(__FUNCTION__.' '.$db->lasterror(), LOG_ERR);
		return $tree;
	}
	while ($obj = $db->fetch_object($resql)) {
		$tree[(int) $obj->rowid] = (int) $obj->fk_parent;
	}
	$db->free($resql);
	return $tree;
}

/**
 * Sections de l'écran : une par tag configuré (existant), triées par libellé complet
 *
 * @param	DoliDB	$db		Base de données
 * @return	array			array(id_tag => libellé complet « Parent >> Tag »)
 */
function diamantutils_stocktable_sections($db)
{
	static $sections = null;
	if ($sections !== null) {
		return $sections;
	}
	$sections = array();
	$roots = diamantutils_stocktable_ids(getDolGlobalString('DIAMANTUTILS_STOCKTABLE_CATEGORIES'));
	if (empty($roots)) {
		return $sections;
	}
	require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
	$cat = new Categorie($db);
	$arbo = $cat->get_full_arbo(Categorie::TYPE_PRODUCT);
	if (is_array($arbo)) {
		foreach ($arbo as $c) {
			if (in_array((int) $c['id'], $roots, true)) {
				$sections[(int) $c['id']] = (string) $c['fulllabel'];
			}
		}
	}
	uasort($sections, function ($a, $b) {
		return strnatcasecmp($a, $b);
	});
	return $sections;
}

/**
 * Section (tag configuré) de chaque catégorie du périmètre : le tag configuré le plus proche
 * en remontant l'arbre (une sous-catégorie va dans la section de son tag parent)
 *
 * @param	array	$tree		array(id => id_parent)
 * @param	int[]	$roots		Tags configurés
 * @param	int[]	$catids		Catégories du périmètre
 * @return	array				array(id_categorie => id_tag)
 */
function diamantutils_stocktable_root_map($tree, $roots, $catids)
{
	$map = array();
	$roots = array_flip($roots);
	foreach ($catids as $id) {
		$cur = (int) $id;
		$guard = 0;
		while ($cur > 0 && !isset($roots[$cur]) && $guard++ < 100) {
			$cur = (isset($tree[$cur]) ? (int) $tree[$cur] : 0);
		}
		if ($cur > 0 && isset($roots[$cur])) {
			$map[(int) $id] = $cur;
		}
	}
	return $map;
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


/*
 * Lecture et affichage
 */

/**
 * Données de l'écran, en 4 requêtes quel que soit le nombre de produits :
 *  1. lignes du tableau (avec produit, unité, fournisseur, appartenance au périmètre) ;
 *  2. stock Dolibarr par produit et par lot (hors entrepôts exclus) ;
 *  3. produits du périmètre avec leur stock Dolibarr total (hors entrepôts exclus) ;
 *  4. tags des produits, pour les sections (une section par tag configuré).
 *
 * Groupes rendus : produits du périmètre ayant des lignes ou du stock (total ou par lot) non nul,
 * produits hors périmètre ayant encore des lignes (sans comparaison), et $fk_product s'il est donné.
 *
 * @param	DoliDB	$db				Base de données
 * @param	int[]	$catids			Catégories affichées (filtre de l'écran compris)
 * @param	int		$fk_product		Limiter à ce produit (0 = tous)
 * @return	array|int				array(pid => groupe), trié par réf. produit ; <0 si erreur
 */
function diamantutils_stocktable_load($db, $catids, $fk_product = 0)
{
	$fk_product = (int) $fk_product;
	$tolerance = diamantutils_stocktable_tolerance();
	$exclude = diamantutils_stocktable_excluded_warehouses();
	$scopesql = diamantutils_stocktable_scope_sql(diamantutils_stocktable_category_ids($db));
	$filtersql = diamantutils_stocktable_scope_sql($catids);
	$filtered = ($filtersql !== $scopesql);

	$groups = array();
	$newgroup = function ($pid, $obj, $inscope) {
		return array(
			'id' => (int) $pid,
			'exists' => ($obj->pid !== null),
			'ref' => (string) $obj->ref,
			'label' => (string) $obj->label,
			'tobatch' => ((int) $obj->tobatch > 0),
			'unit' => diamantutils_stock_unit($obj->fk_unit, $obj->unit_type, $obj->unit_scale, $obj->unit_code, $obj->unit_short),
			'inscope' => (bool) $inscope,
			'lines' => array(),
			'doli_lots' => array(),
			'doli_total' => 0.0,
		);
	};

	// 1. Lignes du tableau
	$sql = "SELECT l.rowid, l.fk_product, l.batch, l.fk_soc, l.nb_pieces, l.pieces_per_colis, l.larg_mm, l.long_mm, l.note,";
	$sql .= " p.rowid as pid, p.ref, p.label, p.fk_product_type, p.tobatch, p.fk_unit,";
	$sql .= " u.code as unit_code, u.scale as unit_scale, u.unit_type, u.short_label as unit_short,";
	$sql .= " s.nom as soc_name,";
	$sql .= ($scopesql !== '' ? " (CASE WHEN p.fk_product_type = 0 AND p.rowid IN (".$scopesql.") THEN 1 ELSE 0 END)" : " 0")." as inscope";
	$sql .= " FROM ".MAIN_DB_PREFIX."diamantutils_stockline as l";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = l.fk_product AND p.entity IN (".getEntity('product').")";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."c_units as u ON u.rowid = p.fk_unit";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = l.fk_soc";
	$sql .= " WHERE l.entity IN (".getEntity('diamantutils_stockline').")";
	if ($filtered) {
		// Filtre par tag : seulement les produits de ce tag (pas de ligne « hors périmètre »)
		$sql .= ($filtersql !== '' ? " AND l.fk_product IN (".$filtersql.")" : " AND 1 = 0");
	}
	if ($fk_product > 0) {
		$sql .= " AND l.fk_product = ".$fk_product;
	}
	$sql .= " ORDER BY l.rowid";
	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog(__FUNCTION__.' '.$db->lasterror(), LOG_ERR);
		return -1;
	}
	while ($obj = $db->fetch_object($resql)) {
		$pid = (int) $obj->fk_product;
		if (!isset($groups[$pid])) {
			$groups[$pid] = $newgroup($pid, $obj, !empty($obj->inscope));
		}
		$groups[$pid]['lines'][] = array(
			'id' => (int) $obj->rowid,
			'batch' => (string) $obj->batch,
			'fk_soc' => ($obj->fk_soc > 0 ? (int) $obj->fk_soc : 0),
			'soc_name' => (string) $obj->soc_name,
			'nb_pieces' => (float) $obj->nb_pieces,
			'pieces_per_colis' => (float) $obj->pieces_per_colis,
			'larg_mm' => ($obj->larg_mm === null ? null : (float) $obj->larg_mm),
			'long_mm' => ($obj->long_mm === null ? null : (float) $obj->long_mm),
			'note' => (string) $obj->note,
		);
	}
	$db->free($resql);

	if ($filtersql !== '') {
		$whsql = "SELECT e.rowid FROM ".MAIN_DB_PREFIX."entrepot as e WHERE e.entity IN (".getEntity('stock').")";
		if (!empty($exclude)) {
			$whsql .= " AND e.rowid NOT IN (".implode(',', $exclude).")";
		}

		// 2. Stock Dolibarr par produit et par lot
		$sql = "SELECT ps.fk_product, pb.batch, SUM(pb.qty) as qty";
		$sql .= " FROM ".MAIN_DB_PREFIX."product_batch as pb";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_stock as ps ON ps.rowid = pb.fk_product_stock";
		$sql .= " WHERE ps.fk_product IN (".$filtersql.")";
		$sql .= " AND ps.fk_entrepot IN (".$whsql.")";
		if ($fk_product > 0) {
			$sql .= " AND ps.fk_product = ".$fk_product;
		}
		$sql .= " GROUP BY ps.fk_product, pb.batch";
		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__FUNCTION__.' '.$db->lasterror(), LOG_ERR);
			return -1;
		}
		$doli_lots = array();
		while ($obj = $db->fetch_object($resql)) {
			$doli_lots[(int) $obj->fk_product][(string) $obj->batch] = (float) $obj->qty;
		}
		$db->free($resql);

		// 3. Produits du périmètre et stock Dolibarr total
		$sql = "SELECT p.rowid as pid, p.ref, p.label, p.tobatch, p.fk_unit,";
		$sql .= " u.code as unit_code, u.scale as unit_scale, u.unit_type, u.short_label as unit_short,";
		$sql .= " SUM(ps.reel) as stock";
		$sql .= " FROM ".MAIN_DB_PREFIX."product as p";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."c_units as u ON u.rowid = p.fk_unit";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_stock as ps ON ps.fk_product = p.rowid AND ps.fk_entrepot IN (".$whsql.")";
		$sql .= " WHERE p.rowid IN (".$filtersql.")";
		$sql .= " AND p.fk_product_type = 0";
		$sql .= " AND p.entity IN (".getEntity('product').")";
		if ($fk_product > 0) {
			$sql .= " AND p.rowid = ".$fk_product;
		}
		$sql .= " GROUP BY p.rowid, p.ref, p.label, p.tobatch, p.fk_unit, u.code, u.scale, u.unit_type, u.short_label";
		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__FUNCTION__.' '.$db->lasterror(), LOG_ERR);
			return -1;
		}
		while ($obj = $db->fetch_object($resql)) {
			$pid = (int) $obj->pid;
			$lots = (isset($doli_lots[$pid]) ? $doli_lots[$pid] : array());
			$total = (float) $obj->stock;
			$hasstock = (abs($total) > 1e-6);
			foreach ($lots as $qty) {
				$hasstock = $hasstock || (abs($qty) > 1e-6);
			}
			if (!isset($groups[$pid])) {
				if (!$hasstock && $pid !== $fk_product) {
					continue;
				}
				$groups[$pid] = $newgroup($pid, $obj, true);
			}
			$groups[$pid]['inscope'] = true;
			$groups[$pid]['doli_lots'] = $lots;
			$groups[$pid]['doli_total'] = $total;
		}
		$db->free($resql);
	}

	// 4. Section de chaque produit : premier tag configuré dans l'ordre d'affichage
	// (un produit présent dans plusieurs tags n'apparaît qu'une fois)
	$sections = diamantutils_stocktable_sections($db);
	$order = array_flip(array_keys($sections));
	$allcats = diamantutils_stocktable_category_ids($db);
	$rootmap = diamantutils_stocktable_root_map(diamantutils_stocktable_tree($db), array_keys($sections), $allcats);
	if (!empty($groups) && !empty($allcats)) {
		$sql = "SELECT cp.fk_product, cp.fk_categorie FROM ".MAIN_DB_PREFIX."categorie_product as cp";
		$sql .= " WHERE cp.fk_categorie IN (".implode(',', $allcats).")";
		if ($fk_product > 0) {
			$sql .= " AND cp.fk_product = ".$fk_product;
		}
		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__FUNCTION__.' '.$db->lasterror(), LOG_ERR);
			return -1;
		}
		while ($obj = $db->fetch_object($resql)) {
			$pid = (int) $obj->fk_product;
			$root = (isset($rootmap[(int) $obj->fk_categorie]) ? $rootmap[(int) $obj->fk_categorie] : 0);
			if (!isset($groups[$pid]) || !$root || !$groups[$pid]['inscope']) {
				continue;
			}
			$cur = (isset($groups[$pid]['section']) ? $groups[$pid]['section'] : 0);
			if (!$cur || $order[$root] < $order[$cur]) {
				$groups[$pid]['section'] = $root;
			}
		}
		$db->free($resql);
	}
	foreach ($groups as $pid => $group) {
		if (!isset($group['section'])) {
			$groups[$pid]['section'] = 0;	// hors périmètre
		}
	}

	// Comparaison (produits du périmètre) ; hors périmètre : quantités seules
	foreach ($groups as $pid => $group) {
		if ($group['inscope']) {
			$groups[$pid]['cmp'] = diamantutils_stock_compare($group['unit'], $group['lines'], $group['doli_lots'], $group['doli_total'], $group['tobatch'], $tolerance);
		} else {
			$cmp = diamantutils_stock_compare($group['unit'], $group['lines'], array(), 0, true, $tolerance);
			foreach ($cmp['lots'] as $batch => $lot) {
				$cmp['lots'][$batch]['state'] = DIAMANTUTILS_STOCK_UNKNOWN;
				$cmp['lots'][$batch]['doli'] = null;
				$cmp['lots'][$batch]['diff'] = null;
			}
			$cmp['doli'] = null;
			$cmp['diff'] = null;
			$cmp['state'] = DIAMANTUTILS_STOCK_UNKNOWN;
			$groups[$pid]['cmp'] = $cmp;
		}
	}

	// Tri : sections dans l'ordre des tags, hors périmètre à la fin, puis réf. produit
	uasort($groups, function ($a, $b) use ($order) {
		$sa = ($a['section'] && isset($order[$a['section']]) ? $order[$a['section']] : PHP_INT_MAX);
		$sb = ($b['section'] && isset($order[$b['section']]) ? $order[$b['section']] : PHP_INT_MAX);
		if ($sa !== $sb) {
			return ($sa < $sb ? -1 : 1);
		}
		return strnatcasecmp($a['ref'], $b['ref']);
	});
	return $groups;
}

/**
 * Traduction brute, sans remplacement des %s (formats passés à sprintf ou au JS)
 *
 * @param	string	$key	Clé de langue
 * @return	string
 */
function diamantutils_stocktable_rawtrans($key)
{
	global $langs;
	if (isset($langs->tab_translate[$key])) {
		return (string) $langs->tab_translate[$key];
	}
	return $key;
}

/**
 * Quantité en texte simple (2 décimales) avec unité
 *
 * @param	float	$qty	Quantité
 * @param	array	$unit	Unité
 * @return	string
 */
function diamantutils_stocktable_qty_text($qty, $unit)
{
	return price(round((float) $qty, 2), 0, '', 0, 2, 2).' '.diamantutils_stocktable_unit_label($unit);
}

/**
 * Libellé court d'unité affichable (m2 → m²)
 *
 * @param	array	$unit	Résultat de diamantutils_stock_unit()
 * @return	string
 */
function diamantutils_stocktable_unit_label($unit)
{
	return str_replace(array('m2', 'm3'), array('m²', 'm³'), (string) $unit['short']);
}

/**
 * Quantité affichée (2 décimales) avec unité
 *
 * @param	float|null	$qty		Quantité (null = non calculable)
 * @param	array		$unit		Unité
 * @param	bool		$signed		Afficher le signe +
 * @return	string					HTML
 */
function diamantutils_stocktable_qty_html($qty, $unit, $signed = false)
{
	if ($qty === null) {
		return '<span class="opacitymedium">—</span>';
	}
	$qty = round((float) $qty, 2);
	if ($qty == 0) {
		$qty = 0.0;
	}
	$txt = price($qty, 0, '', 0, 2, 2);
	if ($signed && $qty > 0) {
		$txt = '+'.$txt;
	}
	return '<span class="nowraponall">'.$txt.' <span class="opacitymedium small">'.dol_escape_htmltag(diamantutils_stocktable_unit_label($unit)).'</span></span>';
}

/**
 * Pastille d'état
 *
 * @param	string	$state	DIAMANTUTILS_STOCK_*
 * @return	string			HTML
 */
function diamantutils_stocktable_badge($state)
{
	global $langs;
	return '<span class="dst-badge dst-'.dol_escape_htmltag($state).'">'.$langs->trans('DiamantutilsStockState'.$state).'</span>';
}

/**
 * Nombre de colonnes du tableau
 */
if (!defined('DIAMANTUTILS_STOCKTABLE_NBCOLS')) {
	define('DIAMANTUTILS_STOCKTABLE_NBCOLS', 12);
}

/**
 * Rendu HTML d'un groupe produit (un <tbody>), utilisé par la page et par l'AJAX
 *
 * @param	array	$group		Groupe (diamantutils_stocktable_load())
 * @param	array	$perms		array('write' => bool, 'delete' => bool)
 * @return	string				HTML
 */
function diamantutils_stocktable_render_group($group, $perms)
{
	global $langs;

	$cmp = $group['cmp'];
	$unit = $group['unit'];
	$editable = ($group['inscope'] && !empty($perms['write']));
	$fmt = diamantutils_stocktable_rawtrans('DiamantutilsStockColisFmt');
	$decsep = $langs->transnoentitiesnoconv('SeparatorDecimal');
	if ($decsep === 'SeparatorDecimal' || $decsep === '') {
		$decsep = ',';
	}
	$state = ($group['inscope'] ? $cmp['state'] : 'outofscope');

	// Texte de recherche : produit, lots, fournisseurs
	$search = $group['ref'].' '.$group['label'];
	foreach ($cmp['lots'] as $batch => $lot) {
		$search .= ' '.$batch;
		foreach ($lot['lines'] as $line) {
			$search .= ' '.$line['soc_name'];
		}
	}

	$out = '<tbody class="dst-group" data-pid="'.((int) $group['id']).'" data-state="'.dol_escape_htmltag($state).'"';
	$out .= ' data-ref="'.dol_escape_htmltag($group['ref']).'" data-tobatch="'.($group['tobatch'] ? 1 : 0).'" data-section="'.((int) $group['section']).'"';
	$out .= ' data-search="'.dol_escape_htmltag(dol_strtolower($search)).'">';

	// En-tête du groupe : produit et totaux
	$out .= '<tr class="dst-head">';
	$out .= '<td colspan="6" class="dst-head-product">';
	$out .= '<button type="button" class="dst-toggle" title="'.dol_escape_htmltag($langs->trans('DiamantutilsStockTableToggle')).'" aria-expanded="true"><span class="dst-caret"></span></button>';
	if ($group['exists']) {
		$out .= '<a href="'.DOL_URL_ROOT.'/product/card.php?id='.((int) $group['id']).'">'.img_picto('', 'product', 'class="pictofixedwidth"').'<strong>'.dol_escape_htmltag($group['ref']).'</strong></a>';
		$out .= ' <span class="opacitymedium">'.dol_escape_htmltag($group['label']).'</span>';
	} else {
		$out .= '<strong>'.$langs->trans('DiamantutilsStockTableProductDeleted', (int) $group['id']).'</strong>';
	}
	if (!$group['inscope']) {
		$out .= ' <span class="dst-badge dst-outofscope" title="'.dol_escape_htmltag($langs->trans('DiamantutilsStockTableOutOfScopeHelp')).'">'.$langs->trans('DiamantutilsStockTableOutOfScope').'</span>';
	} elseif (!$unit['ok']) {
		$out .= ' <span class="dst-badge dst-unknown">'.$langs->trans($unit['error'], dol_escape_htmltag($group['ref'])).'</span>';
	}
	if ($group['inscope'] && $group['tobatch'] && abs($cmp['doli_nolot']) > 1e-6) {
		$out .= ' <span class="dst-nolot">'.dol_escape_htmltag($langs->transnoentities('DiamantutilsStockTableNoLotPart', diamantutils_stocktable_qty_text($cmp['doli_nolot'], $unit))).'</span>';
	}
	$out .= '</td>';
	$out .= '<td class="right dst-total">'.diamantutils_stocktable_qty_html($cmp['table'], $unit).'</td>';
	$out .= '<td></td>';
	if ($group['inscope']) {
		$out .= '<td class="right dst-total"><a href="'.DOL_URL_ROOT.'/product/stock/product.php?id='.((int) $group['id']).'" title="'.dol_escape_htmltag($langs->trans('Stock')).'">'.diamantutils_stocktable_qty_html($cmp['doli'], $unit).'</a></td>';
		$out .= '<td class="right dst-total'.($cmp['state'] == DIAMANTUTILS_STOCK_DIFF ? ' dst-diff-txt' : '').'">'.diamantutils_stocktable_qty_html($cmp['diff'], $unit, true).'</td>';
		$out .= '<td class="center">'.diamantutils_stocktable_badge($cmp['state']).'</td>';
	} else {
		$out .= '<td></td><td></td><td></td>';
	}
	$out .= '<td class="right nowraponall">';
	if ($editable && $unit['ok']) {
		$out .= '<button type="button" class="dst-btn dst-add" title="'.dol_escape_htmltag($langs->trans('DiamantutilsStockTableAddLine')).'">+</button>';
	}
	$out .= '</td>';
	$out .= '</tr>';

	// Lignes, regroupées par lot
	foreach ($cmp['lots'] as $batch => $lot) {
		$batch = (string) $batch;
		$batchlabel = ($batch === '' ? '<span class="opacitymedium">'.$langs->trans('DiamantutilsStockTableNoLot').'</span>' : dol_escape_htmltag($batch));
		$nblines = count($lot['lines']);

		// Dolibarr / écart / état du lot : sur la première ligne du lot (cellules vides ensuite,
		// pas de rowspan : une ligne de saisie peut s'insérer entre deux lignes du lot)
		if ($group['inscope']) {
			$lotcells = '<td class="right">'.diamantutils_stocktable_qty_html($lot['doli'], $unit).'</td>';
			$lotcells .= '<td class="right'.($lot['state'] == DIAMANTUTILS_STOCK_DIFF ? ' dst-diff-txt' : '').'">'.($nblines ? diamantutils_stocktable_qty_html($lot['diff'], $unit, true) : '').'</td>';
			$lotcells .= '<td class="center">'.diamantutils_stocktable_badge($lot['state']).'</td>';
		} else {
			$lotcells = '<td></td><td></td><td></td>';
		}
		$contcells = '<td class="dst-cont"></td><td class="dst-cont"></td><td class="dst-cont"></td>';

		// Lot présent seulement dans Dolibarr : ligne grisée « à saisir »
		if (!$nblines) {
			$out .= '<tr class="dst-line dst-toenter" data-batch="'.dol_escape_htmltag($batch).'">';
			$out .= '<td class="dst-lot nowraponall">'.img_picto('', 'lot', 'class="pictofixedwidth"').$batchlabel.'</td>';
			$out .= '<td colspan="7"><span class="opacitymedium">'.($batch === '' ? $langs->trans('DiamantutilsStockTableNoLotStock') : $langs->trans('DiamantutilsStockTableToEnter')).'</span></td>';
			$out .= $lotcells;
			$out .= '<td class="right nowraponall">';
			if ($editable && $unit['ok'] && $batch !== '') {
				$out .= '<button type="button" class="dst-btn dst-addlot" title="'.dol_escape_htmltag($langs->trans('DiamantutilsStockTableAddToTable')).'">'.$langs->trans('DiamantutilsStockTableAddToTable').'</button>';
			}
			$out .= '</td></tr>';
			continue;
		}

		$first = true;
		foreach ($lot['lines'] as $line) {
			$colis = diamantutils_stock_colis($line['nb_pieces'], $line['pieces_per_colis'], $fmt, ' + ', $decsep);
			$out .= '<tr class="dst-line" data-id="'.((int) $line['id']).'" data-batch="'.dol_escape_htmltag($batch).'"';
			$out .= ' data-nb="'.dol_escape_htmltag((string) $line['nb_pieces']).'" data-per="'.dol_escape_htmltag((string) $line['pieces_per_colis']).'"';
			$out .= ' data-larg="'.dol_escape_htmltag((string) $line['larg_mm']).'" data-long="'.dol_escape_htmltag((string) $line['long_mm']).'"';
			$out .= ' data-soc="'.((int) $line['fk_soc']).'" data-note="'.dol_escape_htmltag($line['note']).'">';
			$out .= '<td class="dst-lot nowraponall">'.($first ? img_picto('', 'lot', 'class="pictofixedwidth"').$batchlabel : '<span class="opacitymedium small dst-samelot">'.$batchlabel.'</span>');
			if ($line['note'] !== '') {
				$out .= '<br><span class="opacitymedium small">'.dol_escape_htmltag($line['note']).'</span>';
			}
			$out .= '</td>';
			$out .= '<td class="nowraponall">'.dol_escape_htmltag($colis['text']);
			if ($colis['warn'] === 'partial') {
				$out .= ' '.img_warning($langs->trans('DiamantutilsStockColisPartial'));
			}
			$out .= '</td>';
			$out .= '<td class="right">'.diamantutils_stock_num($line['nb_pieces'], 3, $decsep).'</td>';
			$out .= '<td class="right">'.diamantutils_stock_num($line['pieces_per_colis'], 3, $decsep).'</td>';
			$out .= '<td class="right">'.($line['larg_mm'] === null ? '' : diamantutils_stock_num($line['larg_mm'], 1, $decsep)).'</td>';
			$out .= '<td class="right">'.($line['long_mm'] === null ? '' : diamantutils_stock_num($line['long_mm'], 1, $decsep)).'</td>';
			$out .= '<td class="right">'.diamantutils_stocktable_qty_html($line['qty'], $unit);
			if ($line['qty'] === null && $line['qty_error'] !== '') {
				$out .= ' '.img_warning($langs->trans($line['qty_error'], $group['ref']));
			}
			$out .= '</td>';
			$out .= '<td class="tdoverflowmax150">'.($line['fk_soc'] ? '<a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.((int) $line['fk_soc']).'">'.dol_escape_htmltag($line['soc_name']).'</a>' : '').'</td>';
			$out .= ($first ? $lotcells : $contcells);
			$out .= '<td class="right nowraponall">';
			if ($editable && $unit['ok']) {
				$out .= '<button type="button" class="dst-btn dst-edit" title="'.dol_escape_htmltag($langs->trans('Modify')).'">'.img_edit($langs->trans('Modify'), 0).'</button>';
			}
			if (!empty($perms['delete'])) {
				$out .= '<button type="button" class="dst-btn dst-delete" title="'.dol_escape_htmltag($langs->trans('Delete')).'">'.img_delete($langs->trans('Delete')).'</button>';
			}
			$out .= '</td>';
			$out .= '</tr>';
			$first = false;
		}
	}

	$out .= '</tbody>';
	return $out;
}

/**
 * Rendu HTML d'un en-tête de section (tag configuré, ou « hors périmètre » pour id 0).
 * Les compteurs sont recalculés par le script à chaque filtrage.
 *
 * @param	int		$id			Id du tag (0 = hors périmètre)
 * @param	string	$label		Libellé complet « Parent >> Tag »
 * @param	int		$nb			Nombre de produits
 * @param	int		$nbdiff		Nombre de produits en écart
 * @return	string				HTML
 */
function diamantutils_stocktable_render_section($id, $label, $nb, $nbdiff)
{
	global $langs;

	$parts = explode(' >> ', (string) $label);
	$last = array_pop($parts);
	$out = '<tbody class="dst-section" data-section="'.((int) $id).'">';
	$out .= '<tr class="dst-section-head"><td colspan="'.DIAMANTUTILS_STOCKTABLE_NBCOLS.'">';
	$out .= '<button type="button" class="dst-section-toggle" title="'.dol_escape_htmltag($langs->trans('DiamantutilsStockTableToggle')).'" aria-expanded="true"><span class="dst-caret"></span></button>';
	$out .= img_picto('', 'category', 'class="pictofixedwidth"');
	if (!empty($parts)) {
		$out .= '<span class="dst-section-path">'.dol_escape_htmltag(implode(' » ', $parts)).' » </span>';
	}
	$out .= '<span class="dst-section-label">'.dol_escape_htmltag($last).'</span>';
	$out .= ' <span class="dst-section-count opacitymedium">'.$langs->trans('DiamantutilsStockTableProducts', (int) $nb, (int) $nbdiff).'</span>';
	$out .= '</td></tr></tbody>';
	return $out;
}
