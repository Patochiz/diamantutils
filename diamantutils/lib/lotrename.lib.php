<?php
/**
 * Renommage d'un lot / numéro de série (fiche lot, product/stock/productlot_card.php)
 *
 * Dans Dolibarr, le numéro de lot n'est pas une clé : il est recopié en texte dans chaque table
 * qui le cite. Renommer un lot = remplacer l'ancien numéro par le nouveau, pour ce produit,
 * dans toutes ces tables, dans une seule transaction (tout ou rien) :
 *  - fiche lot (llx_product_lot) et stock par entrepôt (llx_product_batch) ;
 *  - historique : mouvements de stock, lignes de lot des expéditions et des réceptions, lignes de
 *    facture (point de vente), inventaires, fabrications (MRP), transferts de stock ;
 *  - module : lignes des ordres de transformation et du tableau de stock (avant v2.13).
 * Tables vérifiées dans Dolibarr 20.0.0 et 23.0.0 (colonne batch varchar(128)). Les tables
 * d'autres modules externes ne sont pas connues et ne sont pas modifiées.
 *
 * Les documents d'un lot sont rangés sous son id (Productlot::$ref = rowid) : rien à déplacer.
 * L'identifiant du lot ne change pas : attributs (colisage…), fichiers joints, liens restent.
 */

/**
 * Fonctionnalité « Renommer un lot » active ? (désactivée par défaut)
 *
 * @return	bool
 */
function diamantutils_lotrename_enabled()
{
	return isModEnabled('diamantutils') && isModEnabled('productbatch') && getDolGlobalInt('DIAMANTUTILS_LOTRENAME_ENABLED') > 0;
}

/**
 * Droit de renommer un lot : modifier les produits (comme la fiche lot) et créer des
 * mouvements de stock (le stock par lot est réécrit)
 *
 * @param	User	$user	Utilisateur
 * @return	bool
 */
function diamantutils_lotrename_allowed($user)
{
	return $user->hasRight('produit', 'creer') && $user->hasRight('stock', 'mouvement', 'creer');
}

/**
 * Tables mises à jour : nom (sans préfixe) => condition « lignes de ce produit »
 * ('%P' = id produit). Tables facultatives (module jamais activé) ignorées si absentes.
 *
 * @return	array	array(table => array('where' => string, 'optional' => bool))
 */
function diamantutils_lotrename_tables()
{
	$p = MAIN_DB_PREFIX;
	return array(
		'product_lot' => array('where' => "fk_product = %P", 'optional' => false),
		'product_batch' => array('where' => "fk_product_stock IN (SELECT ps.rowid FROM ".$p."product_stock as ps WHERE ps.fk_product = %P)", 'optional' => false),
		'stock_mouvement' => array('where' => "fk_product = %P", 'optional' => false),
		'expeditiondet_batch' => array('where' => "fk_expeditiondet IN (SELECT ed.rowid FROM ".$p."expeditiondet as ed LEFT JOIN ".$p."commandedet as cd ON cd.rowid = ed.fk_elementdet WHERE COALESCE(ed.fk_product, cd.fk_product) = %P)", 'optional' => true),
		'receptiondet_batch' => array('where' => "fk_product = %P", 'optional' => true),
		'facturedet' => array('where' => "fk_product = %P", 'optional' => true),
		'inventorydet' => array('where' => "fk_product = %P", 'optional' => true),
		'mrp_production' => array('where' => "fk_product = %P", 'optional' => true),
		'stocktransfer_stocktransferline' => array('where' => "fk_product = %P", 'optional' => true),
		'diamantutils_transfo_det' => array('where' => "fk_product = %P", 'optional' => true),
		'diamantutils_stockline' => array('where' => "fk_product = %P", 'optional' => true),
	);
}

/**
 * La table existe-t-elle ?
 *
 * @param	DoliDB	$db		Base de données
 * @param	string	$table	Nom sans préfixe
 * @return	bool
 */
function diamantutils_lotrename_table_exists($db, $table)
{
	static $cache = array();
	if (!array_key_exists($table, $cache)) {
		$list = $db->DDLListTables($db->database_name, MAIN_DB_PREFIX.$table);
		$cache[$table] = (is_array($list) && in_array(MAIN_DB_PREFIX.$table, $list, true));
	}
	return $cache[$table];
}

/**
 * Nouveau numéro valide pour ce lot ? Refus avec message, jamais de correction muette.
 *
 * @param	DoliDB		$db		Base de données
 * @param	Productlot	$lot	Lot à renommer
 * @param	string		$new	Nouveau numéro (nettoyé)
 * @param	string		$error	Message d'erreur en sortie
 * @return	bool
 */
