<?php
/**
 * Fonctions utilitaires du module DiamantUtils
 *
 * - calcul quantité ↔ pièces × longueur selon l'unité du produit ;
 * - lecture de la longueur d'un lot à partir de son nom ;
 * - lecture du stock des lots d'un produit dans un entrepôt ;
 * - onglets de la fiche transformation.
 */

/**
 * Extrait la longueur (mm) d'un nom de lot : premier entier trouvé dans le nom.
 * Les lots existants sont incohérents (« Longueur 3000 » / « Longueur 3000mm »),
 * on accepte donc les deux formes.
 *
 * @param	string		$batch	Nom du lot
 * @return	int|null			Longueur en mm, ou null si aucun entier
 */
function diamantutils_lot_length($batch)
{
	if (preg_match('/(\d+)/', (string) $batch, $reg)) {
		$length = (int) $reg[1];
		return ($length > 0 ? $length : null);
	}
	return null;
}

/**
 * Affiche une quantité de stock (arrondi stock, sans zéros superflus)
 *
 * @param	float	$qty	Quantité
 * @return	string
 */
function diamantutils_qty_format($qty)
{
	return price(price2num((float) $qty, 'MS'), 0, '', 0, 0);
}

/**
 * Nom de lot proposé pour une longueur donnée (constante DIAMANTUTILS_TRANSFO_LOT_FORMAT)
 *
 * @param	float	$length_mm	Longueur en mm
 * @return	string				Nom de lot
 */
function diamantutils_lot_name($length_mm)
{
	$format = getDolGlobalString('DIAMANTUTILS_TRANSFO_LOT_FORMAT', 'Longueur %dmm');
	if (substr_count($format, '%d') != 1) {
		$format = 'Longueur %dmm';
	}
	return sprintf($format, (int) round((float) $length_mm));
}

/**
 * Informations d'un produit utiles à la transformation : unité, largeur, lot, PMP
 * et mode de saisie.
 *
 * Modes de saisie :
 * - 'surface' : unité de surface + largeur renseignée → nb × longueur mm ;
 * - 'size'    : unité de longueur → nb × longueur mm ;
 * - 'qty'     : unité de type quantité (pièce…) → nb pièces ;
 * - 'direct'  : saisie directe de la quantité.
 *
 * 'factor' = quantité (dans l'unité du produit) d'une pièce de 1 mm, pour les modes
 * 'surface' et 'size'. Quantité d'une ligne = nb × longueur × factor.
 *
 * @param	DoliDB	$db				Base de données
 * @param	int		$fk_product		Id produit
 * @return	array|null				Informations, ou null si produit introuvable
 */
function diamantutils_product_info($db, $fk_product)
{
	static $cache = array();

	$fk_product = (int) $fk_product;
	if ($fk_product <= 0) {
		return null;
	}
	if (array_key_exists($fk_product, $cache)) {
		return $cache[$fk_product];
	}

	$sql = "SELECT p.rowid, p.ref, p.label, p.fk_unit, p.width, p.width_units, p.tobatch, p.pmp, p.fk_product_type,";
	$sql .= " u.code as unit_code, u.scale as unit_scale, u.unit_type, u.short_label as unit_short_label, u.label as unit_label";
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
		$cache[$fk_product] = null;
		return null;
	}

	$info = array(
		'id' => (int) $obj->rowid,
		'ref' => $obj->ref,
		'label' => $obj->label,
		'fk_unit' => (int) $obj->fk_unit,
		'unit_code' => (string) $obj->unit_code,
		'unit_type' => (string) $obj->unit_type,
		'unit_scale' => ($obj->unit_scale === null ? null : (int) $obj->unit_scale),
		'unit_short' => (string) $obj->unit_short_label,
		'width_m' => null,
		'status_batch' => (int) $obj->tobatch,
		'pmp' => (float) $obj->pmp,
		'mode' => 'direct',
		'factor' => null,
		'nowidth' => false,
	);

	// Largeur utile en mètres (champs natifs width + width_units, scale -3 = mm, -2 = cm, 0 = m)
	$width = (float) $obj->width;
	$width_units = $obj->width_units;
	if ($width > 0 && $width_units !== null && $width_units !== '' && in_array((int) $width_units, array(-3, -2, -1, 0), true)) {
		$info['width_m'] = $width * pow(10, (int) $width_units);
	}

	// Bases migrées : unit_type parfois vide, on le déduit du code de l'unité
	if ($info['unit_type'] === '' && $info['unit_code'] !== '') {
		$bycode = array(
			'M2' => array('surface', 0), 'DM2' => array('surface', -2), 'CM2' => array('surface', -4), 'MM2' => array('surface', -6),
			'M' => array('size', 0), 'DM' => array('size', -1), 'CM' => array('size', -2), 'MM' => array('size', -3),
			'P' => array('qty', 0), 'SET' => array('qty', 0),
		);
		$code = strtoupper($info['unit_code']);
		if (isset($bycode[$code])) {
			$info['unit_type'] = $bycode[$code][0];
			if ($info['unit_scale'] === null) {
				$info['unit_scale'] = $bycode[$code][1];
			}
		}
	}

	// Échelles 88 à 99 = unités impériales, non gérées en saisie profilé
	$scale = $info['unit_scale'];
	$scaleok = ($scale !== null && $scale < 80);

	if ($info['unit_type'] == 'surface' && $scaleok) {
		if ($info['width_m'] > 0) {
			// m² d'une pièce de 1 mm, converti dans l'unité du produit (m², dm²…)
			$info['mode'] = 'surface';
			$info['factor'] = (0.001 * $info['width_m']) / pow(10, $scale);
		} else {
			$info['nowidth'] = true;
		}
	} elseif ($info['unit_type'] == 'size' && $scaleok) {
		$info['mode'] = 'size';
		$info['factor'] = 0.001 / pow(10, $scale);
	} elseif ($info['unit_type'] == 'qty') {
		$info['mode'] = 'qty';
	}

	$cache[$fk_product] = $info;
	return $info;
}

