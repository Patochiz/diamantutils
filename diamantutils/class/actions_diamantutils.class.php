<?php
/**
 * Hook sur la page facture (invoicecard)
 *
 * - Pré-remplit l'extrafield "factures" sur le formulaire de création
 *   (création depuis la fiche commande).
 * - Rattrapage : remplit l'extrafield à l'ouverture d'une facture existante
 *   dont le champ est vide (factures créées depuis la liste des commandes).
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
	 * Construit le HTML récapitulatif des factures liées aux commandes
	 * d'une facture existante, en partant des liens element_element.
	 */
	private function buildInvoiceSummaryFromLinkedOrders($invoiceId)
	{
		global $langs, $conf;
		$db = $this->db;

		$orderIds = array();
		$orderTotalHt = 0;
		$sql = "SELECT DISTINCT c.rowid, c.total_ht";
		$sql .= " FROM ".MAIN_DB_PREFIX."element_element as el";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."commande as c ON c.rowid = el.fk_source";
		$sql .= " WHERE el.fk_target = ".((int) $invoiceId);
		$sql .= " AND el.sourcetype = 'commande'";
		$sql .= " AND el.targettype = 'facture'";
		$resql = $db->query($sql);
		if ($resql) {
			while ($row = $db->fetch_object($resql)) {
				$orderIds[] = (int) $row->rowid;
				$orderTotalHt += (float) $row->total_ht;
			}
			$db->free($resql);
		}

		if (empty($orderIds)) {
			return '';
		}

		$sql = "SELECT DISTINCT f.rowid, f.ref, f.datef, f.total_ht";
		$sql .= " FROM ".MAIN_DB_PREFIX."element_element as el";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."facture as f ON f.rowid = el.fk_target";
		$sql .= " WHERE el.fk_source IN (".implode(',', $orderIds).")";
		$sql .= " AND el.sourcetype = 'commande'";
		$sql .= " AND el.targettype = 'facture'";
		$sql .= " AND f.fk_statut <> 3";
		$sql .= " ORDER BY f.datef, f.ref";
		$resql = $db->query($sql);
		if (!$resql) {
			return '';
		}

		$allInvoices = array();
		while ($row = $db->fetch_object($resql)) {
			$allInvoices[] = $row;
		}
		$db->free($resql);

		if (empty($allInvoices)) {
			return '';
		}

		$html = '';
		$totalInvoiced = 0;
		$currency = $langs->getCurrencySymbol($conf->currency);
		foreach ($allInvoices as $inv) {
			$url = DOL_URL_ROOT.'/compta/facture/card.php?facid='.((int) $inv->rowid);
			$ref = dol_escape_htmltag($inv->ref);
			$date = dol_print_date($db->jdate($inv->datef), 'day');
			$ht = price($inv->total_ht, 0, '', 1, -1, 2);
			$html .= '<a href="'.$url.'">'.$ref.'</a> ('.$date.' — '.$ht.' '.$currency.' HT)<br>'."\n";
			$totalInvoiced += (float) $inv->total_ht;
		}

		$remaining = $orderTotalHt - $totalInvoiced;
		$amountStr = price($totalInvoiced, 0, '', 1, -1, 2).' '.$currency.' HT';
		$orderStr = price($orderTotalHt, 0, '', 1, -1, 2).' '.$currency.' HT';
		$remainStr = price($remaining, 0, '', 1, -1, 2).' '.$currency.' HT';
		$html .= '<strong>=&gt; '.$langs->trans('DiamantutilsTotalInvoiced', $amountStr, $orderStr).'</strong><br>'."\n";
		$html .= '<strong>'.$langs->trans('DiamantutilsRemainingToInvoice', $remainStr).'</strong>'."\n";

		return $html;
	}

	private function getRelatedInvoicesHtml($orderId)
	{
		global $langs, $conf;
		$db = $this->db;

		$sql = "SELECT DISTINCT f.rowid, f.ref, f.datef, f.total_ht";
		$sql .= " FROM ".MAIN_DB_PREFIX."element_element as el";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."facture as f ON f.rowid = el.fk_target";
		$sql .= " WHERE el.fk_source = ".((int) $orderId);
		$sql .= " AND el.sourcetype = 'commande'";
		$sql .= " AND el.targettype = 'facture'";
		$sql .= " AND f.fk_statut <> 3";

		$sql .= " UNION SELECT DISTINCT f.rowid, f.ref, f.datef, f.total_ht";
		$sql .= " FROM ".MAIN_DB_PREFIX."element_element as el";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."facture as f ON f.rowid = el.fk_source";
		$sql .= " WHERE el.fk_target = ".((int) $orderId);
		$sql .= " AND el.sourcetype = 'facture'";
		$sql .= " AND el.targettype = 'commande'";
		$sql .= " AND f.fk_statut <> 3";

		$sql .= " ORDER BY datef, ref";

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

		$sqlOrder = "SELECT total_ht FROM ".MAIN_DB_PREFIX."commande WHERE rowid = ".((int) $orderId);
		$resqlOrder = $db->query($sqlOrder);
		$orderTotalHt = 0;
		if ($resqlOrder) {
			$objOrder = $db->fetch_object($resqlOrder);
			if ($objOrder) {
				$orderTotalHt = (float) $objOrder->total_ht;
			}
			$db->free($resqlOrder);
		}

		$html = '';
		$totalInvoiced = 0;
		$currency = $langs->getCurrencySymbol($conf->currency);
		foreach ($invoices as $inv) {
			$url = DOL_URL_ROOT.'/compta/facture/card.php?facid='.((int) $inv->rowid);
			$ref = dol_escape_htmltag($inv->ref);
			$date = dol_print_date($db->jdate($inv->datef), 'day');
			$ht = price($inv->total_ht, 0, '', 1, -1, 2);
			$html .= '<a href="'.$url.'">'.$ref.'</a> ('.$date.' — '.$ht.' '.$currency.' HT)<br>'."\n";
			$totalInvoiced += (float) $inv->total_ht;
		}

		$remaining = $orderTotalHt - $totalInvoiced;
		$amountStr = price($totalInvoiced, 0, '', 1, -1, 2).' '.$currency.' HT';
		$orderStr = price($orderTotalHt, 0, '', 1, -1, 2).' '.$currency.' HT';
		$remainStr = price($remaining, 0, '', 1, -1, 2).' '.$currency.' HT';
		$html .= '<strong>=&gt; '.$langs->trans('DiamantutilsTotalInvoiced', $amountStr, $orderStr).'</strong><br>'."\n";
		$html .= '<strong>'.$langs->trans('DiamantutilsRemainingToInvoice', $remainStr).'</strong>'."\n";

		return $html;
	}

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

		// Rattrapage : à l'ouverture d'une facture existante dont l'extrafield
		// est vide, on le remplit à partir des liens element_element (qui
		// existent maintenant, même si le trigger BILL_CREATE n'a pas pu les
		// exploiter au moment de la création).
		if (is_object($object) && !empty($object->id) && property_exists($object, 'element') && $object->element == 'facture') {
			$object->fetch_optionals();
			if (empty($object->array_options['options_factures'])) {
				$langs->load('diamantutils@diamantutils');
				$html = $this->buildInvoiceSummaryFromLinkedOrders($object->id);
				if (!empty($html)) {
					$object->array_options['options_factures'] = $html;
					$object->updateExtraFields();
				}
			}
		}

		return 0;
	}

	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $langs;

		$origin = GETPOST('origin', 'alpha');
		$originid = GETPOSTINT('originid');

		if (!isModEnabled('diamantutils')) {
			return 0;
		}

		$mode = getDolGlobalString('DIAMANTUTILS_INVOICE_CHECK_MODE', 'AFFICHER');
		if ($mode == 'DESACTIVE') {
			return 0;
		}

		$langs->load('diamantutils@diamantutils');

		$html = '';
		if ($origin == 'commande' && $originid > 0) {
			$html = $this->getRelatedInvoicesHtml($originid);
		}

		$this->resprints = '';

		if (!empty($html)) {
			if (!isset($object->array_options)) {
				$object->array_options = array();
			}
			$object->array_options['options_factures'] = $html;

			$jsHtml = json_encode($html);
			$this->resprints .= '<script type="text/javascript">'."\n";
			$this->resprints .= 'jQuery(document).ready(function() {'."\n";
			$this->resprints .= '  var htmlVal = '.$jsHtml.';'."\n";
			$this->resprints .= '  setTimeout(function() {'."\n";
			$this->resprints .= '    var el = document.getElementById("options_factures");'."\n";
			$this->resprints .= '    if (!el) el = document.querySelector("[name=\'options_factures\']");'."\n";
			$this->resprints .= '    if (el) el.value = htmlVal;'."\n";
			$this->resprints .= '    if (typeof CKEDITOR !== "undefined" && CKEDITOR.instances) {'."\n";
			$this->resprints .= '      var inst = CKEDITOR.instances["options_factures"];'."\n";
			$this->resprints .= '      if (inst) { inst.setData(htmlVal); return; }'."\n";
			$this->resprints .= '      CKEDITOR.on("instanceReady", function(evt) {'."\n";
			$this->resprints .= '        if (evt.editor.name === "options_factures") evt.editor.setData(htmlVal);'."\n";
			$this->resprints .= '      });'."\n";
			$this->resprints .= '    }'."\n";
			$this->resprints .= '  }, 500);'."\n";
			$this->resprints .= '});'."\n";
			$this->resprints .= '</script>'."\n";
		}

		return 0;
	}
}
