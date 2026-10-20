<?php
/**
 * Lots de produits : ensembles prédéfinis de produits (produit + quantité) ajoutés en une
 * fois sur un devis ou une commande client en brouillon.
 *
 * Les lots sont partagés par tous les utilisateurs de l'entité.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

class DiamantLot extends CommonObject
{
	public $module = 'diamantutils';

	public $element = 'diamantutils_lot';

	public $table_element = 'diamantutils_lot';

	public $table_element_line = 'diamantutils_lot_det';

	public $fk_element = 'fk_lot';

	public $picto = 'object_list';

	public $ismultientitymanaged = 1;

	public $isextrafieldmanaged = 0;

	public $label;

	public $active = 1;

	public $datec;

	/** @var int Nombre de lignes (rempli par fetch() et fetchAll()) */
	public $nb_lines = 0;

	/** @var DiamantLotLine[] */
	public $lines = array();

	/**
	 * @param	DoliDB	$db		Base de données
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Lien vers la fiche du lot
	 *
	 * @param	int		$withpicto	1 = avec picto
	 * @return	string
	 */
	public function getNomUrl($withpicto = 0)
	{
		$url = dol_buildpath('/diamantutils/lot_card.php', 1).'?id='.((int) $this->id);
		$out = '<a href="'.$url.'">';
		if ($withpicto) {
			$out .= img_picto('', 'list', 'class="pictofixedwidth"');
		}
		$out .= dol_escape_htmltag((string) $this->label).'</a>';
		return $out;
	}

	/**
	 * Charge un lot et ses lignes
	 *
	 * @param	int		$id		Identifiant
	 * @return	int				1 si trouvé, 0 si absent, <0 si erreur
	 */
	public function fetch($id)
	{
		$sql = "SELECT l.rowid, l.entity, l.label, l.active, l.fk_user_creat, l.datec";
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
		$this->setFromObject($obj);

		if ($this->fetchLines() < 0) {
			return -1;
		}
		return 1;
	}

	/**
	 * @param	object	$obj	Ligne SQL
	 * @return	void
	 */
	private function setFromObject($obj)
	{
		$this->id = (int) $obj->rowid;
		$this->entity = (int) $obj->entity;
		$this->label = $obj->label;
		$this->active = (int) $obj->active;
		$this->fk_user_creat = (int) $obj->fk_user_creat;
		$this->datec = $this->db->jdate($obj->datec);
		$this->date_creation = $this->datec;
		if (isset($obj->nb_lines)) {
			$this->nb_lines = (int) $obj->nb_lines;
		}
	}

	/**
	 * Charge les lignes du lot, triées par rang
	 *
	 * @return	int		Nombre de lignes, <0 si erreur
	 */
	public function fetchLines()
	{
		$this->lines = array();

		$sql = "SELECT d.rowid, d.fk_lot, d.rang, d.fk_product, d.qty,";
		$sql .= " p.ref as product_ref, p.label as product_label, p.fk_product_type as product_type, p.tosell as product_tosell, p.entity as product_entity";
		$sql .= " FROM ".MAIN_DB_PREFIX.$this->table_element_line." as d";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = d.fk_product";
		$sql .= " WHERE d.fk_lot = ".((int) $this->id);
		$sql .= " ORDER BY d.rang ASC, d.rowid ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$line = new DiamantLotLine();
			$line->id = (int) $obj->rowid;
			$line->fk_lot = (int) $obj->fk_lot;
			$line->rang = (int) $obj->rang;
			$line->fk_product = (int) $obj->fk_product;
			$line->qty = (float) $obj->qty;
			$line->product_ref = $obj->product_ref;
			$line->product_label = $obj->product_label;
			$line->product_type = ($obj->product_type === null ? null : (int) $obj->product_type);
			$line->product_tosell = ($obj->product_tosell === null ? null : (int) $obj->product_tosell);
			$this->lines[] = $line;
		}
		$this->db->free($resql);
		$this->nb_lines = count($this->lines);
		return $this->nb_lines;
	}

	/**
	 * Lots de l'entité (sans leurs lignes), triés par libellé
	 *
	 * @param	int		$activeonly		1 = lots actifs seulement
	 * @return	DiamantLot[]|int		Tableau, ou <0 si erreur
	 */
	public function fetchAll($activeonly = 1)
	{
		$sql = "SELECT l.rowid, l.entity, l.label, l.active, l.fk_user_creat, l.datec,";
		$sql .= " (SELECT COUNT(d.rowid) FROM ".MAIN_DB_PREFIX.$this->table_element_line." as d WHERE d.fk_lot = l.rowid) as nb_lines";
		$sql .= " FROM ".MAIN_DB_PREFIX.$this->table_element." as l";
		$sql .= " WHERE l.entity IN (".getEntity($this->element).")";
		if ($activeonly) {
			$sql .= " AND l.active = 1";
		}
		$sql .= " ORDER BY l.label ASC, l.rowid ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$lots = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$lot = new self($this->db);
			$lot->setFromObject($obj);
			$lots[] = $lot;
		}
		$this->db->free($resql);
		return $lots;
	}

	/**
	 * Contrôle des champs avant enregistrement
	 *
	 * @return	int		1 si OK, <0 sinon
	 */
	private function check()
	{
		global $langs;

		$this->label = trim((string) $this->label);
		if ($this->label === '') {
			$this->error = $langs->trans('ErrorFieldRequired', $langs->transnoentities('Label'));
			$this->errors = array($this->error);
			return -1;
		}
		$this->label = dol_trunc($this->label, 255, 'right', 'UTF-8', 1);
		return 1;
	}

	/**
	 * Crée le lot, avec les lignes de $this->lines si elles sont renseignées
	 *
	 * @param	User	$user	Utilisateur
	 * @return	int				id si OK, <0 si KO
	 */
	public function create($user)
	{
		global $conf;

		if ($this->check() < 0) {
			return -1;
		}

		$this->db->begin();

		$this->datec = dol_now();
		$sql = "INSERT INTO ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " (entity, label, active, fk_user_creat, datec)";
		$sql .= " VALUES (".((int) $conf->entity);
		$sql .= ", '".$this->db->escape($this->label)."'";
		$sql .= ", ".($this->active ? 1 : 0);
		$sql .= ", ".((int) $user->id);
		$sql .= ", '".$this->db->idate($this->datec)."')";

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.$this->table_element);
		$this->entity = (int) $conf->entity;
		$this->fk_user_creat = (int) $user->id;

		$lines = $this->lines;
		$this->lines = array();
		foreach ($lines as $line) {
			if ($this->addLotLine($line->fk_product, $line->qty) < 0) {
				$this->db->rollback();
				$this->id = 0;
				return -1;
			}
		}

		$this->db->commit();
		return $this->id;
	}

	/**
	 * Met à jour l'en-tête du lot (libellé, actif)
	 *
	 * @param	User	$user	Utilisateur
	 * @return	int				1 si OK, <0 si KO
	 */
	public function update($user)
	{
		if ($this->check() < 0) {
			return -1;
		}

		$sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element." SET";
		$sql .= " label = '".$this->db->escape($this->label)."'";
		$sql .= ", active = ".($this->active ? 1 : 0);
		$sql .= " WHERE rowid = ".((int) $this->id);
		$sql .= " AND entity IN (".getEntity($this->element).")";

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Supprime le lot et ses lignes
	 *
	 * @param	User	$user	Utilisateur
	 * @return	int				1 si OK, <0 si KO
	 */
	public function delete($user)
	{
		$this->db->begin();

		// Lignes supprimées explicitement : la clé étrangère (ON DELETE CASCADE) peut manquer
		// sur une base où la contrainte n'a pas pu être créée
		$sql = "DELETE FROM ".MAIN_DB_PREFIX.$this->table_element_line." WHERE fk_lot = ".((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}

		$sql = "DELETE FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE rowid = ".((int) $this->id);
		$sql .= " AND entity IN (".getEntity($this->element).")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();
		return 1;
	}

	/**
	 * Contrôle d'une quantité de ligne
	 *
	 * @param	mixed	$qty	Quantité saisie
	 * @return	float|null		Quantité, ou null si invalide (erreur dans $this->error)
	 */
	private function checkQty($qty)
	{
		global $langs;

		$qty = (float) price2num($qty, 'MS');
		if (!($qty > 0)) {
			$this->error = $langs->trans('DiamantutilsLotErrorQty');
			$this->errors = array($this->error);
			return null;
		}
		return $qty;
	}

	/**
	 * Ajoute une ligne en fin de lot
	 *
	 * @param	int		$fk_product		Produit
	 * @param	float	$qty			Quantité (> 0)
	 * @return	int						id de la ligne, <0 si KO
	 */
	public function addLotLine($fk_product, $qty)
	{
		global $langs;

		$fk_product = (int) $fk_product;
		if ($fk_product <= 0) {
			$this->error = $langs->trans('ErrorFieldRequired', $langs->transnoentities('Product'));
			$this->errors = array($this->error);
			return -1;
		}
		$qty = $this->checkQty($qty);
		if ($qty === null) {
			return -1;
		}

		$sql = "SELECT MAX(rang) as rangmax FROM ".MAIN_DB_PREFIX.$this->table_element_line." WHERE fk_lot = ".((int) $this->id);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		$rang = ($obj && $obj->rangmax !== null ? (int) $obj->rangmax + 1 : 1);
		$this->db->free($resql);

		$sql = "INSERT INTO ".MAIN_DB_PREFIX.$this->table_element_line." (fk_lot, rang, fk_product, qty)";
		$sql .= " VALUES (".((int) $this->id).", ".$rang.", ".$fk_product.", ".((float) $qty).")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$line = new DiamantLotLine();
		$line->id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.$this->table_element_line);
		$line->fk_lot = (int) $this->id;
		$line->rang = $rang;
		$line->fk_product = $fk_product;
		$line->qty = $qty;
		$this->lines[] = $line;
		$this->nb_lines = count($this->lines);

		return $line->id;
	}

	/**
	 * Modifie la quantité d'une ligne du lot
	 *
	 * @param	int		$lineid		Ligne
	 * @param	float	$qty		Quantité (> 0)
	 * @return	int					1 si OK, <0 si KO
	 */
	public function updateLotLineQty($lineid, $qty)
	{
		$qty = $this->checkQty($qty);
		if ($qty === null) {
			return -1;
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element_line." SET qty = ".((float) $qty);
		$sql .= " WHERE rowid = ".((int) $lineid)." AND fk_lot = ".((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Supprime une ligne du lot
	 *
	 * @param	int		$lineid		Ligne
	 * @return	int					1 si OK, <0 si KO
	 */
	public function deleteLotLine($lineid)
	{
		$sql = "DELETE FROM ".MAIN_DB_PREFIX.$this->table_element_line;
		$sql .= " WHERE rowid = ".((int) $lineid)." AND fk_lot = ".((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Monte ou descend une ligne d'un cran. Les rangs sont renumérotés 1..n au passage
	 * (rangs en double ou trous laissés par une suppression).
	 *
	 * @param	int		$lineid		Ligne
	 * @param	string	$direction	'up' ou 'down'
	 * @return	int					1 si déplacée, 0 si déjà en bout, <0 si KO
	 */
	public function moveLotLine($lineid, $direction)
	{
		if ($this->fetchLines() < 0) {
			return -1;
		}
		$ids = array();
		foreach ($this->lines as $line) {
			$ids[] = $line->id;
		}
		$pos = array_search((int) $lineid, $ids, true);
		if ($pos === false) {
			return 0;
		}
		$target = ($direction == 'up' ? $pos - 1 : $pos + 1);
		if ($target < 0 || $target >= count($ids)) {
			return 0;
		}
		$tmp = $ids[$target];
		$ids[$target] = $ids[$pos];
		$ids[$pos] = $tmp;

		$this->db->begin();
		foreach ($ids as $i => $id) {
			$sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element_line." SET rang = ".((int) $i + 1);
			$sql .= " WHERE rowid = ".((int) $id)." AND fk_lot = ".((int) $this->id);
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}
		}
		$this->db->commit();
		return 1;
	}
}

/**
 * Ligne d'un lot de produits
 */
class DiamantLotLine
{
	public $id;

	public $fk_lot;

	public $rang = 0;

	public $fk_product;

	public $qty = 1;

	/** Produit (lecture) : null si le produit a été supprimé */
	public $product_ref;

	public $product_label;

	public $product_type;

	public $product_tosell;
}
