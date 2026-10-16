<?php
/**
 * Commandes sous-traitées (livraison directe client) — contrôles des réceptions et expéditions
 *
 * Réception (ligne de réception ou de ventilation d'une commande fournisseur) :
 *  - CF à OUI : entrepôt = Sous-traitance et, pour un produit à lots, lot = ref de la CF ;
 *  - CF à NON : entrepôt ≠ Sous-traitance.
 * Expédition (ligne prélevée sur l'entrepôt Sous-traitance) :
 *  - lot = ref d'une CF à OUI liée à la commande client de l'expédition ;
 *  - lot d'une CF à OUI non liée : message dédié (lier la CF puis recommencer).
 */

dol_include_once('/diamantutils/lib/soustraitance.lib.php');

class DiamantutilsSoustraitance
{
	/**
	 * MAX(rowid) de llx_receptiondet_batch par commande fournisseur, mémorisé avant une
	 * ventilation directe (hook doActions de dispatch.php) : les lignes au-dessus sont
	 * celles insérées par la requête en cours.
	 *
	 * @var array<int,int>
	 */
	public static $dispatchMaxRowid = array();

	/** @var DoliDB */
	public $db;

	/** @var array Cache des commandes fournisseur lues */
	private $cache_cf = array();

	/**
	 * @param	DoliDB	$db		Base de données
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Ref et option « Livraison directe client » d'une commande fournisseur
	 *
	 * Sans valeur (pas de ligne d'extrafields, attribut supprimé) : NON.
	 *
	 * @param	int			$fk_cf	Id commande fournisseur
	 * @return	array|null			array('id', 'ref', 'direct' => bool), null si introuvable
	 */
	public function getSupplierOrder($fk_cf)
	{
		$fk_cf = (int) $fk_cf;
		if ($fk_cf <= 0) {
			return null;
		}
		if (array_key_exists($fk_cf, $this->cache_cf)) {
			return $this->cache_cf[$fk_cf];
		}

		$sql = "SELECT cf.rowid, cf.ref, ef.".DIAMANTUTILS_ST_EXTRAFIELD." as direct";
		$sql .= " FROM ".MAIN_DB_PREFIX."commande_fournisseur as cf";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."commande_fournisseur_extrafields as ef ON ef.fk_object = cf.rowid";
		$sql .= " WHERE cf.rowid = ".$fk_cf;
		$resql = $this->db->query($sql);
		if (!$resql) {
			// Attribut absent : la commande est considérée à NON
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_WARNING);
			$sql = "SELECT cf.rowid, cf.ref, NULL as direct FROM ".MAIN_DB_PREFIX."commande_fournisseur as cf WHERE cf.rowid = ".$fk_cf;
			$resql = $this->db->query($sql);
			if (!$resql) {
				return null;
			}
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		$res = null;
		if ($obj) {
			$res = array(
				'id' => (int) $obj->rowid,
				'ref' => (string) $obj->ref,
				'direct' => ((string) $obj->direct === 'OUI'),
			);
		}
		$this->cache_cf[$fk_cf] = $res;
		return $res;
	}

	/**
	 * Contrôle une ligne de réception / ventilation
	 *
	 * @param	int		$fk_cf			Id commande fournisseur
	 * @param	int		$fk_product		Id produit
	 * @param	int		$fk_entrepot	Id entrepôt
	 * @param	string	$batch			Lot
	 * @return	string					Message d'erreur, '' si la ligne est conforme
	 */
	public function checkReceptionLine($fk_cf, $fk_product, $fk_entrepot, $batch)
	{
		global $langs;

		$fk_entrepot = (int) $fk_entrepot;
		if ($fk_entrepot <= 0) {
			return '';	// pas de mouvement de stock
		}
		$cf = $this->getSupplierOrder($fk_cf);
		if (empty($cf)) {
			return '';
		}

		$langs->load('diamantutils@diamantutils');
		$st_warehouse = diamantutils_st_warehouse_id();
		$product = $this->getProduct($fk_product);
		$productref = ($product ? $product['ref'] : '#'.((int) $fk_product));

		if ($cf['direct']) {
			if ($fk_entrepot != $st_warehouse) {
				return $langs->transnoentitiesnoconv('DiamantutilsSTErrorDirectWarehouse', $cf['ref'], $productref, $cf['ref']);
			}
			if ($product && $product['tobatch'] && trim((string) $batch) !== $cf['ref']) {
				return $langs->transnoentitiesnoconv('DiamantutilsSTErrorDirectBatch', $cf['ref'], $productref, $cf['ref'], (string) $batch);
			}
		} elseif ($fk_entrepot == $st_warehouse) {
			return $langs->transnoentitiesnoconv('DiamantutilsSTErrorNotDirect', $cf['ref'], $productref);
		}
		return '';
	}