/**
 * Indique si le mode de saisie est « profilé » (pièces × longueur)
 *
 * @param	array|null	$info	Résultat de diamantutils_product_info()
 * @return	bool
 */
function diamantutils_is_profile($info)
{
	return (!empty($info) && in_array($info['mode'], array('surface', 'size')) && $info['factor'] > 0);
}

/**
 * Quantité d'une pièce de longueur donnée, dans l'unité du produit
 *
 * @param	array	$info		Résultat de diamantutils_product_info()
 * @param	float	$length_mm	Longueur en mm
 * @return	float|null			Quantité, ou null si non calculable
 */
function diamantutils_piece_qty($info, $length_mm)
{
	if (!diamantutils_is_profile($info) || !($length_mm > 0)) {
		return null;
	}
	return (float) $length_mm * $info['factor'];
}

/**
 * Calcule la quantité d'une ligne (le serveur fait foi).
 *
 * @param	array	$info		Résultat de diamantutils_product_info()
 * @param	float	$nb_pieces	Nombre de pièces (ou null)
 * @param	float	$length_mm	Longueur mm (ou null)
 * @param	float	$qty		Quantité saisie directement (ou null)
 * @return	array				array('qty' => float, 'nb_pieces' => float|null, 'length_mm' => float|null)
 */
function diamantutils_compute_line($info, $nb_pieces, $length_mm, $qty)
{
	$nb_pieces = (float) $nb_pieces;
	$length_mm = (float) $length_mm;

	if (diamantutils_is_profile($info) && $nb_pieces > 0 && $length_mm > 0) {
		return array(
			'qty' => (float) price2num($nb_pieces * $length_mm * $info['factor'], 'MS'),
			'nb_pieces' => $nb_pieces,
			'length_mm' => $length_mm,
		);
	}
	if (!empty($info) && $info['mode'] == 'qty' && $nb_pieces > 0) {
		return array(
			'qty' => (float) price2num($nb_pieces, 'MS'),
			'nb_pieces' => $nb_pieces,
			'length_mm' => null,
		);
	}
	return array(
		'qty' => (float) price2num((float) $qty, 'MS'),
		'nb_pieces' => null,
		'length_mm' => null,
	);
}

/**
 * Nombre de pièces équivalent à une quantité de lot
 *
 * @param	array	$info		Résultat de diamantutils_product_info()
 * @param	string	$batch		Nom du lot
 * @param	float	$qty		Quantité du lot
 * @return	array				array('pieces' => float|null, 'length' => int|null, 'multiple' => bool)
 */
function diamantutils_lot_pieces($info, $batch, $qty)
{
	$res = array('pieces' => null, 'length' => null, 'multiple' => true);
	if (empty($info)) {
		return $res;
	}
	if ($info['mode'] == 'qty') {
		$res['pieces'] = (float) $qty;
	} elseif (diamantutils_is_profile($info)) {
		$length = diamantutils_lot_length($batch);
		$res['length'] = $length;
		$pieceqty = diamantutils_piece_qty($info, $length);
		if ($pieceqty > 0) {
			$res['pieces'] = (float) $qty / $pieceqty;
		}
	}
	if ($res['pieces'] !== null) {
		$res['multiple'] = (abs($res['pieces'] - round($res['pieces'])) <= 0.01);
	}
	return $res;
}

