<?php
/**
 * Listes personnalisées : requêtes SQL enregistrées et affichées sous le menu Outils
 *
 * Sécurité de l'exécution :
 * - seules les requêtes SELECT / WITH d'une seule instruction sont acceptées
 *   (mots-clés d'écriture et fonctions dangereuses refusés) ;
 * - la requête s'exécute dans une transaction en lecture seule, annulée ensuite ;
 * - l'écriture du SQL est réservée aux administrateurs ayant le droit de gestion.
 */

class DiamantutilsCustomList
{
	/** Lignes maximum d'un export CSV */
	const EXPORT_MAX_ROWS = 50000;

	/** @var DoliDB */
	public $db;

	public $error = '';
	public $errors = array();

	public $table_element = 'diamantutils_customlist';

	public $id;
	public $entity;
	public $label;
	public $description;
	public $sqlquery;
	public $position = 0;
	public $active = 1;
	public $date_creation;
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
	 * Lien vers l'affichage de la liste
	 *
	 * @param	int		$withpicto	1 = avec picto
	 * @return	string
	 */
	public function getNomUrl($withpicto = 0)
	{
		$url = dol_buildpath('/diamantutils/customlist_view.php', 1).'?id='.((int) $this->id);
		$out = '<a href="'.$url.'" title="'.dol_escape_htmltag($this->description).'">';
		if ($withpicto) {
			$out .= img_picto('', 'list', 'class="pictofixedwidth"');
		}
		$out .= dol_escape_htmltag($this->label).'</a>';
		return $out;
	}

	/**
	 * Charge une liste
	 *
	 * @param	int		$id		Identifiant
	 * @return	int				1 si trouvée, 0 si absente, <0 si erreur
	 */
	public function fetch($id)
	{
		global $conf;

		$sql = "SELECT rowid, entity, label, description, sqlquery, position, active, date_creation, fk_user_creat, fk_user_modif";
		$sql .= " FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE rowid = ".((int) $id);
		$sql .= " AND entity = ".((int) $conf->entity);

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
		return 1;
	}

