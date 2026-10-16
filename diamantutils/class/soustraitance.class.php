<?php
/**
 * Commandes sous-traitées (livraison directe client) — contrôles des réceptions
 *
 * Règle (ligne de réception ou de ventilation d'une commande fournisseur) :
 *  - CF à OUI : entrepôt = Sous-traitance et, pour un produit à lots, lot = ref de la CF ;
 *  - CF à NON : entrepôt ≠ Sous-traitance.
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
