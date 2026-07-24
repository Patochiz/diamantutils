<?php
/**
 * Hook sur la page facture (invoicecard)
 *
 * Pré-remplit l'extrafield "factures" avec la liste des factures déjà liées
 * aux mêmes lignes de commande, sur le formulaire de création de facture.
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
	 * Recherche les factures liées à une commande donnée.
	 *
	 * @param int $orderId ID de la commande
	 * @return string HTML avec les liens vers les factures, vide si aucune
	 */
	private function getRelatedInvoicesHtml($orderId)
	{
		$db = $this->db;

		$sql = "SELECT DISTINCT f.rowid, f.ref, f.datef";
		$sql .= " FROM ".MAIN_DB_PREFIX."commandedet as cd";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."facturedet as fd ON fd.fk_origin_line = cd.rowid";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."facture as f ON f.rowid = fd.fk_facture";
		$sql .= " WHERE cd.fk_commande = ".((int) $orderId);
		$sql .= " AND f.fk_statut <> 3";
		$sql .= " ORDER BY f.datef, f.ref";

		$resql = $db->query($sql);
		if (!$resql) {
			return '';
		}

		$invoices = array();
		while ($row = $db->fetch_object($resql)) {
			$invoices[] = $row;
		}
		$db->free($resql);

		if (empty($invoices)) {
			return '';
		}

		$html = '';
		foreach ($invoices as $inv) {
			$url = DOL_URL_ROOT.'/compta/facture/card.php?facid='.((int) $inv->rowid);
			$ref = dol_escape_htmltag($inv->ref);
			$date = dol_print_date($db->jdate($inv->datef), 'day');
			$html .= '<a href="'.$url.'">'.$ref.'</a> ('.$date.')<br>'."\n";
		}

		return $html;
	}

	/**
	 * Hook doActions — s'exécute au début du traitement de la page.
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

		$html = $this->getRelatedInvoicesHtml($originid);
		if (empty($html)) {
			return 0;
		}

		if (!isset($object->array_options)) {
			$object->array_options = array();
		}
		$object->array_options['options_factures'] = $html;

		return 0;
	}

	/**
	 * Hook formObjectOptions — s'exécute pendant le rendu du formulaire,
	 * juste avant showOptionals (extrafields).
	 */
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
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

		$html = $this->getRelatedInvoicesHtml($originid);
		if (empty($html)) {
			return 0;
		}

		if (!isset($object->array_options)) {
			$object->array_options = array();
		}
		$object->array_options['options_factures'] = $html;

		$jsHtml = json_encode($html);
		$this->resprints = '<script type="text/javascript">'."\n";
		$this->resprints .= 'jQuery(document).ready(function() {'."\n";
		$this->resprints .= '  var htmlVal = '.$jsHtml.';'."\n";
		$this->resprints .= '  var el = document.getElementById("options_factures");'."\n";
		$this->resprints .= '  if (el) { el.value = htmlVal; el.innerHTML = htmlVal; }'."\n";
		$this->resprints .= '  if (typeof CKEDITOR !== "undefined") {'."\n";
		$this->resprints .= '    if (CKEDITOR.instances && CKEDITOR.instances["options_factures"]) {'."\n";
		$this->resprints .= '      CKEDITOR.instances["options_factures"].setData(htmlVal);'."\n";
		$this->resprints .= '    } else {'."\n";
		$this->resprints .= '      CKEDITOR.on("instanceReady", function(evt) {'."\n";
		$this->resprints .= '        if (evt.editor.name === "options_factures") {'."\n";
		$this->resprints .= '          evt.editor.setData(htmlVal);'."\n";
		$this->resprints .= '        }'."\n";
		$this->resprints .= '      });'."\n";
		$this->resprints .= '    }'."\n";
		$this->resprints .= '  }'."\n";
		$this->resprints .= '});'."\n";
		$this->resprints .= '</script>'."\n";

		return 0;
	}
}
