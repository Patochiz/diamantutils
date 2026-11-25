<?php
/**
 * Tableau de stock par lots — fonctions communes (v2.13)
 *
 * Vue des données Dolibarr seules, par produit des tags configurés : lots en stock, colisage
 * (attribut du lot), pièces par colis et qté par pièce (attributs du lot), quantité réelle.
 * Aucune saisie propre au tableau, aucun mouvement de stock. Les attributs du lot se modifient
 * en ligne par ajax/lotcolisage.php (même mécanisme et mêmes droits que l'onglet Stock).
 *
 * Quantité d'un lot : stock réel total, hors entrepôts ignorés du colisage
 * (DIAMANTUTILS_STOCKCOLISAGE_EXCLUDE_WAREHOUSES) : c'est la quantité sur laquelle le colisage
 * enregistré est calculé. Le stock des entrepôts ignorés est affiché sur une ligne à part.
 *
 * Par produit (v2.14) : stock physique (tous entrepôts), commandes client en cours (commandes
 * validées ou en cours, moins les quantités des expéditions validées), commandes fournisseur
 * en cours (commandes approuvées ou commandées, moins les quantités réceptionnées) et stock
 * virtuel = physique − client + fournisseur.
 *
 * Les lignes saisies avant la v2.13 (llx_diamantutils_stockline) sont conservées en base, mais
 * ne sont plus lues ni modifiées.
 */

/** Nombre de colonnes du tableau */
if (!defined('DIAMANTUTILS_STOCKTABLE_NBCOLS')) {
	define('DIAMANTUTILS_STOCKTABLE_NBCOLS', 8);
}

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
 * Condition SQL excluant les produits parents de variantes (produits ayant au moins une variante)
 *
 * @param	string	$field	Colonne de l'id produit (« p.rowid »)
 * @return	string			« AND NOT EXISTS (...) », ou '' si le module Variantes est inactif
 */
function diamantutils_stocktable_noparent_sql($field)
{
	if (!isModEnabled('variants')) {
		return '';
	}
	return " AND NOT EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."product_attribute_combination as pac WHERE pac.fk_product_parent = ".$field.")";
}

/**
 * Données de l'écran : produits du périmètre ayant du stock ou des commandes en cours (hors
 * produits parents de variantes), leurs
 * lots en stock et les attributs de ces lots. 7 requêtes quel que soit le nombre de produits.
 *
 * @param	DoliDB	$db			Base de données
 * @param	int[]	$catids		Catégories affichées (filtre par tag ou tout le périmètre)
 * @return	array|int			array(pid => groupe) trié par section puis réf., -1 si erreur SQL
 */