	/**
	 * Toutes les listes de l'entité, triées par position puis libellé
	 *
	 * @param	int		$activeonly		1 = listes actives seulement
	 * @return	DiamantutilsCustomList[]|int	Tableau, ou <0 si erreur
	 */
	public function fetchAll($activeonly = 1)
	{
		global $conf;

		$sql = "SELECT rowid, entity, label, description, sqlquery, position, active, date_creation, fk_user_creat, fk_user_modif";
		$sql .= " FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE entity = ".((int) $conf->entity);
		if ($activeonly) {
			$sql .= " AND active = 1";
		}
		$sql .= " ORDER BY position ASC, label ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$lists = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$list = new self($this->db);
			$list->setFromObject($obj);
			$lists[] = $list;
		}
		$this->db->free($resql);
		return $lists;
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
		$this->description = $obj->description;
		$this->sqlquery = $obj->sqlquery;
		$this->position = (int) $obj->position;
		$this->active = (int) $obj->active;
		$this->date_creation = $this->db->jdate($obj->date_creation);
		$this->fk_user_creat = (int) $obj->fk_user_creat;
		$this->fk_user_modif = (int) $obj->fk_user_modif;
	}

	/**
	 * Contrôle des champs avant enregistrement
	 *
	 * @return	int		1 si OK, <0 sinon (erreurs dans $this->errors)
	 */
	private function check()
	{
		global $langs;

		$this->errors = array();
		$this->label = trim((string) $this->label);
		$this->sqlquery = trim((string) $this->sqlquery);
		if ($this->label === '') {
			$this->errors[] = $langs->trans('ErrorFieldRequired', $langs->transnoentities('Label'));
		}
		if ($this->sqlquery === '') {
			$this->errors[] = $langs->trans('ErrorFieldRequired', $langs->transnoentities('DiamantutilsCLSql'));
		} elseif (!self::validateSql($this->sqlquery, $error)) {
			$this->errors[] = $error;
		}
		if (!empty($this->errors)) {
			$this->error = implode(', ', $this->errors);
			return -1;
		}
		return 1;
	}

	/**
	 * Crée la liste
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

		$sql = "INSERT INTO ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " (entity, label, description, sqlquery, position, active, date_creation, fk_user_creat)";
		$sql .= " VALUES (".((int) $conf->entity);
		$sql .= ", '".$this->db->escape($this->label)."'";
		$sql .= ", ".((string) $this->description !== '' ? "'".$this->db->escape($this->description)."'" : "NULL");
		$sql .= ", '".$this->db->escape($this->sqlquery)."'";
		$sql .= ", ".((int) $this->position);
		$sql .= ", ".($this->active ? 1 : 0);
		$sql .= ", '".$this->db->idate(dol_now())."'";
		$sql .= ", ".((int) $user->id).")";

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$this->id = $this->db->last_insert_id(MAIN_DB_PREFIX.$this->table_element);
		return $this->id;
	}

	/**
	 * Met à jour la liste
	 *
	 * @param	User	$user	Utilisateur
	 * @return	int				1 si OK, <0 si KO
	 */
	public function update($user)
	{
		global $conf;

		if ($this->check() < 0) {
			return -1;
		}

		$sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element." SET";
		$sql .= " label = '".$this->db->escape($this->label)."'";
		$sql .= ", description = ".((string) $this->description !== '' ? "'".$this->db->escape($this->description)."'" : "NULL");
		$sql .= ", sqlquery = '".$this->db->escape($this->sqlquery)."'";
		$sql .= ", position = ".((int) $this->position);
		$sql .= ", active = ".($this->active ? 1 : 0);
		$sql .= ", fk_user_modif = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $this->id);
		$sql .= " AND entity = ".((int) $conf->entity);

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Supprime la liste
	 *
	 * @return	int		1 si OK, <0 si KO
	 */
	public function delete()
	{
		global $conf;

		$sql = "DELETE FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE rowid = ".((int) $this->id);
		$sql .= " AND entity = ".((int) $conf->entity);

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Vérifie qu'une requête est une lecture simple : une seule instruction SELECT / WITH,
	 * sans mot-clé d'écriture ni fonction dangereuse. Les chaînes entre guillemets et les
	 * commentaires sont ignorés pour la recherche des mots-clés.
	 *
	 * @param	string	$sql		Requête saisie
	 * @param	string	$error		Message d'erreur (sortie)
	 * @return	bool
	 */
	public static function validateSql($sql, &$error = '')
	{
		global $langs;

		$error = '';
		$sql = self::cleanSql($sql);
		if ($sql === '') {
			$error = $langs->trans('DiamantutilsCLSqlOnlySelect');
			return false;
		}

		// Chaînes et commentaires retirés : seuls les mots-clés « en clair » comptent
		$code = preg_replace(array(
			"/'(?:[^'\\\\]|\\\\.|'')*'/s",
			'/"(?:[^"\\\\]|\\\\.|"")*"/s',
			'/\/\*.*?\*\//s',
			'/(--\s|#)[^\n]*/',
		), ' ', $sql);

		if (!preg_match('/^\s*\(?\s*(SELECT|WITH)\b/i', $code)) {
			$error = $langs->trans('DiamantutilsCLSqlOnlySelect');
			return false;
		}
		if (strpos($code, ';') !== false) {
			$error = $langs->trans('DiamantutilsCLSqlOneStatement');
			return false;
		}
		$forbidden = array(
			'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'MERGE', 'UPSERT', 'DROP', 'ALTER', 'CREATE', 'RENAME', 'TRUNCATE',
			'GRANT', 'REVOKE', 'LOCK', 'UNLOCK', 'CALL', 'EXEC', 'EXECUTE', 'PREPARE', 'DEALLOCATE', 'HANDLER', 'SET',
			'COPY', 'OUTFILE', 'DUMPFILE', 'LOAD_FILE', 'SLEEP', 'BENCHMARK', 'GET_LOCK', 'PG_SLEEP', 'PG_READ_FILE',
			'PG_READ_BINARY_FILE', 'LO_IMPORT', 'LO_EXPORT', 'DBLINK',
		);
		if (preg_match('/\b('.implode('|', $forbidden).')\b/i', $code, $reg)) {
			$error = $langs->trans('DiamantutilsCLSqlForbidden', strtoupper($reg[1]));
			return false;
		}
		return true;
	}

	/**
	 * Requête nettoyée : espaces et point-virgule final retirés
	 *
	 * @param	string	$sql	Requête
	 * @return	string
	 */
	public static function cleanSql($sql)
	{
		$sql = trim((string) $sql);
		while (substr($sql, -1) === ';') {
			$sql = rtrim(substr($sql, 0, -1));
		}
		return $sql;
	}

	/**
	 * Requête prête à exécuter : __PREFIX__ et __ENTITY__ remplacés
	 *
	 * @param	string	$sql	Requête saisie
	 * @return	string
	 */
	public static function prepareSql($sql)
	{
		global $conf;
		return strtr(self::cleanSql($sql), array(
			'__PREFIX__' => MAIN_DB_PREFIX,
			'__ENTITY__' => (string) ((int) $conf->entity),
		));
	}

	/**
	 * Exécute la requête de la liste (ou une requête donnée, pour le test avant enregistrement).
	 *
	 * Le tri se fait par numéro de colonne (ORDER BY n sur la requête encapsulée). Sans tri
	 * demandé, l'ordre de la requête est conservé. La recherche et la pagination se font sur
	 * les valeurs lues : elles portent exactement sur ce qui est affiché.
	 *
	 * @param	string		$search		Texte recherché dans toutes les colonnes ('' = aucun)
	 * @param	int			$sortcol	Numéro de colonne de tri (1..n), 0 = ordre de la requête
	 * @param	string		$sortorder	ASC ou DESC
	 * @param	int			$limit		Lignes maximum retournées (0 = toutes)
	 * @param	int			$offset		Lignes sautées (après recherche)
	 * @param	string|null	$sqlquery	Requête à exécuter à la place de celle de la liste
	 * @return	array|int				['columns' => string[], 'rows' => array[], 'total' => int, 'truncated' => bool], ou <0 si erreur
	 */
	public function execute($search = '', $sortcol = 0, $sortorder = 'ASC', $limit = 0, $offset = 0, $sqlquery = null)
	{
		global $langs;

		$sqlquery = ($sqlquery === null ? $this->sqlquery : $sqlquery);
		if (!self::validateSql($sqlquery, $error)) {
			$this->error = $error;
			return -1;
		}

		$sql = self::prepareSql($sqlquery);
		if ($sortcol > 0) {
			$sql = "SELECT * FROM (".$sql.") dcl ORDER BY ".((int) $sortcol).(strtoupper($sortorder) === 'DESC' ? ' DESC' : ' ASC');
		}

		// Transaction en lecture seule : la base refuse toute écriture, même si le filtre était contourné
		$readonly = false;
		if (empty($this->db->transaction_opened)) {
			$begin = (in_array($this->db->type, array('pgsql')) ? 'BEGIN READ ONLY' : 'START TRANSACTION READ ONLY');
			if ($this->db->query($begin)) {
				$readonly = true;
			}
		}
		if (!$readonly) {
			$this->error = $langs->trans('DiamantutilsCLReadOnlyFailed');
			return -1;
		}

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->db->query('ROLLBACK');
			return -1;
		}

		$columns = self::fieldNames($resql);
		$needle = ($search !== '' ? dol_strtolower($search) : '');
		$rows = array();
		$total = 0;
		$truncated = false;
		while ($row = $this->db->fetch_row($resql)) {
			if ($needle !== '' && strpos(dol_strtolower(implode(' ', $row)), $needle) === false) {
				continue;
			}
			$total++;
			if ($total <= $offset || ($limit > 0 && count($rows) >= $limit)) {
				continue;
			}
			$rows[] = $row;
			if ($limit <= 0 && count($rows) >= self::EXPORT_MAX_ROWS) {
				$truncated = true;
				break;
			}
		}
		$this->db->free($resql);
		$this->db->query('ROLLBACK');

		if (empty($columns) && !empty($rows)) {
			$columns = array_keys($rows[0]);
		}

		return array('columns' => $columns, 'rows' => $rows, 'total' => $total, 'truncated' => $truncated);
	}

	/**
	 * Noms des colonnes d'un résultat (même sans ligne)
	 *
	 * @param	mixed	$resql	Résultat de requête
	 * @return	string[]
	 */
	private static function fieldNames($resql)
	{
		$names = array();
		if ($resql instanceof mysqli_result) {
			foreach ($resql->fetch_fields() as $field) {
				$names[] = $field->name;
			}
		} elseif (function_exists('pg_num_fields') && ($resql instanceof \PgSql\Result || is_resource($resql))) {
			$nb = pg_num_fields($resql);
			for ($i = 0; $i < $nb; $i++) {
				$names[] = pg_field_name($resql, $i);
			}
		}
		return $names;
	}
}
