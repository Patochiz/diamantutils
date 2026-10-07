<?php
/**
 * Ordre de transformation (OT) de stock par lots
 *
 * Un ordre = N lignes consommées (IN) + M lignes produites (OUT)
 * + des pertes éventuelles (LOSS).
 * Workflow : brouillon → validé (lignes figées, aucun mouvement) → consommé (tous les
 * mouvements de stock dans une transaction unique : tout ou rien) → annulé.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
dol_include_once('/diamantutils/lib/diamantutils.lib.php');

class Transformation extends CommonObject
{
	const STATUS_DRAFT = 0;
	const STATUS_VALIDATED = 1;
	const STATUS_CONSUMED = 2;
	const STATUS_CANCELED = 9;

	const DIRECTION_IN = 'IN';
	const DIRECTION_OUT = 'OUT';
	const DIRECTION_LOSS = 'LOSS';

	public $element = 'diamantutils_transfo';
	public $table_element = 'diamantutils_transfo';
	public $table_element_line = 'diamantutils_transfo_det';
	public $fk_element = 'fk_transfo';
	public $picto = 'stock';
	public $ismultientitymanaged = 1;

	public $ref;
	public $label;
	public $type = 'DECOUPE';
	public $fk_warehouse;
	public $fk_commandedet;
	public $fk_commande;
	public $status = 0;
	public $note;
	public $date_creation;
	public $date_valid;
	public $fk_user_creat;
	public $fk_user_valid;
	public $date_consume;
	public $fk_user_consume;

	/** @var TransformationLine[] */
	public $lines = array();

	/** @var string[] Avertissements non bloquants remontés par checkBalance() / validate() */
	public $warnings = array();

	/**
	 * @param	DoliDB	$db		Base de données
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Types de transformation disponibles
	 *
	 * @return	array	code => libellé traduit
	 */
	public static function getTypes()
	{
		global $langs;
		return array(
			'DECOUPE' => $langs->trans('DiamantutilsTypeDECOUPE'),
			'PEINTURE' => $langs->trans('DiamantutilsTypePEINTURE'),
			'AUTRE' => $langs->trans('DiamantutilsTypeAUTRE'),
		);
	}

	/**
	 * Prochaine référence : OT-AAMM-NNNN, compteur par mois
	 *
	 * @return	string
	 */
	public function getNextRef()
	{
		global $conf;

		$prefix = 'OT-'.dol_print_date(dol_now(), '%y%m').'-';
		$posnum = dol_strlen($prefix) + 1;

		$sql = "SELECT MAX(CAST(SUBSTRING(ref FROM ".$posnum.") AS SIGNED)) as maxnum";
		$sql .= " FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE ref LIKE '".$this->db->escape($prefix)."%'";
		$sql .= " AND entity = ".((int) $conf->entity);

		$max = 0;
		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			if ($obj) {
				$max = (int) $obj->maxnum;
			}
			$this->db->free($resql);
		}

		return $prefix.sprintf('%04d', $max + 1);
	}

	/**
	 * Crée la transformation (brouillon)
	 *
	 * @param	User	$user		Utilisateur
	 * @return	int					id si OK, <0 si KO
	 */
	public function create($user)
	{
		global $conf;

		if (!in_array($this->type, array_keys(self::getTypes()))) {
			$this->type = 'AUTRE';
		}
		if (!($this->fk_warehouse > 0)) {
			$this->error = 'ErrorFieldRequired';
			$this->errors[] = $this->error;
			return -1;
		}

		$now = dol_now();
		$this->ref = $this->getNextRef();
		$this->entity = $conf->entity;
		$this->status = self::STATUS_DRAFT;
		$this->date_creation = $now;
		$this->fk_user_creat = $user->id;

		$sql = "INSERT INTO ".MAIN_DB_PREFIX.$this->table_element." (";
		$sql .= "entity, ref, label, type, fk_warehouse, fk_commandedet, fk_commande, status, note, date_creation, fk_user_creat";
		$sql .= ") VALUES (";
		$sql .= ((int) $this->entity);
		$sql .= ", '".$this->db->escape($this->ref)."'";
		$sql .= ", ".($this->label !== null && $this->label !== '' ? "'".$this->db->escape($this->label)."'" : "NULL");
		$sql .= ", '".$this->db->escape($this->type)."'";
		$sql .= ", ".((int) $this->fk_warehouse);
		$sql .= ", ".($this->fk_commandedet > 0 ? ((int) $this->fk_commandedet) : "NULL");
		$sql .= ", ".($this->fk_commande > 0 ? ((int) $this->fk_commande) : "NULL");
		$sql .= ", ".((int) $this->status);
		$sql .= ", ".($this->note !== null && $this->note !== '' ? "'".$this->db->escape($this->note)."'" : "NULL");
		$sql .= ", '".$this->db->idate($now)."'";
		$sql .= ", ".((int) $user->id);
		$sql .= ")";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			return -1;
		}

		$this->id = $this->db->last_insert_id(MAIN_DB_PREFIX.$this->table_element);
		return $this->id;
	}

	/**
	 * Charge la transformation et ses lignes
	 *
	 * @param	int		$id		Id
	 * @param	string	$ref	Référence
	 * @return	int				>0 si OK, 0 si introuvable, <0 si KO
	 */
	public function fetch($id, $ref = '')
	{
		$sql = "SELECT t.rowid, t.entity, t.ref, t.label, t.type, t.fk_warehouse, t.fk_commandedet, t.fk_commande,";
		$sql .= " t.status, t.note, t.date_creation, t.date_valid, t.fk_user_creat, t.fk_user_valid, t.date_consume, t.fk_user_consume";
		$sql .= " FROM ".MAIN_DB_PREFIX.$this->table_element." as t";
		if ($id > 0) {
			$sql .= " WHERE t.rowid = ".((int) $id);
		} else {
			$sql .= " WHERE t.ref = '".$this->db->escape($ref)."'";
		}
		$sql .= " AND t.entity IN (".getEntity($this->element).")";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj) {
			return 0;
		}

		$this->id = (int) $obj->rowid;
		$this->entity = (int) $obj->entity;
		$this->ref = $obj->ref;
		$this->label = $obj->label;
		$this->type = $obj->type;
		$this->fk_warehouse = (int) $obj->fk_warehouse;
		$this->fk_commandedet = (int) $obj->fk_commandedet;
		$this->fk_commande = (int) $obj->fk_commande;
		$this->status = (int) $obj->status;
		$this->note = $obj->note;
		$this->date_creation = $this->db->jdate($obj->date_creation);
		$this->date_valid = $this->db->jdate($obj->date_valid);
		$this->fk_user_creat = (int) $obj->fk_user_creat;
		$this->fk_user_valid = (int) $obj->fk_user_valid;
		$this->date_consume = $this->db->jdate($obj->date_consume);
		$this->fk_user_consume = (int) $obj->fk_user_consume;

		if ($this->fetchLines() < 0) {
			return -1;
		}

		return 1;
	}

	/**
	 * Charge les lignes
	 *
	 * @return	int		>=0 si OK, <0 si KO
	 */
	public function fetchLines()
	{
		$this->lines = array();

		$sql = "SELECT d.rowid, d.fk_transfo, d.direction, d.fk_product, d.batch, d.nb_pieces, d.length_mm, d.qty,";
		$sql .= " d.unit_cost, d.fk_stock_mouvement, d.rang";
		$sql .= " FROM ".MAIN_DB_PREFIX.$this->table_element_line." as d";
		$sql .= " WHERE d.fk_transfo = ".((int) $this->id);
		$sql .= " ORDER BY d.rang, d.rowid";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			return -1;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$line = new TransformationLine($this->db);
			$line->id = (int) $obj->rowid;
			$line->fk_transfo = (int) $obj->fk_transfo;
			$line->direction = $obj->direction;
			$line->fk_product = (int) $obj->fk_product;
			$line->batch = (string) $obj->batch;
			$line->nb_pieces = ($obj->nb_pieces === null ? null : (float) $obj->nb_pieces);
			$line->length_mm = ($obj->length_mm === null ? null : (float) $obj->length_mm);
			$line->qty = (float) $obj->qty;
			$line->unit_cost = ($obj->unit_cost === null ? null : (float) $obj->unit_cost);
			$line->fk_stock_mouvement = (int) $obj->fk_stock_mouvement;
			$line->rang = (int) $obj->rang;
			$this->lines[] = $line;
		}
		$this->db->free($resql);

		return count($this->lines);
	}

	/**
	 * Met à jour l'en-tête (brouillon uniquement)
	 *
	 * @param	User	$user	Utilisateur
	 * @return	int				>0 si OK, <0 si KO
	 */
	public function update($user)
	{
		global $langs;

		if ($this->status != self::STATUS_DRAFT) {
			$this->error = $langs->trans('DiamantutilsErrorNotDraft');
			$this->errors[] = $this->error;
			return -1;
		}
		if (!in_array($this->type, array_keys(self::getTypes()))) {
			$this->type = 'AUTRE';
		}

		$sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element." SET";
		$sql .= " label = ".($this->label !== null && $this->label !== '' ? "'".$this->db->escape($this->label)."'" : "NULL");
		$sql .= ", type = '".$this->db->escape($this->type)."'";
		$sql .= ", fk_warehouse = ".((int) $this->fk_warehouse);
		$sql .= ", fk_commandedet = ".($this->fk_commandedet > 0 ? ((int) $this->fk_commandedet) : "NULL");
		$sql .= ", fk_commande = ".($this->fk_commande > 0 ? ((int) $this->fk_commande) : "NULL");
		$sql .= ", note = ".($this->note !== null && $this->note !== '' ? "'".$this->db->escape($this->note)."'" : "NULL");
		$sql .= " WHERE rowid = ".((int) $this->id);
		$sql .= " AND status = ".self::STATUS_DRAFT;

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			return -1;
		}
		return 1;
	}

	/**
	 * Supprime une transformation brouillon et ses lignes
	 *
	 * @param	User	$user	Utilisateur
	 * @return	int				>0 si OK, <0 si KO
	 */
	public function delete($user)
	{
		global $langs;

		if ($this->status != self::STATUS_DRAFT) {
			$this->error = $langs->trans('DiamantutilsErrorNotDraft');
			$this->errors[] = $this->error;
			return -1;
		}

		$this->db->begin();

		$sql = "DELETE FROM ".MAIN_DB_PREFIX.$this->table_element_line." WHERE fk_transfo = ".((int) $this->id);
		$res1 = $this->db->query($sql);
		$sql = "DELETE FROM ".MAIN_DB_PREFIX.$this->table_element." WHERE rowid = ".((int) $this->id)." AND status = ".self::STATUS_DRAFT;
		$res2 = $this->db->query($sql);

		if (!$res1 || !$res2) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();
		return 1;
	}

	/**
	 * Ajoute une ligne (brouillon uniquement). La quantité est recalculée côté serveur
	 * en mode profilé (§3) : pour une ligne IN, la longueur est lue sur le lot.
	 *
	 * @param	string	$direction		IN | OUT | LOSS
	 * @param	int		$fk_product		Id produit
	 * @param	string	$batch			Lot
	 * @param	float	$nb_pieces		Nombre de pièces (saisie profilé)
	 * @param	float	$length_mm		Longueur mm (saisie profilé, ignorée en IN)
	 * @param	float	$qty			Quantité saisie directement
	 * @param	int		$rang			Ordre
	 * @return	int						id de ligne si OK, <0 si KO
	 */
	public function addLine($direction, $fk_product, $batch, $nb_pieces, $length_mm, $qty, $rang = 0)
	{
		global $langs;

		if ($this->status != self::STATUS_DRAFT) {
			$this->error = $langs->trans('DiamantutilsErrorNotDraft');
			$this->errors[] = $this->error;
			return -1;
		}
		if (!in_array($direction, array(self::DIRECTION_IN, self::DIRECTION_OUT, self::DIRECTION_LOSS))) {
			$this->error = 'Bad direction';
			$this->errors[] = $this->error;
			return -1;
		}

		$info = diamantutils_product_info($this->db, $fk_product);
		if (empty($info)) {
			$this->error = $langs->trans('DiamantutilsErrorProductNotFound', (int) $fk_product);
			$this->errors[] = $this->error;
			return -1;
		}

		$batch = trim((string) $batch);
		if ($direction == self::DIRECTION_IN) {
			// Ligne consommée : la longueur vient du lot, seulement en mode longueur
			$length_mm = (diamantutils_is_profile($info) ? diamantutils_lot_length($batch) : null);
		}
		$computed = diamantutils_compute_line($info, $nb_pieces, $length_mm, $qty);

		$sql = "INSERT INTO ".MAIN_DB_PREFIX.$this->table_element_line." (";
		$sql .= "fk_transfo, direction, fk_product, batch, nb_pieces, length_mm, qty, rang";
		$sql .= ") VALUES (";
		$sql .= ((int) $this->id);
		$sql .= ", '".$this->db->escape($direction)."'";
		$sql .= ", ".((int) $fk_product);
		$sql .= ", ".($batch !== '' ? "'".$this->db->escape($batch)."'" : "NULL");
		$sql .= ", ".($computed['nb_pieces'] !== null ? ((float) $computed['nb_pieces']) : "NULL");
		$sql .= ", ".($computed['length_mm'] !== null ? ((float) $computed['length_mm']) : "NULL");
		$sql .= ", ".((float) $computed['qty']);
		$sql .= ", ".((int) $rang);
		$sql .= ")";

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			return -1;
		}

		return $this->db->last_insert_id(MAIN_DB_PREFIX.$this->table_element_line);
	}

	/**
	 * Supprime une ligne (brouillon uniquement)
	 *
	 * @param	int		$lineid		Id de ligne
	 * @return	int					>0 si OK, <0 si KO
	 */
	public function deleteLine($lineid)
	{
		global $langs;

		if ($this->status != self::STATUS_DRAFT) {
			$this->error = $langs->trans('DiamantutilsErrorNotDraft');
			$this->errors[] = $this->error;
			return -1;
		}

		$sql = "DELETE FROM ".MAIN_DB_PREFIX.$this->table_element_line;
		$sql .= " WHERE rowid = ".((int) $lineid)." AND fk_transfo = ".((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			return -1;
		}
		return 1;
	}

	/**
	 * Remplace toutes les lignes du brouillon (enregistrement de l'écran de saisie)
	 *
	 * @param	array	$lines	Liste de array(direction, fk_product, batch, nb_pieces, length_mm, qty)
	 * @return	int				>0 si OK, <0 si KO
	 */
	public function setLines($lines)
	{
		global $langs;

		if ($this->status != self::STATUS_DRAFT) {
			$this->error = $langs->trans('DiamantutilsErrorNotDraft');
			$this->errors[] = $this->error;
			return -1;
		}

		$this->db->begin();

		$sql = "DELETE FROM ".MAIN_DB_PREFIX.$this->table_element_line." WHERE fk_transfo = ".((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			$this->db->rollback();
			return -1;
		}

		$rang = 0;
		foreach ($lines as $l) {
			$rang++;
			$res = $this->addLine($l['direction'], $l['fk_product'], $l['batch'], $l['nb_pieces'], $l['length_mm'], $l['qty'], $rang);
			if ($res < 0) {
				$this->db->rollback();
				return -1;
			}
		}

		$this->db->commit();
		$this->fetchLines();
		return 1;
	}

	/**
	 * Recalcule côté serveur les quantités des lignes en mode profilé (le serveur fait foi)
	 *
	 * @return	int		>0 si OK, <0 si KO
	 */
	protected function recomputeLines()
	{
		foreach ($this->lines as $line) {
			$info = diamantutils_product_info($this->db, $line->fk_product);
			if (empty($info)) {
				continue;
			}
			if ($line->direction == self::DIRECTION_IN) {
				$length = (diamantutils_is_profile($info) ? diamantutils_lot_length($line->batch) : null);
			} else {
				$length = $line->length_mm;
			}
			$computed = diamantutils_compute_line($info, $line->nb_pieces, $length, $line->qty);
			if ((string) price2num($computed['qty'], 'MS') !== (string) price2num($line->qty, 'MS')
				|| $computed['length_mm'] != $line->length_mm || $computed['nb_pieces'] != $line->nb_pieces) {
				$line->qty = $computed['qty'];
				$line->nb_pieces = $computed['nb_pieces'];
				$line->length_mm = $computed['length_mm'];
				$sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element_line." SET";
				$sql .= " qty = ".((float) $line->qty);
				$sql .= ", nb_pieces = ".($line->nb_pieces !== null ? ((float) $line->nb_pieces) : "NULL");
				$sql .= ", length_mm = ".($line->length_mm !== null ? ((float) $line->length_mm) : "NULL");
				$sql .= " WHERE rowid = ".((int) $line->id);
				if (!$this->db->query($sql)) {
					$this->error = $this->db->lasterror();
					$this->errors[] = $this->error;
					return -1;
				}
			}
		}
		return 1;
	}

	/**
	 * Contrôle d'équilibre (R4).
	 * Si tous les produits ont la même unité (fk_unit identique et non nul) :
	 * Σ consommé = Σ produit + perte, à la tolérance d'arrondi près.
	 * Sinon pas de contrôle de somme, juste un avertissement.
	 *
	 * @return	array	array('sameunit', 'in', 'out', 'loss', 'diff', 'ok', 'unit')
	 */
	public function checkBalance()
	{
		$units = array();
		$sum = array(self::DIRECTION_IN => 0, self::DIRECTION_OUT => 0, self::DIRECTION_LOSS => 0);

		foreach ($this->lines as $line) {
			$info = diamantutils_product_info($this->db, $line->fk_product);
			$units[(int) (empty($info) ? 0 : $info['fk_unit'])] = (empty($info) ? '' : $info['unit_short']);
			$sum[$line->direction] += (float) $line->qty;
		}

		$sameunit = (count($units) == 1 && !isset($units[0]));
		$diff = (float) price2num($sum[self::DIRECTION_IN] - $sum[self::DIRECTION_OUT] - $sum[self::DIRECTION_LOSS], 'MS');
		$tolerance = pow(10, -getDolGlobalInt('MAIN_MAX_DECIMALS_STOCK', 5)) * max(1, count($this->lines));

		return array(
			'sameunit' => $sameunit,
			'unit' => ($sameunit ? reset($units) : ''),
			'in' => (float) price2num($sum[self::DIRECTION_IN], 'MS'),
			'out' => (float) price2num($sum[self::DIRECTION_OUT], 'MS'),
			'loss' => (float) price2num($sum[self::DIRECTION_LOSS], 'MS'),
			'diff' => $diff,
			'ok' => (!$sameunit || abs($diff) <= $tolerance),
		);
	}

	/**
	 * Libellé des mouvements de stock : ref + type + ref commande client si liée
	 *
	 * @return	string
	 */
	protected function getMovementLabel()
	{
		$types = self::getTypes();
		$label = $this->ref.' '.(isset($types[$this->type]) ? $types[$this->type] : $this->type);
		$orderref = $this->getOrderRef();
		if ($orderref) {
			$label .= ' '.$orderref;
		}
		return dol_trunc($label, 250, 'right', 'UTF-8', 1);
	}

	/**
	 * Référence de la commande client liée
	 *
	 * @return	string
	 */
	public function getOrderRef()
	{
		if (!($this->fk_commande > 0)) {
			return '';
		}
		$sql = "SELECT ref FROM ".MAIN_DB_PREFIX."commande WHERE rowid = ".((int) $this->fk_commande);
		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			$this->db->free($resql);
			if ($obj) {
				return $obj->ref;
			}
		}
		return '';
	}

	/**
	 * PMP courant d'un produit (lu en base au moment de la validation)
	 *
	 * @param	int		$fk_product		Id produit
	 * @return	float
	 */
	protected function getCurrentPmp($fk_product)
	{
		$sql = "SELECT pmp FROM ".MAIN_DB_PREFIX."product WHERE rowid = ".((int) $fk_product);
		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			$this->db->free($resql);
			if ($obj) {
				return (float) $obj->pmp;
			}
		}
		return 0;
	}

	/**
	 * Lot à transmettre au mouvement : vide si le produit n'est pas géré en lot
	 *
	 * @param	TransformationLine	$line	Ligne
	 * @return	string
	 */
	protected function getMovementBatch($line)
	{
		$info = diamantutils_product_info($this->db, $line->fk_product);
		if (!isModEnabled('productbatch') || empty($info) || $info['status_batch'] <= 0) {
			return '';
		}
		return (string) $line->batch;
	}

	/**
	 * Contrôles bloquants de la validation : lignes, R3, R4, R5
	 *
	 * @return	int		>0 si OK, <0 si KO (erreurs dans $this->errors)
	 */
	protected function checkLines()
	{
		global $langs;

		$nberrors = count($this->errors);
		$nbin = 0;
		$nbout = 0;

		foreach ($this->lines as $i => $line) {
			$info = diamantutils_product_info($this->db, $line->fk_product);
			$numline = $i + 1;
			if (empty($info)) {
				$this->errors[] = $langs->trans('DiamantutilsErrorProductNotFound', $line->fk_product);
				continue;
			}
			if (!($line->qty > 0)) {
				$this->errors[] = $langs->trans('DiamantutilsErrorLineQty', $numline, $info['ref']);
				continue;
			}
			// R5 : lot obligatoire sur les lignes qui créent un mouvement d'un produit géré en lot
			if ($line->direction != self::DIRECTION_LOSS && isModEnabled('productbatch') && $info['status_batch'] > 0 && $line->batch === '') {
				$this->errors[] = $langs->trans('DiamantutilsErrorBatchRequired', $numline, $info['ref']);
			}
			if ($line->direction == self::DIRECTION_IN) {
				$nbin++;
			} elseif ($line->direction == self::DIRECTION_OUT) {
				$nbout++;
			}
		}

		// R3 : au moins une ligne consommée et une ligne produite
		if ($nbin < 1) {
			$this->errors[] = $langs->trans('DiamantutilsErrorNoLineIn');
		}
		if ($nbout < 1) {
			$this->errors[] = $langs->trans('DiamantutilsErrorNoLineOut');
		}

		// R4 : équilibre si même unité
		$balance = $this->checkBalance();
		if (!$balance['ok']) {
			$this->errors[] = $langs->trans('DiamantutilsErrorBalance', diamantutils_qty_format($balance['in']), diamantutils_qty_format($balance['out']), diamantutils_qty_format($balance['loss']), $balance['unit']);
		} elseif (!$balance['sameunit'] && count($this->lines)) {
			$this->warnings[] = $langs->trans('DiamantutilsWarningUnitsDiffer');
		}

		return (count($this->errors) > $nberrors ? -1 : 1);
	}

	/**
	 * R6 : stock disponible pour chaque couple (produit, entrepôt, lot) consommé,
	 * en cumulant les lignes d'un même lot.
	 *
	 * @return	string[]	Messages, un par couple en défaut (vide si tout est disponible)
	 */
	public function checkStock()
	{
		global $langs;

		$needed = array();	// [fk_product][batch] => qty consommée cumulée
		foreach ($this->lines as $line) {
			if ($line->direction != self::DIRECTION_IN) {
				continue;
			}
			$batch = $this->getMovementBatch($line);
			if (!isset($needed[$line->fk_product][$batch])) {
				$needed[$line->fk_product][$batch] = 0;
			}
			$needed[$line->fk_product][$batch] += $line->qty;
		}

		$messages = array();
		foreach ($needed as $fk_product => $batches) {
			$info = diamantutils_product_info($this->db, $fk_product);
			foreach ($batches as $batch => $qty) {
				$stock = diamantutils_stock_qty($this->db, $fk_product, $this->fk_warehouse, (string) $batch);
				if ((float) price2num($stock - $qty, 'MS') < 0) {
					$messages[] = $langs->trans('DiamantutilsErrorStockInsufficient', (empty($info) ? $fk_product : $info['ref']), ((string) $batch !== '' ? $batch : '-'), diamantutils_qty_format($stock), diamantutils_qty_format($qty));
				}
			}
		}
		return $messages;
	}

	/**
	 * Coût : Σ (qty IN × PMP actuel du produit IN), réparti au prorata des quantités OUT.
	 * La perte n'absorbe pas de coût : elle est portée par les sorties.
	 *
	 * @return	array	array('pmp' => [fk_product => PMP], 'total' => coût total, 'unit_out' => coût unitaire des sorties)
	 */
	public function computeCost()
	{
		$pmps = array();
		$totalcost = 0;
		$totalout = 0;
		foreach ($this->lines as $line) {
			if ($line->direction == self::DIRECTION_IN) {
				if (!isset($pmps[$line->fk_product])) {
					$pmps[$line->fk_product] = $this->getCurrentPmp($line->fk_product);
				}
				$totalcost += $line->qty * $pmps[$line->fk_product];
			} elseif ($line->direction == self::DIRECTION_OUT) {
				$totalout += $line->qty;
			}
		}
		return array(
			'pmp' => $pmps,
			'total' => $totalcost,
			'unit_out' => ($totalout > 0 ? (float) price2num($totalcost / $totalout, 'MU') : 0),
		);
	}

	/**
	 * Indique si un lot existe déjà pour un produit (llx_product_lot)
	 *
	 * @param	int		$fk_product		Id produit
	 * @param	string	$batch			Lot
	 * @return	bool
	 */
	protected function lotExists($fk_product, $batch)
	{
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."product_lot";
		$sql .= " WHERE fk_product = ".((int) $fk_product)." AND batch = '".$this->db->escape($batch)."'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			return false;
		}
		$exists = ($this->db->num_rows($resql) > 0);
		$this->db->free($resql);
		return $exists;
	}

	/**
	 * Mouvements prévus, une ligne par couple (produit, lot), stock actuel lu en temps réel.
	 * Les pertes forment des lignes à part, sans stock.
	 *
	 * @return	array	Liste de array('fk_product', 'ref', 'unit', 'batch', 'stock', 'delta', 'after', 'newlot', 'loss', 'negative')
	 */
	public function getPlannedMovements()
	{
		$rows = array();
		foreach ($this->lines as $line) {
			$info = diamantutils_product_info($this->db, $line->fk_product);
			$batch = $this->getMovementBatch($line);
			$loss = ($line->direction == self::DIRECTION_LOSS);
			$key = ($loss ? 'LOSS|' : 'MVT|').$line->fk_product.'|'.$batch;
			if (!isset($rows[$key])) {
				$rows[$key] = array(
					'fk_product' => $line->fk_product,
					'ref' => (empty($info) ? '' : $info['ref']),
					'unit' => (empty($info) ? '' : $info['unit_short']),
					'batch' => ($loss ? '' : $batch),
					'stock' => null,
					'delta' => 0,
					'after' => null,
					'newlot' => false,
					'loss' => $loss,
					'negative' => false,
				);
			}
			$rows[$key]['delta'] += ($line->direction == self::DIRECTION_OUT ? $line->qty : -$line->qty);
		}

		foreach ($rows as $key => $row) {
			$rows[$key]['delta'] = (float) price2num($row['delta'], 'MS');
			if ($row['loss']) {
				continue;
			}
			$stock = diamantutils_stock_qty($this->db, $row['fk_product'], $this->fk_warehouse, $row['batch']);
			$rows[$key]['stock'] = (float) price2num($stock, 'MS');
			$rows[$key]['after'] = (float) price2num($stock + $rows[$key]['delta'], 'MS');
			$rows[$key]['negative'] = ($rows[$key]['after'] < 0);
			$rows[$key]['newlot'] = ($row['batch'] !== '' && !$this->lotExists($row['fk_product'], $row['batch']));
		}

		return array_values($rows);
	}

	/**
	 * Valide l'ordre (brouillon → validé) : recalcul serveur, contrôles R3, R4, R5 bloquants.
	 * R6 n'est qu'un avertissement à cette étape. Aucun mouvement de stock.
	 *
	 * @param	User	$user	Utilisateur
	 * @return	int				>0 si OK, <0 si KO (erreurs dans $this->errors, avertissements dans $this->warnings)
	 */
	public function validate($user)
	{
		global $langs;

		if ($this->status != self::STATUS_DRAFT) {
			$this->errors[] = $langs->trans('DiamantutilsErrorNotDraft');
			return -1;
		}

		$this->db->begin();

		if ($this->fetchLines() < 0 || $this->recomputeLines() < 0 || $this->checkLines() < 0) {
			$this->db->rollback();
			$this->fetchLines();
			return -1;
		}
		foreach ($this->checkStock() as $msg) {
			$this->warnings[] = $msg;
		}

		$now = dol_now();
		$sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element." SET";
		$sql .= " status = ".self::STATUS_VALIDATED;
		$sql .= ", date_valid = '".$this->db->idate($now)."'";
		$sql .= ", fk_user_valid = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $this->id)." AND status = ".self::STATUS_DRAFT;
		if (!$this->db->query($sql)) {
			$this->errors[] = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();
		$this->status = self::STATUS_VALIDATED;
		$this->date_valid = $now;
		$this->fk_user_valid = $user->id;
		return 1;
	}

	/**
	 * Remet un ordre validé en brouillon
	 *
	 * @param	User	$user	Utilisateur
	 * @return	int				>0 si OK, <0 si KO
	 */
	public function setDraft($user)
	{
		global $langs;

		if ($this->status != self::STATUS_VALIDATED) {
			$this->errors[] = $langs->trans('DiamantutilsErrorNotValidated');
			return -1;
		}

		$sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element." SET status = ".self::STATUS_DRAFT;
		$sql .= ", date_valid = NULL, fk_user_valid = NULL";
		$sql .= " WHERE rowid = ".((int) $this->id)." AND status = ".self::STATUS_VALIDATED;
		if (!$this->db->query($sql)) {
			$this->errors[] = $this->db->lasterror();
			return -1;
		}
		$this->status = self::STATUS_DRAFT;
		$this->date_valid = null;
		$this->fk_user_valid = 0;
		return 1;
	}

	/**
	 * Consomme l'ordre (validé → consommé) : re-contrôle R6 bloquant, coût, mouvements de stock.
	 * Tout se fait dans une transaction unique.
	 *
	 * @param	User	$user	Utilisateur
	 * @return	int				>0 si OK, <0 si KO (erreurs dans $this->errors)
	 */
	public function consume($user)
	{
		global $langs;

		require_once DOL_DOCUMENT_ROOT.'/product/stock/class/mouvementstock.class.php';

		if ($this->status != self::STATUS_VALIDATED) {
			$this->errors[] = $langs->trans('DiamantutilsErrorNotValidated');
			return -1;
		}

		$this->db->begin();

		if ($this->fetchLines() < 0) {
			$this->db->rollback();
			return -1;
		}

		// R6 bloquant : le stock a pu bouger depuis la validation
		$shortages = $this->checkStock();
		if (!empty($shortages)) {
			foreach ($shortages as $msg) {
				$this->errors[] = $msg;
			}
			$this->db->rollback();
			return -1;
		}

		$cost = $this->computeCost();
		$label = $this->getMovementLabel();
		$inventorycode = $this->ref;
		$error = 0;

		// Mouvements : d'abord les consommations, puis les productions
		foreach (array(self::DIRECTION_IN, self::DIRECTION_OUT) as $direction) {
			foreach ($this->lines as $line) {
				if ($line->direction != $direction) {
					continue;
				}
				$batch = $this->getMovementBatch($line);
				$mvt = new MouvementStock($this->db);
				$mvt->setOrigin('transformation@diamantutils', $this->id);

				if ($direction == self::DIRECTION_IN) {
					$line->unit_cost = $cost['pmp'][$line->fk_product];
					$result = $mvt->livraison($user, $line->fk_product, $this->fk_warehouse, $line->qty, $line->unit_cost, $label, '', '', '', $batch, 0, $inventorycode);
				} else {
					$line->unit_cost = $cost['unit_out'];
					$result = $mvt->reception($user, $line->fk_product, $this->fk_warehouse, $line->qty, $line->unit_cost, $label, '', '', $batch, '', 0, $inventorycode);
				}

				if ($result <= 0) {
					$error++;
					$this->addMovementErrors($mvt, $line, $batch);
					break 2;
				}

				$sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element_line." SET";
				$sql .= " unit_cost = ".((float) $line->unit_cost);
				$sql .= ", fk_stock_mouvement = ".((int) $result);
				$sql .= " WHERE rowid = ".((int) $line->id);
				if (!$this->db->query($sql)) {
					$error++;
					$this->errors[] = $this->db->lasterror();
					break 2;
				}
				$line->fk_stock_mouvement = (int) $result;
			}
		}

		if (!$error) {
			$now = dol_now();
			$sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element." SET";
			$sql .= " status = ".self::STATUS_CONSUMED;
			$sql .= ", date_consume = '".$this->db->idate($now)."'";
			$sql .= ", fk_user_consume = ".((int) $user->id);
			$sql .= " WHERE rowid = ".((int) $this->id)." AND status = ".self::STATUS_VALIDATED;
			if (!$this->db->query($sql)) {
				$error++;
				$this->errors[] = $this->db->lasterror();
			} else {
				$this->status = self::STATUS_CONSUMED;
				$this->date_consume = $now;
				$this->fk_user_consume = $user->id;
			}
		}

		if ($error) {
			$this->db->rollback();
			// Les valeurs en mémoire ne correspondent plus à la base
			$this->fetchLines();
			return -1;
		}

		$this->db->commit();
		return 1;
	}

	/**
	 * Erreurs d'un mouvement de stock refusé
	 *
	 * @param	MouvementStock		$mvt	Mouvement
	 * @param	TransformationLine	$line	Ligne
	 * @param	string				$batch	Lot
	 * @return	void
	 */
	protected function addMovementErrors($mvt, $line, $batch)
	{
		global $langs;

		$info = diamantutils_product_info($this->db, $line->fk_product);
		$this->errors[] = $langs->trans('DiamantutilsErrorMovement', (empty($info) ? $line->fk_product : $info['ref']), ($batch !== '' ? $batch : '-'));
		if (!empty($mvt->error)) {
			$this->errors[] = $mvt->error;
		}
		foreach ((array) $mvt->errors as $err) {
			if ($err != $mvt->error) {
				$this->errors[] = $err;
			}
		}
	}

	/**
	 * Annule l'ordre.
	 * - Brouillon ou validé : abandon, statut annulé sans mouvement.
	 * - Consommé : mouvements inverses avec l'inventorycode ref-ANN, refus si un lot
	 *   produit n'a plus le stock suffisant.
	 *
	 * @param	User	$user	Utilisateur
	 * @return	int				>0 si OK, <0 si KO
	 */
	public function cancel($user)
	{
		global $langs;

		require_once DOL_DOCUMENT_ROOT.'/product/stock/class/mouvementstock.class.php';

		if (!in_array($this->status, array(self::STATUS_DRAFT, self::STATUS_VALIDATED, self::STATUS_CONSUMED))) {
			$this->errors[] = $langs->trans('DiamantutilsErrorCannotCancel');
			return -1;
		}

		// Abandon d'un ordre sans mouvement
		if ($this->status != self::STATUS_CONSUMED) {
			$sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element." SET status = ".self::STATUS_CANCELED;
			$sql .= " WHERE rowid = ".((int) $this->id)." AND status = ".((int) $this->status);
			if (!$this->db->query($sql)) {
				$this->errors[] = $this->db->lasterror();
				return -1;
			}
			$this->status = self::STATUS_CANCELED;
			return 1;
		}

		$this->db->begin();

		if ($this->fetchLines() < 0) {
			$this->db->rollback();
			return -1;
		}

		// Les lots produits doivent encore avoir le stock suffisant
		$needed = array();
		foreach ($this->lines as $line) {
			if ($line->direction != self::DIRECTION_OUT) {
				continue;
			}
			$batch = $this->getMovementBatch($line);
			if (!isset($needed[$line->fk_product][$batch])) {
				$needed[$line->fk_product][$batch] = 0;
			}
			$needed[$line->fk_product][$batch] += $line->qty;
		}
		$nberrors = count($this->errors);
		foreach ($needed as $fk_product => $batches) {
			$info = diamantutils_product_info($this->db, $fk_product);
			foreach ($batches as $batch => $qty) {
				$stock = diamantutils_stock_qty($this->db, $fk_product, $this->fk_warehouse, (string) $batch);
				if ((float) price2num($stock - $qty, 'MS') < 0) {
					$this->errors[] = $langs->trans('DiamantutilsErrorCancelStock', $info['ref'], ((string) $batch !== '' ? $batch : '-'), diamantutils_qty_format($stock), diamantutils_qty_format($qty));
				}
			}
		}
		if (count($this->errors) > $nberrors) {
			$this->db->rollback();
			return -1;
		}

		$label = $langs->trans('DiamantutilsCancellationOf', $this->getMovementLabel());
		$inventorycode = $this->ref.'-ANN';
		$error = 0;

		// D'abord retirer les productions, puis remettre les consommations
		foreach (array(self::DIRECTION_OUT, self::DIRECTION_IN) as $direction) {
			foreach ($this->lines as $line) {
				if ($line->direction != $direction) {
					continue;
				}
				$batch = $this->getMovementBatch($line);
				$mvt = new MouvementStock($this->db);
				$mvt->setOrigin('transformation@diamantutils', $this->id);

				if ($direction == self::DIRECTION_OUT) {
					$result = $mvt->livraison($user, $line->fk_product, $this->fk_warehouse, $line->qty, (float) $line->unit_cost, $label, '', '', '', $batch, 0, $inventorycode);
				} else {
					$result = $mvt->reception($user, $line->fk_product, $this->fk_warehouse, $line->qty, (float) $line->unit_cost, $label, '', '', $batch, '', 0, $inventorycode);
				}

				if ($result <= 0) {
					$error++;
					$this->addMovementErrors($mvt, $line, $batch);
					break 2;
				}
			}
		}

		if (!$error) {
			$sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element." SET status = ".self::STATUS_CANCELED;
			$sql .= " WHERE rowid = ".((int) $this->id)." AND status = ".self::STATUS_CONSUMED;
			if (!$this->db->query($sql)) {
				$error++;
				$this->errors[] = $this->db->lasterror();
			}
		}

		if ($error) {
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();
		$this->status = self::STATUS_CANCELED;
		return 1;
	}

	/**
	 * Lien vers la fiche
	 *
	 * @param	int		$withpicto	0 = pas de picto, 1 = picto + ref, 2 = picto seul
	 * @return	string
	 */
	public function getNomUrl($withpicto = 0)
	{
		global $langs;

		$url = dol_buildpath('/diamantutils/transformation_card.php', 1).'?id='.((int) $this->id);
		$label = img_picto('', $this->picto).' <u>'.$langs->trans('DiamantutilsOrder').'</u><br><b>'.$langs->trans('Ref').':</b> '.dol_escape_htmltag($this->ref);

		$result = '<a href="'.$url.'" title="'.dol_escape_htmltag($label, 1).'" class="classfortooltip">';
		if ($withpicto) {
			$result .= img_picto('', $this->picto, 'class="paddingright"');
		}
		if ($withpicto != 2) {
			$result .= dol_escape_htmltag($this->ref);
		}
		$result .= '</a>';
		return $result;
	}

	/**
	 * Libellé du statut
	 *
	 * @param	int		$mode	Mode d'affichage (voir dolGetStatus)
	 * @return	string
	 */
	public function getLibStatut($mode = 0)
	{
		return $this->LibStatut($this->status, $mode);
	}

	/**
	 * Libellé d'un statut
	 *
	 * @param	int		$status		Statut
	 * @param	int		$mode		Mode d'affichage
	 * @return	string
	 */
	public function LibStatut($status, $mode = 0)
	{
		global $langs;

		$labels = array(
			self::STATUS_DRAFT => array($langs->transnoentitiesnoconv('Draft'), 'status0'),
			self::STATUS_VALIDATED => array($langs->transnoentitiesnoconv('Validated'), 'status1'),
			self::STATUS_CONSUMED => array($langs->transnoentitiesnoconv('DiamantutilsStatusConsumed'), 'status6'),
			self::STATUS_CANCELED => array($langs->transnoentitiesnoconv('Canceled'), 'status9'),
		);
		if (!isset($labels[$status])) {
			return '';
		}
		return dolGetStatus($labels[$status][0], $labels[$status][0], '', $labels[$status][1], $mode);
	}
}

/**
 * Ligne de transformation
 */
class TransformationLine
{
	public $db;
	public $id;
	public $fk_transfo;
	public $direction;
	public $fk_product;
	public $batch = '';
	public $nb_pieces;
	public $length_mm;
	public $qty = 0;
	public $unit_cost;
	public $fk_stock_mouvement;
	public $rang = 0;

	/**
	 * @param	DoliDB	$db		Base de données
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}
}