	/**
	 * Contrôle toutes les lignes d'une réception (lues en base, dans la transaction en cours)
	 *
	 * @param	int		$fk_reception	Id réception
	 * @return	array					Messages d'erreur (vide si conforme)
	 */
	public function checkReception($fk_reception)
	{
		$sql = "SELECT rowid, fk_element, fk_product, fk_entrepot, batch";
		$sql .= " FROM ".MAIN_DB_PREFIX."receptiondet_batch";
		$sql .= " WHERE fk_reception = ".((int) $fk_reception);
		$sql .= " ORDER BY rowid";
		return $this->checkLinesSql($sql);
	}

	/**
	 * Contrôle une ligne de llx_receptiondet_batch (relue en base)
	 *
	 * @param	int		$rowid	Id de la ligne
	 * @return	array			Messages d'erreur (vide si conforme)
	 */
	public function checkReceptionDetLine($rowid)
	{
		$sql = "SELECT rowid, fk_element, fk_product, fk_entrepot, batch";
		$sql .= " FROM ".MAIN_DB_PREFIX."receptiondet_batch";
		$sql .= " WHERE rowid = ".((int) $rowid);
		return $this->checkLinesSql($sql);
	}

	/**
	 * Contrôle les lignes ventilées directement (sans réception) sur une CF par la requête en cours
	 *
	 * Lignes au-dessus du MAX(rowid) mémorisé dans doActions ; à défaut (appel hors
	 * dispatch.php), la dernière ligne ventilée de la CF.
	 *
	 * @param	int		$fk_cf	Id commande fournisseur
	 * @return	array			Messages d'erreur (vide si conforme)
	 */
	public function checkDispatch($fk_cf)
	{
		$fk_cf = (int) $fk_cf;
		$sql = "SELECT rowid, fk_element, fk_product, fk_entrepot, batch";
		$sql .= " FROM ".MAIN_DB_PREFIX."receptiondet_batch";
		$sql .= " WHERE fk_element = ".$fk_cf;
		$sql .= " AND fk_reception IS NULL";
		if (isset(self::$dispatchMaxRowid[$fk_cf])) {
			$sql .= " AND rowid > ".((int) self::$dispatchMaxRowid[$fk_cf]);
			$sql .= " ORDER BY rowid";
		} else {
			$sql .= " ORDER BY rowid DESC";
			$sql .= $this->db->plimit(1);
		}
		return $this->checkLinesSql($sql);
	}