function diamantutils_stocktable_load($db, $catids)
{
	dol_include_once('/diamantutils/lib/stockcolisage.lib.php');

	$groups = array();
	$filtersql = diamantutils_stocktable_scope_sql($catids);
	if ($filtersql === '') {
		return $groups;
	}
	$whsql = "SELECT e.rowid FROM ".MAIN_DB_PREFIX."entrepot as e WHERE e.entity IN (".getEntity('stock').")";
	$exclude = diamantutils_stockcolisage_excluded_warehouses();
	if (!empty($exclude)) {
		$whsql .= " AND e.rowid NOT IN (".implode(',', array_map('intval', $exclude)).")";
	}

	// 1. Produits du périmètre : stock physique (tous entrepôts) et stock hors entrepôts ignorés
	$allwhsql = "SELECT e.rowid FROM ".MAIN_DB_PREFIX."entrepot as e WHERE e.entity IN (".getEntity('stock').")";
	$sql = "SELECT p.rowid as pid, p.ref, p.label, p.tobatch, u.short_label as unit_short,";
	$sql .= " SUM(ps.reel) as stock_all,";
	$sql .= " SUM(CASE WHEN ps.fk_entrepot IN (".$whsql.") THEN ps.reel ELSE 0 END) as stock";
	$sql .= " FROM ".MAIN_DB_PREFIX."product as p";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."c_units as u ON u.rowid = p.fk_unit";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_stock as ps ON ps.fk_product = p.rowid AND ps.fk_entrepot IN (".$allwhsql.")";
	$sql .= " WHERE p.rowid IN (".$filtersql.")";
	$sql .= " AND p.fk_product_type = 0";
	$sql .= " AND p.entity IN (".getEntity('product').")";
	$sql .= diamantutils_stocktable_noparent_sql('p.rowid');
	$sql .= " GROUP BY p.rowid, p.ref, p.label, p.tobatch, u.short_label";
	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog(__FUNCTION__.' '.$db->lasterror(), LOG_ERR);
		return -1;
	}
	while ($obj = $db->fetch_object($resql)) {
		$groups[(int) $obj->pid] = array(
			'id' => (int) $obj->pid,
			'ref' => (string) $obj->ref,
			'label' => (string) $obj->label,
			'tobatch' => ((int) $obj->tobatch > 0),
			'unit' => (string) $obj->unit_short,
			'total' => (float) $obj->stock,
			'physical' => (float) $obj->stock_all,
			'customer' => 0.0,
			'supplier' => 0.0,
			'lots' => array(),
			'section' => 0,
		);
	}
	$db->free($resql);

	// 2. Lots en stock (hors entrepôts ignorés), avec leur fiche lot éventuelle
	$sql = "SELECT ps.fk_product, pb.batch, SUM(pb.qty) as qty, MIN(pl.rowid) as lotid";
	$sql .= " FROM ".MAIN_DB_PREFIX."product_batch as pb";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_stock as ps ON ps.rowid = pb.fk_product_stock";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_lot as pl ON pl.fk_product = ps.fk_product AND pl.batch = pb.batch AND pl.entity IN (".getEntity('productlot').")";
	$sql .= " WHERE ps.fk_product IN (".$filtersql.")";
	$sql .= " AND ps.fk_entrepot IN (".$whsql.")";
	$sql .= " GROUP BY ps.fk_product, pb.batch";
	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog(__FUNCTION__.' '.$db->lasterror(), LOG_ERR);
		return -1;
	}
	$lotids = array();
	while ($obj = $db->fetch_object($resql)) {
		$pid = (int) $obj->fk_product;
		if (!isset($groups[$pid]) || abs((float) $obj->qty) < 1e-9) {
			continue;
		}
		$groups[$pid]['lots'][(string) $obj->batch] = array(
			'batch' => (string) $obj->batch,
			'qty' => (float) $obj->qty,
			'lotid' => (int) $obj->lotid,
		);
		if ((int) $obj->lotid > 0) {
			$lotids[] = (int) $obj->lotid;
		}
	}
	$db->free($resql);

	// 3. Commandes en cours : client (validées ou en cours, moins expédié par des expéditions
	// validées) et fournisseur (approuvées ou commandées, moins réceptionné)
	if (!empty($groups)) {
		$pids = implode(',', array_keys($groups));
		$queries = array(
			'customer' => "SELECT cd.fk_product, SUM(cd.qty) as qty FROM ".MAIN_DB_PREFIX."commandedet as cd"
				." INNER JOIN ".MAIN_DB_PREFIX."commande as c ON c.rowid = cd.fk_commande"
				." WHERE cd.fk_product IN (".$pids.") AND c.fk_statut IN (1, 2) AND c.entity IN (".getEntity('commande').")"
				." GROUP BY cd.fk_product",
			'shipped' => "SELECT cd.fk_product, SUM(ed.qty) as qty FROM ".MAIN_DB_PREFIX."expeditiondet as ed"
				." INNER JOIN ".MAIN_DB_PREFIX."expedition as e ON e.rowid = ed.fk_expedition"
				." INNER JOIN ".MAIN_DB_PREFIX."commandedet as cd ON cd.rowid = ed.fk_elementdet"
				." INNER JOIN ".MAIN_DB_PREFIX."commande as c ON c.rowid = cd.fk_commande"
				." WHERE cd.fk_product IN (".$pids.") AND c.fk_statut IN (1, 2) AND e.fk_statut >= 1 AND c.entity IN (".getEntity('commande').")"
				." GROUP BY cd.fk_product",
			'supplier' => "SELECT cfd.fk_product, SUM(cfd.qty - COALESCE(rec.qty, 0)) as qty FROM ".MAIN_DB_PREFIX."commande_fournisseurdet as cfd"
				." INNER JOIN ".MAIN_DB_PREFIX."commande_fournisseur as cf ON cf.rowid = cfd.fk_commande"
				." LEFT JOIN (SELECT rdb.fk_elementdet, SUM(rdb.qty) as qty FROM ".MAIN_DB_PREFIX."receptiondet_batch as rdb"
				." INNER JOIN ".MAIN_DB_PREFIX."reception as r ON r.rowid = rdb.fk_reception GROUP BY rdb.fk_elementdet) as rec ON rec.fk_elementdet = cfd.rowid"
				." WHERE cfd.fk_product IN (".$pids.") AND cf.fk_statut IN (3, 4) AND cf.entity IN (".getEntity('supplier_order').")"
				." GROUP BY cfd.fk_product",
		);
		foreach ($queries as $key => $sql) {
			$resql = $db->query($sql);
			if (!$resql) {
				dol_syslog(__FUNCTION__.' '.$db->lasterror(), LOG_ERR);
				return -1;
			}
			while ($obj = $db->fetch_object($resql)) {
				$pid = (int) $obj->fk_product;
				if (!isset($groups[$pid])) {
					continue;
				}
				if ($key == 'shipped') {
					$groups[$pid]['customer'] -= (float) $obj->qty;
				} else {
					$groups[$pid][$key] += (float) $obj->qty;
				}
			}
			$db->free($resql);
		}
	}

	// Produits sans stock ni commande en cours : non affichés
	foreach ($groups as $pid => $group) {
		if (abs($group['physical']) < 1e-9 && empty($group['lots']) && abs($group['customer']) < 1e-9 && abs($group['supplier']) < 1e-9) {
			unset($groups[$pid]);
		} else {
			uksort($groups[$pid]['lots'], 'strnatcasecmp');
		}
	}

	// 4. Attributs des lots (colisage, pièces par colis, qté par pièce)
	try {
		$attrs = diamantutils_stockcolisage_lot_attributes($db, $lotids);
	} catch (Throwable $e) {
		dol_syslog(__FUNCTION__.' '.$e->getMessage(), LOG_ERR);
		return -1;
	}
	foreach ($groups as $pid => $group) {
		foreach ($group['lots'] as $batch => $lot) {
			$groups[$pid]['lots'][$batch]['attr'] = (isset($attrs[$lot['lotid']]) ? $attrs[$lot['lotid']] : array('colisage' => null, 'ppc' => null, 'qpp' => null));
		}
	}

	// 5. Section de chaque produit : premier tag configuré dans l'ordre d'affichage
	$sections = diamantutils_stocktable_sections($db);
	$order = array_flip(array_keys($sections));
	$allcats = diamantutils_stocktable_category_ids($db);
	$rootmap = diamantutils_stocktable_root_map(diamantutils_stocktable_tree($db), array_keys($sections), $allcats);
	if (!empty($groups) && !empty($allcats)) {
		$sql = "SELECT cp.fk_product, cp.fk_categorie FROM ".MAIN_DB_PREFIX."categorie_product as cp";
		$sql .= " WHERE cp.fk_categorie IN (".implode(',', $allcats).")";
		$sql .= " AND cp.fk_product IN (".implode(',', array_keys($groups)).")";
		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__FUNCTION__.' '.$db->lasterror(), LOG_ERR);
			return -1;
		}
		while ($obj = $db->fetch_object($resql)) {
			$pid = (int) $obj->fk_product;
			$root = (isset($rootmap[(int) $obj->fk_categorie]) ? $rootmap[(int) $obj->fk_categorie] : 0);
			if (!$root || !isset($groups[$pid])) {
				continue;
			}
			$cur = $groups[$pid]['section'];
			if (!$cur || $order[$root] < $order[$cur]) {
				$groups[$pid]['section'] = $root;
			}
		}
		$db->free($resql);
	}

	// Tri : sections dans l'ordre des tags, puis réf. produit
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
 * Quantité affichée (arrondi stock) avec l'unité courte du produit
 *
 * @param	float	$qty	Quantité
 * @param	string	$unit	Unité courte
 * @return	string			HTML
 */
