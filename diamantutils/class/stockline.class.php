<?php
/**
 * Tableau de stock par lots : une ligne de colisage constaté (produit, lot, pièces,
 * pièces par colis, dimensions, fournisseur).
 *
 * Toutes les écritures passent par cette classe et sa validation (le serveur fait foi) :
 *  - produit existant, de type produit, dans les tags configurés, entité courante ;
 *  - nombre de pièces et pièces par colis > 0, entiers sauf produit au mètre ;
 *  - dimensions > 0 quand l'unité du produit les exige, > 0 ou vides sinon ;
 *  - lot : connu de Dolibarr pour un produit à lots, sauf « autre lot » demandé explicitement ;
 *    vide pour un produit sans lot.
 * Les quantités calculées ne sont pas stockées. Aucune écriture de stock.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
dol_include_once('/diamantutils/lib/stocktable.lib.php');

class DiamantutilsStockLine extends CommonObject
{
	public $module = 'diamantutils';

	public $element = 'diamantutils_stockline';

	public $table_element = 'diamantutils_stockline';

	public $picto = 'stock';

	public $ismultientitymanaged = 1;

	public $isextrafieldmanaged = 0;

	public $fk_product;

	public $batch = '';

	public $fk_soc;

	public $nb_pieces;

	public $pieces_per_colis;

	public $larg_mm;

	public $long_mm;

	public $note;

	public $datec;

	public $fk_user_creat;

	public $fk_user_modif;

	/**
	 * @param	DoliDB	$db		Base de données
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Charge une ligne
	 *
	 * @param	int		$id		Identifiant
	 * @return	int				1 si trouvée, 0 si absente, <0 si erreur
	 */
	public function fetch($id)
	{
		$sql = "SELECT l.rowid, l.entity, l.fk_product, l.batch, l.fk_soc, l.nb_pieces, l.pieces_per_colis,";
		$sql .= " l.larg_mm, l.long_mm, l.note, l.datec, l.fk_user_creat, l.fk_user_modif";
		$sql .= " FROM ".MAIN_DB_PREFIX.$this->table_element." as l";
		$sql .= " WHERE l.rowid = ".((int) $id);
		$sql .= " AND l.entity IN (".getEntity($this->element).")";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj) {
			return 0;
		}
		$this->id = (int) $obj->rowid;
		$this->entity = (int) $obj->entity;
		$this->fk_product = (int) $obj->fk_product;
		$this->batch = (string) $obj->batch;
		$this->fk_soc = ($obj->fk_soc > 0 ? (int) $obj->fk_soc : null);
		$this->nb_pieces = (float) $obj->nb_pieces;
		$this->pieces_per_colis = (float) $obj->pieces_per_colis;
		$this->larg_mm = ($obj->larg_mm === null ? null : (float) $obj->larg_mm);
		$this->long_mm = ($obj->long_mm === null ? null : (float) $obj->long_mm);
		$this->note = $obj->note;
		$this->datec = $this->db->jdate($obj->datec);
		$this->date_creation = $this->datec;
		$this->fk_user_creat = (int) $obj->fk_user_creat;
		$this->fk_user_modif = (int) $obj->fk_user_modif;
		return 1;
	}

	/**
	 * Erreur de validation
	 *
	 * @param	string	$key	Clé de langue
	 * @param	mixed	...$args	Paramètres
	 * @return	int				-1
	 */
	private function fail($key, ...$args)
	{
		global $langs;
		$this->error = $langs->trans($key, ...$args);
		$this->errors = array($this->error);
		return -1;
	}

	/**
	 * Valeur numérique facultative : null si vide
	 *
	 * @param	mixed	$value	Valeur
	 * @return	float|null
	 */
	private static function optNum($value)
	{
		if ($value === null || $value === '') {
			return null;
		}
		return (float) $value;
	}

	/**
	 * Validation complète avant écriture. Refus avec message, jamais de correction muette.
	 *
	 * @param	bool	$allow_new_batch	Lot inconnu de Dolibarr accepté (choix explicite « Autre lot »)
	 * @return	int							1 si OK, <0 sinon
	 */
	public function check($allow_new_batch = false)
	{
		global $langs;
		$langs->load('diamantutils@diamantutils');

		// Produit : existant, de type produit, dans les tags configurés
		$product = diamantutils_stocktable_product($this->db, $this->fk_product);
		if (empty($product)) {
			return $this->fail('DiamantutilsStockErrProduct', (int) $this->fk_product);
		}
		if ($product['type'] !== 0) {
			return $this->fail('DiamantutilsStockErrProductType', $product['ref']);
		}
		if (!$product['inscope']) {
			return $this->fail('DiamantutilsStockErrOutOfScope', $product['ref']);
		}
		$unit = $product['unit'];
		if (!$unit['ok']) {
			return $this->fail($unit['error'], $product['ref']);
		}

		// Pièces et pièces par colis : > 0, entiers sauf produit au mètre
		$this->nb_pieces = self::optNum($this->nb_pieces);
		$this->pieces_per_colis = self::optNum($this->pieces_per_colis);
		$decimals = ($unit['type'] === 'size');
		foreach (array('nb_pieces' => 'DiamantutilsStockColPieces', 'pieces_per_colis' => 'DiamantutilsStockColPerColis') as $field => $label) {
			$value = $this->$field;
			if ($value === null || !($value > 0)) {
				return $this->fail('DiamantutilsStockErrPositive', $langs->transnoentities($label));
			}
			if ($value > 1e9) {
				return $this->fail('DiamantutilsStockErrTooBig', $langs->transnoentities($label));
			}
			if (!$decimals && abs($value - round($value)) > 1e-9) {
				return $this->fail('DiamantutilsStockErrInteger', $langs->transnoentities($label));
			}
		}

		// Dimensions : exigées selon l'unité, toujours > 0 si renseignées
		$this->larg_mm = self::optNum($this->larg_mm);
		$this->long_mm = self::optNum($this->long_mm);
		$required = diamantutils_stock_dims_required($unit['type']);
		foreach (array('larg' => 'DiamantutilsStockColLarg', 'long' => 'DiamantutilsStockColLong') as $dim => $label) {
			$value = $this->{$dim.'_mm'};
			if ($value === null) {
				if ($required[$dim]) {
					return $this->fail('DiamantutilsStockErrDimRequired', $langs->transnoentities($label), diamantutils_stocktable_unit_label($unit));
				}
				continue;
			}
			if (!($value > 0) || $value > 1e7) {
				return $this->fail('DiamantutilsStockErrDim', $langs->transnoentities($label));
			}
		}

		// Lot
		$this->batch = trim((string) $this->batch);
		if ($product['tobatch']) {
			if ($this->batch === '') {
				return $this->fail('DiamantutilsStockErrBatchRequired', $product['ref']);
			}
			if (dol_strlen($this->batch) > 128) {
				return $this->fail('DiamantutilsStockErrBatchLength');
			}
			if (!$allow_new_batch && !diamantutils_stocktable_batch_exists($this->db, $this->fk_product, $this->batch)) {
				return $this->fail('DiamantutilsStockErrBatchUnknown', $this->batch, $product['ref']);
			}
		} elseif ($this->batch !== '') {
			return $this->fail('DiamantutilsStockErrBatchNotManaged', $product['ref']);
		}

		// Fournisseur facultatif : tiers fournisseur de l'entité
		$this->fk_soc = ((int) $this->fk_soc > 0 ? (int) $this->fk_soc : null);
		if ($this->fk_soc) {
			$sql = "SELECT s.rowid FROM ".MAIN_DB_PREFIX."societe as s";
			$sql .= " WHERE s.rowid = ".((int) $this->fk_soc)." AND s.fournisseur = 1";
			$sql .= " AND s.entity IN (".getEntity('societe').")";
			$resql = $this->db->query($sql);
			$found = ($resql && $this->db->num_rows($resql) > 0);
			if ($resql) {
				$this->db->free($resql);
			}
			if (!$found) {
				return $this->fail('DiamantutilsStockErrSupplier');
			}
		}

		$this->note = trim((string) $this->note);
		if (dol_strlen($this->note) > 255) {
			return $this->fail('DiamantutilsStockErrNoteLength');
		}

		return 1;
	}

	/**
	 * Valeur SQL d'un nombre facultatif
	 *
	 * @param	float|null	$value	Valeur
	 * @return	string
	 */
	private static function sqlNum($value)
	{
		return ($value === null ? 'NULL' : (string) ((float) $value));
	}

	/**
	 * Crée la ligne
	 *
	 * @param	User	$user				Utilisateur
	 * @param	bool	$allow_new_batch	Lot inconnu de Dolibarr accepté (choix explicite)
	 * @return	int							id si OK, <0 si KO
	 */
	public function create($user, $allow_new_batch = false)
	{
		global $conf;

		if ($this->check($allow_new_batch) < 0) {
			return -1;
		}

		$this->datec = dol_now();
		$sql = "INSERT INTO ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " (entity, fk_product, batch, fk_soc, nb_pieces, pieces_per_colis, larg_mm, long_mm, note, datec, fk_user_creat)";
		$sql .= " VALUES (".((int) $conf->entity);
		$sql .= ", ".((int) $this->fk_product);
		$sql .= ", '".$this->db->escape($this->batch)."'";
		$sql .= ", ".($this->fk_soc ? (int) $this->fk_soc : 'NULL');
		$sql .= ", ".self::sqlNum($this->nb_pieces);
		$sql .= ", ".self::sqlNum($this->pieces_per_colis);
		$sql .= ", ".self::sqlNum($this->larg_mm);
		$sql .= ", ".self::sqlNum($this->long_mm);
		$sql .= ", ".($this->note !== '' ? "'".$this->db->escape($this->note)."'" : 'NULL');
		$sql .= ", '".$this->db->idate($this->datec)."'";
		$sql .= ", ".((int) $user->id).")";

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->errors = array($this->error);
			return -1;
		}
		$this->id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.$this->table_element);
		$this->entity = (int) $conf->entity;
		$this->fk_user_creat = (int) $user->id;
		return $this->id;
	}

	/**
	 * Met à jour la ligne. Le produit d'une ligne ne change jamais.
	 *
	 * @param	User	$user				Utilisateur
	 * @param	bool	$allow_new_batch	Lot inconnu de Dolibarr accepté (choix explicite)
	 * @return	int							1 si OK, <0 si KO
	 */
	public function update($user, $allow_new_batch = false)
	{
		$orig = new self($this->db);
		if ($orig->fetch($this->id) <= 0) {
			return $this->fail('DiamantutilsStockErrLineNotFound', (int) $this->id);
		}
		if ((int) $orig->fk_product !== (int) $this->fk_product) {
			return $this->fail('DiamantutilsStockErrProductLocked');
		}
		if ($this->check($allow_new_batch) < 0) {
			return -1;
		}

		$sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element." SET";
		$sql .= " batch = '".$this->db->escape($this->batch)."'";
		$sql .= ", fk_soc = ".($this->fk_soc ? (int) $this->fk_soc : 'NULL');
		$sql .= ", nb_pieces = ".self::sqlNum($this->nb_pieces);
		$sql .= ", pieces_per_colis = ".self::sqlNum($this->pieces_per_colis);
		$sql .= ", larg_mm = ".self::sqlNum($this->larg_mm);
		$sql .= ", long_mm = ".self::sqlNum($this->long_mm);
		$sql .= ", note = ".($this->note !== '' ? "'".$this->db->escape($this->note)."'" : 'NULL');
		$sql .= ", fk_user_modif = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $this->id);
		$sql .= " AND entity IN (".getEntity($this->element).")";

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->errors = array($this->error);
			return -1;
		}
		$this->fk_user_modif = (int) $user->id;
		return 1;
	}

	/**
	 * Supprime la ligne (possible même pour un produit sorti du périmètre)
	 *
	 * @param	User	$user	Utilisateur
	 * @return	int				1 si OK, <0 si KO
	 */
	public function delete($user)
	{
		$sql = "DELETE FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE rowid = ".((int) $this->id);
		$sql .= " AND entity IN (".getEntity($this->element).")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->errors = array($this->error);
			return -1;
		}
		return 1;
	}
}