	/**
	 * Mémorise le MAX(rowid) des lignes ventilées d'une CF (avant ventilation)
	 *
	 * @param	int		$fk_cf	Id commande fournisseur
	 * @return	void
	 */
	public function rememberDispatchMaxRowid($fk_cf)
	{
		$fk_cf = (int) $fk_cf;
		$sql = "SELECT MAX(rowid) as maxid FROM ".MAIN_DB_PREFIX."receptiondet_batch WHERE fk_element = ".$fk_cf;
		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			self::$dispatchMaxRowid[$fk_cf] = ($obj ? (int) $obj->maxid : 0);
			$this->db->free($resql);
		}
	}

	/**
	 * Contrôle les lignes renvoyées par une requête sur llx_receptiondet_batch
	 *
	 * @param	string	$sql	Requête (colonnes rowid, fk_element, fk_product, fk_entrepot, batch)
	 * @return	array			Messages d'erreur (sans doublon)
	 */
	private function checkLinesSql($sql)
	{
		$errors = array();
		$resql = $this->db->query($sql);
		if (!$resql) {
			$errors[] = $this->db->lasterror();
			return $errors;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$msg = $this->checkReceptionLine($obj->fk_element, $obj->fk_product, $obj->fk_entrepot, $obj->batch);
			if ($msg !== '' && !in_array($msg, $errors)) {
				$errors[] = $msg;
			}
		}
		$this->db->free($resql);
		return $errors;
	}

	/**
	 * Commandes fournisseur liées à une commande client (llx_element_element, dans les deux sens)
	 *
	 * @param	int		$fk_commande	Id commande client
	 * @return	array					Liste de array('id', 'ref', 'direct')
	 */
	public function getLinkedSupplierOrders($fk_commande)
	{
		$fk_commande = (int) $fk_commande;
		$res = array();
		if ($fk_commande <= 0) {
			return $res;
		}
		$sql = "SELECT fk_target as fk_cf FROM ".MAIN_DB_PREFIX."element_element";
		$sql .= " WHERE sourcetype = 'commande' AND fk_source = ".$fk_commande." AND targettype = 'order_supplier'";
		$sql .= " UNION SELECT fk_source as fk_cf FROM ".MAIN_DB_PREFIX."element_element";
		$sql .= " WHERE targettype = 'commande' AND fk_target = ".$fk_commande." AND sourcetype = 'order_supplier'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			return $res;
		}
		$ids = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$ids[] = (int) $obj->fk_cf;
		}
		$this->db->free($resql);
		foreach (array_unique($ids) as $id) {
			$cf = $this->getSupplierOrder($id);
			if ($cf) {
				$res[$cf['id']] = $cf;
			}
		}
		return $res;
	}

	/**
	 * Refs des commandes fournisseur à OUI liées à une commande client (= lots attendus)
	 *
	 * @param	int		$fk_commande	Id commande client
	 * @return	string[]
	 */
	public function getExpectedLots($fk_commande)
	{
		$lots = array();
		foreach ($this->getLinkedSupplierOrders($fk_commande) as $cf) {
			if ($cf['direct']) {
				$lots[] = $cf['ref'];
			}
		}
		sort($lots);
		return $lots;
	}

	/**
	 * Commande fournisseur dont la ref est égale à un nom de lot
	 *
	 * @param	string		$ref	Ref (= nom du lot)
	 * @return	array|null			array('id', 'ref', 'direct'), null si aucune
	 */
	public function getSupplierOrderByRef($ref)
	{
		$ref = trim((string) $ref);
		if ($ref === '') {
			return null;
		}
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."commande_fournisseur";
		$sql .= " WHERE ref = '".$this->db->escape($ref)."'";
		$sql .= " AND entity IN (".getEntity('supplier_order').")";
		$resql = $this->db->query($sql);
		if (!$resql) {
			return null;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		return ($obj ? $this->getSupplierOrder($obj->rowid) : null);
	}

	/**
	 * Lots disponibles (qty non nulle) dans l'entrepôt Sous-traitance pour une liste de lots
	 *
	 * @param	string[]	$lots	Noms de lots
	 * @return	array				Liste de array('batch', 'product_ref', 'qty', 'fk_product_batch')
	 */
	public function getWarehouseLots($lots = null)
	{
		$res = array();
		$sql = "SELECT pb.rowid, pb.batch, pb.qty, p.ref as product_ref";
		$sql .= " FROM ".MAIN_DB_PREFIX."product_batch as pb";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_stock as ps ON ps.rowid = pb.fk_product_stock";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = ps.fk_product";
		$sql .= " WHERE ps.fk_entrepot = ".((int) diamantutils_st_warehouse_id());
		$sql .= " AND ABS(pb.qty) > 0.0001";
		if (is_array($lots)) {
			if (empty($lots)) {
				return $res;
			}
			$in = array();
			foreach ($lots as $lot) {
				$in[] = "'".$this->db->escape($lot)."'";
			}
			$sql .= " AND pb.batch IN (".implode(',', $in).")";
		}
		$sql .= " ORDER BY pb.batch, p.ref";
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			return $res;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$res[] = array(
				'fk_product_batch' => (int) $obj->rowid,
				'batch' => (string) $obj->batch,
				'product_ref' => (string) $obj->product_ref,
				'qty' => (float) $obj->qty,
			);
		}
		$this->db->free($resql);
		return $res;
	}

	/**
	 * Contrôle toutes les lignes d'une expédition (lues en base, dans la transaction en cours)
	 *
	 * @param	int		$fk_expedition	Id expédition
	 * @return	array					Messages d'erreur (vide si conforme)
	 */
	public function checkShipment($fk_expedition)
	{
		return $this->checkShipmentSql("ed.fk_expedition = ".((int) $fk_expedition));
	}

	/**
	 * Contrôle une ligne d'expédition (llx_expeditiondet), relue en base
	 *
	 * @param	int		$fk_expeditiondet	Id ligne d'expédition
	 * @return	array						Messages d'erreur (vide si conforme)
	 */
	public function checkShipmentLine($fk_expeditiondet)
	{
		return $this->checkShipmentSql("ed.rowid = ".((int) $fk_expeditiondet));
	}

	/**
	 * Contrôle des lignes d'expédition prélevées sur l'entrepôt Sous-traitance
	 *
	 * Le lot est lu sur la ligne de lot (expeditiondet_batch.batch) et résolu depuis
	 * fk_origin_stock (rowid de llx_product_batch) : les deux noms sont contrôlés.
	 * L'entrepôt est celui de la ligne, de la ligne de lot ou du stock d'origine.
	 *
	 * @param	string	$where	Filtre SQL sur ed (expeditiondet)
	 * @return	array			Messages d'erreur (sans doublon)
	 */
	private function checkShipmentSql($where)
	{
		global $langs;

		$errors = array();
		$st_warehouse = diamantutils_st_warehouse_id();

		$sql = "SELECT ed.rowid, ed.fk_entrepot as ed_wh, cd.fk_commande, cd.fk_product, c.ref as commande_ref,";
		$sql .= " edb.batch, edb.fk_warehouse as edb_wh, pb.batch as pb_batch, ps.fk_entrepot as ps_wh";
		$sql .= " FROM ".MAIN_DB_PREFIX."expeditiondet as ed";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."commandedet as cd ON cd.rowid = ed.fk_elementdet";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."commande as c ON c.rowid = cd.fk_commande";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."expeditiondet_batch as edb ON edb.fk_expeditiondet = ed.rowid";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_batch as pb ON pb.rowid = edb.fk_origin_stock";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_stock as ps ON ps.rowid = pb.fk_product_stock";
		$sql .= " WHERE ".$where;
		$sql .= " ORDER BY ed.rowid, edb.rowid";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$errors[] = $this->db->lasterror();
			return $errors;
		}
		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = $obj;
		}
		$this->db->free($resql);

		$langs->load('diamantutils@diamantutils');
		$expected = array();
		foreach ($rows as $obj) {
			if (!in_array($st_warehouse, array((int) $obj->ed_wh, (int) $obj->edb_wh, (int) $obj->ps_wh), true)) {
				continue;	// ligne prélevée sur nos dépôts : non contrôlée
			}
			$fk_commande = (int) $obj->fk_commande;
			if (!isset($expected[$fk_commande])) {
				$expected[$fk_commande] = $this->getExpectedLots($fk_commande);
			}
			$product = $this->getProduct($obj->fk_product);
			$productref = ($product ? $product['ref'] : '#'.((int) $obj->fk_product));
			$expectedlist = (empty($expected[$fk_commande]) ? $langs->transnoentitiesnoconv('None') : implode(', ', $expected[$fk_commande]));

			$lots = array();
			foreach (array($obj->batch, $obj->pb_batch) as $lot) {
				$lot = trim((string) $lot);
				if ($lot !== '' && !in_array($lot, $lots, true)) {
					$lots[] = $lot;
				}
			}

			$msg = '';
			if (empty($lots)) {
				// Produit sans lot : il faut au moins une CF en livraison directe liée
				if (empty($expected[$fk_commande])) {
					$msg = $langs->transnoentitiesnoconv('DiamantutilsSTErrorShipNoDirect', $productref, (string) $obj->commande_ref);
				}
			} else {
				foreach ($lots as $lot) {
					if (in_array($lot, $expected[$fk_commande], true)) {
						continue;
					}
					$cf = $this->getSupplierOrderByRef($lot);
					if ($cf && $cf['direct']) {
						$msg = $langs->transnoentitiesnoconv('DiamantutilsSTErrorShipNotLinked', $cf['ref']);
					} else {
						$msg = $langs->transnoentitiesnoconv('DiamantutilsSTErrorShipBadLot', $lot, $productref, (string) $obj->commande_ref, $expectedlist);
					}
					break;
				}
			}
			if ($msg !== '' && !in_array($msg, $errors)) {
				$errors[] = $msg;
			}
		}
		return $errors;
	}

	/**
	 * Ref et gestion par lot d'un produit
	 *
	 * @param	int			$fk_product		Id produit
	 * @return	array|null					array('ref', 'tobatch' => bool)
	 */
	private function getProduct($fk_product)
	{
		static $cache = array();

		$fk_product = (int) $fk_product;
		if ($fk_product <= 0) {
			return null;
		}
		if (!array_key_exists($fk_product, $cache)) {
			$cache[$fk_product] = null;
			$resql = $this->db->query("SELECT ref, tobatch FROM ".MAIN_DB_PREFIX."product WHERE rowid = ".$fk_product);
			if ($resql) {
				$obj = $this->db->fetch_object($resql);
				if ($obj) {
					$cache[$fk_product] = array(
						'ref' => (string) $obj->ref,
						'tobatch' => (isModEnabled('productbatch') && (int) $obj->tobatch > 0),
					);
				}
				$this->db->free($resql);
			}
		}
		return $cache[$fk_product];
	}
}
