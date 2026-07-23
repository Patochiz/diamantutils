<?php
/**
 * Hook sur la page facture (invoicecard)
 *
 * Pré-remplit l'extrafield "factures" avec la liste des factures déjà liées
 * aux mêmes lignes de commande, avant l'affichage du formulaire de création.
 */

class ActionsDiamantutils
{
	public $db;
	public $error = '';
	public $errors = array();
	public $resprints = '';
	public $results = array();

	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * @param array       $parameters Paramètres du hook
	 * @param CommonObject $object    Objet Facture
	 * @param string      $action    Action en cours
	 * @param HookManager $hookmanager
	 * @return int 0=OK, <0=erreur
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $langs;

		if (!isModEnabled('diamantutils')) {
			return 0;
		}

		$mode = getDolGlobalString('DIAMANTUTILS_INVOICE_CHECK_MODE', 'AFFICHER');
		if ($mode == 'DESACTIVE') {
			return 0;
		}

		if ($action != 'create') {
			return 0;
		}

		$origin = GETPOST('origin', 'alpha');
		$originid = GETPOSTINT('originid');

		if ($origin != 'commande' || $originid <= 0) {
			return 0;
		}

		$langs->load('diamantutils@diamantutils');

		$db = $this->db;

		$sql = "SELECT DISTINCT f.rowid, f.ref, f.datef";
		$sql .= " FROM ".MAIN_DB_PREFIX."commandedet as cd";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."facturedet as fd ON fd.fk_origin_line = cd.rowid";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."facture as f ON f.rowid = fd.fk_facture";
		$sql .= " WHERE cd.fk_commande = ".((int) $originid);
		$sql .= " AND f.fk_statut <> 3";
		$sql .= " ORDER BY f.datef, f.ref";

		$resql = $db->query($sql);
		if (!$resql) {
			return 0;
		}

		$invoices = array();
		while ($row = $db->fetch_object($resql)) {
			$invoices[] = $row;
		}
		$db->free($resql);

		if (empty($invoices)) {
			return 0;
		}

		$html = '';
		foreach ($invoices as $inv) {
			$url = DOL_URL_ROOT.'/compta/facture/card.php?facid='.((int) $inv->rowid);
			$ref = dol_escape_htmltag($inv->ref);
			$date = dol_print_date($db->jdate($inv->datef), 'day');
			$html .= '<a href="'.$url.'">'.$ref.'</a> ('.$date.')<br>'."\n";
		}

		if (!isset($object->array_options)) {
			$object->array_options = array();
		}
		$object->array_options['options_factures'] = $html;

		return 0;
	}
}