function diamantutils_stocktable_qty_html($qty, $unit)
{
	$txt = price2num($qty, 'MS');
	return dol_escape_htmltag($txt.($unit !== '' ? ' '.$unit : '')).((float) $qty < 0 ? ' '.img_warning() : '');
}

/**
 * Quantité d'une colonne de commandes : vide si nulle, lien vers les commandes du produit
 *
 * @param	float	$qty	Quantité
 * @param	string	$unit	Unité courte
 * @param	string	$url	Lien (commandes du produit)
 * @return	string			HTML
 */
function diamantutils_stocktable_order_html($qty, $unit, $url)
{
	if (abs($qty) < 1e-9) {
		return '';
	}
	return '<a href="'.$url.'">'.diamantutils_stocktable_qty_html($qty, $unit).'</a>';
}

/**
 * Rendu HTML d'un groupe produit (un <tbody>)
 *
 * @param	array	$group		Groupe (diamantutils_stocktable_load())
 * @param	array	$perms		array('colisage' => bool, 'num' => bool, 'auto' => bool, 'excluded' => libellé des entrepôts ignorés)
 * @return	string				HTML
 */
function diamantutils_stocktable_render_group($group, $perms)
{
	global $langs;

	$search = $group['ref'].' '.$group['label'];
	foreach ($group['lots'] as $lot) {
		$search .= ' '.$lot['batch'].' '.diamantutils_stockcolisage_text($lot['attr']['colisage']);
	}

	$out = '<tbody class="dst-group" data-pid="'.((int) $group['id']).'" data-product="'.((int) $group['id']).'" data-section="'.((int) $group['section']).'"';
	$out .= ' data-search="'.dol_escape_htmltag(dol_strtolower($search)).'">';

	// En-tête : produit, stock physique, commandes en cours, stock virtuel
	$virtual = $group['physical'] - $group['customer'] + $group['supplier'];
	$out .= '<tr class="dst-head">';
	$out .= '<td colspan="'.(DIAMANTUTILS_STOCKTABLE_NBCOLS - 4).'" class="dst-head-product">';
	$out .= '<button type="button" class="dst-toggle" title="'.dol_escape_htmltag($langs->trans('DiamantutilsStockTableToggle')).'" aria-expanded="true"><span class="dst-caret"></span></button>';
	$out .= '<a href="'.DOL_URL_ROOT.'/product/card.php?id='.((int) $group['id']).'">'.img_picto('', 'product', 'class="pictofixedwidth"').'<strong>'.dol_escape_htmltag($group['ref']).'</strong></a>';
	$out .= ' <span class="opacitymedium">'.dol_escape_htmltag($group['label']).'</span>';
	$out .= '</td>';
	$out .= '<td class="right dst-total nowraponall"><a href="'.DOL_URL_ROOT.'/product/stock/product.php?id='.((int) $group['id']).'" title="'.dol_escape_htmltag($langs->trans('Stock')).'">'.diamantutils_stocktable_qty_html($group['physical'], $group['unit']).'</a></td>';
	$out .= '<td class="right dst-total nowraponall">'.diamantutils_stocktable_order_html($group['customer'], $group['unit'], DOL_URL_ROOT.'/product/stats/commande.php?id='.((int) $group['id'])).'</td>';
	$out .= '<td class="right dst-total nowraponall">'.diamantutils_stocktable_order_html($group['supplier'], $group['unit'], DOL_URL_ROOT.'/product/stats/commande_fournisseur.php?id='.((int) $group['id'])).'</td>';
	$out .= '<td class="right dst-total nowraponall">'.diamantutils_stocktable_qty_html($virtual, $group['unit']).'</td>';
	$out .= '</tr>';
	$empty3 = '<td></td><td></td><td></td>';

	// Lots en stock
	$sumlots = 0.0;
	foreach ($group['lots'] as $lot) {
		$sumlots += $lot['qty'];
		$attr = $lot['attr'];
		$out .= '<tr class="dst-line">';
		$out .= '<td class="dst-lot nowraponall">';
		if ($lot['lotid'] > 0) {
			$out .= '<a href="'.DOL_URL_ROOT.'/product/stock/productlot_card.php?id='.((int) $lot['lotid']).'">'.img_picto('', 'lot', 'class="pictofixedwidth"').dol_escape_htmltag($lot['batch']).'</a>';
		} else {
			$out .= img_picto('', 'lot', 'class="pictofixedwidth"').dol_escape_htmltag($lot['batch']);
		}
		$out .= '</td>';
		// Colisage : calculé sur la quantité du lot (celle de la valeur enregistrée) ou saisi à la main
		$calc = ($perms['auto'] ? diamantutils_colisage_compute($lot['qty'], $attr['qpp'], $attr['ppc']) : array('mode' => 'manual'));
		if ($calc['mode'] == 'auto') {
			$out .= diamantutils_stockcolisage_auto_cell($calc, $lot['batch'], 0);
		} else {
			$out .= diamantutils_stockcolisage_cell($attr['colisage'], $lot['batch'], $perms['colisage'], 0);
		}
		$numtitle = ($perms['auto'] && $calc['mode'] != 'auto' ? diamantutils_stockcolisage_num_title($attr['colisage']) : '');
		$out .= diamantutils_stockcolisage_num_cell(DIAMANTUTILS_STOCKCOLISAGE_PPC, $attr['ppc'], $lot['batch'], 0, $perms['num'], $numtitle);
		$out .= diamantutils_stockcolisage_num_cell(DIAMANTUTILS_STOCKCOLISAGE_QPP, $attr['qpp'], $lot['batch'], 0, $perms['num'], $numtitle);
		$out .= '<td class="right nowraponall">'.diamantutils_stocktable_qty_html($lot['qty'], $group['unit']).'</td>';
		$out .= $empty3;
		$out .= '</tr>';
	}

	// Stock non rattaché à un lot (produit sans lots, ou part sans lot)
	$nolot = $group['total'] - $sumlots;
	if (abs($nolot) > 1e-6) {
		$out .= '<tr class="dst-line dst-nolotline">';
		$out .= '<td colspan="'.(DIAMANTUTILS_STOCKTABLE_NBCOLS - 4).'"><span class="opacitymedium">'.$langs->trans($group['tobatch'] ? 'DiamantutilsStockTableNoLotPartLine' : 'DiamantutilsStockTableNoLotProduct').'</span></td>';
		$out .= '<td class="right nowraponall">'.diamantutils_stocktable_qty_html($nolot, $group['unit']).'</td>';
		$out .= $empty3;
		$out .= '</tr>';
	}

	// Stock des entrepôts ignorés : compris dans le stock physique, pas dans les lots affichés
	$ignored = $group['physical'] - $group['total'];
	if (abs($ignored) > 1e-6) {
		$out .= '<tr class="dst-line dst-nolotline">';
		$out .= '<td colspan="'.(DIAMANTUTILS_STOCKTABLE_NBCOLS - 4).'"><span class="opacitymedium">'.dol_escape_htmltag($langs->transnoentities('DiamantutilsStockTableIgnoredLine', (string) $perms['excluded'])).'</span></td>';
		$out .= '<td class="right nowraponall">'.diamantutils_stocktable_qty_html($ignored, $group['unit']).'</td>';
		$out .= $empty3;
		$out .= '</tr>';
	}

	$out .= '</tbody>';
	return $out;
}

/**
 * Rendu HTML d'un en-tête de section (tag configuré). Le compteur est recalculé par le script
 * à chaque filtrage.
 *
 * @param	int		$id			Id du tag
 * @param	string	$label		Libellé complet « Parent >> Tag »
 * @param	int		$nb			Nombre de produits
 * @return	string				HTML
 */
function diamantutils_stocktable_render_section($id, $label, $nb)
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
	$out .= ' <span class="dst-section-count opacitymedium">('.$langs->trans('DiamantutilsStockTableNbProducts', (int) $nb).')</span>';
	$out .= '</td></tr></tbody>';
	return $out;
}

/**
 * Traduction brute, sans remplacement des %s (formats passés au JS)
 *
 * @param	string	$key	Clé de langue
 * @return	string
 */
function diamantutils_stocktable_rawtrans($key)
{
	global $langs;
	// transnoentitiesnoconv() sans argument remplace les %s par '' : lecture directe
	if (isset($langs->tab_translate[$key])) {
		return (string) $langs->tab_translate[$key];
	}
	return $key;
}