/**
 * Lots disponibles (qty > 0) d'un produit dans un entrepôt
 *
 * @param	DoliDB	$db				Base de données
 * @param	int		$fk_product		Id produit
 * @param	int		$fk_warehouse	Id entrepôt
 * @return	array					Liste de array('batch', 'qty', 'pieces', 'length', 'multiple')
 */
function diamantutils_product_lots($db, $fk_product, $fk_warehouse)
{
	$lots = array();
	$info = diamantutils_product_info($db, $fk_product);
	if (empty($info)) {
		return $lots;
	}

	$sql = "SELECT pb.batch, SUM(pb.qty) as qty";
	$sql .= " FROM ".MAIN_DB_PREFIX."product_batch as pb";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_stock as ps ON ps.rowid = pb.fk_product_stock";
	$sql .= " WHERE ps.fk_product = ".((int) $fk_product);
	$sql .= " AND ps.fk_entrepot = ".((int) $fk_warehouse);
	$sql .= " GROUP BY pb.batch";
	$sql .= " HAVING SUM(pb.qty) > 0";
	$sql .= " ORDER BY pb.batch";

	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog(__FUNCTION__.' '.$db->lasterror(), LOG_ERR);
		return $lots;
	}
	while ($obj = $db->fetch_object($resql)) {
		$qty = (float) price2num($obj->qty, 'MS');
		$pieces = diamantutils_lot_pieces($info, $obj->batch, $qty);
		$lots[] = array(
			'batch' => $obj->batch,
			'qty' => $qty,
			'pieces' => ($pieces['pieces'] === null ? null : (float) price2num($pieces['pieces'], 2)),
			'length' => $pieces['length'],
			'multiple' => $pieces['multiple'],
		);
	}
	$db->free($resql);

	// Tri par longueur croissante quand elle est connue (chutes d'abord)
	usort($lots, function ($a, $b) {
		if ($a['length'] === null || $b['length'] === null) {
			return strcmp((string) $a['batch'], (string) $b['batch']);
		}
		return $a['length'] <=> $b['length'];
	});

	return $lots;
}

/**
 * Stock réel d'un couple (produit, entrepôt, lot). Sans lot : stock de l'entrepôt.
 *
 * @param	DoliDB	$db				Base de données
 * @param	int		$fk_product		Id produit
 * @param	int		$fk_warehouse	Id entrepôt
 * @param	string	$batch			Lot ('' pour un produit sans lot)
 * @return	float					Quantité en stock
 */
function diamantutils_stock_qty($db, $fk_product, $fk_warehouse, $batch = '')
{
	if ((string) $batch !== '') {
		$sql = "SELECT SUM(pb.qty) as qty";
		$sql .= " FROM ".MAIN_DB_PREFIX."product_batch as pb";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_stock as ps ON ps.rowid = pb.fk_product_stock";
		$sql .= " WHERE ps.fk_product = ".((int) $fk_product);
		$sql .= " AND ps.fk_entrepot = ".((int) $fk_warehouse);
		$sql .= " AND pb.batch = '".$db->escape($batch)."'";
	} else {
		$sql = "SELECT SUM(ps.reel) as qty";
		$sql .= " FROM ".MAIN_DB_PREFIX."product_stock as ps";
		$sql .= " WHERE ps.fk_product = ".((int) $fk_product);
		$sql .= " AND ps.fk_entrepot = ".((int) $fk_warehouse);
	}

	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog(__FUNCTION__.' '.$db->lasterror(), LOG_ERR);
		return 0;
	}
	$obj = $db->fetch_object($resql);
	$db->free($resql);
	return ($obj ? (float) $obj->qty : 0);
}

/**
 * Onglets de la fiche transformation
 *
 * @param	Transformation	$object		Transformation
 * @return	array						Onglets
 */
function diamantutils_transfo_prepare_head($object)
{
	global $langs;

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath('/diamantutils/transformation_card.php', 1).'?id='.((int) $object->id);
	$head[$h][1] = $langs->trans('DiamantutilsTransformation');
	$head[$h][2] = 'card';
	$h++;

	if ($object->status > 0) {
		$head[$h][0] = DOL_URL_ROOT.'/product/stock/movement_list.php?search_inventorycode='.urlencode($object->ref);
		$head[$h][1] = $langs->trans('DiamantutilsStockMovements');
		$head[$h][2] = 'movements';
		$h++;
	}

	return $head;
}