function diamantutils_lotrename_check($db, $lot, $new, &$error)
{
	global $langs;

	$error = '';
	if ($new === '') {
		$error = $langs->trans('DiamantutilsLotRenameErrEmpty');
		return false;
	}
	if (dol_strlen($new) > 128) {
		$error = $langs->trans('DiamantutilsLotRenameErrLength', 128);
		return false;
	}
	if ($new === (string) $lot->batch) {
		$error = $langs->trans('DiamantutilsLotRenameErrSame');
		return false;
	}

	// Numéro déjà utilisé pour ce produit : autre fiche lot, stock ou historique d'un autre lot
	// (la comparaison SQL peut ignorer la casse : un simple changement de casse reste permis)
	$fk = (int) $lot->fk_product;
	$checks = array(
		"SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."product_lot WHERE fk_product = ".$fk." AND batch = '".$db->escape($new)."' AND rowid <> ".((int) $lot->id),
		"SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."product_batch as pb INNER JOIN ".MAIN_DB_PREFIX."product_stock as ps ON ps.rowid = pb.fk_product_stock WHERE ps.fk_product = ".$fk." AND pb.batch = '".$db->escape($new)."' AND NOT (pb.batch = '".$db->escape($lot->batch)."')",
		"SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."stock_mouvement WHERE fk_product = ".$fk." AND batch = '".$db->escape($new)."' AND NOT (batch = '".$db->escape($lot->batch)."')",
	);
	foreach ($checks as $sql) {
		$resql = $db->query($sql);
		if (!$resql) {
			$error = $db->lasterror();
			return false;
		}
		$obj = $db->fetch_object($resql);
		$db->free($resql);
		if ($obj && (int) $obj->nb > 0) {
			$error = $langs->trans('DiamantutilsLotRenameErrExists', $new);
			return false;
		}
	}
	return true;
}

/**
 * Renomme un lot dans toutes les tables qui citent son numéro, pour son produit seulement,
 * dans une transaction. Trace dans la note privée du lot et dans le journal.
 *
 * @param	DoliDB		$db		Base de données
 * @param	User		$user	Utilisateur
 * @param	Productlot	$lot	Lot à renommer (chargé)
 * @param	string		$new	Nouveau numéro (nettoyé)
 * @param	string		$error	Message d'erreur en sortie
 * @return	array|false			array(table => nombre de lignes modifiées), false si erreur (rien n'est modifié)
 */
function diamantutils_lotrename($db, $user, $lot, $new, &$error)
{
	global $langs;

	$error = '';
	if (!diamantutils_lotrename_check($db, $lot, $new, $error)) {
		return false;
	}
	$old = (string) $lot->batch;
	$fk = (int) $lot->fk_product;
	$counts = array();

	$db->begin();
	foreach (diamantutils_lotrename_tables() as $table => $def) {
		if (!diamantutils_lotrename_table_exists($db, $table)) {
			if (!$def['optional']) {
				$db->rollback();
				$error = 'Table '.MAIN_DB_PREFIX.$table.' introuvable';
				return false;
			}
			continue;
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX.$table." SET batch = '".$db->escape($new)."'";
		$sql .= " WHERE batch = '".$db->escape($old)."'";
		$sql .= " AND ".str_replace('%P', (string) $fk, $def['where']);
		if ($table == 'product_lot') {
			$sql .= " AND rowid = ".((int) $lot->id);
		}
		$resql = $db->query($sql);
		if (!$resql) {
			$error = $db->lasterror();
			$db->rollback();
			dol_syslog(__FUNCTION__.' '.$table.' : '.$error, LOG_ERR);
			return false;
		}
		$counts[$table] = (int) $db->affected_rows($resql);
	}

	// Trace dans la note privée du lot
	$trace = $langs->transnoentities('DiamantutilsLotRenameTrace', $old, $new, dol_print_date(dol_now(), 'dayhour'), $user->login);
	$note = (string) $lot->note_private;
	$note = ($note !== '' ? $note.(dol_textishtml($note) ? '<br>' : "\n") : '').dol_escape_htmltag($trace);
	$sql = "UPDATE ".MAIN_DB_PREFIX."product_lot SET note_private = '".$db->escape($note)."', fk_user_modif = ".((int) $user->id)." WHERE rowid = ".((int) $lot->id);
	if (!$db->query($sql)) {
		$error = $db->lasterror();
		$db->rollback();
		return false;
	}

	$db->commit();
	dol_syslog(__FUNCTION__.' lot '.$lot->id.' produit '.$fk.' « '.$old.' » → « '.$new.' » par '.$user->login.' : '.json_encode($counts), LOG_NOTICE);
	return $counts;
}
